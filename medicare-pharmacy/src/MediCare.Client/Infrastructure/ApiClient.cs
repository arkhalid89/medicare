using System.IO;
using System.Net;
using System.Net.Http;
using System.Net.Http.Headers;
using System.Net.Security;
using System.Security.Cryptography;
using System.Security.Cryptography.X509Certificates;
using System.Text;
using System.Text.Json;
using System.Threading;

namespace MediCare.Client.Infrastructure;

/// <summary>An error the host returned (or "OFFLINE" when it could not be reached).</summary>
public sealed class ApiException : Exception
{
    public const string Offline = "OFFLINE";
    public const string CertMismatch = "CERT_MISMATCH";

    public int Status { get; }
    public string Code { get; }
    public Dictionary<string, string> Details { get; }

    public ApiException(int status, string code, string message, Dictionary<string, string>? details = null) : base(message)
    {
        Status = status;
        Code = code;
        Details = details ?? new Dictionary<string, string>();
    }

    public bool IsOffline => Code == Offline;
    public bool IsAuth => Code == ErrorCodes.Unauthorized || Code == ErrorCodes.SessionExpired;
}

/// <summary>
/// HTTPS + JSON client for the host (ADR-004). The host's self-signed certificate is pinned by its
/// SHA-256 fingerprint: any other certificate is refused, so a stranger on the LAN cannot pose as the host.
/// </summary>
public sealed class ApiClient : IDisposable
{
    public static readonly JsonSerializerOptions Json = new JsonSerializerOptions(JsonSerializerDefaults.Web);

    private readonly HttpClient _http;
    private readonly HttpClient _slow;
    private readonly string _expectedFingerprint;

    public Uri BaseUri { get; }
    public string? Token { get; set; }
    public string HostName => BaseUri.Host;

    /// <summary>The fingerprint of the certificate the host presented on the last TLS handshake.</summary>
    public string LastSeenFingerprint { get; private set; } = "";

    /// <summary>Raised (on a worker thread) when a request fails because the session ended.</summary>
    public event Action<ApiException>? AuthLost;
    /// <summary>Raised when the host answers again after being unreachable, or stops answering.</summary>
    public event Action<bool>? Reachability;

    private bool _reachable = true;

    static ApiClient()
    {
#if NETFRAMEWORK
        // Windows 7 / .NET Framework 4.8: make sure TLS 1.2 is offered.
        ServicePointManager.SecurityProtocol |= SecurityProtocolType.Tls12;
#endif
    }

    /// <param name="hostUrl">e.g. https://PHARM-HOST:8443</param>
    /// <param name="fingerprint">Pinned SHA-256 fingerprint. Empty only while pairing.</param>
    public ApiClient(string hostUrl, string fingerprint)
    {
        BaseUri = new Uri(hostUrl.TrimEnd('/') + "/");
        _expectedFingerprint = Normalise(fingerprint);
        _http = new HttpClient(CreateHandler()) { BaseAddress = BaseUri, Timeout = TimeSpan.FromSeconds(30) };
        _slow = new HttpClient(CreateHandler()) { BaseAddress = BaseUri, Timeout = TimeSpan.FromMinutes(15) };
    }

    private HttpClientHandler CreateHandler()
    {
        var h = new HttpClientHandler { UseProxy = false, AutomaticDecompression = DecompressionMethods.GZip | DecompressionMethods.Deflate };
        h.ServerCertificateCustomValidationCallback = ValidateCertificate;
        return h;
    }

    private bool ValidateCertificate(HttpRequestMessage msg, X509Certificate2? cert, X509Chain? chain, SslPolicyErrors errors)
    {
        if (cert == null) return false;
        using (var sha = SHA256.Create())
            LastSeenFingerprint = string.Join(":", sha.ComputeHash(cert.RawData).Select(b => b.ToString("X2")));
        // While pairing there is nothing pinned yet: accept, and let the person compare the fingerprint on screen.
        if (_expectedFingerprint.Length == 0) return true;
        return Normalise(LastSeenFingerprint) == _expectedFingerprint;
    }

    public static string Normalise(string? fp) => new string((fp ?? "").Where(Uri.IsHexDigit).Select(char.ToUpperInvariant).ToArray());

    // ---------------- verbs ----------------

    public Task<T> Get<T>(string path, object? query = null, bool slow = false) =>
        Send<T>(HttpMethod.Get, path + Query(query), null, null, slow);

    public Task<T> Post<T>(string path, object? body, IEnumerable<string>? approvalTokens = null, bool slow = false) =>
        Send<T>(HttpMethod.Post, path, body ?? new { }, approvalTokens, slow);

