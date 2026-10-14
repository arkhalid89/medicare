using System.Collections.Concurrent;
using System.Diagnostics;
using System.Globalization;
using System.Net;
using System.Net.Http.Headers;
using System.Security.Cryptography;
using System.Text;
using System.Text.Json;
using MediCare.Contracts;
using MediCare.Core;

namespace MediCare.LoadTest;

/// <summary>
/// S25 load test (docs/04-development/testing.md): counter bots selling (1-8 lines, 30 % 2D scans, 10 % credit,
/// 5 % returns), a goods-in bot, a report bot and a backup in the middle of the run, against a running host.
/// Pass criteria: p95 lookup ≤ 300 ms, p95 post ≤ 500 ms, zero 5xx, zero duplicate numbers, self-check green,
/// no sale blocked > 1 s while the backup runs.
///
///   dotnet run -c Release --project tests/MediCare.LoadTest -- --url https://PHARM-HOST:8443 --minutes 60 --rate 3
///   options: --counters 10  --medicines 2000  --admin rehan --password ...  --out results.md
/// </summary>
internal static class Program
{
    private static readonly JsonSerializerOptions Json = new(JsonSerializerDefaults.Web);
    private static readonly ConcurrentDictionary<string, ConcurrentBag<double>> Timings = new();
    private static readonly ConcurrentBag<string> SaleNos = new();
    private static readonly ConcurrentBag<string> Failures = new();
    private static int _server5xx, _stockChanged, _returns, _credit, _scans2d, _purchases, _reports;
    private static volatile bool _backupRunning;
    private static readonly ConcurrentBag<double> PostsDuringBackup = new();

    private static async Task<int> Main(string[] args)
    {
        var o = Options.Parse(args);
        Console.WriteLine($"MediCare load test → {o.Url}  counters={o.Counters} rate={o.Rate}/min each  minutes={o.Minutes}  medicines={o.Medicines}");
        var admin = new Client(o.Url);
        var status = await admin.Get<SetupStatus>("setup/status");
        Console.WriteLine($"Host {status.ServerVersion}, schema {status.SchemaVersion}, TLS fingerprint {(admin.Fingerprint.Length > 0 ? admin.Fingerprint : "(plain HTTP)")}");
        if (status.NeedsSetup)
            await admin.Post<OkResult>("setup", new SetupRequest { PharmacyName = "Load Test Pharmacy", AdminFullName = "Load Admin", AdminUsername = o.Admin, AdminPassword = o.Password, AdminPin = "2468" });
        await admin.Login(o.Admin, o.Password, 99);

        var world = await Seed(admin, o);
        var bots = new List<Client>();
        for (int i = 1; i <= o.Counters; i++)
        {
            var c = new Client(o.Url);
            await c.Login($"bot{i:00}", "Counter12345", i);
            await c.Post<ShiftDto>("shifts/open", new OpenShiftRequest { Float = 5000, CounterNo = i }).ContinueWith(_ => { });
            bots.Add(c);
        }

        if (o.Preload > 0) await Preload(bots, world, o.Preload);

        Console.WriteLine($"Running for {o.Minutes} min…");
        using var cts = new CancellationTokenSource(TimeSpan.FromMinutes(o.Minutes));
        var sw = Stopwatch.StartNew();
        var tasks = bots.Select((b, i) => CounterBot(b, i + 1, world, o, cts.Token)).ToList();
        tasks.Add(GoodsInBot(admin, world, o, cts.Token));
        tasks.Add(ReportBot(admin, o, cts.Token));
        tasks.Add(BackupMidway(admin, o, cts.Token));
        await Task.WhenAll(tasks);
        sw.Stop();

        Console.WriteLine("Verifying…");
        var check = await admin.Post<SelfCheckResult>("selfcheck", new { });
        var duplicates = SaleNos.Count - SaleNos.Distinct().Count();
        var report = Report(o, sw.Elapsed, check, duplicates, status);
        Console.WriteLine(report);
        if (o.Out.Length > 0) await File.WriteAllTextAsync(o.Out, report);
        var pass = P95("lookup") <= 300 && P95("post sale") <= 500 && _server5xx == 0 && duplicates == 0 && Failures.IsEmpty
                   && check.Items.Where(i => !i.Check.StartsWith("Verified backup")).All(i => i.Ok) && (PostsDuringBackup.IsEmpty || PostsDuringBackup.Max() <= 1000);
        Console.WriteLine(pass ? "RESULT: PASS" : "RESULT: FAIL");
        return pass ? 0 : 1;
    }

