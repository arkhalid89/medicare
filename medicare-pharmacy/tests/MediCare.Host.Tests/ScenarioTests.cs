using System.Net;
using MediCare.Contracts;
using MediCare.Core;
using MediCare.Host.Infrastructure;
using Microsoft.Data.Sqlite;
using Xunit;

namespace MediCare.Host.Tests;

public class ScenarioTests
{
    // ---------- helpers ----------
    private static async Task<long> CreateSupplier(Api api, string name = "Muller & Phipps Pakistan") =>
        (await api.Post<OkResult>("/api/v1/suppliers", new SupplierDto { Name = name, Mobile = "0300-1234567", TermsDays = 30 })).Id!.Value;

    private static async Task<MedicineDto> CreateRisek(Api api, decimal stripPrice = 420m, string barcode = "8964000202029", string? schedule = null)
    {
        var id = (await api.Post<OkResult>("/api/v1/medicines", new MedicineDto
        {
            Name = "Risek 20 mg", Generic = "Omeprazole", Manufacturer = "Getz Pharma", DosageForm = "Capsule", Strength = "20 mg", BaseUnit = "Capsule", MinStockQty = 280,
            Schedule = schedule ?? "", UrduName = "رائزیک ۲۰",
            Packs = new() { new PackDto { Name = "Box", BaseQty = 70, IsSellable = true, SalePrice = stripPrice * 5 }, new PackDto { Name = "Strip", BaseQty = 14, IsSellable = true, SalePrice = stripPrice }, new PackDto { Name = "Capsule", BaseQty = 1, IsSellable = true, SalePrice = 30 } },
            DefaultSalePack = "Strip", PurchasePack = "Box", Barcodes = new() { new BarcodeDto { Code = barcode, PackName = "Strip" } },
        })).Id!.Value;
        return await api.Get<MedicineDto>($"/api/v1/medicines/{id}");
    }

    private static long Pack(MedicineDto m, string name) => m.Packs.Single(p => p.Name == name).Id;

    private static async Task<PurchaseDto> Receive(Api api, long supplierId, MedicineDto m, int boxes, decimal rate, int free = 0, decimal disc = 0, decimal charges = 0,
        string batch = "RK0925", DateTime? expiry = null, string supplierInv = "")
    {
        var id = (await api.Post<OkResult>("/api/v1/purchases", new PurchaseDto
        {
            SupplierId = supplierId, SupplierInvNo = supplierInv.Length > 0 ? supplierInv : "INV-" + Guid.NewGuid().ToString("N")[..6], InvDate = Clock.Today, Charges = charges,
            Lines = new() { new PurchaseLineDto { MedicineId = m.Id, PackId = Pack(m, "Box"), BatchNo = batch, Expiry = expiry ?? new DateTime(2027, 8, 31), Qty = boxes, FreeQty = free, Rate = rate, DiscountPercent = disc, CaptureMethod = "2D" } },
        })).Id!.Value;
        return await api.Post<PurchaseDto>($"/api/v1/purchases/{id}/post", new PostRequest { IdempotencyKey = Guid.NewGuid().ToString() });
    }

    private static SaleRequest CashSale(MedicineDto m, string pack, int qty, decimal amount, decimal tendered = 0, decimal invoiceDiscount = 0) => new()
    {
        IdempotencyKey = Guid.NewGuid().ToString(), CounterNo = 1, InvoiceDiscount = invoiceDiscount,
        Lines = new() { new SaleLineInput { MedicineId = m.Id, PackId = Pack(m, pack), Qty = qty } },
        Payments = new() { new PaymentInput { Method = PaymentMethods.Cash, Amount = amount, Tendered = tendered == 0 ? amount : tendered } },
    };

    private static T Sql<T>(TestHost host, string sql) { using var c = new SqliteConnection($"Data Source={Path.Combine(host.DataDir, "medicare.db")}"); c.Open(); using var cmd = c.CreateCommand(); cmd.CommandText = sql; return (T)Convert.ChangeType(cmd.ExecuteScalar()!, typeof(T)); }

