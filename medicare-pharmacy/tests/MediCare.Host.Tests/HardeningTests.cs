using System.Net;
using MediCare.Contracts;
using MediCare.Host.Security;
using Microsoft.AspNetCore.Routing;
using Microsoft.Data.Sqlite;
using Microsoft.Extensions.DependencyInjection;
using Xunit;
using static MediCare.Host.Tests.ScenarioTests;

namespace MediCare.Host.Tests;

/// <summary>S25 security checklist and integrity checks (testing.md, NFR-009).</summary>
public class HardeningTests
{
    private static readonly string[] AnonymousRoutes = { "/api/v1/health", "/api/v1/setup/status", "/api/v1/setup", "/api/v1/pairing/fingerprint", "/api/v1/auth/login" };

    private static List<(string method, string pattern, RequirePermAttribute? perm, bool anonymous)> Endpoints(TestHost host) =>
        host.Services.GetRequiredService<EndpointDataSource>().Endpoints.OfType<RouteEndpoint>()
            .Where(e => (e.RoutePattern.RawText ?? "").StartsWith("/api/", StringComparison.Ordinal))
            .SelectMany(e => (e.Metadata.GetMetadata<HttpMethodMetadata>()?.HttpMethods ?? new[] { "GET" })
                .Select(m => (m, e.RoutePattern.RawText!, e.Metadata.GetMetadata<RequirePermAttribute>(), e.Metadata.GetMetadata<AllowAnonymousApi>() != null)))
            .ToList();

    private static string Url(string pattern) => System.Text.RegularExpressions.Regex.Replace(pattern, @"\{(\w+)(:[^}]*)?\}", m => m.Groups[1].Value switch
    {
        "kind" => "category",
        "key" => "daily_sales",
        "no" => "X-1",
        _ => "1",
    });

    private static async Task<HttpStatusCode> Call(Api api, string method, string url)
    {
        using var req = new HttpRequestMessage(new HttpMethod(method), url);
        if (method != "GET" && method != "DELETE") req.Content = new StringContent("{}", System.Text.Encoding.UTF8, "application/json");
        using var r = await api.Http.SendAsync(req);
        return r.StatusCode;
    }

    [Fact]
    public async Task Every_Endpoint_Declares_Access_And_Rejects_Anonymous_Callers()
    {
        using var host = new TestHost();
        await host.SetupAndLoginAdmin();
        var endpoints = Endpoints(host);
        Assert.True(endpoints.Count > 100, $"only {endpoints.Count} endpoints found");
        Assert.All(endpoints, e => Assert.True(e.perm != null || e.anonymous, $"{e.method} {e.pattern} declares no permission"));
        Assert.Equal(AnonymousRoutes.OrderBy(x => x), endpoints.Where(e => e.anonymous).Select(e => e.pattern).Distinct().OrderBy(x => x));

        var anon = host.Anonymous();
        foreach (var e in endpoints.Where(e => !e.anonymous))
            Assert.True(await Call(anon, e.method, Url(e.pattern)) == HttpStatusCode.Unauthorized, $"{e.method} {e.pattern} answered an anonymous caller");
    }