    // ---------------- data ----------------

    private sealed class World
    {
        public List<MedicineDto> Medicines = new();
        public Dictionary<long, List<(string batch, DateTime expiry)>> Batches = new();
        public List<CustomerDto> CreditCustomers = new();
        public long SupplierId;
    }

    private static string Ean(int i)
    {
        var body = "896" + (500000000 + i).ToString(CultureInfo.InvariantCulture);
        return body + Gtin.CheckDigit(body);
    }

    private static async Task<World> Seed(Client admin, Options o)
    {
        var w = new World();
        var sw = Stopwatch.StartNew();
        var sup = await admin.Get<List<SupplierDto>>("suppliers?q=Load%20Distributor");
        w.SupplierId = sup.FirstOrDefault()?.Id ?? (await admin.Post<OkResult>("suppliers", new SupplierDto { Name = "Load Distributor", TermsDays = 30 })).Id!.Value;
        // every load-test medicine has barcode Ean(i): look each one up (search results are capped), create the missing ones
        Console.WriteLine($"Checking / creating {o.Medicines} medicines…");
        var found = new MedicineDto[o.Medicines];
        var gate = new SemaphoreSlim(8);
        await Task.WhenAll(Enumerable.Range(0, o.Medicines).Select(async i =>
        {
            await gate.WaitAsync();
            try
            {
                try { found[i] = (await admin.Get<ScanResult>("medicines/scan?code=" + Ean(i))).Medicine; return; }
                catch (ApiError e) when (e.Code == ErrorCodes.NotFound) { }
                var id = (await admin.Post<OkResult>("medicines", new MedicineDto
                {
                    Name = $"LT-{i:00000} Medicine", Generic = $"Generic {i % 400}", Manufacturer = $"Maker {i % 40}", DosageForm = "Tablet", Strength = $"{(i % 9 + 1) * 50}mg", BaseUnit = "Tablet",
                    Packs = new() { new PackDto { Name = "Box", BaseQty = 100, IsSellable = true, SalePrice = 1000 + i % 500 }, new PackDto { Name = "Strip", BaseQty = 10, IsSellable = true, SalePrice = 100 + i % 50 }, new PackDto { Name = "Tablet", BaseQty = 1, IsSellable = true, SalePrice = 11 } },
                    DefaultSalePack = "Strip", PurchasePack = "Box", Barcodes = new() { new BarcodeDto { Code = Ean(i), PackName = "Strip" } }, TaxRatePercent = i % 10 == 0 ? 17 : 0, MinStockQty = 50,
                })).Id!.Value;
                found[i] = await admin.Get<MedicineDto>($"medicines/{id}");
            }
            finally { gate.Release(); }
        }));
        w.Medicines.AddRange(found);
        var stocked = found.Where(m => m.StockQty >= 2000).Select(m => m.Id).ToHashSet();
        // two batches per medicine, 30 boxes each (3,000 tablets), received in bills of 50 lines
        var toStock = w.Medicines.Where(m => !stocked.Contains(m.Id)).ToList();
        if (toStock.Count > 0) Console.WriteLine($"Receiving stock for {toStock.Count} medicines…");
        foreach (var chunk in toStock.Chunk(25))
        {
            var lines = chunk.SelectMany(m => new[] { ("A", 14), ("B", 26) }.Select(b => new PurchaseLineDto
            {
                MedicineId = m.Id, PackId = m.Packs.Single(p => p.Name == "Box").Id, BatchNo = $"{b.Item1}{m.Id:00000}", Expiry = Dates.EndOfMonth(DateTime.Today.Year + 1 + (b.Item1 == "B" ? 1 : 0), (int)(m.Id % 12) + 1),
                Qty = 30, Rate = 700, SalePrice = m.Packs.Single(p => p.Name == "Strip").SalePrice, CaptureMethod = "2D",
            })).ToList();
            var id = (await admin.Post<OkResult>("purchases", new PurchaseDto { SupplierId = w.SupplierId, SupplierInvNo = "LT-" + Guid.NewGuid().ToString("N")[..8], InvDate = DateTime.Today, Lines = lines })).Id!.Value;
            await admin.Post<PurchaseDto>($"purchases/{id}/post", new PostRequest { IdempotencyKey = Guid.NewGuid().ToString() });
        }
        foreach (var m in w.Medicines)
            w.Batches[m.Id] = new() { ($"A{m.Id:00000}", Dates.EndOfMonth(DateTime.Today.Year + 1, (int)(m.Id % 12) + 1)), ($"B{m.Id:00000}", Dates.EndOfMonth(DateTime.Today.Year + 2, (int)(m.Id % 12) + 1)) };

        var customers = (await admin.Get<List<CustomerDto>>("customers?q=Load%20Credit")).Where(c => c.Type == "CREDIT").ToList();
        for (int i = customers.Count; i < 20; i++)
            await admin.Post<OkResult>("customers", new CustomerDto { Name = $"Load Credit {i:00}", Mobile = $"0300{i:0000000}", Type = "CREDIT", CreditLimit = 10_000_000 });
        w.CreditCustomers = (await admin.Get<List<CustomerDto>>("customers?q=Load%20Credit")).Where(c => c.Type == "CREDIT").ToList();

        var roles = await admin.Get<List<RoleDto>>("roles");
        var cashierRole = roles.Single(r => r.Name == "Cashier").Id;
        var users = await admin.Get<List<UserDto>>("users");
        for (int i = 1; i <= o.Counters; i++)
        {
            if (users.Any(u => u.Username == $"bot{i:00}")) continue;
            await admin.Post<OkResult>("users", new UserSaveRequest { Username = $"bot{i:00}", FullName = $"Counter Bot {i:00}", RoleId = cashierRole, Password = "Temporary123", SessionTimeoutMin = 240 });
            var c = new Client(o.Url);
            await c.Login($"bot{i:00}", "Temporary123", i);
            await c.Post<OkResult>("auth/password", new ChangePasswordRequest { CurrentPassword = "Temporary123", NewPassword = "Counter12345" });
        }
        Console.WriteLine($"Seed ready in {sw.Elapsed.TotalSeconds:0} s: {w.Medicines.Count} medicines, {w.Medicines.Count * 2} batches.");
        return w;
    }

