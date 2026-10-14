using Dapper;
using MediCare.Contracts;
using MediCare.Core;
using MediCare.Host.Domain;
using MediCare.Host.Infrastructure;
using MediCare.Host.Security;
using Microsoft.Data.Sqlite;

namespace MediCare.Host.Modules;

public static class HttpExtras
{
    /// <summary>Approval tokens can come in the body or in the X-Approval-Token header (comma separated).</summary>
    public static List<string?> Tokens(this HttpContext http, params string?[] fromBody)
    {
        var list = fromBody.Where(t => !string.IsNullOrWhiteSpace(t)).ToList();
        foreach (var h in http.Request.Headers["X-Approval-Token"]) list.AddRange((h ?? "").Split(',', StringSplitOptions.RemoveEmptyEntries | StringSplitOptions.TrimEntries));
        return list;
    }
}

public static class PartyModule
{
    public static void Map(WebApplication app)
    {
        var g = app.MapGroup("/api/v1");
        g.MapGet("/suppliers", (string? q, bool? includeInactive, PartyService s) => s.Suppliers(q, includeInactive ?? false)).Authenticated();
        g.MapGet("/suppliers/{id:long}", (long id, PartyService s) => s.Supplier(id)).Authenticated();
        g.MapPost("/suppliers", (HttpContext http, SupplierDto d, PartyService s) => s.SaveSupplier(http.Ctx(), null, d)).Perm(Perm.SuppliersEdit);
        g.MapPut("/suppliers/{id:long}", (long id, HttpContext http, SupplierDto d, PartyService s) => s.SaveSupplier(http.Ctx(), id, d)).Perm(Perm.SuppliersEdit);
        g.MapPost("/suppliers/{id:long}/opening", (long id, HttpContext http, OpeningBalanceRequest r, PartyService s) => s.Opening(http.Ctx(), "S", id, r.Amount)).Perm(Perm.SuppliersEdit);
        g.MapGet("/suppliers/{id:long}/ledger", (long id, DateTime? from, DateTime? to, PartyService s) => s.Ledger("S", id, from, to)).Perm(Perm.SuppliersView);
        g.MapGet("/suppliers/{id:long}/outstanding", (long id, PartyService s) => s.SupplierOutstanding(id)).Perm(Perm.SuppliersView);
        g.MapPost("/supplier-payments", (HttpContext http, SupplierPaymentRequest r, PartyService s) => s.PaySupplier(http.Ctx(), r, http.Tokens())).Perm(Perm.SupplierPaymentsPost);
        g.MapPost("/supplier-refunds", (HttpContext http, SupplierRefundRequest r, PartyService s) => s.SupplierRefund(http.Ctx(), r, http.Tokens())).Perm(Perm.SupplierPaymentsPost);

        g.MapGet("/customers", (string? q, bool? includeInactive, PartyService s) => s.Customers(q, includeInactive ?? false)).Authenticated();
        g.MapGet("/customers/{id:long}", (long id, PartyService s) => s.Customer(id)).Authenticated();
        g.MapPost("/customers", (HttpContext http, CustomerDto d, PartyService s) => s.SaveCustomer(http.Ctx(), null, d)).Perm(Perm.CustomersEdit);
        g.MapPut("/customers/{id:long}", (long id, HttpContext http, CustomerDto d, PartyService s) => s.SaveCustomer(http.Ctx(), id, d)).Perm(Perm.CustomersEdit);
        g.MapPost("/customers/{id:long}/opening", (long id, HttpContext http, OpeningBalanceRequest r, PartyService s) => s.Opening(http.Ctx(), "C", id, r.Amount)).Perm(Perm.CustomersEdit);
        g.MapGet("/customers/{id:long}/ledger", (long id, DateTime? from, DateTime? to, PartyService s) => s.Ledger("C", id, from, to)).Perm(Perm.CustomersView);
        g.MapGet("/customers/{id:long}/outstanding", (long id, PartyService s) => s.CustomerOutstanding(id)).Perm(Perm.CustomersView);
        g.MapPost("/receipts", (HttpContext http, ReceiptRequest r, PartyService s) => s.Receive(http.Ctx(), r, http.Tokens())).Perm(Perm.ReceiptsPost);
    }
}

public sealed class PartyService(Db db, SettingsService settings)
{
    private static string Digits(string s) => new(s.Where(char.IsDigit).ToArray());