    // ---------- tests ----------
    [Fact]
    public async Task Setup_Login_Permissions_And_PasswordChange()
    {
        using var host = new TestHost();
        var anon = host.Anonymous();
        Assert.True((await anon.Get<SetupStatus>("/api/v1/setup/status")).NeedsSetup);
        await anon.Fails(() => anon.Post<LoginResponse>("/api/v1/auth/login", new LoginRequest { Username = "x", Password = "y" }), ErrorCodes.SetupRequired);
        var admin = await host.SetupAndLoginAdmin();
        Assert.Contains(Perm.UsersManage, admin.Login!.Permissions);
        await anon.Fails(() => anon.Post<OkResult>("/api/v1/setup", new SetupRequest { PharmacyName = "x", AdminFullName = "x", AdminUsername = "xyz", AdminPassword = "Abcdef12345", AdminPin = "1234" }), ErrorCodes.Conflict);
        await anon.Fails(() => anon.Get<List<UserDto>>("/api/v1/users"), ErrorCodes.Unauthorized);

        var roles = await admin.Get<List<RoleDto>>("/api/v1/roles");
        var cashierRole = roles.Single(r => r.Name == "Cashier");
        await admin.Post<OkResult>("/api/v1/users", new UserSaveRequest { Username = "asad", FullName = "Asad Raza", RoleId = cashierRole.Id, Password = "Cashier123", SessionTimeoutMin = 5 });
        var cashier = await host.Login("asad", "Cashier123", 3);
        Assert.True(cashier.Login!.MustChangePassword);
        await cashier.Fails(() => cashier.Get<List<MedicineListItem>>("/api/v1/medicines"), ErrorCodes.MustChangePassword);
        await cashier.Post<OkResult>("/api/v1/auth/password", new ChangePasswordRequest { CurrentPassword = "Cashier123", NewPassword = "Cashier456" });
        await cashier.Get<List<MedicineListItem>>("/api/v1/medicines");
        await cashier.Fails(() => cashier.Get<List<UserDto>>("/api/v1/users"), ErrorCodes.PermissionDenied);

        // lockout after 5 wrong attempts
        for (int i = 0; i < 4; i++) await anon.Fails(() => anon.Post<LoginResponse>("/api/v1/auth/login", new LoginRequest { Username = "asad", Password = "wrong" }), ErrorCodes.BadCredentials);
        await anon.Fails(() => anon.Post<LoginResponse>("/api/v1/auth/login", new LoginRequest { Username = "asad", Password = "wrong" }), ErrorCodes.AccountLocked);
        await anon.Fails(() => anon.Post<LoginResponse>("/api/v1/auth/login", new LoginRequest { Username = "asad", Password = "Cashier456" }), ErrorCodes.AccountLocked);

        // logout revokes
        await admin.Post<OkResult>("/api/v1/auth/logout", new { });
        await admin.Fails(() => admin.Get<List<UserDto>>("/api/v1/users"), ErrorCodes.Unauthorized);
    }