    public Task<T> Put<T>(string path, object body) => Send<T>(HttpMethod.Put, path, body, null, false);

    public Task<T> Delete<T>(string path) => Send<T>(HttpMethod.Delete, path, null, null, false);

    /// <summary>Downloads a file (backup, export). Returns the bytes and the file name the host suggests.</summary>
    public async Task<(byte[] data, string fileName)> Download(string path, object? query = null)
    {
        using var req = Build(HttpMethod.Get, path + Query(query), null, null);
        var resp = await Exchange(_slow, req).ConfigureAwait(true);
        using (resp)
        {
            await EnsureOk(resp).ConfigureAwait(true);
            var data = await resp.Content.ReadAsByteArrayAsync().ConfigureAwait(true);
            var name = resp.Content.Headers.ContentDisposition?.FileNameStar ?? resp.Content.Headers.ContentDisposition?.FileName ?? "download.bin";
            return (data, name.Trim('"'));
        }
    }

    /// <summary>Downloads to a file on disk without holding it all in memory (backups can be large).</summary>
    public async Task<string> DownloadTo(string path, string targetFolder)
    {
        using var req = Build(HttpMethod.Get, path, null, null);
        var resp = await Exchange(_slow, req, HttpCompletionOption.ResponseHeadersRead).ConfigureAwait(true);
        using (resp)
        {
            await EnsureOk(resp).ConfigureAwait(true);
            var name = (resp.Content.Headers.ContentDisposition?.FileNameStar ?? resp.Content.Headers.ContentDisposition?.FileName ?? "backup.mcbak").Trim('"');
            var target = Path.Combine(targetFolder, Path.GetFileName(name));
            using (var src = await resp.Content.ReadAsStreamAsync().ConfigureAwait(true))
            using (var dst = File.Create(target))
                await src.CopyToAsync(dst).ConfigureAwait(true);
            return target;
        }
    }

    /// <summary>Uploads a file as multipart form data (medicine import, restore).</summary>
    public async Task<T> Upload<T>(string path, string filePath)
    {
        using var content = new MultipartFormDataContent();
        var stream = File.OpenRead(filePath);
        var file = new StreamContent(stream);
        file.Headers.ContentType = new MediaTypeHeaderValue("application/octet-stream");
        content.Add(file, "file", Path.GetFileName(filePath));
        using var req = Build(HttpMethod.Post, path, null, null);
        req.Content = content;
        var resp = await Exchange(_slow, req).ConfigureAwait(true);
        using (resp)
        {
            await EnsureOk(resp).ConfigureAwait(true);
            return Deserialize<T>(await resp.Content.ReadAsStringAsync().ConfigureAwait(true));
        }
    }

    // ---------------- core ----------------

    private async Task<T> Send<T>(HttpMethod method, string path, object? body, IEnumerable<string>? approvalTokens, bool slow)
    {
        using var req = Build(method, path, body, approvalTokens);
        var resp = await Exchange(slow ? _slow : _http, req).ConfigureAwait(true);
        using (resp)
        {
            await EnsureOk(resp).ConfigureAwait(true);
            var text = await resp.Content.ReadAsStringAsync().ConfigureAwait(true);
            return Deserialize<T>(text);
        }
    }

    private HttpRequestMessage Build(HttpMethod method, string path, object? body, IEnumerable<string>? approvalTokens)
    {
        var req = new HttpRequestMessage(method, "api/v1/" + path.TrimStart('/'));
        if (!string.IsNullOrEmpty(Token)) req.Headers.Authorization = new AuthenticationHeaderValue("Bearer", Token);
        var tokens = approvalTokens?.Where(t => !string.IsNullOrWhiteSpace(t)).ToList();
        if (tokens != null && tokens.Count > 0) req.Headers.TryAddWithoutValidation("X-Approval-Token", string.Join(",", tokens));
        if (body != null) req.Content = new StringContent(JsonSerializer.Serialize(body, body.GetType(), Json), Encoding.UTF8, "application/json");
        return req;
    }