    public static long CustomerBalance(SqliteConnection c, SqliteTransaction? tx, long id) =>
        c.ExecuteScalar<long>("SELECT IFNULL(SUM(jl.debit_paisa - jl.credit_paisa),0) FROM journal_lines jl JOIN accounts a ON a.id=jl.account_id WHERE a.code='1100' AND jl.party_type='C' AND jl.party_id=@id", new { id }, tx);

    public static long SupplierBalance(SqliteConnection c, SqliteTransaction? tx, long id) =>
        c.ExecuteScalar<long>("SELECT IFNULL(SUM(jl.credit_paisa - jl.debit_paisa),0) FROM journal_lines jl JOIN accounts a ON a.id=jl.account_id WHERE a.code='2000' AND jl.party_type='S' AND jl.party_id=@id", new { id }, tx);

    // ---------- suppliers ----------
    public List<SupplierDto> Suppliers(string? q, bool includeInactive) => db.Read(c =>
        c.Query("SELECT * FROM suppliers WHERE (@all=1 OR is_active=1) AND (@q IS NULL OR name LIKE '%'||@q||'%' OR code LIKE @q||'%' OR mobile LIKE '%'||@q||'%') ORDER BY name LIMIT 500",
            new { all = includeInactive ? 1 : 0, q = string.IsNullOrWhiteSpace(q) ? null : q.Trim() }).Select(r => (SupplierDto)MapSupplier(c, r)).ToList());

    public SupplierDto Supplier(long id) => db.Read(c => MapSupplier(c, c.QuerySingleOrDefault("SELECT * FROM suppliers WHERE id=@id", new { id }) ?? throw AppException.NotFound("Supplier")));

    private static SupplierDto MapSupplier(SqliteConnection c, dynamic r) => new()
    {
        Id = r.id, Code = r.code, Name = r.name, Contact = r.contact, Mobile = r.mobile, Address = r.address, TaxNo = r.tax_no, TermsDays = (int)(long)r.terms_days,
        IsActive = r.is_active == 1, Notes = r.notes, OpeningBalance = Money.FromPaisa((long)r.opening_paisa), OpeningPosted = r.opening_posted == 1,
        Balance = Money.FromPaisa(SupplierBalance(c, null, (long)r.id)),
    };

