using System.Globalization;
using Dapper;
using MediCare.Contracts;
using MediCare.Core;
using MediCare.Host.Domain;
using MediCare.Host.Infrastructure;
using MediCare.Host.Security;
using Microsoft.Data.Sqlite;

namespace MediCare.Host.Modules;

public static class SalesModule
{
    public static void Map(WebApplication app)
    {
        var g = app.MapGroup("/api/v1");
        g.MapPost("/sales/quote", (SaleRequest r, SalesService s) => s.Quote(r)).Perm(Perm.SalesPos);
        g.MapPost("/sales", (HttpContext http, SaleRequest r, SalesService s) => s.Post(http.Ctx(), r, http.Tokens(r.ApprovalTokens.ToArray()))).Perm(Perm.SalesPos);
        g.MapGet("/sales", (HttpContext http, DateTime? from, DateTime? to, string? q, int? counter, long? customerId, SalesService s) => s.List(http.Ctx(), from, to, q, counter, customerId)).Authenticated();
        g.MapGet("/sales/{id:long}", (long id, HttpContext http, SalesService s) => s.Detail(http.Ctx(), id)).Authenticated();
        g.MapGet("/sales/by-no/{no}", (string no, HttpContext http, SalesService s) => s.DetailByNo(http.Ctx(), no)).Authenticated();
        g.MapGet("/sales/{id:long}/receipt", (long id, bool? duplicate, HttpContext http, SalesService s) => s.Reprint(http.Ctx(), id, duplicate ?? true)).Authenticated();
        g.MapPost("/sales/{id:long}/reverse", (long id, HttpContext http, ReverseRequest r, SalesService s) => s.Reverse(http.Ctx(), id, r)).Perm(Perm.SalesPos);
        g.MapGet("/holds", (int? counter, SalesService s) => s.Holds(counter)).Perm(Perm.SalesPos);
        g.MapPost("/holds", (HttpContext http, HoldSaveRequest r, SalesService s) => s.Hold(http.Ctx(), r)).Perm(Perm.SalesPos);
        g.MapDelete("/holds/{id:long}", (long id, HttpContext http, SalesService s) => s.Recall(http.Ctx(), id)).Perm(Perm.SalesPos);
        g.MapPost("/sales-returns", (HttpContext http, SalesReturnRequest r, ReturnService s) => s.Post(http.Ctx(), r, http.Tokens(r.ApprovalToken))).Perm(Perm.ReturnPost);
    }
}

public sealed class SalesService(Db db, SettingsService settings)
{
    private sealed class Sub
    {
        public int LineIndex;
        public CatalogueService.MedRow Med = null!;
        public CatalogueService.PackRow Pack = null!;
        public long BatchId;
        public int BaseQty;
        public decimal Rate;
        public decimal Gross;
        public decimal Discount;
        public string Pick = "FEFO";
        public PricedLine Priced = null!;
    }

    public static long WalkInId(SqliteConnection c, SqliteTransaction? tx) => c.ExecuteScalar<long>("SELECT id FROM customers WHERE type='WALKIN' ORDER BY id LIMIT 1", transaction: tx);

    /// <summary>Allocates batches (FEFO, or the scanned / picked batch) and prices the cart (FR-064).
    /// Used by Post and by the read-only Quote the POS calls, so the screen always shows what will be posted.</summary>
    private static (List<Sub> subs, PriceResult priced) PriceCart(SqliteConnection c, SqliteTransaction? tx, SaleRequest req, bool taxInclusive)
    {
        var today = Clock.Today;

        // ---- allocate batches (FEFO, or the scanned / picked batch) ----
        var subs = new List<Sub>();
        var consumed = new Dictionary<long, int>();
        for (int i = 0; i < req.Lines.Count; i++)
        {
            var line = req.Lines[i];
            var med = CatalogueService.Row(c, tx, line.MedicineId);
            if (med.IsActive == 0) throw new AppException(409, ErrorCodes.BatchNotSaleable, $"{med.Name} is inactive and cannot be sold.");
            var pack = c.QuerySingleOrDefault<CatalogueService.PackRow>("SELECT * FROM medicine_packs WHERE id=@id", new { id = line.PackId }, tx);
            if (pack == null || pack.MedicineId != med.Id || pack.IsSellable == 0 || pack.IsActive == 0)
                throw AppException.Validation($"{med.Name}: the chosen pack cannot be sold.");
            var baseQty = line.Qty * (int)pack.BaseQty;
            var batches = CatalogueService.BatchesOf(c, tx, med.Id, includeEmpty: true);
            if (line.BatchId.HasValue)
            {
                var forced = batches.FirstOrDefault(b => b.BatchId == line.BatchId.Value);
                if (forced == null || !Fefo.IsSaleable(forced.Expiry, today) || forced.AvailableQty <= 0)
                    throw new AppException(409, ErrorCodes.BatchNotSaleable, forced == null ? $"{med.Name}: the chosen batch does not belong to this medicine."
                        : !Fefo.IsSaleable(forced.Expiry, today) ? $"Batch {forced.BatchNo} of {med.Name} expired on {Dates.Display(forced.Expiry)}. Expired stock cannot be sold."
                        : $"Batch {forced.BatchNo} of {med.Name} has no saleable stock.");
            }
            var stock = batches.Select(b => new BatchStock { BatchId = b.BatchId, Expiry = b.Expiry, Available = b.AvailableQty - (consumed.TryGetValue(b.BatchId, out var u) ? u : 0) }).ToList();
            var alloc = Fefo.Allocate(stock, baseQty, today, line.BatchId);
            if (!alloc.Complete)
            {
                var available = baseQty - alloc.Shortfall;
                throw new AppException(409, ErrorCodes.StockChanged, $"{med.Name}: only {CatalogueService.QtyText(available, CatalogueService.Packs(c, tx, med.Id), med.BaseUnit)} saleable in stock; {line.Qty} {pack.Name} requested.",
                    new Dictionary<string, string> { ["lineIndex"] = i.ToString(CultureInfo.InvariantCulture), ["medicineId"] = med.Id.ToString(CultureInfo.InvariantCulture), ["available"] = available.ToString(CultureInfo.InvariantCulture) });
            }
            var defaultPack = med.DefaultSalePackId.HasValue ? c.QuerySingleOrDefault<CatalogueService.PackRow>("SELECT * FROM medicine_packs WHERE id=@id", new { id = med.DefaultSalePackId }, tx) : null;
            var lineSubs = new List<Sub>();
            foreach (var a in alloc.Allocations)
            {
                consumed[a.BatchId] = (consumed.TryGetValue(a.BatchId, out var u) ? u : 0) + a.Qty;
                var batch = batches.First(b => b.BatchId == a.BatchId);
                var rate = Money.FromPaisa(pack.SalePaisa);
                if (batch.SalePrice.HasValue && batch.SalePrice.Value > 0 && defaultPack != null)
                    rate = PricingCalculator.ScalePrice(batch.SalePrice.Value, (int)defaultPack.BaseQty, (int)pack.BaseQty);
                lineSubs.Add(new Sub
                {
                    LineIndex = i, Med = med, Pack = pack, BatchId = a.BatchId, BaseQty = a.Qty, Rate = rate, Gross = PricingCalculator.LineGross(rate, a.Qty, (int)pack.BaseQty),
                    Pick = line.BatchId.HasValue && a.BatchId == line.BatchId.Value ? (line.Pick == "2D" ? "2D" : "MANUAL") : "FEFO",
                });
            }
            var lineGross = lineSubs.Sum(s => s.Gross);
            if (line.DiscountAmount > lineGross) throw AppException.Validation($"{med.Name}: discount is more than the line amount.");
            var shares = Costing.AllocatePro(line.DiscountAmount, lineSubs.Select(s => s.Gross).ToList());
            for (int k = 0; k < lineSubs.Count; k++) lineSubs[k].Discount = shares[k];
            subs.AddRange(lineSubs);
        }

        // ---- price (FR-064) ----
        var priced = PricingCalculator.Calculate(subs.Select(s => new PriceLine { UnitPrice = s.Gross, Qty = 1, LineDiscount = s.Discount, TaxRatePercent = Money.BpToPercent(s.Med.TaxBp) }).ToList(), req.InvoiceDiscount, taxInclusive);
        for (int k = 0; k < subs.Count; k++) subs[k].Priced = priced.Lines[k];
        return (subs, priced);
    }

