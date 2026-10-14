using System.Diagnostics;
using System.Net.Security;
using System.Security.Cryptography;
using System.Security.Cryptography.X509Certificates;
using System.Text.Json;

namespace MediCare.HostMonitor;

public sealed record HealthResult(bool Responding, bool? Maintenance, string? Version, long ElapsedMs, string? Error);

/// <summary>
/// Calls the host's anonymous <c>GET /api/v1/health</c> on the loopback address. When the fingerprint is known
/// the TLS certificate is pinned to it, exactly as counter PCs do, so a mismatch shows up here too.
/// </summary>
public sealed class HealthProbe : IDisposable
{
    private static readonly TimeSpan Timeout = TimeSpan.FromSeconds(4);
    private HttpClient? _client;
    private string _clientKey = "";

    public async Task<HealthResult> CheckAsync(int port, bool https, string? fingerprint)
    {
        var client = GetClient(https, fingerprint);
        var url = $"{(https ? "https" : "http")}://127.0.0.1:{port}/api/v1/health";
        var sw = Stopwatch.StartNew();
        try
        {
            using var response = await client.GetAsync(url).ConfigureAwait(false);
            var body = await response.Content.ReadAsStringAsync().ConfigureAwait(false);
            sw.Stop();
            if (!response.IsSuccessStatusCode)
                return new HealthResult(false, null, null, sw.ElapsedMilliseconds, $"HTTP {(int)response.StatusCode}");

            using var doc = JsonDocument.Parse(body);
            var root = doc.RootElement;
            bool? maintenance = root.TryGetProperty("maintenance", out var m) && m.ValueKind is JsonValueKind.True or JsonValueKind.False ? m.GetBoolean() : null;
            var version = root.TryGetProperty("version", out var v) && v.ValueKind == JsonValueKind.String ? v.GetString() : null;
            return new HealthResult(true, maintenance, version, sw.ElapsedMilliseconds, null);
        }
        catch (HttpRequestException ex) when (ex.InnerException is System.Security.Authentication.AuthenticationException)
        {
            return new HealthResult(false, null, null, sw.ElapsedMilliseconds,
                "The certificate the host presents does not match the fingerprint in the status file.");
        }
        catch (Exception ex) when (ex is HttpRequestException or TaskCanceledException or JsonException)
        {
            return new HealthResult(false, null, null, sw.ElapsedMilliseconds, ex is TaskCanceledException ? "No answer within 4 s." : ex.Message);
        }
    }

    private HttpClient GetClient(bool https, string? fingerprint)
    {
        var key = $"{https}|{fingerprint}";
        if (_client != null && key == _clientKey) return _client;

        _client?.Dispose();
        var handler = new SocketsHttpHandler
        {
            ConnectTimeout = Timeout,
            UseProxy = false,
            SslOptions = new SslClientAuthenticationOptions
            {
                // Loopback only. Pin to the published fingerprint when we have it; otherwise accept the
                // self-signed certificate (the name never matches 127.0.0.1).
                RemoteCertificateValidationCallback = (_, cert, _, _) =>
                    cert != null && (string.IsNullOrEmpty(fingerprint) || string.Equals(Fingerprint(cert), fingerprint.Trim(), StringComparison.OrdinalIgnoreCase)),
            },
        };
        _client = new HttpClient(handler) { Timeout = Timeout };
        _clientKey = key;
        return _client;
    }

    /// <summary>Same format as the host: SHA-256 of the DER certificate, uppercase hex pairs joined by colons.</summary>
    public static string Fingerprint(X509Certificate cert) =>
        string.Join(":", SHA256.HashData(cert.GetRawCertData()).Select(b => b.ToString("X2")));

    public void Dispose() => _client?.Dispose();
}