    [Fact]
    public async Task Purchase_Sale_Return_CostingAndBooks_MatchWorkedExample()
    {
        using var host = new TestHost();
        var api = await host.SetupAndLoginAdmin();
        var sup = await CreateSupplier(api);
        var risek = await CreateRisek(api);

        var p1 = await Receive(api, sup, risek, boxes: 20, rate: 1754m, free: 2, disc: 2m, charges: 350m);
        Assert.Equal("POSTED", p1.Status);
        Assert.Equal(34728.40m, p1.Net);
        Assert.Equal(22.5509m, p1.Lines[0].UnitCost);
        var med = await api.Get<MedicineDto>($"/api/v1/medicines/{risek.Id}");
        Assert.Equal(1540, med.StockQty);
        Assert.Equal(22.5509m, med.AvgCost);
        Assert.Equal(34728.40m, (await api.Get<SupplierDto>($"/api/v1/suppliers/{sup}")).Balance);

        // scan by EAN and by GS1 2D
        var scan = await api.Get<ScanResult>("/api/v1/medicines/scan?code=8964000202029");
        Assert.Equal(risek.Id, scan.Medicine.Id);
        var gs = Gs1Parser.Build("08964000202029", new DateTime(2027, 8, 31), "RK0925", "S1");
        var scan2 = await api.Get<ScanResult>("/api/v1/medicines/scan?code=" + Uri.EscapeDataString(gs));
        Assert.Equal("RK0925", scan2.Gs1!.Batch);
        Assert.NotNull(scan2.ScannedBatchId);

        var sale1 = await api.Post<SaleResult>("/api/v1/sales", CashSale(risek, "Strip", 1, 420m, tendered: 500m));
        Assert.Equal(420m, sale1.Net);
        Assert.Equal(80m, sale1.Change);
        Assert.Equal(315.71m, Sql<long>(host, $"SELECT cogs_paisa FROM sales_headers WHERE id={sale1.Id}") / 100m);

        await Receive(api, sup, risek, boxes: 10, rate: 1800m);
        Assert.Equal(23.5457m, (await api.Get<MedicineDto>($"/api/v1/medicines/{risek.Id}")).AvgCost);

        var sale2 = await api.Post<SaleResult>("/api/v1/sales", CashSale(risek, "Strip", 2, 840m));
        Assert.Equal(659.28m, Sql<long>(host, $"SELECT cogs_paisa FROM sales_headers WHERE id={sale2.Id}") / 100m);
        Assert.Equal(1540 - 14 + 700 - 28, (await api.Get<MedicineDto>($"/api/v1/medicines/{risek.Id}")).StockQty);

        var detail = await api.Get<SaleDetail>($"/api/v1/sales/{sale2.Id}");
        var ret = await api.Post<SalesReturnResult>("/api/v1/sales-returns", new SalesReturnRequest
        {
            IdempotencyKey = Guid.NewGuid().ToString(), SaleId = sale2.Id, RefundMethod = "CASH",
            Lines = detail.Lines.Select(l => new SalesReturnLineInput { SaleItemId = l.Id, BaseQty = l.BaseQty, Outcome = "RESTOCK", Reason = "Doctor changed medicine" }).ToList(),
        });
        Assert.Equal(840m, ret.Refund);
        Assert.Equal(1540 - 14 + 700, (await api.Get<MedicineDto>($"/api/v1/medicines/{risek.Id}")).StockQty);
        Assert.Equal(659.28m, Sql<long>(host, $"SELECT SUM(cost_paisa) FROM sales_return_items WHERE return_id={ret.Id}") / 100m);
        // over-return refused
        await api.Fails(() => api.Post<SalesReturnResult>("/api/v1/sales-returns", new SalesReturnRequest
        {
            IdempotencyKey = Guid.NewGuid().ToString(), SaleId = sale2.Id, RefundMethod = "CASH",
            Lines = detail.Lines.Select(l => new SalesReturnLineInput { SaleItemId = l.Id, BaseQty = 1, Outcome = "RESTOCK" }).ToList(),
        }), ErrorCodes.Validation);

        // profit report: net sales 420 (sale1) + 840 - 840 return; COGS 315.71
        var profit = await api.Get<ReportResult>("/api/v1/reports/profit");
        string Line(string name) => profit.Rows.Single(r => r[0] == name)[1];
        Assert.Equal("420.00", Line("Net sales"));
        Assert.Equal("-315.71", Line("Cost of goods sold (moving average)"));
        Assert.Equal("104.29", Line("Gross profit"));

        // books balance and stock equals movements
        Assert.Equal(0L, Sql<long>(host, "SELECT COUNT(*) FROM (SELECT entry_id FROM journal_lines GROUP BY entry_id HAVING SUM(debit_paisa) <> SUM(credit_paisa))"));
        var check = await api.Post<SelfCheckResult>("/api/v1/selfcheck", new { });
        Assert.All(check.Items.Where(i => !i.Check.Contains("backup")), i => Assert.True(i.Ok, i.Check + ": " + i.Detail));
        await api.Post<BackupDto>("/api/v1/backups", new { });
        Assert.True((await api.Post<SelfCheckResult>("/api/v1/selfcheck", new { })).Ok);

        // posted rows are immutable at the database level
        Assert.ThrowsAny<SqliteException>(() => Sql<long>(host, $"UPDATE sales_headers SET net_paisa = 1 WHERE id={sale1.Id}"));
        Assert.ThrowsAny<SqliteException>(() => Sql<long>(host, "DELETE FROM stock_movements"));
        Assert.ThrowsAny<SqliteException>(() => Sql<long>(host, "DELETE FROM audit_logs"));
    }

    [Fact]
    public async Task Idempotency_And_Concurrency_NoDuplicatesNoNegativeStock()
    {
        using var host = new TestHost();
        var api = await host.SetupAndLoginAdmin();
        var sup = await CreateSupplier(api);
        var risek = await CreateRisek(api);
        await Receive(api, sup, risek, boxes: 2, rate: 1754m); // 140 caps = 10 strips

        var same = CashSale(risek, "Strip", 1, 420m);
        var results = await Task.WhenAll(Enumerable.Range(0, 20).Select(_ => api.Post<SaleResult>("/api/v1/sales", same)));
        Assert.Single(results.Select(r => r.No).Distinct());
        Assert.Equal(1L, Sql<long>(host, "SELECT COUNT(*) FROM sales_headers"));

        // 15 different sales for 9 remaining strips: exactly 9 succeed, never negative
        var tasks = Enumerable.Range(0, 15).Select(async _ =>
        {
            try { return (await api.Post<SaleResult>("/api/v1/sales", CashSale(risek, "Strip", 1, 420m))).No; }
            catch (ApiException e) when (e.Error.Code == ErrorCodes.StockChanged) { return null; }
        });
        var nos = (await Task.WhenAll(tasks)).Where(n => n != null).ToList();
        Assert.Equal(9, nos.Count);
        Assert.Equal(nos.Count, nos.Distinct().Count());
        Assert.Equal(0, (await api.Get<MedicineDto>($"/api/v1/medicines/{risek.Id}")).StockQty);
        Assert.Equal(0L, Sql<long>(host, "SELECT COUNT(*) FROM stock_balances WHERE qty < 0"));
        // same key, different payload is refused
        same.Lines[0].Qty = 2;
        await api.Fails(() => api.Post<SaleResult>("/api/v1/sales", same), ErrorCodes.IdempotencyMismatch);
    }