    public OkResult SaveSupplier(Ctx ctx, long? id, SupplierDto d)
    {
        if (string.IsNullOrWhiteSpace(d.Name)) throw AppException.Validation("Supplier name is required.");
        if (d.TermsDays < 0 || d.TermsDays > 365) throw AppException.Validation("Payment terms must be 0 to 365 days.");
        var newId = db.Write((c, tx) =>
        {
            var p = new { n = d.Name.Trim(), ct = d.Contact.Trim(), m = d.Mobile.Trim(), a = d.Address.Trim(), tn = d.TaxNo.Trim(), t = d.TermsDays, act = d.IsActive ? 1 : 0, notes = d.Notes.Trim() };
            if (id == null)
            {
                var sid = c.ExecuteScalar<long>(@"INSERT INTO suppliers(code, name, contact, mobile, address, tax_no, terms_days, is_active, notes, created_at)
                    VALUES('TMP-'||hex(randomblob(6)),@n,@ct,@m,@a,@tn,@t,@act,@notes,@now) RETURNING id", new { p.n, p.ct, p.m, p.a, p.tn, p.t, p.act, p.notes, now = Clock.NowIso() }, tx);
                c.Execute("UPDATE suppliers SET code=@code WHERE id=@sid", new { code = $"SUP-{sid:000}", sid }, tx);
                Audit.Write(c, tx, ctx, "SUPPLIER_CREATED", "suppliers", d.Name);
                return sid;
            }
            var before = c.QuerySingleOrDefault("SELECT name, mobile, is_active FROM suppliers WHERE id=@id", new { id }, tx) ?? throw AppException.NotFound("Supplier");
            c.Execute("UPDATE suppliers SET name=@n, contact=@ct, mobile=@m, address=@a, tax_no=@tn, terms_days=@t, is_active=@act, notes=@notes WHERE id=@id", new { p.n, p.ct, p.m, p.a, p.tn, p.t, p.act, p.notes, id }, tx);
            Audit.Write(c, tx, ctx, "SUPPLIER_CHANGED", "suppliers", d.Name, Json.Serialize(before), Json.Serialize(p));
            return id.Value;
        });
        return new OkResult { Id = newId, Message = "Supplier saved." };
    }

    // ---------- customers ----------
    public List<CustomerDto> Customers(string? q, bool includeInactive) => db.Read(c =>
    {
        var text = string.IsNullOrWhiteSpace(q) ? null : q.Trim();
        var digits = text == null ? null : Digits(text);
        return c.Query(@"SELECT * FROM customers WHERE (@all=1 OR is_active=1) AND (@q IS NULL OR name LIKE '%'||@q||'%' OR code LIKE @q||'%' OR (@d <> '' AND mobile_digits LIKE '%'||@d||'%'))
                         ORDER BY CASE type WHEN 'WALKIN' THEN 0 ELSE 1 END, name LIMIT 500",
            new { all = includeInactive ? 1 : 0, q = text, d = digits ?? "" }).Select(r => (CustomerDto)MapCustomer(c, r)).ToList();
    });

    public CustomerDto Customer(long id) => db.Read(c => MapCustomer(c, c.QuerySingleOrDefault("SELECT * FROM customers WHERE id=@id", new { id }) ?? throw AppException.NotFound("Customer")));

    private static CustomerDto MapCustomer(SqliteConnection c, dynamic r) => new()
    {
        Id = r.id, Code = r.code, Name = r.name, Mobile = r.mobile, Address = r.address, Type = r.type, CreditLimit = Money.FromPaisa((long)r.credit_limit_paisa),
        IsActive = r.is_active == 1, OpeningBalance = Money.FromPaisa((long)r.opening_paisa), OpeningPosted = r.opening_posted == 1,
        Balance = Money.FromPaisa(CustomerBalance(c, null, (long)r.id)),
    };

    public OkResult SaveCustomer(Ctx ctx, long? id, CustomerDto d)
    {
        if (string.IsNullOrWhiteSpace(d.Name)) throw AppException.Validation("Customer name is required.");
        if (!new[] { "REGULAR", "CREDIT" }.Contains(d.Type)) throw AppException.Validation("Customer type must be Regular or Credit.");
        if (d.CreditLimit < 0) throw AppException.Validation("Credit limit cannot be negative.");
        var digits = Digits(d.Mobile ?? "");
        var newId = db.Write((c, tx) =>
        {
            if (id != null && c.ExecuteScalar<string?>("SELECT type FROM customers WHERE id=@id", new { id }, tx) == "WALKIN")
                throw AppException.Validation("The Walk-in customer cannot be edited.");
            if (digits.Length >= 7)
            {
                var other = c.QueryOne<(long id, string name, string code)>("SELECT id, name, code FROM customers WHERE mobile_digits=@digits AND id<>@id", new { digits, id = id ?? 0 }, tx);
                if (other != null) throw AppException.Conflict($"Mobile {d.Mobile} already belongs to {other.Value.name} ({other.Value.code}).");
            }
            var p = new { n = d.Name.Trim(), m = (d.Mobile ?? "").Trim(), md = digits, a = (d.Address ?? "").Trim(), t = d.Type, l = Money.ToPaisa(d.CreditLimit), act = d.IsActive ? 1 : 0 };
            if (id == null)
            {
                var cid = c.ExecuteScalar<long>(@"INSERT INTO customers(code, name, mobile, mobile_digits, address, type, credit_limit_paisa, is_active, created_at)
                    VALUES('TMP-'||hex(randomblob(6)),@n,@m,@md,@a,@t,@l,@act,@now) RETURNING id", new { p.n, p.m, p.md, p.a, p.t, p.l, p.act, now = Clock.NowIso() }, tx);
                c.Execute("UPDATE customers SET code=@code WHERE id=@cid", new { code = $"C-{cid:0000}", cid }, tx);
                Audit.Write(c, tx, ctx, "CUSTOMER_CREATED", "customers", d.Name, after: $"{d.Type} limit {d.CreditLimit}");
                return cid;
            }
            var before = c.QuerySingleOrDefault("SELECT name, type, credit_limit_paisa FROM customers WHERE id=@id", new { id }, tx) ?? throw AppException.NotFound("Customer");
            c.Execute("UPDATE customers SET name=@n, mobile=@m, mobile_digits=@md, address=@a, type=@t, credit_limit_paisa=@l, is_active=@act WHERE id=@id", new { p.n, p.m, p.md, p.a, p.t, p.l, p.act, id }, tx);
            Audit.Write(c, tx, ctx, "CUSTOMER_CHANGED", "customers", d.Name, Json.Serialize(before), Json.Serialize(p));
            return id.Value;
        });
        return new OkResult { Id = newId, Message = "Customer saved." };
    }

    // ---------- opening balances (FR-050, FR-055) ----------
    public OkResult Opening(Ctx ctx, string partyType, long id, decimal amount)
    {
        if (amount <= 0) throw AppException.Validation("Opening balance must be more than zero.");
        var table = partyType == "S" ? "suppliers" : "customers";
        db.Write((c, tx) =>
        {
            var row = c.QueryOne<(string name, long posted, string? type)>($"SELECT name, opening_posted, {(partyType == "C" ? "type" : "NULL")} FROM {table} WHERE id=@id", new { id }, tx) ?? throw AppException.NotFound("Party");
            if (row.posted == 1) throw AppException.Conflict("The opening balance was already posted. Correct it with a receipt, payment or adjustment.");
            if (row.type == "WALKIN") throw AppException.Validation("Walk-in cannot have a balance.");
            var paisa = Money.ToPaisa(amount);
            var j = new Journal();
            if (partyType == "S") j.Dr(Acct.OpeningEquity, paisa).Cr(Acct.Payables, paisa, "S", id);
            else j.Dr(Acct.Receivables, paisa, "C", id).Cr(Acct.OpeningEquity, paisa);
            j.Post(c, tx, settings, ctx, "OPEN", id, "OPENING", $"Opening balance {row.name}");
            c.Execute($"UPDATE {table} SET opening_paisa=@paisa, opening_posted=1 WHERE id=@id", new { paisa, id }, tx);
            Audit.Write(c, tx, ctx, "OPENING_BALANCE", table, row.name, after: Money.Format(amount));
        });
        return new OkResult { Message = "Opening balance posted." };
    }

    // ---------- ledgers ----------
    private static string Describe(string sourceType) => sourceType switch
    {
        "SI" => "Sale", "SR" => "Sales return", "CR" => "Receipt", "OPEN" => "Opening balance", "PI" => "Purchase", "PR" => "Purchase return",
        "SP" => "Payment", "SRF" => "Supplier refund", "REV" => "Reversal", _ => sourceType,
    };

    public LedgerResult Ledger(string partyType, long id, DateTime? from, DateTime? to)
    {
        var f = from ?? Clock.Today.AddMonths(-1);
        var t = to ?? Clock.Today;
        var code = partyType == "S" ? "2000" : "1100";
        var sign = partyType == "S" ? -1 : 1;
        return db.Read(c =>
        {
            var name = c.ExecuteScalar<string?>(partyType == "S" ? "SELECT name FROM suppliers WHERE id=@id" : "SELECT name FROM customers WHERE id=@id", new { id }) ?? throw AppException.NotFound("Party");
            var fromText = Clock.DateText(f);
            var toText = Clock.DateText(t);
            var opening = sign * c.ExecuteScalar<long>(@"SELECT IFNULL(SUM(jl.debit_paisa - jl.credit_paisa),0) FROM journal_lines jl JOIN journal_entries je ON je.id=jl.entry_id
                JOIN accounts a ON a.id=jl.account_id WHERE a.code=@code AND jl.party_type=@pt AND jl.party_id=@id AND je.entry_date < @fromText", new { code, pt = partyType, id, fromText });
            var result = new LedgerResult { Title = name, From = f, To = t, Opening = Money.FromPaisa(opening), BalanceSide = partyType == "S" ? "Cr" : "Dr" };
            var bal = opening;
            foreach (var r in c.Query<(string at, string doc_no, string source_type, string memo, long debit, long credit)>(@"SELECT je.at, je.doc_no, je.source_type, je.memo, jl.debit_paisa, jl.credit_paisa
                FROM journal_lines jl JOIN journal_entries je ON je.id=jl.entry_id JOIN accounts a ON a.id=jl.account_id
                WHERE a.code=@code AND jl.party_type=@pt AND jl.party_id=@id AND je.entry_date >= @fromText AND je.entry_date <= @toText ORDER BY je.id", new { code, pt = partyType, id, fromText, toText }))
            {
                bal += sign * (r.debit - r.credit);
                result.Lines.Add(new LedgerLine { At = Clock.ToPkt(r.at), DocNo = r.doc_no, Details = r.memo.Length > 0 ? r.memo : Describe(r.source_type), Debit = Money.FromPaisa(r.debit), Credit = Money.FromPaisa(r.credit), Balance = Money.FromPaisa(bal) });
            }
            result.Closing = Money.FromPaisa(bal);
            return result;
        });
    }

    public List<OutstandingDoc> CustomerOutstanding(long id) => db.Read(c => c.Query<(long id, string no, string business_date, long credit, long settled)>(
            "SELECT id, no, business_date, credit_paisa, settled_paisa FROM sales_headers WHERE customer_id=@id AND status='POSTED' AND credit_paisa > settled_paisa ORDER BY id", new { id })
        .Select(r => new OutstandingDoc { Id = r.id, No = r.no, Date = Clock.ParseDate(r.business_date), Total = Money.FromPaisa(r.credit), Outstanding = Money.FromPaisa(r.credit - r.settled) }).ToList());

    public List<OutstandingDoc> SupplierOutstanding(long id) => db.Read(c => c.Query<(long id, string no, string inv_date, string? due_date, long net, long paid, long returned)>(
            "SELECT id, no, inv_date, due_date, net_paisa, paid_paisa, returned_paisa FROM purchases WHERE supplier_id=@id AND status='POSTED' AND is_opening=0 AND net_paisa > paid_paisa + returned_paisa ORDER BY inv_date, id", new { id })
        .Select(r => new OutstandingDoc { Id = r.id, No = r.no, Date = Clock.ParseDate(r.inv_date), DueDate = Clock.ParseDateOrNull(r.due_date), Total = Money.FromPaisa(r.net), Outstanding = Money.FromPaisa(r.net - r.paid - r.returned) }).ToList());

    // ---------- customer receipts (FR-058) ----------
    public VoucherResult Receive(Ctx ctx, ReceiptRequest r, List<string?> tokens)
    {
        if (r.Amount <= 0) throw AppException.Validation("Receipt amount must be more than zero.");
        if (r.Method == PaymentMethods.Credit) throw AppException.Validation("A receipt cannot be paid on credit.");
        var hash = Idempotency.HashOf(r);
        return db.Write((c, tx) =>
        {
            var stored = Idempotency.Find<VoucherResult>(c, tx, r.IdempotencyKey, "receipt", hash);
            if (stored != null) return stored;
            Guards.DayOpen(c, tx, ctx, tokens, r.IdempotencyKey);
            var cust = c.QueryOne<(string name, string type, string code)>("SELECT name, type, code FROM customers WHERE id=@id", new { id = r.CustomerId }, tx) ?? throw AppException.NotFound("Customer");
            if (cust.type == "WALKIN") throw AppException.Validation("Choose a named customer for a receipt.");
            var amount = Money.ToPaisa(r.Amount);
            var allocations = r.Allocations.Where(a => a.Amount > 0).ToList();
            if (Money.ToPaisa(allocations.Sum(a => a.Amount)) > amount) throw AppException.Validation("Allocated amount is more than the amount received. Reduce an allocation or leave the extra as customer credit.");
            var acct = CashAccounts.ForMethod(c, tx, r.Method, r.CashAccountId);
            var shift = CashAccounts.ShiftFor(c, tx, ctx, acct);
            var no = DocNumbers.Next(c, tx, settings, "receipt");
            var id = c.ExecuteScalar<long>(@"INSERT INTO customer_receipts(no, idempotency_key, customer_id, at, business_date, amount_paisa, method, cash_account_id, reference, notes, user_id, shift_id)
                VALUES(@no,@key,@cid,@at,@bd,@amt,@m,@acct,@ref,@notes,@u,@shift) RETURNING id",
                new { no, key = r.IdempotencyKey, cid = r.CustomerId, at = Clock.NowIso(), bd = Clock.BusinessDate(), amt = amount, m = r.Method, acct = acct.Id, @ref = r.Reference, notes = r.Notes, u = ctx.UserId, shift }, tx);
            foreach (var a in allocations)
            {
                var s = c.QueryOne<(long customer_id, long credit, long settled, string no)>("SELECT customer_id, credit_paisa, settled_paisa, no FROM sales_headers WHERE id=@id AND status='POSTED'", new { id = a.DocId }, tx)
                        ?? throw AppException.Validation("An allocated invoice was not found.");
                if (s.customer_id != r.CustomerId) throw AppException.Validation($"Invoice {s.no} belongs to another customer.");
                var p = Money.ToPaisa(a.Amount);
                if (p > s.credit - s.settled) throw AppException.Validation($"Allocation to {s.no} is more than its outstanding {Money.Format(Money.FromPaisa(s.credit - s.settled))}.");
                c.Execute("INSERT INTO receipt_allocations(receipt_id, sale_id, amount_paisa) VALUES(@id,@sid,@p)", new { id, sid = a.DocId, p }, tx);
                c.Execute("UPDATE sales_headers SET settled_paisa = settled_paisa + @p WHERE id=@sid", new { p, sid = a.DocId }, tx);
            }
            var je = new Journal().DrAccount(acct.AccountId, amount, shift, "Customer receipt").Cr(Acct.Receivables, amount, "C", r.CustomerId)
                .Post(c, tx, settings, ctx, "CR", id, no, $"Receipt from {cust.name}");
            c.Execute("UPDATE customer_receipts SET journal_entry_id=@je WHERE id=@id", new { je, id }, tx);
            Audit.Write(c, tx, ctx, "RECEIPT_POSTED", "receipts", no, after: $"{cust.name} {Money.Format(r.Amount)} {r.Method}");
            var balance = Money.FromPaisa(CustomerBalance(c, tx, r.CustomerId));
            var result = new VoucherResult
            {
                Id = id, No = no, Amount = r.Amount,
                Print = Voucher(ctx, "Payment Receipt", no, cust.name, r.Method, r.Amount, r.Reference, $"Balance now Rs {Money.Format(balance)}"),
            };
            result.Print.CustomerBalance = balance;
            Idempotency.Save(c, tx, r.IdempotencyKey, "receipt", hash, result);
            return result;
        });
    }

    internal ReceiptDto Voucher(Ctx ctx, string title, string no, string party, string method, decimal amount, string reference, string note) => new()
    {
        Title = title, PharmacyName = settings.Get("pharmacy.name"), Address = settings.Get("pharmacy.address"), Phone = settings.Get("pharmacy.phone"),
        LicenseNo = settings.Get("pharmacy.license"), DocNo = no, At = Clock.NowPkt, Cashier = ctx.FullName, CounterNo = ctx.CounterNo, Customer = party,
        Lines = new List<ReceiptLine> { new() { Name = string.IsNullOrEmpty(reference) ? method : $"{method} ({reference})", QtyText = "", Amount = amount } },
        Net = amount, Payments = new List<KeyValuePair<string, decimal>> { new(method, amount) }, Note = note,
    };

    // ---------- supplier payments (FR-052) ----------
    public VoucherResult PaySupplier(Ctx ctx, SupplierPaymentRequest r, List<string?> tokens)
    {
        if (r.Amount <= 0) throw AppException.Validation("Payment amount must be more than zero.");
        var hash = Idempotency.HashOf(r);
        return db.Write((c, tx) =>
        {
            var stored = Idempotency.Find<VoucherResult>(c, tx, r.IdempotencyKey, "supplier-payment", hash);
            if (stored != null) return stored;
            Guards.DayOpen(c, tx, ctx, tokens, r.IdempotencyKey);
            var name = c.ExecuteScalar<string?>("SELECT name FROM suppliers WHERE id=@id", new { id = r.SupplierId }, tx) ?? throw AppException.NotFound("Supplier");
            var amount = Money.ToPaisa(r.Amount);
            var acct = CashAccounts.Get(c, tx, r.CashAccountId);
            CashAccounts.EnsureFunds(c, tx, acct, amount);
            var allocations = r.Allocations.Where(a => a.Amount > 0).ToList();
            if (Money.ToPaisa(allocations.Sum(a => a.Amount)) > amount) throw AppException.Validation("Allocated amount is more than the payment.");
            var shift = CashAccounts.ShiftFor(c, tx, ctx, acct);
            var id = InsertPayment(c, tx, ctx, settings, "PAYMENT", r.IdempotencyKey, r.SupplierId, amount, acct, r.Reference, r.Notes, out var no);
            foreach (var a in allocations)
            {
                var p = c.QueryOne<(long supplier_id, long net, long paid, long returned, string no)>("SELECT supplier_id, net_paisa, paid_paisa, returned_paisa, no FROM purchases WHERE id=@id AND status='POSTED'", new { id = a.DocId }, tx)
                        ?? throw AppException.Validation("An allocated purchase was not found.");
                if (p.supplier_id != r.SupplierId) throw AppException.Validation($"Purchase {p.no} belongs to another supplier.");
                var amt = Money.ToPaisa(a.Amount);
                if (amt > p.net - p.paid - p.returned) throw AppException.Validation($"Allocation to {p.no} is more than its outstanding.");
                c.Execute("INSERT INTO payment_allocations(payment_id, purchase_id, amount_paisa) VALUES(@id,@pid,@amt)", new { id, pid = a.DocId, amt }, tx);
                c.Execute("UPDATE purchases SET paid_paisa = paid_paisa + @amt WHERE id=@pid", new { amt, pid = a.DocId }, tx);
            }
            var je = new Journal().Dr(Acct.Payables, amount, "S", r.SupplierId).CrAccount(acct.AccountId, amount, shift, "Supplier payment")
                .Post(c, tx, settings, ctx, "SP", id, no, $"Payment to {name}");
            c.Execute("UPDATE supplier_payments SET journal_entry_id=@je WHERE id=@id", new { je, id }, tx);
            Audit.Write(c, tx, ctx, "SUPPLIER_PAYMENT", "payments", no, after: $"{name} {Money.Format(r.Amount)} from {acct.Name}");
            var balance = Money.FromPaisa(SupplierBalance(c, tx, r.SupplierId));
            var result = new VoucherResult { Id = id, No = no, Amount = r.Amount, Print = Voucher(ctx, "Payment Voucher", no, name, acct.Name, r.Amount, r.Reference, $"Supplier balance now Rs {Money.Format(balance)}") };
            Idempotency.Save(c, tx, r.IdempotencyKey, "supplier-payment", hash, result);
            return result;
        });
    }

    internal static long InsertPayment(SqliteConnection c, SqliteTransaction tx, Ctx ctx, SettingsService settings, string kind, string key, long supplierId, long amount, CashAccountRow acct, string reference, string notes, out string no)
    {
        no = DocNumbers.Next(c, tx, settings, "payment");
        return c.ExecuteScalar<long>(@"INSERT INTO supplier_payments(no, idempotency_key, supplier_id, kind, at, business_date, amount_paisa, cash_account_id, reference, notes, user_id)
            VALUES(@no,@key,@sid,@kind,@at,@bd,@amt,@acct,@ref,@notes,@u) RETURNING id",
            new { no, key, sid = supplierId, kind, at = Clock.NowIso(), bd = Clock.BusinessDate(), amt = amount, acct = acct.Id, @ref = reference, notes, u = ctx.UserId }, tx);
    }

    public VoucherResult SupplierRefund(Ctx ctx, SupplierRefundRequest r, List<string?> tokens)
    {
        if (r.Amount <= 0) throw AppException.Validation("Refund amount must be more than zero.");
        var hash = Idempotency.HashOf(r);
        return db.Write((c, tx) =>
        {
            var stored = Idempotency.Find<VoucherResult>(c, tx, r.IdempotencyKey, "supplier-refund", hash);
            if (stored != null) return stored;
            Guards.DayOpen(c, tx, ctx, tokens, r.IdempotencyKey);
            var name = c.ExecuteScalar<string?>("SELECT name FROM suppliers WHERE id=@id", new { id = r.SupplierId }, tx) ?? throw AppException.NotFound("Supplier");
            var amount = Money.ToPaisa(r.Amount);
            var acct = CashAccounts.Get(c, tx, r.CashAccountId);
            var shift = CashAccounts.ShiftFor(c, tx, ctx, acct);
            var id = InsertPayment(c, tx, ctx, settings, "REFUND", r.IdempotencyKey, r.SupplierId, amount, acct, r.Reference, "Refund received", out var no);
            var je = new Journal().DrAccount(acct.AccountId, amount, shift, "Supplier refund").Cr(Acct.Payables, amount, "S", r.SupplierId)
                .Post(c, tx, settings, ctx, "SRF", id, no, $"Refund from {name}");
            c.Execute("UPDATE supplier_payments SET journal_entry_id=@je WHERE id=@id", new { je, id }, tx);
            Audit.Write(c, tx, ctx, "SUPPLIER_REFUND", "payments", no, after: $"{name} {Money.Format(r.Amount)}");
            var result = new VoucherResult { Id = id, No = no, Amount = r.Amount, Print = Voucher(ctx, "Supplier Refund", no, name, acct.Name, r.Amount, r.Reference, "") };
            Idempotency.Save(c, tx, r.IdempotencyKey, "supplier-refund", hash, result);
            return result;
        });
    }
}