    /// <summary>Posts history before the measured window so tables are not empty (not timed).</summary>
    private static async Task Preload(List<Client> bots, World w, int count)
    {
        Console.WriteLine($"Preloading {count} invoices of history…");
        var sw = Stopwatch.StartNew();
        var done = 0;
        await Task.WhenAll(bots.Select(async (c, i) =>
        {
            var rnd = new Random(1000 + i);
            while (Interlocked.Increment(ref done) <= count)
            {
                var req = new SaleRequest { IdempotencyKey = Guid.NewGuid().ToString(), CounterNo = i + 1 };
                foreach (var m in Enumerable.Range(0, rnd.Next(1, 6)).Select(_ => w.Medicines[rnd.Next(w.Medicines.Count)]).Distinct())
                    req.Lines.Add(new SaleLineInput { MedicineId = m.Id, PackId = m.Packs.Single(p => p.Name == "Tablet").Id, Qty = rnd.Next(1, 3) });
                var q = await c.Post<SaleQuote>("sales/quote", req);
                req.Payments.Add(new PaymentInput { Method = PaymentMethods.Cash, Amount = q.Net });
                try { await c.Post<SaleResult>("sales", req); } catch (ApiError e) when (e.Code == ErrorCodes.StockChanged) { }
                if (done % 5000 == 0) Console.WriteLine($"  {done} invoices, {sw.Elapsed.TotalSeconds:0} s");
            }
        }));
        Console.WriteLine($"Preloaded in {sw.Elapsed.TotalMinutes:0.0} min ({count / sw.Elapsed.TotalSeconds:0} invoices/s).");
    }