    [Fact]
    public async Task Fefo_SplitsBatches_AndExpiredStockCannotBeSold()
    {
        using var host = new TestHost();
        var api = await host.SetupAndLoginAdmin();
        var sup = await CreateSupplier(api);
        var risek = await CreateRisek(api);
        var today = Clock.Today;
        await Receive(api, sup, risek, boxes: 1, rate: 1754m, batch: "LATE", expiry: today.AddDays(300));
        await Receive(api, sup, risek, boxes: 1, rate: 1754m, batch: "EARLY", expiry: today.AddDays(20));
        var quote = await api.Post<SaleQuote>("/api/v1/sales/quote", CashSale(risek, "Box", 1, 2100m));
        Assert.Equal(2100m, quote.Net);
        Assert.StartsWith("EARLY", quote.Lines.Single().Batches);
        var sale = await api.Post<SaleResult>("/api/v1/sales", CashSale(risek, "Box", 1, 2100m));
        Assert.Equal(quote.Net, sale.Net);
        var d = await api.Get<SaleDetail>($"/api/v1/sales/{sale.Id}");
        Assert.Equal("EARLY", d.Lines.Single().BatchNo);
        var sale2 = await api.Post<SaleResult>("/api/v1/sales", CashSale(risek, "Strip", 1, 420m));
        Assert.Equal("LATE", (await api.Get<SaleDetail>($"/api/v1/sales/{sale2.Id}")).Lines.Single().BatchNo);

        // move the clock past LATE's expiry: nothing is saleable
        Clock.UtcNowFn = () => DateTime.UtcNow.AddDays(301);
        await api.Fails(() => api.Get<List<BatchRow>>("/api/v1/batches"), ErrorCodes.SessionExpired);
        api = await host.Login("rehan", "Admin12345", counter: 1);
        await api.Fails(() => api.Post<SaleResult>("/api/v1/sales", CashSale(risek, "Strip", 1, 420m)), ErrorCodes.StockChanged);
        var expired = await api.Get<List<BatchRow>>("/api/v1/batches?view=EXPIRED");
        Assert.Single(expired);
        // forced batch that is expired gives the explicit error
        var req = CashSale(risek, "Strip", 1, 420m);
        req.Lines[0].BatchId = expired[0].BatchId; req.Lines[0].Pick = "2D";
        await api.Fails(() => api.Post<SaleResult>("/api/v1/sales", req), ErrorCodes.BatchNotSaleable);
    }

