using Dapper;
using MediCare.Contracts;
using MediCare.Core;
using MediCare.Host.Domain;
using MediCare.Host.Infrastructure;
using MediCare.Host.Security;
using Microsoft.Data.Sqlite;

namespace MediCare.Host.Modules;

public static class AccountModule
{
    public static void Map(WebApplication app)
    {
        var g = app.MapGroup("/api/v1");
        g.MapGet("/cash/{id:long}/book", (long id, DateTime? from, DateTime? to, AccountService s) => s.Book(id, from, to)).Perm(Perm.CashView);
        g.MapPost("/cash/entries", (HttpContext http, CashEntryRequest r, AccountService s) => s.Entry(http.Ctx(), r, http.Tokens())).Perm(Perm.CashPost);
        g.MapPost("/cash/transfers", (HttpContext http, TransferRequest r, AccountService s) => s.Transfer(http.Ctx(), r, http.Tokens())).Perm(Perm.CashPost);
        g.MapGet("/expenses", (DateTime? from, DateTime? to, AccountService s) => s.Expenses(from, to)).Perm(Perm.CashView);
        g.MapPost("/expenses", (HttpContext http, ExpenseRequest r, AccountService s) => s.Expense(http.Ctx(), r, http.Tokens(r.ApprovalToken))).Perm(Perm.ExpensesPost);
        g.MapGet("/shifts/current", (HttpContext http, AccountService s) => s.CurrentShift(http.Ctx())).Authenticated();
        g.MapGet("/shifts", (DateTime? date, AccountService s) => s.Shifts(date)).Perm(Perm.CashView);
        g.MapPost("/shifts/open", (HttpContext http, OpenShiftRequest r, AccountService s) => s.OpenShift(http.Ctx(), r)).Perm(Perm.ShiftClose);
        g.MapPost("/shifts/{id:long}/close", (long id, HttpContext http, CloseShiftRequest r, AccountService s) => s.CloseShift(http.Ctx(), id, r, http.Tokens(r.ApprovalToken))).Perm(Perm.ShiftClose);
        g.MapGet("/day/status", (AccountService s) => s.DayStatus()).Authenticated();
        g.MapPost("/day/close", (HttpContext http, AccountService s, EventBus bus) => { var r = s.CloseDay(http.Ctx()); bus.Publish(EventTypes.SettingsChanged); return r; }).Perm(Perm.DayClose);
    }
}