    [Fact]
    public async Task Cashier_Is_Refused_Every_Endpoint_Outside_Their_Role_And_Refusals_Are_Audited()
    {
        using var host = new TestHost();
        var admin = await host.SetupAndLoginAdmin();
        var roles = await admin.Get<List<RoleDto>>("/api/v1/roles");
        await admin.Post<OkResult>("/api/v1/users", new UserSaveRequest { Username = "asad", FullName = "Asad Raza", RoleId = roles.Single(r => r.Name == "Cashier").Id, Password = "Cashier123x" });
        var cashier = await host.Login("asad", "Cashier123x", 2);
        await cashier.Post<OkResult>("/api/v1/auth/password", new ChangePasswordRequest { CurrentPassword = "Cashier123x", NewPassword = "Cashier456x" });
        cashier = await host.Login("asad", "Cashier456x", 2);
        var mine = cashier.Login!.Permissions.ToHashSet();
        Assert.DoesNotContain(Perm.UsersManage, mine);

        var denied = 0;
        foreach (var e in Endpoints(host).Where(e => e.perm != null && e.perm.Permission.Length > 0 && !mine.Contains(e.perm.Permission)))
        {
            Assert.True(await Call(cashier, e.method, Url(e.pattern)) == HttpStatusCode.Forbidden, $"{e.method} {e.pattern} ({e.perm!.Permission}) was not refused for a cashier");
            denied++;
        }
        Assert.True(denied > 30, $"only {denied} endpoints were outside the cashier role");
        Assert.True(Sql<long>(host, "SELECT COUNT(*) FROM audit_logs WHERE action='PERMISSION_DENIED' AND username='asad'") >= denied);
    }

    [Fact]
    public async Task Posted_Rows_Cannot_Be_Edited_Or_Deleted_And_Audit_Chain_Holds()
    {
        using var host = new TestHost();
        var api = await host.SetupAndLoginAdmin();
        var sup = await CreateSupplier(api);
        var risek = await CreateRisek(api);
        await Receive(api, sup, risek, boxes: 1, rate: 1754m);
        await api.Post<SaleResult>("/api/v1/sales", CashSale(risek, "Strip", 1, 420m));

        using var c = new SqliteConnection($"Data Source={Path.Combine(host.DataDir, "medicare.db")};Pooling=False");
        c.Open();
        foreach (var sql in new[]
        {
            "UPDATE sales_headers SET net_paisa = 1", "DELETE FROM sales_headers", "UPDATE sales_items SET base_qty = 1", "DELETE FROM sales_payments",
            "UPDATE stock_movements SET qty_delta = 0", "DELETE FROM stock_movements", "UPDATE journal_lines SET debit_paisa = 0", "DELETE FROM journal_entries",
            "UPDATE audit_logs SET reason = 'x'", "DELETE FROM audit_logs", "DELETE FROM purchases",
        })
        {
            using var cmd = c.CreateCommand();
            cmd.CommandText = sql;
            var ex = Assert.ThrowsAny<SqliteException>(() => cmd.ExecuteNonQuery());
            Assert.Contains("immutable", ex.Message.Replace("append-only", "immutable"));
        }
        var check = await api.Post<SelfCheckResult>("/api/v1/selfcheck", new { });
        Assert.True(check.Items.Single(i => i.Check.StartsWith("Audit log chain")).Ok);
    }

    [Fact]
    public async Task Passwords_Pins_And_Tokens_Never_Reach_The_Log()
    {
        const string password = "Zq7SecretPassw0rd";
        const string pin = "739164";
        using var host = new TestHost();
        var admin = await host.SetupAndLoginAdmin();
        var roles = await admin.Get<List<RoleDto>>("/api/v1/roles");
        await admin.Post<OkResult>("/api/v1/users", new UserSaveRequest { Username = "sana", FullName = "Sana Iqbal", RoleId = roles.Single(r => r.Name.StartsWith("Pharmacist")).Id, Password = password, Pin = pin });
        var sana = await host.Login("sana", password, 3);
        await host.Anonymous().Fails(() => host.Anonymous().Post<LoginResponse>("/api/v1/auth/login", new LoginRequest { Username = "sana", Password = password + "x" }));
        var tok = await admin.Post<ApprovalTokenResponse>("/api/v1/approvals/inline", new InlineApprovalRequest { Action = ApprovalActions.Discount, ContextKey = "k1", Value = 5, ApproverUsername = "sana", Pin = pin, Reason = "test" });
        await admin.Post<SaleResult>("/api/v1/sales", new SaleRequest { IdempotencyKey = "bad", Lines = new() }).ContinueWith(_ => { });

        var logs = Directory.GetFiles(Path.Combine(host.DataDir, "logs"));
        Assert.NotEmpty(logs);
        var text = string.Concat(logs.Select(f => { using var s = new FileStream(f, FileMode.Open, FileAccess.Read, FileShare.ReadWrite); using var r = new StreamReader(s); return r.ReadToEnd(); }));
        foreach (var secret in new[] { password, pin, sana.Login!.Token, admin.Login!.Token, tok.Token, "Admin12345" })
            Assert.DoesNotContain(secret, text);
        // and the database stores only hashes
        Assert.Equal(0L, Sql<long>(host, $"SELECT COUNT(*) FROM users WHERE pwd_hash LIKE '%{password}%' OR pin_hash LIKE '%{pin}%'"));
        Assert.Equal(0L, Sql<long>(host, $"SELECT COUNT(*) FROM sessions WHERE token_hash = '{sana.Login.Token}'"));
    }