    [Fact]
    public async Task Discount_Needs_Manager_Approval_And_Credit_Limit_Then_Receipt()
    {
        using var host = new TestHost();
        var admin = await host.SetupAndLoginAdmin();
        var roles = await admin.Get<List<RoleDto>>("/api/v1/roles");
        await admin.Post<OkResult>("/api/v1/users", new UserSaveRequest { Username = "asad", FullName = "Asad Raza", RoleId = roles.Single(r => r.Name == "Cashier").Id, Password = "Cashier123" });
        await admin.Post<OkResult>("/api/v1/users", new UserSaveRequest { Username = "sana", FullName = "Sana Iqbal", RoleId = roles.Single(r => r.Name.StartsWith("Pharmacist")).Id, Password = "Manager123", Pin = "4321" });
        var sup = await CreateSupplier(admin);
        var risek = await CreateRisek(admin);
        await Receive(admin, sup, risek, boxes: 5, rate: 1754m);
        var cashier = await host.Login("asad", "Cashier123", 3);
        await cashier.Post<OkResult>("/api/v1/auth/password", new ChangePasswordRequest { CurrentPassword = "Cashier123", NewPassword = "Cashier456" });

        // a manager cannot approve beyond their own 10% limit
        var big = CashSale(risek, "Strip", 1, 420m - 50.40m, invoiceDiscount: 50.40m);
        await cashier.Fails(() => cashier.Post<ApprovalTokenResponse>("/api/v1/approvals/inline", new InlineApprovalRequest { Action = ApprovalActions.Discount, ContextKey = big.IdempotencyKey, Value = 12, ApproverUsername = "sana", Pin = "4321", Reason = "Senior citizen" }), ErrorCodes.PermissionDenied);
        // 8% discount on 420 is above the cashier's 5% → needs approval
        var sale = CashSale(risek, "Strip", 1, 420m - 33.60m, invoiceDiscount: 33.60m);
        var err = await cashier.Fails(() => cashier.Post<SaleResult>("/api/v1/sales", sale), ErrorCodes.ApprovalRequired);
        Assert.Equal(ApprovalActions.Discount, err.Details!["action"]);
        await cashier.Fails(() => cashier.Post<ApprovalTokenResponse>("/api/v1/approvals/inline", new InlineApprovalRequest { Action = ApprovalActions.Discount, ContextKey = sale.IdempotencyKey, Value = 8, ApproverUsername = "sana", Pin = "0000", Reason = "Senior citizen" }), ErrorCodes.BadCredentials);
        var tok = await cashier.Post<ApprovalTokenResponse>("/api/v1/approvals/inline", new InlineApprovalRequest { Action = ApprovalActions.Discount, ContextKey = sale.IdempotencyKey, Value = 8, ApproverUsername = "sana", Pin = "4321", Reason = "Senior citizen" });
        sale.ApprovalTokens.Add(tok.Token);
        var posted = await cashier.Post<SaleResult>("/api/v1/sales", sale);
        Assert.Equal(386.40m, posted.Net);
        // token is single-use
        var sale2 = CashSale(risek, "Strip", 1, 386.40m, invoiceDiscount: 33.60m);
        sale2.ApprovalTokens.Add(tok.Token);
        await cashier.Fails(() => cashier.Post<SaleResult>("/api/v1/sales", sale2), ErrorCodes.ApprovalInvalid);

        // credit sale: limit 500, sale of 840 → needs CREDIT_LIMIT approval; manager posts directly
        var cust = (await cashier.Post<OkResult>("/api/v1/customers", new CustomerDto { Name = "Hafiz Clinic", Mobile = "0301-4455667", Type = "CREDIT", CreditLimit = 500 })).Id!.Value;
        var credit = new SaleRequest { IdempotencyKey = Guid.NewGuid().ToString(), CounterNo = 3, CustomerId = cust, Lines = { new SaleLineInput { MedicineId = risek.Id, PackId = Pack(risek, "Strip"), Qty = 2 } }, Payments = { new PaymentInput { Method = PaymentMethods.Credit, Amount = 840 } } };
        await cashier.Fails(() => cashier.Post<SaleResult>("/api/v1/sales", credit), ErrorCodes.ApprovalRequired);
        var manager = await host.Login("sana", "Manager123", 1);
        await manager.Post<OkResult>("/api/v1/auth/password", new ChangePasswordRequest { CurrentPassword = "Manager123", NewPassword = "Manager456" });
        var cs = await manager.Post<SaleResult>("/api/v1/sales", credit);
        Assert.Equal(840m, (await admin.Get<CustomerDto>($"/api/v1/customers/{cust}")).Balance);
        // walk-in credit refused
        var walk = CashSale(risek, "Strip", 1, 420); walk.Payments = new() { new PaymentInput { Method = PaymentMethods.Credit, Amount = 420 } };
        await cashier.Fails(() => cashier.Post<SaleResult>("/api/v1/sales", walk), ErrorCodes.Validation);
        // receipt with allocation
        var outstanding = await admin.Get<List<OutstandingDoc>>($"/api/v1/customers/{cust}/outstanding");
        Assert.Equal(840m, outstanding.Single().Outstanding);
        await admin.Fails(() => admin.Post<VoucherResult>("/api/v1/receipts", new ReceiptRequest { IdempotencyKey = Guid.NewGuid().ToString(), CustomerId = cust, Amount = 500, Allocations = { new AllocationInput { DocId = cs.Id, Amount = 600 } } }), ErrorCodes.Validation);
        await admin.Post<VoucherResult>("/api/v1/receipts", new ReceiptRequest { IdempotencyKey = Guid.NewGuid().ToString(), CustomerId = cust, Amount = 500, Allocations = { new AllocationInput { DocId = cs.Id, Amount = 500 } } });
        Assert.Equal(340m, (await admin.Get<CustomerDto>($"/api/v1/customers/{cust}")).Balance);
        var ledger = await admin.Get<LedgerResult>($"/api/v1/customers/{cust}/ledger");
        Assert.Equal(340m, ledger.Closing);
        Assert.Equal(340m, (await admin.Get<List<OutstandingDoc>>($"/api/v1/customers/{cust}/outstanding")).Single().Outstanding);
    }