public sealed class AccountService(Db db, SettingsService settings)
{
    public LedgerResult Book(long cashAccountId, DateTime? from, DateTime? to)
    {
        var f = from ?? Clock.Today;
        var t = to ?? Clock.Today;
        return db.Read(c =>
        {
            var acct = CashAccounts.Get(c, null, cashAccountId);
            var opening = c.ExecuteScalar<long>(@"SELECT IFNULL(SUM(jl.debit_paisa - jl.credit_paisa),0) FROM journal_lines jl JOIN journal_entries je ON je.id=jl.entry_id
                WHERE jl.account_id=@a AND je.entry_date < @f", new { a = acct.AccountId, f = Clock.DateText(f) });
            var result = new LedgerResult { Title = acct.Name, From = f, To = t, Opening = Money.FromPaisa(opening), BalanceSide = "Dr" };
            var bal = opening;
            var rows = c.Query<(string at, string doc_no, string source_type, string memo, long debit, long credit, long n)>(@"SELECT je.at, je.doc_no, je.source_type, je.memo, SUM(jl.debit_paisa), SUM(jl.credit_paisa), COUNT(*)
                FROM journal_lines jl JOIN journal_entries je ON je.id=jl.entry_id
                WHERE jl.account_id=@a AND je.entry_date BETWEEN @f AND @t GROUP BY je.id ORDER BY je.id", new { a = acct.AccountId, f = Clock.DateText(f), t = Clock.DateText(t) }).ToList();
            foreach (var r in rows)
            {
                bal += r.debit - r.credit;
                result.Lines.Add(new LedgerLine { At = Clock.ToPkt(r.at), DocNo = r.doc_no, Details = r.memo, Debit = Money.FromPaisa(r.debit), Credit = Money.FromPaisa(r.credit), Balance = Money.FromPaisa(bal) });
            }
            result.Closing = Money.FromPaisa(bal);
            return result;
        });
    }

    public OkResult Entry(Ctx ctx, CashEntryRequest r, List<string?> tokens)
    {
        if (r.Amount <= 0) throw AppException.Validation("Amount must be more than zero.");
        var (counterCode, isIn, label) = r.Category switch
        {
            "OPENING_BALANCE" => (Acct.OpeningEquity, true, "Opening balance"),
            "OWNER_CAPITAL" => (Acct.OwnerEquity, true, "Owner capital"),
            "OWNER_DRAWINGS" => (Acct.OwnerEquity, false, "Owner drawings"),
            "OTHER_INCOME" => (Acct.OtherIncome, true, "Other income"),
            "PAID_OUT" => (Acct.OpEx, false, "Paid out"),
            _ => throw AppException.Validation("Choose what kind of cash entry this is."),
        };
        var hash = Idempotency.HashOf(r);
        return db.Write((c, tx) =>
        {
            var stored = Idempotency.Find<OkResult>(c, tx, r.IdempotencyKey, "cash-entry", hash);
            if (stored != null) return stored;
            Guards.DayOpen(c, tx, ctx, tokens, r.IdempotencyKey);
            var acct = CashAccounts.Get(c, tx, r.CashAccountId);
            var amount = Money.ToPaisa(r.Amount);
            if (!isIn) CashAccounts.EnsureFunds(c, tx, acct, amount);
            var shift = CashAccounts.ShiftFor(c, tx, ctx, acct);
            var no = DocNumbers.Next(c, tx, settings, "cash");
            var id = c.ExecuteScalar<long>(@"INSERT INTO cash_entries(no, idempotency_key, kind, at, business_date, cash_account_id, category, amount_paisa, memo, user_id, shift_id)
                VALUES(@no,@key,'ENTRY',@t,@bd,@a,@cat,@amt,@memo,@u,@shift) RETURNING id",
                new { no, key = r.IdempotencyKey, t = Clock.NowIso(), bd = Clock.BusinessDate(), a = acct.Id, cat = r.Category, amt = amount, memo = r.Memo, u = ctx.UserId, shift }, tx);
            var j = new Journal();
            if (isIn) j.DrAccount(acct.AccountId, amount, shift, label).Cr(counterCode, amount);
            else j.Dr(counterCode, amount).CrAccount(acct.AccountId, amount, shift, label);
            var je = j.Post(c, tx, settings, ctx, "CE", id, no, $"{label}: {r.Memo}".TrimEnd(' ', ':'));
            c.Execute("UPDATE cash_entries SET journal_entry_id=@je WHERE id=@id", new { je, id }, tx);
            Audit.Write(c, tx, ctx, "CASH_ENTRY", "cash", no, after: $"{label} {Money.Format(r.Amount)} {acct.Name}", reason: r.Memo);
            var result = new OkResult { Id = id, No = no, Message = $"{label} {Money.FormatRs(r.Amount)} posted ({no})." };
            Idempotency.Save(c, tx, r.IdempotencyKey, "cash-entry", hash, result);
            return result;
        });
    }

    public OkResult Transfer(Ctx ctx, TransferRequest r, List<string?> tokens)
    {
        if (r.Amount <= 0) throw AppException.Validation("Amount must be more than zero.");
        if (r.FromAccountId == r.ToAccountId) throw AppException.Validation("Choose two different accounts.");
        var hash = Idempotency.HashOf(r);
        return db.Write((c, tx) =>
        {
            var stored = Idempotency.Find<OkResult>(c, tx, r.IdempotencyKey, "transfer", hash);
            if (stored != null) return stored;
            Guards.DayOpen(c, tx, ctx, tokens, r.IdempotencyKey);
            var from = CashAccounts.Get(c, tx, r.FromAccountId);
            var to = CashAccounts.Get(c, tx, r.ToAccountId);
            var amount = Money.ToPaisa(r.Amount);
            CashAccounts.EnsureFunds(c, tx, from, amount);
            var no = DocNumbers.Next(c, tx, settings, "cash");
            var fromShift = CashAccounts.ShiftFor(c, tx, ctx, from);
            var toShift = CashAccounts.ShiftFor(c, tx, ctx, to);
            var id = c.ExecuteScalar<long>(@"INSERT INTO cash_entries(no, idempotency_key, kind, at, business_date, cash_account_id, to_account_id, category, amount_paisa, memo, user_id, shift_id)
                VALUES(@no,@key,'TRANSFER',@t,@bd,@f,@to,'TRANSFER',@amt,@memo,@u,@shift) RETURNING id",
                new { no, key = r.IdempotencyKey, t = Clock.NowIso(), bd = Clock.BusinessDate(), f = from.Id, to = to.Id, amt = amount, memo = r.Reference, u = ctx.UserId, shift = fromShift }, tx);
            var je = new Journal().DrAccount(to.AccountId, amount, toShift, "Transfer in").CrAccount(from.AccountId, amount, fromShift, "Transfer out")
                .Post(c, tx, settings, ctx, "TR", id, no, $"Transfer {from.Name} to {to.Name} {r.Reference}".Trim());
            c.Execute("UPDATE cash_entries SET journal_entry_id=@je WHERE id=@id", new { je, id }, tx);
            Audit.Write(c, tx, ctx, "CASH_TRANSFER", "cash", no, after: $"{Money.Format(r.Amount)} {from.Name} -> {to.Name}");
            var result = new OkResult { Id = id, No = no, Message = $"Transfer {no} posted." };
            Idempotency.Save(c, tx, r.IdempotencyKey, "transfer", hash, result);
            return result;
        });
    }

    public List<ExpenseDto> Expenses(DateTime? from, DateTime? to) => db.Read(c => c.Query(@"SELECT e.*, ca.name AS account, u.full_name AS entered, IFNULL(ap.full_name,'') AS approver
            FROM expenses e JOIN cash_accounts ca ON ca.id=e.cash_account_id JOIN users u ON u.id=e.user_id LEFT JOIN approvals a ON a.id=e.approval_id LEFT JOIN users ap ON ap.id=a.approver_id
            WHERE e.business_date BETWEEN @f AND @t ORDER BY e.id DESC", new { f = Clock.DateText(from ?? Clock.Today.AddDays(-30)), t = Clock.DateText(to ?? Clock.Today) })
        .Select(e => new ExpenseDto
        {
            Id = e.id, No = e.no, Date = Clock.ParseDate((string)e.business_date), Category = e.category, Description = e.description, Amount = Money.FromPaisa((long)e.amount_paisa),
            Account = e.account, Reference = e.reference, EnteredBy = e.entered, ApprovedBy = e.approver,
        }).ToList());

    public OkResult Expense(Ctx ctx, ExpenseRequest r, List<string?> tokens)
    {
        if (r.Amount <= 0) throw AppException.Validation("Amount must be more than zero.");
        if (string.IsNullOrWhiteSpace(r.Category)) throw AppException.Validation("Choose the expense category.");
        var hash = Idempotency.HashOf(new { r.IdempotencyKey, r.Category, r.Description, r.Amount, r.CashAccountId, r.Reference });
        return db.Write((c, tx) =>
        {
            var stored = Idempotency.Find<OkResult>(c, tx, r.IdempotencyKey, "expense", hash);
            if (stored != null) return stored;
            Guards.DayOpen(c, tx, ctx, tokens, r.IdempotencyKey);
            var acct = CashAccounts.Get(c, tx, r.CashAccountId);
            var amount = Money.ToPaisa(r.Amount);
            CashAccounts.EnsureFunds(c, tx, acct, amount);
            long? approval = r.Amount > settings.GetDecimal("approval.expenseAbove", 5000) ? ApprovalService.Require(c, tx, ctx, tokens, ApprovalActions.Expense, r.IdempotencyKey, r.Amount) : null;
            var shift = CashAccounts.ShiftFor(c, tx, ctx, acct);
            var no = DocNumbers.Next(c, tx, settings, "expense");
            var id = c.ExecuteScalar<long>(@"INSERT INTO expenses(no, idempotency_key, at, business_date, category, description, amount_paisa, cash_account_id, reference, user_id, approval_id, shift_id)
                VALUES(@no,@key,@t,@bd,@cat,@d,@amt,@a,@ref,@u,@appr,@shift) RETURNING id",
                new { no, key = r.IdempotencyKey, t = Clock.NowIso(), bd = Clock.BusinessDate(), cat = r.Category.Trim(), d = r.Description.Trim(), amt = amount, a = acct.Id, @ref = r.Reference, u = ctx.UserId, appr = approval, shift }, tx);
            var je = new Journal().Dr(Acct.OpEx, amount, memo: r.Category).CrAccount(acct.AccountId, amount, shift, "Expense")
                .Post(c, tx, settings, ctx, "EX", id, no, $"{r.Category}: {r.Description}".TrimEnd(' ', ':'));
            c.Execute("UPDATE expenses SET journal_entry_id=@je WHERE id=@id", new { je, id }, tx);
            Audit.Write(c, tx, ctx, "EXPENSE_POSTED", "expenses", no, after: $"{r.Category} {Money.Format(r.Amount)} from {acct.Name}", approver: ApprovalService.ApproverName(c, tx, approval));
            var result = new OkResult { Id = id, No = no, Message = $"Expense {no} posted." };
            Idempotency.Save(c, tx, r.IdempotencyKey, "expense", hash, result);
            return result;
        });
    }

    // ---------- shifts (FR-088) ----------
    private static ShiftDto MapShift(SqliteConnection c, SqliteTransaction? tx, dynamic s)
    {
        var dto = new ShiftDto
        {
            Id = s.id, User = s.user, Workstation = s.ws ?? "", CounterNo = (int)(long)s.counter_no, OpenedAt = Clock.ToPkt((string)s.opened_at), Float = Money.FromPaisa((long)s.float_paisa),
            ClosedAt = Clock.ToPktOrNull((string?)s.closed_at), Status = s.status, Reason = s.reason,
        };
        var parts = c.Query<(string source_type, long net)>(@"SELECT je.source_type, SUM(jl.debit_paisa - jl.credit_paisa) FROM journal_lines jl JOIN journal_entries je ON je.id=jl.entry_id
            WHERE jl.shift_id=@id GROUP BY je.source_type", new { id = (long)s.id }, tx).ToDictionary(x => x.source_type, x => x.net);
        long Part(params string[] types) => types.Sum(t => parts.TryGetValue(t, out var v) ? v : 0);
        dto.CashSales = Money.FromPaisa(Part("SI"));
        dto.Receipts = Money.FromPaisa(Part("CR"));
        dto.Refunds = Money.FromPaisa(-Part("SR", "REV"));
        dto.PaidOuts = Money.FromPaisa(-(parts.Values.Sum() - Part("SI", "CR", "SR", "REV")));
        dto.Expected = dto.Float + Money.FromPaisa(parts.Values.Sum());
        if (s.status == "CLOSED")
        {
            dto.Expected = Money.FromPaisa((long)s.expected_paisa);
            dto.Counted = Money.FromPaisa((long)s.counted_paisa);
            dto.Difference = Money.FromPaisa((long)s.diff_paisa);
        }
        return dto;
    }

    private const string ShiftSql = "SELECT s.*, u.full_name AS user, w.name AS ws FROM shifts s JOIN users u ON u.id=s.user_id LEFT JOIN workstations w ON w.id=s.workstation_id";

    public ShiftDto ShiftById(SqliteConnection c, long id) => MapShift(c, null, c.QuerySingle(ShiftSql + " WHERE s.id=@id", new { id }));

    public ShiftDto? CurrentShift(Ctx ctx) => db.Read(c =>
    {
        var s = c.QuerySingleOrDefault(ShiftSql + " WHERE s.user_id=@u AND s.status='OPEN' ORDER BY s.id DESC LIMIT 1", new { u = ctx.UserId });
        return s == null ? null : MapShift(c, null, s);
    });

    public List<ShiftDto> Shifts(DateTime? date) => db.Read(c => c.Query(ShiftSql + " WHERE s.business_date=@d OR s.status='OPEN' ORDER BY s.id DESC", new { d = Clock.DateText(date ?? Clock.Today) })
        .Select(s => (ShiftDto)MapShift(c, null, s)).ToList());

    public ShiftDto OpenShift(Ctx ctx, OpenShiftRequest r)
    {
        if (r.Float < 0) throw AppException.Validation("Opening float cannot be negative.");
        var id = db.Write((c, tx) =>
        {
            if (c.ExecuteScalar<long>("SELECT COUNT(*) FROM shifts WHERE user_id=@u AND status='OPEN'", new { u = ctx.UserId }, tx) > 0) throw AppException.Conflict("You already have an open shift. Close it first.");
            var date = Clock.BusinessDate();
            if (c.ExecuteScalar<long>("SELECT COUNT(*) FROM day_closings WHERE business_date=@date", new { date }, tx) > 0) throw new AppException(409, ErrorCodes.DayClosed, "Today is already closed. Ask the manager.");
            var newId = c.ExecuteScalar<long>("INSERT INTO shifts(user_id, workstation_id, counter_no, opened_at, business_date, float_paisa) VALUES(@u,@w,@cn,@t,@d,@f) RETURNING id",
                new { u = ctx.UserId, w = ctx.WorkstationId, cn = r.CounterNo, t = Clock.NowIso(), d = date, f = Money.ToPaisa(r.Float) }, tx);
            Audit.Write(c, tx, ctx, "SHIFT_OPENED", "shifts", $"shift {newId}", after: $"float {Money.Format(r.Float)} counter {r.CounterNo}");
            return newId;
        });
        return db.Read(c => MapShift(c, null, c.QuerySingle(ShiftSql + " WHERE s.id=@id", new { id })));
    }

    public ShiftDto CloseShift(Ctx ctx, long id, CloseShiftRequest r, List<string?> tokens)
    {
        if (r.Counts.Any(x => x.Count < 0 || x.Denomination <= 0)) throw AppException.Validation("Counts cannot be negative.");
        db.Write((c, tx) =>
        {
            var s = c.QuerySingleOrDefault(ShiftSql + " WHERE s.id=@id", new { id }, tx) ?? throw AppException.NotFound("Shift");
            if ((string)s.status != "OPEN") throw AppException.Conflict("This shift is already closed.");
            if ((long)s.user_id != ctx.UserId && !ctx.Has(Perm.CashDiffApprove)) throw new AppException(403, ErrorCodes.PermissionDenied, "Only the cashier or a manager can close this shift.");
            var dto = MapShift(c, tx, s);
            var counted = Money.ToPaisa(r.Counts.Sum(x => (decimal)x.Denomination * x.Count));
            var expected = Money.ToPaisa(dto.Expected);
            var diff = counted - expected;
            long? approval = null;
            if (diff != 0)
            {
                if (string.IsNullOrWhiteSpace(r.Reason)) throw AppException.Validation("Enter the reason for the cash difference.");
                approval = ApprovalService.Require(c, tx, ctx, tokens, ApprovalActions.CashDifference, $"SHIFT:{id}", Money.FromPaisa(Math.Abs(diff)));
            }
            foreach (var x in r.Counts.Where(x => x.Count > 0))
                c.Execute("INSERT OR REPLACE INTO shift_counts(shift_id, denomination, count) VALUES(@id,@d,@n)", new { id, d = x.Denomination, n = x.Count }, tx);
            long? je = null;
            if (diff != 0)
            {
                var cash = CashAccounts.ForMethod(c, tx, PaymentMethods.Cash, null);
                var j = diff < 0 ? new Journal().Dr(Acct.CashOverShort, -diff).CrAccount(cash.AccountId, -diff) : new Journal().DrAccount(cash.AccountId, diff).Cr(Acct.CashOverShort, diff);
                je = j.Post(c, tx, settings, ctx, "CD", id, $"SHIFT-{id}", $"Cash {(diff < 0 ? "short" : "over")} at shift close ({(string)s.user}): {r.Reason}");
            }
            c.Execute("UPDATE shifts SET status='CLOSED', closed_at=@t, expected_paisa=@e, counted_paisa=@cnt, diff_paisa=@d, reason=@reason, approval_id=@a, journal_entry_id=@je WHERE id=@id",
                new { t = Clock.NowIso(), e = expected, cnt = counted, d = diff, reason = r.Reason ?? "", a = approval, je, id }, tx);
            Audit.Write(c, tx, ctx, "SHIFT_CLOSED", "shifts", $"shift {id} ({(string)s.user})", after: $"expected {Money.Format(Money.FromPaisa(expected))} counted {Money.Format(Money.FromPaisa(counted))} diff {Money.Format(Money.FromPaisa(diff))}",
                reason: r.Reason ?? "", approver: ApprovalService.ApproverName(c, tx, approval));
        });
        return db.Read(c => MapShift(c, null, c.QuerySingle(ShiftSql + " WHERE s.id=@id", new { id })));
    }

    public DayStatusDto DayStatus() => db.Read(c =>
    {
        var date = Clock.BusinessDate();
        var closed = c.QueryOne<(string closed_at, string name)>("SELECT d.closed_at, u.full_name FROM day_closings d JOIN users u ON u.id=d.closed_by WHERE d.business_date=@date", new { date });
        return new DayStatusDto
        {
            BusinessDate = date, Closed = closed != null, ClosedAt = closed == null ? null : Clock.ToPkt(closed.Value.closed_at), ClosedBy = closed?.name,
            OpenShifts = c.Query(ShiftSql + " WHERE s.status='OPEN' ORDER BY s.id").Select(s => (ShiftDto)MapShift(c, null, s)).ToList(),
        };
    });

    public DayStatusDto CloseDay(Ctx ctx)
    {
        db.Write((c, tx) =>
        {
            var date = Clock.BusinessDate();
            if (c.ExecuteScalar<long>("SELECT COUNT(*) FROM day_closings WHERE business_date=@date", new { date }, tx) > 0) throw AppException.Conflict("Today is already closed.");
            var open = c.Query<string>("SELECT u.full_name FROM shifts s JOIN users u ON u.id=s.user_id WHERE s.status='OPEN'", transaction: tx).ToList();
            if (open.Count > 0) throw new AppException(409, ErrorCodes.Conflict, $"The day cannot be closed: shifts are still open for {string.Join(", ", open)}.");
            c.Execute("INSERT INTO day_closings(business_date, closed_at, closed_by) VALUES(@date,@t,@u)", new { date, t = Clock.NowIso(), u = ctx.UserId }, tx);
            Audit.Write(c, tx, ctx, "DAY_CLOSED", "closing", date);
        });
        return DayStatus();
    }
}