    // ---------------- actors ----------------

    private static async Task CounterBot(Client c, int counter, World w, Options o, CancellationToken ct)
    {
        var rnd = new Random(counter * 7919);
        var interval = TimeSpan.FromMinutes(1.0 / o.Rate);
        var mySales = new List<SaleResult>();
        await Task.Delay(TimeSpan.FromMilliseconds(rnd.Next(0, (int)interval.TotalMilliseconds)), ct).ContinueWith(_ => { });
        while (!ct.IsCancellationRequested)
        {
            var started = DateTime.UtcNow;
            try
            {
                if (mySales.Count > 0 && rnd.NextDouble() < 0.05) await Return(c, mySales[rnd.Next(mySales.Count)]);
                else
                {
                    var sale = await Sell(c, counter, w, rnd);
                    if (sale != null) mySales.Add(sale);
                }
            }
            catch (OperationCanceledException) { break; }
            catch (Exception ex) { Failures.Add($"counter {counter}: {ex.Message}"); }
            var wait = interval - (DateTime.UtcNow - started);
            if (wait > TimeSpan.Zero) await Task.Delay(wait, ct).ContinueWith(_ => { });
        }
    }

    private static async Task<SaleResult?> Sell(Client c, int counter, World w, Random rnd)
    {
        var req = new SaleRequest { IdempotencyKey = Guid.NewGuid().ToString(), CounterNo = counter };
        var lines = rnd.Next(1, 9);
        var used = new HashSet<long>();
        for (int i = 0; i < lines; i++)
        {
            var m = w.Medicines[rnd.Next(w.Medicines.Count)];
            if (!used.Add(m.Id)) continue;
            long? batch = null;
            if (rnd.NextDouble() < 0.30)
            {
                var b = w.Batches[m.Id][rnd.Next(2)];
                var code = Gs1Parser.Build("0" + m.Barcodes[0].Code, b.expiry, b.batch);
                var scan = await Timed("lookup", () => c.Get<ScanResult>("medicines/scan?code=" + Uri.EscapeDataString(code)));
                batch = scan.ScannedBatchId;
                Interlocked.Increment(ref _scans2d);
            }
            else
            {
                var hits = await Timed("lookup", () => c.Get<List<MedicineListItem>>("medicines?limit=30&q=" + Uri.EscapeDataString(m.Name.Substring(0, 8))));
                if (hits.Count == 0) Failures.Add("search returned nothing for " + m.Name);
                await Timed("lookup", () => c.Get<MedicineDto>($"medicines/{m.Id}"));
            }
            var packName = rnd.NextDouble() < 0.2 ? "Tablet" : "Strip";
            req.Lines.Add(new SaleLineInput { MedicineId = m.Id, PackId = m.Packs.Single(p => p.Name == packName).Id, Qty = rnd.Next(1, 4), BatchId = batch, Pick = batch.HasValue ? "2D" : "FEFO" });
            await Timed("quote", () => c.Post<SaleQuote>("sales/quote", req));
        }
        var quote = await Timed("quote", () => c.Post<SaleQuote>("sales/quote", req));
        if (rnd.NextDouble() < 0.10)
        {
            req.CustomerId = w.CreditCustomers[rnd.Next(w.CreditCustomers.Count)].Id;
            req.Payments.Add(new PaymentInput { Method = PaymentMethods.Credit, Amount = quote.Net });
            Interlocked.Increment(ref _credit);
        }
        else req.Payments.Add(new PaymentInput { Method = PaymentMethods.Cash, Amount = quote.Net, Tendered = Math.Ceiling(quote.Net / 100) * 100 });
        try
        {
            var t0 = Stopwatch.StartNew();
            var r = await Timed("post sale", () => c.Post<SaleResult>("sales", req));
            if (_backupRunning) PostsDuringBackup.Add(t0.Elapsed.TotalMilliseconds);
            SaleNos.Add(r.No);
            // a lost answer is retried with the same key: must return the same invoice
            if (SaleNos.Count % 50 == 0)
            {
                var again = await c.Post<SaleResult>("sales", req);
                if (again.No != r.No) Failures.Add($"idempotent retry returned {again.No} instead of {r.No}");
            }
            return r;
        }
        catch (ApiError e) when (e.Code == ErrorCodes.StockChanged)
        {
            Interlocked.Increment(ref _stockChanged);
            return null;
        }
    }