    private async Task<HttpResponseMessage> Exchange(HttpClient client, HttpRequestMessage req, HttpCompletionOption option = HttpCompletionOption.ResponseContentRead)
    {
        try
        {
            var resp = await client.SendAsync(req, option).ConfigureAwait(true);
            SetReachable(true);
            return resp;
        }
        catch (HttpRequestException ex) when (_expectedFingerprint.Length > 0 && LastSeenFingerprint.Length > 0 && Normalise(LastSeenFingerprint) != _expectedFingerprint)
        {
            Log.Error("Certificate mismatch", ex);
            throw new ApiException(0, ApiException.CertMismatch,
                $"The computer at {HostName} presented a different security certificate than the one this PC was paired with. " +
                "Do not continue. Ask the administrator to check the host and pair this PC again.");
        }
        catch (Exception ex) when (ex is HttpRequestException || ex is TaskCanceledException || ex is IOException || ex is WebException)
        {
            SetReachable(false);
            throw new ApiException(0, ApiException.Offline,
                $"The host {HostName} did not answer. Check that the host PC is on and the network cable is connected. " +
                "If you were saving, press the same button again when it is back. It will not be saved twice.");
        }
    }

    private void SetReachable(bool ok)
    {
        if (ok == _reachable) return;
        _reachable = ok;
        Reachability?.Invoke(ok);
    }

    private async Task EnsureOk(HttpResponseMessage resp)
    {
        if (resp.IsSuccessStatusCode) return;
        var text = resp.Content == null ? "" : await resp.Content.ReadAsStringAsync().ConfigureAwait(true);
        ApiError? err = null;
        try { err = JsonSerializer.Deserialize<ApiError>(text, Json); } catch { /* not JSON */ }
        var ex = new ApiException((int)resp.StatusCode, err?.Code ?? ("HTTP_" + (int)resp.StatusCode),
            string.IsNullOrWhiteSpace(err?.Message) ? $"The host answered {(int)resp.StatusCode} {resp.ReasonPhrase}." : err!.Message, err?.Details);
        if (ex.IsAuth && !string.IsNullOrEmpty(Token)) AuthLost?.Invoke(ex);
        throw ex;
    }

    private static T Deserialize<T>(string text)
    {
        if (typeof(T) == typeof(string)) return (T)(object)text;
        if (string.IsNullOrWhiteSpace(text) || text == "null") return default!;
        return JsonSerializer.Deserialize<T>(text, Json)!;
    }

    public static string Query(object? query)
    {
        if (query == null) return "";
        var parts = new List<string>();
        foreach (var p in query.GetType().GetProperties())
        {
            var v = p.GetValue(query);
            if (v == null) continue;
            string s = v switch
            {
                DateTime d => d.ToString("yyyy-MM-dd", CultureInfo.InvariantCulture),
                bool b => b ? "true" : "false",
                decimal m => m.ToString(CultureInfo.InvariantCulture),
                IFormattable f => f.ToString(null, CultureInfo.InvariantCulture),
                _ => v.ToString() ?? "",
            };
            if (s.Length == 0) continue;
            parts.Add(Uri.EscapeDataString(p.Name) + "=" + Uri.EscapeDataString(s));
        }
        return parts.Count == 0 ? "" : "?" + string.Join("&", parts);
    }

    public static string Query(IDictionary<string, string> values) =>
        values.Count == 0 ? "" : "?" + string.Join("&", values.Where(kv => !string.IsNullOrEmpty(kv.Value)).Select(kv => Uri.EscapeDataString(kv.Key) + "=" + Uri.EscapeDataString(kv.Value)));

    /// <summary>Long-poll for host events (approvals, settings, maintenance). Runs until cancelled.</summary>
    public async Task PollEvents(Action<EventDto> onEvent, CancellationToken ct)
    {
        long after = -1;
        var started = DateTime.UtcNow.AddSeconds(-2);
        while (!ct.IsCancellationRequested)
        {
            try
            {
                if (string.IsNullOrEmpty(Token)) { await Task.Delay(2000, ct).ConfigureAwait(false); continue; }
                using var req = Build(HttpMethod.Get, "events?after=" + Math.Max(after, 0), null, null);
                using var resp = await _slow.SendAsync(req, ct).ConfigureAwait(false);
                SetReachable(true);
                if (!resp.IsSuccessStatusCode) { await Task.Delay(5000, ct).ConfigureAwait(false); continue; }
                var r = JsonSerializer.Deserialize<EventsResponse>(await resp.Content.ReadAsStringAsync().ConfigureAwait(false), Json);
                if (r == null) continue;
                // The first call only learns the current position: events from before this PC connected are not replayed.
                foreach (var e in r.Events)
                    if (after >= 0 || e.At >= started) onEvent(e);
                after = r.LastSeq;
            }
            catch (OperationCanceledException) when (ct.IsCancellationRequested) { return; }
            catch (Exception)
            {
                SetReachable(false);
                try { await Task.Delay(5000, ct).ConfigureAwait(false); } catch (OperationCanceledException) { return; }
            }
        }
    }

    public void Dispose()
    {
        _http.Dispose();
        _slow.Dispose();
    }
}
