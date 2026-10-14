using System.Net;
using System.Net.Http.Headers;
using System.Net.Http.Json;
using System.Text.Json;
using MediCare.Contracts;
using MediCare.Host.Infrastructure;
using MediCare.Host.Security;
using Microsoft.AspNetCore.Mvc.Testing;
using Xunit;

[assembly: CollectionBehavior(DisableTestParallelization = true)]

namespace MediCare.Host.Tests;

/// <summary>Runs the real host (all modules, real SQLite file in a temp folder) in memory.</summary>
public sealed class TestHost : IDisposable
{
    public static readonly JsonSerializerOptions JsonOpts = new(JsonSerializerDefaults.Web);
    public string DataDir { get; } = Path.Combine(Path.GetTempPath(), "medicare-test-" + Guid.NewGuid().ToString("N"));
    private readonly WebApplicationFactory<Program> _factory;

    public TestHost()
    {
        Passwords.Iterations = 1000;
        Clock.UtcNowFn = () => DateTime.UtcNow;
        Environment.SetEnvironmentVariable("MediCare__DataDir", DataDir);
        Environment.SetEnvironmentVariable("MediCare__UseHttps", "false");
        Environment.SetEnvironmentVariable("MediCare__Port", "0");
        _factory = new WebApplicationFactory<Program>();
        _ = _factory.Server;
    }

    public Api Anonymous() => new(_factory.CreateClient());
    public IServiceProvider Services => _factory.Services;

    public async Task<Api> SetupAndLoginAdmin()
    {
        var api = Anonymous();
        await api.Post<OkResult>("/api/v1/setup", new SetupRequest
        {
            PharmacyName = "MediCare Test Pharmacy", Address = "Lahore", Phone = "042-1", LicenseNo = "DSL-1",
            AdminFullName = "Rehan Malik", AdminUsername = "rehan", AdminPassword = "Admin12345", AdminPin = "1234",
        });
        return await Login("rehan", "Admin12345", counter: 1);
    }

    public async Task<Api> Login(string user, string password, int counter = 0, string? machine = null)
    {
        var api = Anonymous();
        var r = await api.Post<LoginResponse>("/api/v1/auth/login", new LoginRequest
        {
            Username = user, Password = password, MachineId = machine ?? $"PC-{user}", WorkstationName = $"COUNTER-{counter}", Os = "Test", ClientVersion = "1.0.0", CounterNo = counter,
        });
        api.SetToken(r.Token);
        api.Login = r;
        return api;
    }

    public void Dispose()
    {
        _factory.Dispose();
        Microsoft.Data.Sqlite.SqliteConnection.ClearAllPools();
        Clock.UtcNowFn = () => DateTime.UtcNow;
        if (Environment.GetEnvironmentVariable("MEDICARE_KEEP_TEST_DATA") == "1") return;
        try { Directory.Delete(DataDir, true); } catch { }
    }
}

public sealed class ApiException(HttpStatusCode status, ApiError error) : Exception($"{(int)status} {error.Code}: {error.Message}")
{
    public HttpStatusCode Status { get; } = status;
    public ApiError Error { get; } = error;
}

public sealed class Api(HttpClient http)
{
    public HttpClient Http { get; } = http;
    public LoginResponse? Login { get; set; }
    public void SetToken(string token) => Http.DefaultRequestHeaders.Authorization = new AuthenticationHeaderValue("Bearer", token);

    private static async Task<T> Read<T>(HttpResponseMessage r)
    {
        var body = await r.Content.ReadAsStringAsync();
        if (!r.IsSuccessStatusCode)
        {
            ApiError err;
            try { err = JsonSerializer.Deserialize<ApiError>(body, TestHost.JsonOpts) ?? new ApiError { Message = body }; }
            catch { err = new ApiError { Code = "?", Message = body }; }
            throw new ApiException(r.StatusCode, err);
        }
        return JsonSerializer.Deserialize<T>(body, TestHost.JsonOpts)!;
    }

    public async Task<T> Get<T>(string url) => await Read<T>(await Http.GetAsync(url));
    public async Task<T> Post<T>(string url, object body, params string[] approvalTokens)
    {
        using var req = new HttpRequestMessage(HttpMethod.Post, url) { Content = JsonContent.Create(body, options: TestHost.JsonOpts) };
        if (approvalTokens.Length > 0) req.Headers.Add("X-Approval-Token", string.Join(",", approvalTokens));
        return await Read<T>(await Http.SendAsync(req));
    }
    public async Task<T> Put<T>(string url, object body) => await Read<T>(await Http.PutAsJsonAsync(url, body, TestHost.JsonOpts));

    public async Task<ApiError> Fails(Func<Task> call, string? code = null)
    {
        var ex = await Assert.ThrowsAsync<ApiException>(call);
        if (code != null) Assert.Equal(code, ex.Error.Code);
        return ex.Error;
    }
}