    private static async Task Return(Client c, SaleResult s)
    {
        var detail = await Timed("lookup", () => c.Get<SaleDetail>($"sales/{s.Id}"));
        var line = detail.Lines.FirstOrDefault(l => l.BaseQty - l.ReturnedBaseQty > 0);
        if (line == null) return;
        var req = new SalesReturnRequest
        {
            IdempotencyKey = Guid.NewGuid().ToString(), SaleId = s.Id, RefundMethod = detail.Outstanding > 0 ? "CREDIT_NOTE" : "CASH",
            Lines = new() { new SalesReturnLineInput { SaleItemId = line.Id, BaseQty = 1, Outcome = "RESTOCK", Reason = "Load test" } },
        };
        await Timed("post return", () => c.Post<SalesReturnResult>("sales-returns", req));
        Interlocked.Increment(ref _returns);
    }

    private static async Task GoodsInBot(Client admin, World w, Options o, CancellationToken ct)
    {
        var rnd = new Random(42);
        var every = TimeSpan.FromMinutes(Math.Max(0.25, 5.0 * 3 / o.Rate));
        while (!ct.IsCancellationRequested)
        {
            await Task.Delay(every, ct).ContinueWith(_ => { });
            if (ct.IsCancellationRequested) break;
            try
            {
                var lines = w.Medicines.OrderBy(_ => rnd.Next()).Take(30).Select(m => new PurchaseLineDto
                {
                    MedicineId = m.Id, PackId = m.Packs.Single(p => p.Name == "Box").Id, BatchNo = w.Batches[m.Id][1].batch, Expiry = w.Batches[m.Id][1].expiry, Qty = 5, Rate = 700, CaptureMethod = "2D",
                }).ToList();
                var id = (await Timed("save purchase", () => admin.Post<OkResult>("purchases", new PurchaseDto { SupplierId = w.SupplierId, SupplierInvNo = "LT-" + Guid.NewGuid().ToString("N")[..8], InvDate = DateTime.Today, Lines = lines }))).Id!.Value;
                await Timed("post purchase", () => admin.Post<PurchaseDto>($"purchases/{id}/post", new PostRequest { IdempotencyKey = Guid.NewGuid().ToString() }));
                Interlocked.Increment(ref _purchases);
            }
            catch (Exception ex) { Failures.Add("goods-in: " + ex.Message); }
        }
    }

    private static async Task ReportBot(Client admin, Options o, CancellationToken ct)
    {
        var today = DateTime.Today.ToString("yyyy-MM-dd", CultureInfo.InvariantCulture);
        var monthStart = new DateTime(DateTime.Today.Year, DateTime.Today.Month, 1).ToString("yyyy-MM-dd", CultureInfo.InvariantCulture);
        var n = 0;
        while (!ct.IsCancellationRequested)
        {
            await Task.Delay(TimeSpan.FromMinutes(Math.Max(0.2, 2.0 * 3 / o.Rate)), ct).ContinueWith(_ => { });
            if (ct.IsCancellationRequested) break;
            try
            {
                await Timed("report", () => admin.Get<ReportResult>($"reports/daily_sales?from={today}&to={today}"));
                if (++n % 5 == 0) await Timed("report", () => admin.Get<ReportResult>($"reports/profit?from={monthStart}&to={today}"));
                await Timed("report", () => admin.Get<DashboardDto>("dashboard"));
                Interlocked.Increment(ref _reports);
            }
            catch (Exception ex) { Failures.Add("reports: " + ex.Message); }
        }
    }