    public SaleQuote Quote(SaleRequest req)
    {
        if (req.Lines.Any(l => l.Qty <= 0)) throw AppException.Validation("Quantities must be more than zero.");
        if (req.Lines.Any(l => l.DiscountAmount < 0) || req.InvoiceDiscount < 0) throw AppException.Validation("Discounts cannot be negative.");
        var taxInclusive = settings.GetBool("tax.inclusive");
        return db.Read(c =>
        {
            var q = new SaleQuote();
            if (req.Lines.Count == 0) return q;
            var (subs, priced) = PriceCart(c, null, req, taxInclusive);
            q.Gross = priced.Gross; q.Discount = priced.Discount; q.Taxable = priced.Taxable; q.Tax = priced.Tax; q.Net = priced.Net;
            q.DiscountPercent = priced.Gross == 0 ? 0 : Math.Ceiling(priced.Discount / priced.Gross * 10000m) / 100m;
            var batchNos = new Dictionary<long, (string no, DateTime exp)>();
            for (int i = 0; i < req.Lines.Count; i++)
            {
                var ls = subs.Where(x => x.LineIndex == i).ToList();
                var parts = new List<string>();
                foreach (var x in ls)
                {
                    if (!batchNos.TryGetValue(x.BatchId, out var b))
                    {
                        var r = c.QuerySingle<(string batch_no, string expiry)>("SELECT batch_no, expiry_date FROM batches WHERE id=@id", new { id = x.BatchId });
                        b = (r.batch_no, DateTime.Parse(r.expiry, CultureInfo.InvariantCulture));
                        batchNos[x.BatchId] = b;
                    }
                    parts.Add($"{b.no} ({Dates.Expiry(b.exp)}){(ls.Count > 1 ? " ×" + x.BaseQty : "")}");
                }
                var first = ls.FirstOrDefault();
                q.Lines.Add(new SaleQuoteLine
                {
                    LineIndex = i,
                    Rate = first?.Rate ?? 0,
                    Gross = ls.Sum(x => x.Priced.Gross),
                    Discount = ls.Sum(x => x.Priced.TotalDiscount),
                    Tax = ls.Sum(x => x.Priced.Tax),
                    Net = ls.Sum(x => x.Priced.Net),
                    Batches = string.Join(", ", parts),
                    Pick = first?.Pick ?? "FEFO",
                    NearestExpiry = ls.Count == 0 ? (DateTime?)null : ls.Min(x => batchNos[x.BatchId].exp),
                    Schedule = first?.Med.Schedule ?? "",
                });
            }
            return q;
        });
    }