    [Fact]
    public async Task SupplierPayment_Shift_Adjustment_Expense_Reports()
    {
        using var host = new TestHost();
        var api = await host.SetupAndLoginAdmin();
        var sup = await CreateSupplier(api);
        var risek = await CreateRisek(api);
        var p = await Receive(api, sup, risek, boxes: 10, rate: 1000m);
        var accounts = await api.Get<List<CashAccountDto>>("/api/v1/cash-accounts");
        var cash = accounts.Single(a => a.Kind == "CASH");
        var bank = accounts.Single(a => a.Kind == "BANK");
        await api.Post<OkResult>("/api/v1/cash/entries", new CashEntryRequest { IdempotencyKey = Guid.NewGuid().ToString(), CashAccountId = bank.Id, Category = "OPENING_BALANCE", Amount = 20000, Memo = "Opening" });
        await api.Post<VoucherResult>("/api/v1/supplier-payments", new SupplierPaymentRequest { IdempotencyKey = Guid.NewGuid().ToString(), SupplierId = sup, Amount = 6000, CashAccountId = bank.Id, Allocations = { new AllocationInput { DocId = p.Id, Amount = 6000 } } });
        Assert.Equal(4000m, (await api.Get<SupplierDto>($"/api/v1/suppliers/{sup}")).Balance);
        Assert.Equal(4000m, (await api.Get<PurchaseDto>($"/api/v1/purchases/{p.Id}")).Outstanding);

        // shift: float 5,000, one cash sale of 420, count 5,400 → short 20 → needs approval (admin may act alone)
        var shift = await api.Post<ShiftDto>("/api/v1/shifts/open", new OpenShiftRequest { Float = 5000, CounterNo = 1 });
        await api.Post<SaleResult>("/api/v1/sales", CashSale(risek, "Strip", 1, 420m));
        var current = await api.Get<ShiftDto>("/api/v1/shifts/current");
        Assert.Equal(5420m, current.Expected);
        await api.Fails(() => api.Post<ShiftDto>($"/api/v1/shifts/{shift.Id}/close", new CloseShiftRequest { Counts = { new DenominationCount { Denomination = 1000, Count = 5 }, new DenominationCount { Denomination = 100, Count = 4 } } }), ErrorCodes.Validation);
        var closed = await api.Post<ShiftDto>($"/api/v1/shifts/{shift.Id}/close", new CloseShiftRequest { Counts = { new DenominationCount { Denomination = 1000, Count = 5 }, new DenominationCount { Denomination = 100, Count = 4 } }, Reason = "Change given wrongly" });
        Assert.Equal(-20m, closed.Difference);

        // adjustment + quarantine + write-off + expense
        var batches = await api.Get<List<BatchRow>>("/api/v1/batches?view=SALEABLE");
        var b = batches.Single();
        await api.Post<OkResult>("/api/v1/stock/adjustments", new AdjustmentRequest { IdempotencyKey = Guid.NewGuid().ToString(), Reason = "Shortage", Lines = { new AdjustmentLineInput { BatchId = b.BatchId, PhysicalQty = b.Qty - 2 } } });
        await api.Post<OkResult>("/api/v1/stock/quarantine", new QuarantineRequest { IdempotencyKey = Guid.NewGuid().ToString(), BatchId = b.BatchId, Qty = 14, Reason = "Damaged box" });
        await api.Post<OkResult>("/api/v1/stock/quarantine", new QuarantineRequest { IdempotencyKey = Guid.NewGuid().ToString(), BatchId = b.BatchId, Qty = 14, Reason = "Destroyed", Action = "WRITE_OFF" });
        await api.Post<OkResult>("/api/v1/expenses", new ExpenseRequest { IdempotencyKey = Guid.NewGuid().ToString(), Category = "Utilities", Description = "LESCO", Amount = 1500, CashAccountId = cash.Id });

        // stock count
        var count = await api.Post<StockCountDto>("/api/v1/stock/counts", new StockCountCreate());
        count.Items[0].CountedQty = count.Items[0].FrozenQty - 1;
        await api.Put<StockCountDto>($"/api/v1/stock/counts/{count.Id}", new StockCountSave { Items = count.Items });
        await api.Post<OkResult>($"/api/v1/stock/counts/{count.Id}/post", new PostRequest { IdempotencyKey = Guid.NewGuid().ToString() });

        // every report runs
        var catalog = await api.Get<List<ReportInfo>>("/api/v1/reports");
        Assert.Equal(26, catalog.Count);
        foreach (var r in catalog)
        {
            var url = $"/api/v1/reports/{r.Key}?from={Clock.Today.AddDays(-30):yyyy-MM-dd}&to={Clock.Today.AddDays(400):yyyy-MM-dd}";
            if (r.Key == "customer_ledger") continue;
            if (r.Key == "supplier_ledger") url += $"&supplier={sup}";
            var res = await api.Get<ReportResult>(url);
            Assert.True(res.Columns.Count > 0, r.Key);
        }
        var csv = await api.Http.GetStringAsync("/api/v1/reports/daily_sales/export?format=csv");
        Assert.Contains("Invoice", csv);
        var xlsx = await api.Http.GetByteArrayAsync("/api/v1/reports/stock_valuation/export?format=xlsx");
        Assert.True(xlsx.Length > 1000);
        var dash = await api.Get<DashboardDto>("/api/v1/dashboard");
        Assert.Equal(1, dash.InvoicesToday);
        Assert.True(dash.InventoryValue > 0);

        // books still balance, stock still equals movements
        await api.Post<BackupDto>("/api/v1/backups", new { });
        var check = await api.Post<SelfCheckResult>("/api/v1/selfcheck", new { });
        Assert.True(check.Ok, string.Join("; ", check.Items.Where(i => !i.Ok).Select(i => i.Check + ": " + i.Detail)));

        // day close blocks posting without approval for users who cannot approve
        await api.Post<DayStatusDto>("/api/v1/day/close", new { });
        var roles = await api.Get<List<RoleDto>>("/api/v1/roles");
        await api.Post<OkResult>("/api/v1/users", new UserSaveRequest { Username = "asad", FullName = "Asad Raza", RoleId = roles.Single(r => r.Name == "Cashier").Id, Password = "Cashier123" });
        var cashier = await host.Login("asad", "Cashier123", 3);
        await cashier.Post<OkResult>("/api/v1/auth/password", new ChangePasswordRequest { CurrentPassword = "Cashier123", NewPassword = "Cashier456" });
        await cashier.Fails(() => cashier.Post<SaleResult>("/api/v1/sales", CashSale(risek, "Strip", 1, 420m)), ErrorCodes.ApprovalRequired);
    }