    [Fact]
    public async Task All_Reports_Run_Export_And_Reconcile()
    {
        using var host = new TestHost();
        var api = await host.SetupAndLoginAdmin();
        var sup = await CreateSupplier(api);
        var risek = await CreateRisek(api, schedule: "B");
        await Receive(api, sup, risek, boxes: 3, rate: 1754m);
        var sale = CashSale(risek, "Strip", 2, 840m);
        sale.Prescriptions.Add(new PrescriptionInput { LineIndex = 0, Prescriber = "Dr. Ali", PrescriberReg = "PMDC-1", Patient = "Ahmed", RxNo = "R1" });
        await api.Post<SaleResult>("/api/v1/sales", sale);
        var sale2 = CashSale(risek, "Strip", 1, 420m);
        sale2.Prescriptions.Add(new PrescriptionInput { LineIndex = 0, Prescriber = "Dr. Ali", PrescriberReg = "PMDC-1", Patient = "Bilal", RxNo = "R2" });
        await api.Post<SaleResult>("/api/v1/sales", sale2);

        var catalog = await api.Get<List<ReportInfo>>("/api/v1/reports");
        var customerId = (await api.Get<List<CustomerDto>>("/api/v1/customers")).First().Id;
        Assert.Equal(26, catalog.Count);
        // the host's business day (Pakistan time), not this machine's calendar date
        var today = (await api.Get<ClientSettings>("/api/v1/settings/client")).BusinessDate;
        foreach (var r in catalog)
        {
            var q = string.Join("&", r.Params.Select(p => p.Type switch
            {
                "date" => $"{p.Key}={(p.Key == "to" && r.Key == "batch_expiry" ? "2030-01-01" : today)}",
                "customer" => $"{p.Key}={customerId}",
                "supplier" => $"{p.Key}={sup}",
                _ => "",
            }).Where(x => x.Length > 0));
            var result = await api.Get<ReportResult>($"/api/v1/reports/{r.Key}?{q}");
            Assert.Equal(result.Columns.Count, result.Rows.FirstOrDefault()?.Count ?? result.Columns.Count);
            var xlsx = await api.Http.GetAsync($"/api/v1/reports/{r.Key}/export?format=xlsx&{q}");
            Assert.True(xlsx.IsSuccessStatusCode, $"{r.Key} xlsx export failed");
            Assert.True((await xlsx.Content.ReadAsByteArrayAsync()).Length > 100);
        }

        var daily = await api.Get<ReportResult>($"/api/v1/reports/daily_sales?from={today}&to={today}");
        var netCol = daily.Columns.FindIndex(c => c.Name.StartsWith("Net", StringComparison.OrdinalIgnoreCase));
        Assert.True(netCol >= 0);
        var reportNet = daily.Rows.Sum(r => decimal.Parse(r[netCol], System.Globalization.NumberStyles.Any, System.Globalization.CultureInfo.InvariantCulture));
        Assert.Equal(Sql<long>(host, "SELECT SUM(net_paisa) FROM sales_headers") / 100m, reportNet);

        var register = await api.Get<ReportResult>($"/api/v1/reports/controlled_register?from={today}&to={today}");
        Assert.Equal(2, register.Rows.Count);
        Assert.Contains(register.Rows, r => r.Contains("Bilal"));
    }