    private static async Task BackupMidway(Client admin, Options o, CancellationToken ct)
    {
        await Task.Delay(TimeSpan.FromMinutes(o.Minutes / 2.0), ct).ContinueWith(_ => { });
        if (ct.IsCancellationRequested) return;
        _backupRunning = true;
        try
        {
            var b = await Timed("backup", () => admin.Post<BackupDto>("backups", new { }));
            if (b.Result != "OK") Failures.Add("backup: " + b.Message);
        }
        catch (Exception ex) { Failures.Add("backup: " + ex.Message); }
        finally { _backupRunning = false; }
    }

    // ---------------- measurement ----------------

    private static async Task<T> Timed<T>(string what, Func<Task<T>> call)
    {
        var sw = Stopwatch.StartNew();
        try { return await call(); }
        finally { Timings.GetOrAdd(what, _ => new ConcurrentBag<double>()).Add(sw.Elapsed.TotalMilliseconds); }
    }

    private static double Percentile(string what, double p)
    {
        if (!Timings.TryGetValue(what, out var bag) || bag.IsEmpty) return 0;
        var list = bag.OrderBy(x => x).ToList();
        return list[Math.Min(list.Count - 1, (int)Math.Ceiling(p * list.Count) - 1)];
    }

    private static double P95(string what) => Percentile(what, 0.95);

    private static string Report(Options o, TimeSpan elapsed, SelfCheckResult check, int duplicates, SetupStatus status)
    {
        var sb = new StringBuilder();
        sb.AppendLine($"### Load test {DateTime.Now:yyyy-MM-dd HH:mm} ({Environment.MachineName}, host {status.ServerVersion})");
        sb.AppendLine();
        sb.AppendLine($"{o.Counters} counters × {o.Rate} sales/min for {elapsed.TotalMinutes:0.0} min against `{o.Url}`; {o.Medicines} medicines / {o.Medicines * 2} batches.");
        sb.AppendLine();
        sb.AppendLine("| Operation | Count | p50 ms | p95 ms | p99 ms | max ms |");
        sb.AppendLine("|---|---|---|---|---|---|");
        foreach (var k in Timings.Keys.OrderBy(k => k))
            sb.AppendLine($"| {k} | {Timings[k].Count} | {Percentile(k, 0.50):0} | {Percentile(k, 0.95):0} | {Percentile(k, 0.99):0} | {Timings[k].Max():0} |");
        sb.AppendLine();
        sb.AppendLine($"- Sales posted: {SaleNos.Count} (credit {_credit}, 2D scans {_scans2d}), returns {_returns}, purchases {_purchases}, report rounds {_reports}.");
        sb.AppendLine($"- Duplicate invoice numbers: {duplicates}. Server errors (5xx): {_server5xx}. Out-of-stock refusals: {_stockChanged}. Client-side failures: {Failures.Count}.");
        sb.AppendLine($"- Sales posted while the backup ran: {PostsDuringBackup.Count}, slowest {(PostsDuringBackup.IsEmpty ? 0 : PostsDuringBackup.Max()):0} ms.");
        sb.AppendLine($"- Self-check: {(check.Ok ? "all green" : "problems")}: " + string.Join("; ", check.Items.Select(i => $"{i.Check} {(i.Ok ? "OK" : "FAIL " + i.Detail)}")));
        foreach (var f in Failures.Distinct().Take(10)) sb.AppendLine($"  - failure: {f}");
        sb.AppendLine($"- Targets: p95 lookup {P95("lookup"):0}/300 ms, p95 post {P95("post sale"):0}/500 ms.");
        return sb.ToString();
    }