    [Fact]
    public async Task Backup_And_Restore_RoundTrip()
    {
        using var host = new TestHost();
        var api = await host.SetupAndLoginAdmin();
        var sup = await CreateSupplier(api);
        var risek = await CreateRisek(api);
        await Receive(api, sup, risek, boxes: 2, rate: 1754m);
        var backup = await api.Post<BackupDto>("/api/v1/backups", new { });
        Assert.Equal("OK", backup.Result);
        var after = await api.Post<SaleResult>("/api/v1/sales", CashSale(risek, "Strip", 1, 420m));
        Assert.Equal(1L, Sql<long>(host, "SELECT COUNT(*) FROM sales_headers"));

        await api.Fails(() => api.Post<OkResult>("/api/v1/restore", new RestoreRequest { BackupId = backup.Id, AdminPassword = "Admin12345", ConfirmPhrase = "restore" }), ErrorCodes.Validation);
        await api.Fails(() => api.Post<OkResult>("/api/v1/restore", new RestoreRequest { BackupId = backup.Id, AdminPassword = "wrong", ConfirmPhrase = "RESTORE" }), ErrorCodes.BadCredentials);
        var ok = await api.Post<OkResult>("/api/v1/restore", new RestoreRequest { BackupId = backup.Id, AdminPassword = "Admin12345", ConfirmPhrase = "RESTORE" });
        Assert.Contains("Restore complete", ok.Message);
        Assert.Equal(0L, Sql<long>(host, "SELECT COUNT(*) FROM sales_headers"));
        Assert.True(Directory.GetFiles(Path.Combine(host.DataDir, "pre-restore")).Length >= 2);
        // sessions were revoked
        await api.Fails(() => api.Get<List<UserDto>>("/api/v1/users"), ErrorCodes.Unauthorized);
        var again = await host.Login("rehan", "Admin12345", 1);
        Assert.Equal(1540 / 11 * 0 + 140, (await again.Get<MedicineDto>($"/api/v1/medicines/{risek.Id}")).StockQty);
        Assert.Contains("RESTORE", Sql<string>(host, "SELECT GROUP_CONCAT(action) FROM audit_logs"));
        _ = after;
    }

    [Fact]
    public async Task Import_Medicines_Csv_Validate_Commit_Undo()
    {
        using var host = new TestHost();
        var api = await host.SetupAndLoginAdmin();
        var csv = "Item Name,Barcode,Generic,Company,Pack Size,Strip Size,TP,MRP\n" +
                  "PANADOL 500MG TAB,8964000101018,Paracetamol,GSK,200,10,500,680\n" +
                  "BRUFEN 400MG TAB,8964000303030,Ibuprofen,Abbott,100,10,600,740\n" +
                  "BAD ROW,,,,,,,\n" +
                  ",8964000101018,,,10,,,\n";
        using var content = new MultipartFormDataContent { { new ByteArrayContent(System.Text.Encoding.UTF8.GetBytes(csv)), "file", "medicines.csv" } };
        var resp = await api.Http.PostAsync("/api/v1/imports/medicines", content);
        Assert.Equal(HttpStatusCode.OK, resp.StatusCode);
        var preview = System.Text.Json.JsonSerializer.Deserialize<ImportPreview>(await resp.Content.ReadAsStringAsync(), TestHost.JsonOpts)!;
        Assert.Equal(4, preview.RowsRead);
        Assert.Equal("Item Name", preview.Mapping[ImportFields.Name]);
        Assert.Equal(1, preview.Errors);
        var dry = await api.Post<ImportPreview>($"/api/v1/imports/{preview.JobId}/commit?dryRun=true", new { });
        Assert.StartsWith("DRY RUN", dry.Status);
        Assert.Empty(await api.Get<List<MedicineListItem>>("/api/v1/medicines"));
        var done = await api.Post<ImportPreview>($"/api/v1/imports/{preview.JobId}/commit", new { });
        Assert.Contains("Imported 3", done.Status);
        var meds = await api.Get<List<MedicineListItem>>("/api/v1/medicines?q=panadol");
        var panadol = await api.Get<MedicineDto>($"/api/v1/medicines/{meds.Single().Id}");
        Assert.Equal("Strip", panadol.DefaultSalePack);
        Assert.Equal(34.00m, panadol.Packs.Single(p => p.Name == "Strip").SalePrice);
        Assert.Equal(2.5m, panadol.DefaultPurchaseCost);
        await api.Post<OkResult>($"/api/v1/imports/{preview.JobId}/undo", new { });
        Assert.Empty(await api.Get<List<MedicineListItem>>("/api/v1/medicines"));
    }