    [Fact]
    public async Task Day_Close_And_Cash_Difference_Need_Approval_And_Self_Check_Stays_Green()
    {
        using var host = new TestHost();
        var admin = await host.SetupAndLoginAdmin();
        var roles = await admin.Get<List<RoleDto>>("/api/v1/roles");
        await admin.Post<OkResult>("/api/v1/users", new UserSaveRequest { Username = "asad", FullName = "Asad Raza", RoleId = roles.Single(r => r.Name == "Cashier").Id, Password = "Cashier123x" });
        var cashier = await host.Login("asad", "Cashier123x", 2);
        await cashier.Post<OkResult>("/api/v1/auth/password", new ChangePasswordRequest { CurrentPassword = "Cashier123x", NewPassword = "Cashier456x" });
        var sup = await CreateSupplier(admin);
        var risek = await CreateRisek(admin);
        await Receive(admin, sup, risek, boxes: 2, rate: 1754m);

        var shift = await cashier.Post<ShiftDto>("/api/v1/shifts/open", new OpenShiftRequest { Float = 1000, CounterNo = 2 });
        await cashier.Post<SaleResult>("/api/v1/sales", CashSale(risek, "Strip", 1, 420m, tendered: 500));
        var expected = (await cashier.Get<ShiftDto>("/api/v1/shifts/current")).Expected;
        Assert.Equal(1420m, expected);

        // 50 short: needs a reason and a manager's approval
        var close = new CloseShiftRequest { Counts = new() { new DenominationCount { Denomination = 1000, Count = 1 }, new DenominationCount { Denomination = 100, Count = 3 }, new DenominationCount { Denomination = 50, Count = 1 }, new DenominationCount { Denomination = 10, Count = 2 } }, Reason = "Change given twice" };
        var err = await cashier.Fails(() => cashier.Post<ShiftDto>($"/api/v1/shifts/{shift.Id}/close", close), ErrorCodes.ApprovalRequired);
        Assert.Equal(ApprovalActions.CashDifference, err.Details!["action"]);
        var tok = await cashier.Post<ApprovalTokenResponse>("/api/v1/approvals/inline", new InlineApprovalRequest { Action = ApprovalActions.CashDifference, ContextKey = err.Details["contextKey"], Value = 50, ApproverUsername = "rehan", Pin = "1234", Reason = "Checked CCTV" });
        var closed = await cashier.Post<ShiftDto>($"/api/v1/shifts/{shift.Id}/close", close, tok.Token);
        Assert.Equal(-50m, closed.Difference);

        // day close, then a sale needs AFTER_DAY_CLOSE approval
        await admin.Post<DayStatusDto>("/api/v1/day/close", new { });
        var late = CashSale(risek, "Strip", 1, 420m);
        late.CounterNo = 2;
        err = await cashier.Fails(() => cashier.Post<SaleResult>("/api/v1/sales", late), ErrorCodes.ApprovalRequired);
        Assert.Equal(ApprovalActions.AfterDayClose, err.Details!["action"]);
        tok = await cashier.Post<ApprovalTokenResponse>("/api/v1/approvals/inline", new InlineApprovalRequest { Action = ApprovalActions.AfterDayClose, ContextKey = late.IdempotencyKey, Value = 0, ApproverUsername = "rehan", Pin = "1234", Reason = "Customer waiting" });
        await cashier.Post<SaleResult>("/api/v1/sales", late, tok.Token);

        var check = await admin.Post<SelfCheckResult>("/api/v1/selfcheck", new { });
        Assert.All(check.Items.Where(i => !i.Check.StartsWith("Verified backup")), i => Assert.True(i.Ok, $"{i.Check}: {i.Detail}"));
    }
}