    // ---------------- HTTP ----------------

    private sealed class ApiError(int status, string code, string message) : Exception($"{status} {code}: {message}")
    {
        public string Code { get; } = code;
    }

    private sealed class Client
    {
        private static string _pinned = "";
        private readonly HttpClient _http;
        public string Fingerprint => _pinned;

        public Client(string url)
        {
            var handler = new SocketsHttpHandler { MaxConnectionsPerServer = 4, PooledConnectionLifetime = TimeSpan.FromMinutes(10) };
            handler.SslOptions.RemoteCertificateValidationCallback = (_, cert, _, _) =>
            {
                if (cert == null) return false;
                var fp = string.Join(":", SHA256.HashData(cert.GetRawCertData()).Select(b => b.ToString("X2")));
                lock (typeof(Client)) { if (_pinned.Length == 0) _pinned = fp; } // trust on first use, like pairing
                return fp == _pinned;
            };
            _http = new HttpClient(handler) { BaseAddress = new Uri(url.TrimEnd('/') + "/api/v1/"), Timeout = TimeSpan.FromMinutes(5) };
        }

        public async Task Login(string user, string password, int counter)
        {
            var r = await Post<LoginResponse>("auth/login", new LoginRequest { Username = user, Password = password, MachineId = "LOADTEST-" + user, WorkstationName = $"LOAD-{counter:00}", Os = "load test", ClientVersion = "loadtest", CounterNo = counter });
            _http.DefaultRequestHeaders.Authorization = new AuthenticationHeaderValue("Bearer", r.Token);
        }

        public Task<T> Get<T>(string path) => Send<T>(new HttpRequestMessage(HttpMethod.Get, path));

        public Task<T> Post<T>(string path, object body) =>
            Send<T>(new HttpRequestMessage(HttpMethod.Post, path) { Content = new StringContent(JsonSerializer.Serialize(body, body.GetType(), Json), Encoding.UTF8, "application/json") });

        private async Task<T> Send<T>(HttpRequestMessage req)
        {
            using (req)
            using (var r = await _http.SendAsync(req))
            {
                var text = await r.Content.ReadAsStringAsync();
                if ((int)r.StatusCode >= 500) Interlocked.Increment(ref _server5xx);
                if (!r.IsSuccessStatusCode)
                {
                    var e = JsonSerializer.Deserialize<MediCare.Contracts.ApiError>(text, Json);
                    throw new ApiError((int)r.StatusCode, e?.Code ?? "?", e?.Message ?? text);
                }
                return JsonSerializer.Deserialize<T>(text, Json)!;
            }
        }
    }

    private sealed class Options
    {
        public string Url = "https://localhost:8443";
        public int Counters = 10;
        public double Rate = 3;
        public double Minutes = 60;
        public int Medicines = 2000;
        public string Admin = "loadadmin";
        public string Password = "LoadAdmin12345";
        public string Out = "";
        public int Preload;

        public static Options Parse(string[] a)
        {
            var o = new Options();
            for (int i = 0; i + 1 < a.Length; i += 2)
            {
                var v = a[i + 1];
                switch (a[i])
                {
                    case "--url": o.Url = v; break;
                    case "--counters": o.Counters = int.Parse(v, CultureInfo.InvariantCulture); break;
                    case "--rate": o.Rate = double.Parse(v, CultureInfo.InvariantCulture); break;
                    case "--minutes": o.Minutes = double.Parse(v, CultureInfo.InvariantCulture); break;
                    case "--medicines": o.Medicines = int.Parse(v, CultureInfo.InvariantCulture); break;
                    case "--admin": o.Admin = v; break;
                    case "--password": o.Password = v; break;
                    case "--out": o.Out = v; break;
                    case "--preload": o.Preload = int.Parse(v, CultureInfo.InvariantCulture); break;
                    default: throw new ArgumentException("Unknown option " + a[i]);
                }
            }
            return o;
        }
    }
}