    [Fact]
    public async Task Reversal_Holds_Register_And_PurchaseReturn()
    {
        using var host = new TestHost();
        var api = await host.SetupAndLoginAdmin();
        var sup = await CreateSupplier(api);
        var xanax = await CreateRisek(api, schedule: "B", barcode: "8964000101018");
        var p = await Receive(api, sup, xanax, boxes: 3, rate: 1000m);

        // Schedule B needs prescriber and patient
        var rx = CashSale(xanax, "Strip", 1, 420m);
        await api.Fails(() => api.Post<SaleResult>("/api/v1/sales", rx), ErrorCodes.Validation);
        rx.Prescriptions.Add(new PrescriptionInput { LineIndex = 0, Prescriber = "Dr. Ayesha Khan", PrescriberReg = "45612-P", Patient = "Tariq Mehmood", RxNo = "77" });
        var s = await api.Post<SaleResult>("/api/v1/sales", rx);
        var reg = await api.Get<ReportResult>("/api/v1/reports/controlled_register");
        Assert.Equal("B-0001", reg.Rows.Single()[0]);

        // reversal (admin may approve alone) restores stock and money
        var stockBefore = (await api.Get<MedicineDto>($"/api/v1/medicines/{xanax.Id}")).StockQty;
        await api.Post<OkResult>($"/api/v1/sales/{s.Id}/reverse", new ReverseRequest { Reason = "Wrong medicine billed" });
        Assert.Equal(stockBefore + 14, (await api.Get<MedicineDto>($"/api/v1/medicines/{xanax.Id}")).StockQty);
        Assert.Equal("REVERSED", (await api.Get<SaleDetail>($"/api/v1/sales/{s.Id}")).Status);
        await api.Fails(() => api.Post<OkResult>($"/api/v1/sales/{s.Id}/reverse", new ReverseRequest { Reason = "again" }), ErrorCodes.Conflict);

        // holds
        var hold = await api.Post<OkResult>("/api/v1/holds", new HoldSaveRequest { CounterNo = 1, Lines = 2, Amount = 860, PayloadJson = "{\"lines\":[]}", Note = "ATM" });
        Assert.Single(await api.Get<List<HoldDto>>("/api/v1/holds?counter=1"));
        var recalled = await api.Http.DeleteAsync($"/api/v1/holds/{hold.Id}");
        Assert.True(recalled.IsSuccessStatusCode);
        Assert.Empty(await api.Get<List<HoldDto>>("/api/v1/holds"));

        // purchase return reduces payable and stock
        var pd = await api.Get<PurchaseDto>($"/api/v1/purchases/{p.Id}");
        var balBefore = (await api.Get<SupplierDto>($"/api/v1/suppliers/{sup}")).Balance;
        await api.Post<OkResult>("/api/v1/purchase-returns", new PurchaseReturnRequest { IdempotencyKey = Guid.NewGuid().ToString(), PurchaseId = p.Id, CreditNoteRef = "CN-1", Lines = { new PurchaseReturnLineInput { PurchaseItemId = pd.Lines[0].Id, BaseQty = 70, Reason = "Near expiry" } } });
        Assert.Equal(balBefore - 1000m, (await api.Get<SupplierDto>($"/api/v1/suppliers/{sup}")).Balance);
        await api.Fails(() => api.Post<OkResult>("/api/v1/purchase-returns", new PurchaseReturnRequest { IdempotencyKey = Guid.NewGuid().ToString(), PurchaseId = p.Id, Lines = { new PurchaseReturnLineInput { PurchaseItemId = pd.Lines[0].Id, BaseQty = 1000 } } }), ErrorCodes.Validation);
        // a reversal is refused once returns exist
        await api.Fails(() => api.Post<OkResult>($"/api/v1/purchases/{p.Id}/reverse", new ReverseRequest { Reason = "x" }), ErrorCodes.Conflict);
        // supplier invoice number cannot be posted twice
        await Receive(api, sup, xanax, boxes: 1, rate: 1000m, supplierInv: "DUP-1", batch: "B2");
        await api.Fails(() => Receive(api, sup, xanax, boxes: 1, rate: 1000m, supplierInv: "DUP-1", batch: "B3"), ErrorCodes.Conflict);
        // same batch number with a different expiry is refused
        await api.Fails(() => Receive(api, sup, xanax, boxes: 1, rate: 1000m, batch: "B2", expiry: new DateTime(2028, 1, 31)), ErrorCodes.Validation);
    }
}