    public SaleResult Post(Ctx ctx, SaleRequest req, List<string?> tokens)
    {
        if (req.Lines.Count == 0) throw AppException.Validation("Add at least one medicine before taking payment.");
        if (req.Lines.Any(l => l.Qty <= 0)) throw AppException.Validation("Quantities must be more than zero.");
        if (req.Lines.Any(l => l.DiscountAmount < 0) || req.InvoiceDiscount < 0) throw AppException.Validation("Discounts cannot be negative.");
        if (req.Payments.Any(p => p.Amount <= 0)) throw AppException.Validation("Each payment amount must be more than zero.");
        var allowed = settings.Get("payments.methods").Split(',', StringSplitOptions.TrimEntries);
        foreach (var p in req.Payments) if (!allowed.Contains(p.Method)) throw AppException.Validation($"Payment method {p.Method} is not allowed. Check Settings → Payments.");
        var hash = Idempotency.HashOf(new { req.CustomerId, req.CounterNo, req.Lines, req.InvoiceDiscount, req.Payments, req.Prescriptions });
        var taxInclusive = settings.GetBool("tax.inclusive");

        return db.Write((c, tx) =>
        {
            var stored = Idempotency.Find<SaleResult>(c, tx, req.IdempotencyKey, "sale", hash);
            if (stored != null) return stored;
            Guards.DayOpen(c, tx, ctx, tokens, req.IdempotencyKey);
            var shiftId = c.ExecuteScalar<long?>("SELECT id FROM shifts WHERE user_id=@u AND status='OPEN' ORDER BY id DESC LIMIT 1", new { u = ctx.UserId }, tx);
            if (shiftId == null && settings.GetBool("shift.requireForSales")) throw new AppException(409, ErrorCodes.ShiftRequired, "Open your shift with the opening float before selling.");
            var customerId = req.CustomerId ?? WalkInId(c, tx);
            var customer = c.QueryOne<(string name, string type, long limit, long active)>("SELECT name, type, credit_limit_paisa, is_active FROM customers WHERE id=@customerId", new { customerId }, tx)
                           ?? throw AppException.NotFound("Customer");
            if (customer.active == 0) throw AppException.Validation($"Customer {customer.name} is inactive.");
            var (subs, priced) = PriceCart(c, tx, req, taxInclusive);
            var net = Money.ToPaisa(priced.Net);

            // ---- approvals: discount, prescription ----
            long? discountApproval = null;
            if (priced.Discount > 0)
            {
                var pct = Math.Ceiling(priced.Discount / priced.Gross * 10000m) / 100m;
                if (pct > ctx.MaxDiscountPercent) discountApproval = ApprovalService.Require(c, tx, ctx, tokens, ApprovalActions.Discount, req.IdempotencyKey, pct);
            }
            foreach (var idx in subs.Where(s => s.Med.Schedule is "B" or "D").Select(s => s.LineIndex).Distinct())
            {
                var rx = req.Prescriptions.FirstOrDefault(p => p.LineIndex == idx);
                if (rx == null || string.IsNullOrWhiteSpace(rx.Prescriber) || string.IsNullOrWhiteSpace(rx.Patient))
                    throw AppException.Validation($"{subs.First(s => s.LineIndex == idx).Med.Name} is a Schedule {subs.First(s => s.LineIndex == idx).Med.Schedule} medicine. Enter the prescriber and the patient before posting.");
            }

            // ---- payments (FR-065) ----
            var credit = Money.ToPaisa(req.Payments.Where(p => p.Method == PaymentMethods.Credit).Sum(p => p.Amount));
            var paid = Money.ToPaisa(req.Payments.Where(p => p.Method != PaymentMethods.Credit).Sum(p => p.Amount));
            if (paid + credit != net)
                throw AppException.Validation($"Payments add up to {Money.FormatRs(Money.FromPaisa(paid + credit))} but the net payable is {Money.FormatRs(priced.Net)}. They must match exactly.");
            long tendered = 0, change = 0;
            foreach (var p in req.Payments.Where(p => p.Method == PaymentMethods.Cash))
            {
                var t = Money.ToPaisa(p.Tendered ?? p.Amount);
                if (t < Money.ToPaisa(p.Amount)) throw AppException.Validation("Cash tendered is less than the cash amount.");
                tendered += t;
                change += t - Money.ToPaisa(p.Amount);
            }
            if (credit > 0)
            {
                if (customer.type == "WALKIN") throw AppException.Validation("Credit needs a named customer. Press F3 to pick one. Walk-in sales cannot go on credit.");
                var newBalance = PartyService.CustomerBalance(c, tx, customerId) + credit;
                if (newBalance > customer.limit)
                    ApprovalService.Require(c, tx, ctx, tokens, ApprovalActions.CreditLimit, req.IdempotencyKey, Money.FromPaisa(newBalance));
            }

            // ---- write ----
            var costs = subs.Select(s =>
            {
                var avg = StockLedger.CostOut(c, tx, s.Med.Id, s.BaseQty);
                return (avg, cogs: Money.ToPaisa(Costing.Cogs(s.BaseQty, Money.FromE4(avg))));
            }).ToList();
            long cogsTotal = costs.Sum(x => x.cogs);
            var no = DocNumbers.Next(c, tx, settings, "sale", req.CounterNo);
            var now = Clock.NowIso();
            var saleId = c.ExecuteScalar<long>(@"INSERT INTO sales_headers(no, idempotency_key, counter_no, workstation_id, cashier_id, customer_id, sold_at, business_date, gross_paisa, discount_paisa,
                taxable_paisa, tax_paisa, net_paisa, credit_paisa, tendered_paisa, change_paisa, cogs_paisa, discount_approval_id, shift_id)
                VALUES(@no,@key,@counter,@ws,@u,@cust,@now,@bd,@gross,@disc,@taxable,@tax,@net,@credit,@tend,@change,@cogsTotal,@appr,@shift) RETURNING id",
                new { no, key = req.IdempotencyKey, counter = req.CounterNo, ws = ctx.WorkstationId, u = ctx.UserId, cust = customerId, now, bd = Clock.BusinessDate(),
                    gross = Money.ToPaisa(priced.Gross), disc = Money.ToPaisa(priced.Discount), taxable = Money.ToPaisa(priced.Taxable), tax = Money.ToPaisa(priced.Tax), net, credit, tend = tendered, change, cogsTotal, appr = discountApproval, shift = shiftId }, tx);
            var itemIds = new Dictionary<int, long>();
            for (int k = 0; k < subs.Count; k++)
            {
                var s = subs[k];
                var (avg, cogs) = costs[k];
                var itemId = c.ExecuteScalar<long>(@"INSERT INTO sales_items(header_id, line_no, medicine_id, medicine_name, batch_id, pack_name, pack_base_qty, base_qty, rate_paisa, gross_paisa,
                    discount_paisa, taxable_paisa, tax_paisa, tax_bp, net_paisa, cost_e4, cogs_paisa, pick)
                    VALUES(@h,@ln,@m,@mn,@b,@pn,@pb,@q,@rate,@g,@d,@tx,@t,@tbp,@n,@cost,@cogs,@pick) RETURNING id",
                    new { h = saleId, ln = s.LineIndex + 1, m = s.Med.Id, mn = s.Med.Name, b = s.BatchId, pn = s.Pack.Name, pb = s.Pack.BaseQty, q = s.BaseQty, rate = Money.ToPaisa(s.Rate),
                        g = Money.ToPaisa(s.Priced.Gross), d = Money.ToPaisa(s.Priced.TotalDiscount), tx = Money.ToPaisa(s.Priced.Taxable), t = Money.ToPaisa(s.Priced.Tax), tbp = s.Med.TaxBp,
                        n = Money.ToPaisa(s.Priced.Net), cost = avg, cogs, pick = s.Pick }, tx);
                if (!itemIds.ContainsKey(s.LineIndex)) itemIds[s.LineIndex] = itemId;
                StockLedger.Move(c, tx, ctx, s.BatchId, StockStates.Saleable, -s.BaseQty, "SI", saleId, itemId, no, avg);
            }

            var j = new Journal();
            foreach (var p in req.Payments)
            {
                var amount = Money.ToPaisa(p.Amount);
                c.Execute("INSERT INTO sales_payments(header_id, method, cash_account_id, amount_paisa, reference) VALUES(@h,@m,@a,@amt,@ref)",
                    new { h = saleId, m = p.Method, a = p.Method == PaymentMethods.Credit ? (long?)null : CashAccounts.ForMethod(c, tx, p.Method, p.CashAccountId).Id, amt = amount, @ref = p.Reference ?? "" }, tx);
                if (p.Method == PaymentMethods.Credit) j.Dr(Acct.Receivables, amount, "C", customerId);
                else
                {
                    var acct = CashAccounts.ForMethod(c, tx, p.Method, p.CashAccountId);
                    j.DrAccount(acct.AccountId, amount, acct.Kind == "CASH" ? shiftId : null, $"Sale {p.Method}");
                }
            }
            j.Dr(Acct.SalesDiscounts, Money.ToPaisa(priced.Discount))
             .Cr(Acct.Sales, Money.ToPaisa(priced.Taxable) + Money.ToPaisa(priced.Discount))
             .Cr(Acct.TaxPayable, Money.ToPaisa(priced.Tax))
             .Dr(Acct.Cogs, cogsTotal).Cr(Acct.Inventory, cogsTotal);
            var je = j.Post(c, tx, settings, ctx, "SI", saleId, no, $"Sale to {customer.name}");
            c.Execute("UPDATE sales_headers SET journal_entry_id=@je WHERE id=@saleId", new { je, saleId }, tx);

            foreach (var rx in req.Prescriptions)
            {
                if (!itemIds.TryGetValue(rx.LineIndex, out var itemId)) continue;
                var schedule = subs.First(s => s.LineIndex == rx.LineIndex).Med.Schedule;
                string? serial = null;
                if (schedule is "B" or "D")
                {
                    c.Execute("INSERT INTO register_sequences(schedule, next_seq) VALUES(@schedule,1) ON CONFLICT(schedule) DO NOTHING", new { schedule }, tx);
                    var seq = c.ExecuteScalar<long>("UPDATE register_sequences SET next_seq=next_seq+1 WHERE schedule=@schedule RETURNING next_seq-1", new { schedule }, tx);
                    serial = $"{schedule}-{seq:0000}";
                }
                c.Execute("INSERT INTO prescriptions(header_id, sales_item_id, prescriber, prescriber_reg, patient, rx_no, register_serial) VALUES(@h,@i,@p,@pr,@pt,@rx,@s)",
                    new { h = saleId, i = itemId, p = rx.Prescriber.Trim(), pr = rx.PrescriberReg.Trim(), pt = rx.Patient.Trim(), rx = rx.RxNo.Trim(), s = serial }, tx);
            }
            if (req.HoldId.HasValue) c.Execute("DELETE FROM holds WHERE id=@id", new { id = req.HoldId }, tx);

            Audit.Write(c, tx, ctx, "SALE_POSTED", "sales", no, after: $"{customer.name} net {Money.Format(priced.Net)} lines {req.Lines.Count}");
            if (discountApproval != null)
                Audit.Write(c, tx, ctx, "DISCOUNT_OVERRIDE", "sales", no, after: $"{Money.Format(priced.Discount)} ({priced.DiscountPercent}%)", approver: ApprovalService.ApproverName(c, tx, discountApproval));

            var result = new SaleResult { Id = saleId, No = no, Net = priced.Net, Change = Money.FromPaisa(change), Receipt = BuildReceipt(c, tx, saleId, false) };
            Idempotency.Save(c, tx, req.IdempotencyKey, "sale", hash, result);
            return result;
        });
    }

    internal ReceiptDto BuildReceipt(SqliteConnection c, SqliteTransaction? tx, long saleId, bool duplicate)
    {
        var h = c.QuerySingleOrDefault(@"SELECT s.*, u.full_name AS cashier, cu.name AS customer, cu.type AS ctype FROM sales_headers s JOIN users u ON u.id=s.cashier_id JOIN customers cu ON cu.id=s.customer_id WHERE s.id=@saleId", new { saleId }, tx)
                ?? throw AppException.NotFound("Sale");
        var items = c.Query(@"SELECT si.*, m.urdu_name, b.batch_no, b.expiry_date FROM sales_items si JOIN medicines m ON m.id=si.medicine_id JOIN batches b ON b.id=si.batch_id
                              WHERE si.header_id=@saleId ORDER BY si.line_no, si.id", new { saleId }, tx).ToList();
        var receipt = new ReceiptDto
        {
            Title = h.status == "REVERSED" ? "Sale Invoice (REVERSED)" : "Sale Invoice", PharmacyName = settings.Get("pharmacy.name"), Address = settings.Get("pharmacy.address"),
            Phone = settings.Get("pharmacy.phone"), LicenseNo = settings.Get("pharmacy.license"), Ntn = settings.Get("pharmacy.ntn"), DocNo = h.no, At = Clock.ToPkt((string)h.sold_at),
            Cashier = h.cashier, CounterNo = (int)(long)h.counter_no, Customer = h.customer, Gross = Money.FromPaisa((long)h.gross_paisa), Discount = Money.FromPaisa((long)h.discount_paisa),
            Tax = Money.FromPaisa((long)h.tax_paisa), Net = Money.FromPaisa((long)h.net_paisa), Tendered = Money.FromPaisa((long)h.tendered_paisa), Change = Money.FromPaisa((long)h.change_paisa),
            ShowBatch = settings.GetBool("receipt.showBatch"), FooterEnglish = settings.Get("receipt.footerEnglish"), IsDuplicate = duplicate,
            FooterUrdu = new[] { settings.Get("receipt.footerUrdu1"), settings.Get("receipt.footerUrdu2") }.Where(s => s.Length > 0).ToList(),
        };
        foreach (var g in items.GroupBy(i => (long)i.line_no))
        {
            var first = g.First();
            var baseQty = g.Sum(i => (long)i.base_qty);
            var packBase = (long)first.pack_base_qty;
            receipt.Lines.Add(new ReceiptLine
            {
                Name = first.medicine_name, UrduName = first.urdu_name, Rate = Money.FromPaisa((long)first.rate_paisa),
                QtyText = baseQty % packBase == 0 ? $"{baseQty / packBase} {first.pack_name}" : $"{baseQty} units",
                Discount = Money.FromPaisa(g.Sum(i => (long)i.discount_paisa)), Amount = Money.FromPaisa(g.Sum(i => (long)i.gross_paisa)),
                BatchNo = string.Join("/", g.Select(i => (string)i.batch_no).Distinct()), Expiry = Dates.Expiry(Clock.ParseDate((string)first.expiry_date)),
            });
        }
        foreach (var p in c.Query<(string method, long amount)>("SELECT method, amount_paisa FROM sales_payments WHERE header_id=@saleId ORDER BY id", new { saleId }, tx))
            receipt.Payments.Add(new KeyValuePair<string, decimal>(p.method, Money.FromPaisa(p.amount)));
        if ((string)h.ctype != "WALKIN") receipt.CustomerBalance = Money.FromPaisa(PartyService.CustomerBalance(c, tx, (long)h.customer_id));
        return receipt;
    }

    public ReceiptDto Reprint(Ctx ctx, long id, bool duplicate)
    {
        var owner = db.Read(c => c.ExecuteScalar<long?>("SELECT cashier_id FROM sales_headers WHERE id=@id", new { id })) ?? throw AppException.NotFound("Sale");
        if (owner != ctx.UserId && !ctx.Has(Perm.SalesViewAll) && !ctx.Has(Perm.ReturnPost)) ctx.Require(Perm.SalesViewAll);
        return db.Write((c, tx) =>
        {
            var r = BuildReceipt(c, tx, id, duplicate);
            if (duplicate) Audit.Write(c, tx, ctx, "REPRINT", "sales", r.DocNo);
            return r;
        });
    }

    public List<SaleSummary> List(Ctx ctx, DateTime? from, DateTime? to, string? q, int? counter, long? customerId)
    {
        var f = from ?? Clock.Today;
        var t = to ?? Clock.Today;
        if ((t - f).TotalDays > 92) throw AppException.Validation("Search at most 92 days at a time, or use the Daily sales report.");
        var mine = !ctx.Has(Perm.SalesViewAll);
        return db.Read(c => c.Query(@"SELECT s.id, s.no, s.sold_at, s.counter_no, u.full_name AS cashier, cu.name AS customer, s.net_paisa, s.credit_paisa, s.settled_paisa, s.status,
                (SELECT GROUP_CONCAT(DISTINCT method) FROM sales_payments WHERE header_id=s.id) AS methods,
                EXISTS(SELECT 1 FROM sales_returns WHERE sale_id=s.id) AS has_returns
            FROM sales_headers s JOIN users u ON u.id=s.cashier_id JOIN customers cu ON cu.id=s.customer_id
            WHERE s.business_date BETWEEN @f AND @t AND (@mine=0 OR s.cashier_id=@u) AND (@counter IS NULL OR s.counter_no=@counter) AND (@cid IS NULL OR s.customer_id=@cid)
              AND (@q IS NULL OR s.no LIKE '%'||@q||'%' OR cu.name LIKE '%'||@q||'%' OR cu.mobile_digits LIKE '%'||@q||'%')
            ORDER BY s.id DESC LIMIT 1000", new { f = Clock.DateText(f), t = Clock.DateText(t), mine = mine ? 1 : 0, u = ctx.UserId, counter, cid = customerId, q = string.IsNullOrWhiteSpace(q) ? null : q.Trim() })
            .Select(r => new SaleSummary
            {
                Id = r.id, No = r.no, At = Clock.ToPkt((string)r.sold_at), CounterNo = (int)(long)r.counter_no, Cashier = r.cashier, Customer = r.customer,
                Payment = ((string?)r.methods ?? "").Contains(',') ? "Split" : (string?)r.methods ?? "", Net = Money.FromPaisa((long)r.net_paisa),
                Outstanding = Money.FromPaisa((long)r.credit_paisa - (long)r.settled_paisa), Status = r.status, HasReturns = r.has_returns == 1,
            }).ToList());
    }

    public SaleDetail DetailByNo(Ctx ctx, string no)
    {
        var id = db.Read(c => c.ExecuteScalar<long?>("SELECT id FROM sales_headers WHERE no=@no COLLATE NOCASE", new { no = no.Trim() })) ?? throw new AppException(404, ErrorCodes.NotFound, $"Invoice {no} was not found.");
        return Detail(ctx, id);
    }

    public SaleDetail Detail(Ctx ctx, long id) => db.Read(c =>
    {
        var h = c.QuerySingleOrDefault(@"SELECT s.*, u.full_name AS cashier, cu.name AS customer, r.no AS rev_no FROM sales_headers s JOIN users u ON u.id=s.cashier_id
                JOIN customers cu ON cu.id=s.customer_id LEFT JOIN reversals r ON r.id=s.reversal_id WHERE s.id=@id", new { id }) ?? throw AppException.NotFound("Sale");
        if ((long)h.cashier_id != ctx.UserId && !ctx.Has(Perm.SalesViewAll) && !ctx.Has(Perm.ReturnPost)) ctx.Require(Perm.SalesViewAll);
        return new SaleDetail
        {
            Id = h.id, No = h.no, At = Clock.ToPkt((string)h.sold_at), CounterNo = (int)(long)h.counter_no, Cashier = h.cashier, CustomerId = h.customer_id, Customer = h.customer,
            Gross = Money.FromPaisa((long)h.gross_paisa), Discount = Money.FromPaisa((long)h.discount_paisa), Tax = Money.FromPaisa((long)h.tax_paisa), Net = Money.FromPaisa((long)h.net_paisa),
            Credit = Money.FromPaisa((long)h.credit_paisa), Outstanding = Money.FromPaisa((long)h.credit_paisa - (long)h.settled_paisa), Status = h.status, ReversedBy = h.rev_no,
            Lines = c.Query(@"SELECT si.*, b.batch_no, b.expiry_date FROM sales_items si JOIN batches b ON b.id=si.batch_id WHERE si.header_id=@id ORDER BY si.line_no, si.id", new { id })
                .Select(l => new SaleLineDetail
                {
                    Id = l.id, LineNo = (int)(long)l.line_no, MedicineId = l.medicine_id, Medicine = l.medicine_name, BatchId = l.batch_id, BatchNo = l.batch_no,
                    Expiry = Clock.ParseDate((string)l.expiry_date), PackName = l.pack_name, PackBaseQty = (int)(long)l.pack_base_qty, BaseQty = (int)(long)l.base_qty,
                    ReturnedBaseQty = (int)(long)l.returned_base_qty, Rate = Money.FromPaisa((long)l.rate_paisa), Discount = Money.FromPaisa((long)l.discount_paisa),
                    Tax = Money.FromPaisa((long)l.tax_paisa), Net = Money.FromPaisa((long)l.net_paisa), Pick = l.pick,
                    QtyText = (long)l.base_qty % (long)l.pack_base_qty == 0 ? $"{(long)l.base_qty / (long)l.pack_base_qty} {l.pack_name}" : $"{l.base_qty} units",
                }).ToList(),
            Payments = c.Query<(string method, long amount, string reference)>("SELECT method, amount_paisa, reference FROM sales_payments WHERE header_id=@id", new { id })
                .Select(p => new PaymentInput { Method = p.method, Amount = Money.FromPaisa(p.amount), Reference = p.reference }).ToList(),
        };
    });

    public OkResult Reverse(Ctx ctx, long id, ReverseRequest r)
    {
        if (string.IsNullOrWhiteSpace(r.Reason)) throw AppException.Validation("Enter the reason for the reversal.");
        return db.Write((c, tx) =>
        {
            var h = c.QueryOne<(string no, string status, long settled, long je, long customer_id)>("SELECT no, status, settled_paisa, journal_entry_id, customer_id FROM sales_headers WHERE id=@id", new { id }, tx)
                    ?? throw AppException.NotFound("Sale");
            if (h.status != "POSTED") throw AppException.Conflict($"Invoice {h.no} is already reversed.");
            var approval = ApprovalService.Require(c, tx, ctx, new[] { r.ApprovalToken }, ApprovalActions.Reversal, $"REV:SI:{id}", 0);
            if (c.ExecuteScalar<long>("SELECT COUNT(*) FROM sales_returns WHERE sale_id=@id", new { id }, tx) > 0) throw AppException.Conflict("This invoice has returns. Use a sales return for the remaining items.");
            if (h.settled > 0) throw AppException.Conflict("Receipts are allocated to this invoice. Use a sales return instead.");
            var no = DocNumbers.Next(c, tx, settings, "reversal");
            var revId = c.ExecuteScalar<long>("INSERT INTO reversals(no, idempotency_key, doc_type, doc_id, reason, approval_id, at, business_date, user_id) VALUES(@no,@key,'SI',@id,@reason,@a,@t,@bd,@u) RETURNING id",
                new { no, key = string.IsNullOrEmpty(r.IdempotencyKey) ? Guid.NewGuid().ToString() : r.IdempotencyKey, id, reason = r.Reason, a = approval, t = Clock.NowIso(), bd = Clock.BusinessDate(), u = ctx.UserId }, tx);
            foreach (var i in c.Query<(long id, long medicine_id, long batch_id, long base_qty, long cost_e4, long cogs)>("SELECT id, medicine_id, batch_id, base_qty, cost_e4, cogs_paisa FROM sales_items WHERE header_id=@id", new { id }, tx).ToList())
            {
                StockLedger.Move(c, tx, ctx, i.batch_id, StockStates.Saleable, (int)i.base_qty, "REV", revId, i.id, no, i.cost_e4);
                StockLedger.CostIn(c, tx, i.medicine_id, (int)i.base_qty, Money.FromPaisa(i.cogs));
            }
            var shift = c.ExecuteScalar<long?>("SELECT id FROM shifts WHERE user_id=@u AND status='OPEN' ORDER BY id DESC LIMIT 1", new { u = ctx.UserId }, tx);
            var je = Journal.Reverse(c, tx, settings, ctx, h.je, "REV", revId, no, $"Reversal of {h.no}: {r.Reason}", shift);
            c.Execute("UPDATE reversals SET journal_entry_id=@je WHERE id=@revId", new { je, revId }, tx);
            c.Execute("UPDATE sales_headers SET status='REVERSED', reversal_id=@revId WHERE id=@id", new { revId, id }, tx);
            Audit.Write(c, tx, ctx, "SALE_REVERSED", "sales", h.no, "POSTED", "REVERSED", r.Reason, ApprovalService.ApproverName(c, tx, approval));
            return new OkResult { Id = revId, No = no, Message = $"Invoice {h.no} reversed by {no}. Stock returned and money reversed." };
        });
    }

    // ---------- holds (FR-067) ----------
    public List<HoldDto> Holds(int? counter) => db.Read(c => c.Query(@"SELECT h.*, u.full_name AS user FROM holds h JOIN users u ON u.id=h.user_id
            WHERE (@counter IS NULL OR h.counter_no=@counter) AND h.expires_at > @now ORDER BY h.id DESC", new { counter, now = Clock.NowIso() })
        .Select(h => new HoldDto
        {
            Id = h.id, CounterNo = (int)(long)h.counter_no, User = h.user, Customer = h.customer_name, Lines = (int)(long)h.lines, Amount = Money.FromPaisa((long)h.amount_paisa),
            Note = h.note, CreatedAt = Clock.ToPkt((string)h.created_at), PayloadJson = h.payload_json,
        }).ToList());

    public OkResult Hold(Ctx ctx, HoldSaveRequest r)
    {
        if (string.IsNullOrWhiteSpace(r.PayloadJson) || r.Lines <= 0) throw AppException.Validation("There is nothing to hold.");
        var id = db.Write((c, tx) =>
        {
            var newId = c.ExecuteScalar<long>(@"INSERT INTO holds(counter_no, workstation_id, user_id, customer_id, customer_name, note, lines, amount_paisa, payload_json, created_at, expires_at)
                VALUES(@cn,@ws,@u,@cid,@cname,@note,@lines,@amt,@payload,@t,@exp) RETURNING id",
                new { cn = r.CounterNo, ws = ctx.WorkstationId, u = ctx.UserId, cid = r.CustomerId, cname = r.CustomerName, note = r.Note, lines = r.Lines, amt = Money.ToPaisa(r.Amount), payload = r.PayloadJson,
                    t = Clock.NowIso(), exp = Clock.Iso(Clock.UtcNow.AddHours(24)) }, tx);
            Audit.Write(c, tx, ctx, "SALE_HELD", "sales", $"hold {newId}", after: $"{r.Lines} lines {Money.Format(r.Amount)}");
            return newId;
        });
        return new OkResult { Id = id, Message = "Sale held. Stock is not reserved while held." };
    }

    public HoldDto Recall(Ctx ctx, long id) => db.Write((c, tx) =>
    {
        var h = c.QuerySingleOrDefault("SELECT h.*, u.full_name AS user FROM holds h JOIN users u ON u.id=h.user_id WHERE h.id=@id", new { id }, tx) ?? throw AppException.NotFound("Held sale");
        c.Execute("DELETE FROM holds WHERE id=@id", new { id }, tx);
        Audit.Write(c, tx, ctx, "SALE_RECALLED", "sales", $"hold {id}");
        return new HoldDto { Id = h.id, CounterNo = (int)(long)h.counter_no, User = h.user, Customer = h.customer_name, Lines = (int)(long)h.lines, Amount = Money.FromPaisa((long)h.amount_paisa), Note = h.note, CreatedAt = Clock.ToPkt((string)h.created_at), PayloadJson = h.payload_json };
    });
}

public sealed class ReturnService(Db db, SettingsService settings)
{
    public SalesReturnResult Post(Ctx ctx, SalesReturnRequest r, List<string?> tokens)
    {
        var lines = r.Lines.Where(l => l.BaseQty > 0).ToList();
        if (lines.Count == 0) throw AppException.Validation("Enter a return quantity for at least one line.");
        if (!new[] { "CASH", "BANK", "CREDIT_NOTE" }.Contains(r.RefundMethod)) throw AppException.Validation("Refund method must be cash, bank or credit note.");
        var hash = Idempotency.HashOf(new { r.SaleId, r.Lines, r.RefundMethod, r.CashAccountId });
        return db.Write((c, tx) =>
        {
            var stored = Idempotency.Find<SalesReturnResult>(c, tx, r.IdempotencyKey, "sales-return", hash);
            if (stored != null) return stored;
            Guards.DayOpen(c, tx, ctx, tokens, r.IdempotencyKey);
            var h = c.QueryOne<(string no, string status, long customer_id, long credit, long settled, int counter)>(
                "SELECT no, status, customer_id, credit_paisa, settled_paisa, counter_no FROM sales_headers WHERE id=@id", new { id = r.SaleId }, tx) ?? throw AppException.NotFound("Original invoice");
            if (h.status != "POSTED") throw AppException.Conflict($"Invoice {h.no} was reversed; nothing can be returned.");
            var customer = c.QuerySingle<(string name, string type)>("SELECT name, type FROM customers WHERE id=@id", new { id = h.customer_id }, tx);
            var outstanding = h.credit - h.settled;
            var method = r.RefundMethod;
            if (outstanding > 0 && method != "CREDIT_NOTE") method = "CREDIT_NOTE";
            if (method == "CREDIT_NOTE" && customer.type == "WALKIN") throw AppException.Validation("A credit note needs a named customer. Refund walk-in customers in cash or bank.");
            var today = Clock.Today;

            var computed = new List<(SalesReturnLineInput input, dynamic item, long refund, long taxable, long tax, long cost, string outcome)>();
            foreach (var l in lines)
            {
                var item = c.QuerySingleOrDefault(@"SELECT si.*, b.expiry_date, b.batch_no FROM sales_items si JOIN batches b ON b.id=si.batch_id WHERE si.id=@id AND si.header_id=@sale", new { id = l.SaleItemId, sale = r.SaleId }, tx)
                           ?? throw AppException.Validation("A return line does not belong to this invoice.");
                var remaining = (long)item.base_qty - (long)item.returned_base_qty;
                if (l.BaseQty > remaining) throw AppException.Validation($"{item.medicine_name}: only {remaining} units can still be returned on this invoice.");
                if (l.Outcome != "RESTOCK" && l.Outcome != "QUARANTINE") throw AppException.Validation("Choose Restock or Quarantine for each line.");
                var prev = c.QuerySingle<(long refund, long taxable, long cost)>("SELECT IFNULL(SUM(refund_paisa),0), IFNULL(SUM(taxable_paisa),0), IFNULL(SUM(cost_paisa),0) FROM sales_return_items WHERE sales_item_id=@id", new { id = l.SaleItemId }, tx);
                long refund, taxable, cost;
                if (l.BaseQty == remaining)
                {
                    refund = (long)item.net_paisa - prev.refund;
                    taxable = (long)item.taxable_paisa - prev.taxable;
                    cost = (long)item.cogs_paisa - prev.cost;
                }
                else
                {
                    refund = Money.ToPaisa(Money.FromPaisa((long)item.net_paisa) * l.BaseQty / (long)item.base_qty);
                    taxable = Money.ToPaisa(Money.FromPaisa((long)item.taxable_paisa) * l.BaseQty / (long)item.base_qty);
                    cost = Money.ToPaisa(Costing.Cogs(l.BaseQty, Money.FromE4((long)item.cost_e4)));
                }
                var outcome = l.Outcome;
                if (!Fefo.IsSaleable(Clock.ParseDate((string)item.expiry_date), today)) outcome = "QUARANTINE";
                computed.Add((l, item, refund, taxable, refund - taxable, cost, outcome));
            }
            var refundTotal = computed.Sum(x => x.refund);
            long? approval = null;
            if (Money.FromPaisa(refundTotal) > settings.GetDecimal("approval.refundAbove", 500))
                approval = ApprovalService.Require(c, tx, ctx, tokens, ApprovalActions.Refund, r.IdempotencyKey, Money.FromPaisa(refundTotal));

            CashAccountRow? acct = method == "CREDIT_NOTE" ? null : CashAccounts.ForMethod(c, tx, method == "CASH" ? PaymentMethods.Cash : PaymentMethods.Bank, r.CashAccountId);
            if (acct != null) CashAccounts.EnsureFunds(c, tx, acct, refundTotal);
            var shift = acct == null ? null : CashAccounts.ShiftFor(c, tx, ctx, acct);
            var no = DocNumbers.Next(c, tx, settings, "salesReturn", ctx.CounterNo > 0 ? ctx.CounterNo : h.counter);
            var retId = c.ExecuteScalar<long>(@"INSERT INTO sales_returns(no, idempotency_key, sale_id, at, business_date, user_id, refund_method, cash_account_id, refund_paisa, approval_id, shift_id)
                VALUES(@no,@key,@sale,@t,@bd,@u,@m,@acct,@refund,@a,@shift) RETURNING id",
                new { no, key = r.IdempotencyKey, sale = r.SaleId, t = Clock.NowIso(), bd = Clock.BusinessDate(), u = ctx.UserId, m = method, acct = acct?.Id, refund = refundTotal, a = approval, shift }, tx);
            long toSaleable = 0, toQuarantine = 0;
            foreach (var x in computed)
            {
                var itemId = c.ExecuteScalar<long>(@"INSERT INTO sales_return_items(return_id, sales_item_id, batch_id, base_qty, outcome, reason, refund_paisa, taxable_paisa, tax_paisa, cost_e4, cost_paisa)
                    VALUES(@ret,@si,@b,@q,@o,@reason,@refund,@taxable,@tax,@ce4,@cost) RETURNING id",
                    new { ret = retId, si = x.input.SaleItemId, b = (long)x.item.batch_id, q = x.input.BaseQty, o = x.outcome, reason = x.input.Reason, x.refund, x.taxable, x.tax, ce4 = (long)x.item.cost_e4, x.cost }, tx);
                c.Execute("UPDATE sales_items SET returned_base_qty = returned_base_qty + @q WHERE id=@id", new { q = x.input.BaseQty, id = x.input.SaleItemId }, tx);
                var state = x.outcome == "RESTOCK" ? StockStates.Saleable : StockStates.Quarantine;
                StockLedger.Move(c, tx, ctx, (long)x.item.batch_id, state, x.input.BaseQty, "SR", retId, itemId, no, (long)x.item.cost_e4);
                StockLedger.CostIn(c, tx, (long)x.item.medicine_id, x.input.BaseQty, Money.FromPaisa(x.cost));
                if (state == StockStates.Saleable) toSaleable += x.cost; else toQuarantine += x.cost;
            }
            var j = new Journal()
                .Dr(Acct.SalesReturns, computed.Sum(x => x.taxable))
                .Dr(Acct.TaxPayable, computed.Sum(x => x.tax));
            if (acct == null) j.Cr(Acct.Receivables, refundTotal, "C", h.customer_id);
            else j.CrAccount(acct.AccountId, refundTotal, shift, "Refund");
            j.Dr(Acct.Inventory, toSaleable).Dr(Acct.InventoryQuarantine, toQuarantine).Cr(Acct.Cogs, toSaleable + toQuarantine);
            var je = j.Post(c, tx, settings, ctx, "SR", retId, no, $"Return against {h.no}");
            c.Execute("UPDATE sales_returns SET journal_entry_id=@je WHERE id=@retId", new { je, retId }, tx);
            if (method == "CREDIT_NOTE" && outstanding > 0)
            {
                var alloc = Math.Min(outstanding, refundTotal);
                c.Execute("INSERT INTO receipt_allocations(return_id, sale_id, amount_paisa) VALUES(@retId,@sale,@alloc)", new { retId, sale = r.SaleId, alloc }, tx);
                c.Execute("UPDATE sales_headers SET settled_paisa = settled_paisa + @alloc WHERE id=@sale", new { alloc, sale = r.SaleId }, tx);
            }
            Audit.Write(c, tx, ctx, "SALES_RETURN", "sales", no, after: $"against {h.no}: refund {Money.Format(Money.FromPaisa(refundTotal))} by {method}; " +
                string.Join("; ", computed.Select(x => $"{x.item.medicine_name} x{x.input.BaseQty} {x.outcome}")), reason: string.Join("; ", lines.Select(l => l.Reason).Where(s => s.Length > 0)),
                approver: ApprovalService.ApproverName(c, tx, approval));

            var receipt = new ReceiptDto
            {
                Title = "Sales Return", PharmacyName = settings.Get("pharmacy.name"), Address = settings.Get("pharmacy.address"), Phone = settings.Get("pharmacy.phone"), LicenseNo = settings.Get("pharmacy.license"),
                DocNo = no, At = Clock.NowPkt, Cashier = ctx.FullName, CounterNo = ctx.CounterNo, Customer = customer.name, Net = Money.FromPaisa(refundTotal),
                Note = $"Against {h.no}. Refund by {(method == "CREDIT_NOTE" ? "credit note to customer account" : method.ToLowerInvariant())}.",
                Lines = computed.Select(x => new ReceiptLine { Name = (string)x.item.medicine_name, QtyText = $"{x.input.BaseQty} units", Amount = Money.FromPaisa(x.refund), BatchNo = (string)x.item.batch_no }).ToList(),
                Payments = new List<KeyValuePair<string, decimal>> { new(method, Money.FromPaisa(refundTotal)) },
            };
            var result = new SalesReturnResult { Id = retId, No = no, Refund = Money.FromPaisa(refundTotal), Receipt = receipt };
            Idempotency.Save(c, tx, r.IdempotencyKey, "sales-return", hash, result);
            return result;
        });
    }
}
