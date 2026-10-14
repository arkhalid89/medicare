using System.Globalization;
using System.Text;
using Dapper;
using MediCare.Contracts;
using MediCare.Core;
using MediCare.Host.Infrastructure;
using MediCare.Host.Security;
using Microsoft.Data.Sqlite;

namespace MediCare.Host.Domain;

/// <summary>Append-only audit trail with a SHA-256 hash chain (FR-100).</summary>
public static class Audit
{
    public static void Write(SqliteConnection c, SqliteTransaction tx, Ctx? ctx, string action, string module, string recordRef = "",
        string before = "", string after = "", string reason = "", string approver = "", string? workstation = null)
    {
        var prev = c.ExecuteScalar<string?>("SELECT hash FROM audit_logs ORDER BY id DESC LIMIT 1", transaction: tx) ?? "GENESIS";
        var at = Clock.NowIso();
        var user = ctx?.Username ?? "";
        var ws = workstation ?? ctx?.WorkstationName ?? "";
        var hash = Compute(prev, at, user, ws, action, module, recordRef, before, after, reason, approver);
        c.Execute(@"INSERT INTO audit_logs(at, user_id, username, workstation, action, module, record_ref, before_json, after_json, reason, approver, prev_hash, hash)
                    VALUES(@at,@uid,@user,@ws,@action,@module,@rec,@before,@after,@reason,@approver,@prev,@hash)",
            new { at, uid = ctx?.UserId, user, ws, action, module, rec = recordRef, before, after, reason, approver, prev, hash }, tx);
    }

    public static string Compute(params string[] parts) => Hashing.Sha256Hex(string.Join("\u001f", parts));

    /// <summary>Returns the id of the first broken link, or null when the chain is intact.</summary>
    public static long? VerifyChain(SqliteConnection c)
    {
        var prev = "GENESIS";
        foreach (var r in c.Query("SELECT id, at, username, workstation, action, module, record_ref, before_json, after_json, reason, approver, prev_hash, hash FROM audit_logs ORDER BY id"))
        {
            string h = Compute(prev, (string)r.at, (string)r.username, (string)r.workstation, (string)r.action, (string)r.module, (string)r.record_ref,
                (string)r.before_json, (string)r.after_json, (string)r.reason, (string)r.approver);
            if ((string)r.prev_hash != prev || (string)r.hash != h) return (long)r.id;
            prev = h;
        }
        return null;
    }
}

/// <summary>Host-assigned document numbers inside the posting transaction (ADR-008, FR-102).</summary>
public static class DocNumbers
{
    public static string Next(SqliteConnection c, SqliteTransaction tx, SettingsService settings, string series, int counter = 0)
    {
        var format = settings.Get($"numbering.{series}");
        var now = Clock.NowPkt;
        var period = format.Contains("{yyMMdd}") ? now.ToString("yyMMdd", CultureInfo.InvariantCulture)
            : format.Contains("{yyMM}") ? now.ToString("yyMM", CultureInfo.InvariantCulture)
            : format.Contains("{yyyy}") ? now.ToString("yyyy", CultureInfo.InvariantCulture) : "ALL";
        var key = format.Contains("{counter}") ? $"{series}-{counter}" : series;
        c.Execute("INSERT INTO doc_sequences(series, period_key, next_seq) VALUES(@key, @period, 1) ON CONFLICT(series, period_key) DO NOTHING", new { key, period }, tx);
        var seq = c.ExecuteScalar<long>("UPDATE doc_sequences SET next_seq = next_seq + 1 WHERE series=@key AND period_key=@period RETURNING next_seq - 1", new { key, period }, tx);
        var sb = new StringBuilder(format)
            .Replace("{counter}", counter.ToString(CultureInfo.InvariantCulture))
            .Replace("{yyMMdd}", now.ToString("yyMMdd", CultureInfo.InvariantCulture))
            .Replace("{yyMM}", now.ToString("yyMM", CultureInfo.InvariantCulture))
            .Replace("{yyyy}", now.ToString("yyyy", CultureInfo.InvariantCulture));
        var s = sb.ToString();
        var i = s.IndexOf("{seq", StringComparison.Ordinal);
        if (i >= 0)
        {
            var end = s.IndexOf('}', i);
            var pad = 4;
            var spec = s.Substring(i + 1, end - i - 1);
            if (spec.Contains(':')) pad = int.Parse(spec.Split(':')[1], CultureInfo.InvariantCulture);
            s = s.Substring(0, i) + seq.ToString(CultureInfo.InvariantCulture).PadLeft(pad, '0') + s.Substring(end + 1);
        }
        else s += "-" + seq.ToString(CultureInfo.InvariantCulture);
        return s;
    }
}

public static class Acct
{
    public const string Cash = "1000", Card = "1020", Wallet = "1030", Receivables = "1100", Inventory = "1200", InventoryQuarantine = "1210",
        Payables = "2000", TaxPayable = "2200", OwnerEquity = "3000", OpeningEquity = "3100", Sales = "4000", SalesReturns = "4010",
        SalesDiscounts = "4020", OtherIncome = "4100", Cogs = "5000", Shrinkage = "5010", Rounding = "5020", OpEx = "6000", CashOverShort = "6900";
}

/// <summary>Builds and posts balanced double-entry journal entries (ADR-007).</summary>
public sealed class Journal
{
    private readonly List<(string code, long? accountId, long debit, long credit, string? partyType, long? partyId, long? shiftId, string memo)> _lines = new();

    public Journal Dr(string code, long paisa, string? partyType = null, long? partyId = null, long? shiftId = null, string memo = "")
    { if (paisa != 0) _lines.Add(paisa > 0 ? (code, null, paisa, 0, partyType, partyId, shiftId, memo) : (code, null, 0, -paisa, partyType, partyId, shiftId, memo)); return this; }

    public Journal Cr(string code, long paisa, string? partyType = null, long? partyId = null, long? shiftId = null, string memo = "")
    { if (paisa != 0) _lines.Add(paisa > 0 ? (code, null, 0, paisa, partyType, partyId, shiftId, memo) : (code, null, -paisa, 0, partyType, partyId, shiftId, memo)); return this; }

    public Journal DrAccount(long accountId, long paisa, long? shiftId = null, string memo = "")
    { if (paisa != 0) _lines.Add(paisa > 0 ? ("", accountId, paisa, 0, null, null, shiftId, memo) : ("", accountId, 0, -paisa, null, null, shiftId, memo)); return this; }

    public Journal CrAccount(long accountId, long paisa, long? shiftId = null, string memo = "")
    { if (paisa != 0) _lines.Add(paisa > 0 ? ("", accountId, 0, paisa, null, null, shiftId, memo) : ("", accountId, -paisa, 0, null, null, shiftId, memo)); return this; }

    public long TotalDebit => _lines.Sum(l => l.debit);
    public long TotalCredit => _lines.Sum(l => l.credit);

    public long Post(SqliteConnection c, SqliteTransaction tx, SettingsService settings, Ctx ctx, string sourceType, long sourceId, string docNo, string memo, long? reversesId = null)
    {
        if (_lines.Count == 0) throw new InvalidOperationException("Empty journal entry.");
        if (TotalDebit != TotalCredit) throw new InvalidOperationException($"Unbalanced journal for {docNo}: Dr {TotalDebit} Cr {TotalCredit}");
        var no = DocNumbers.Next(c, tx, settings, "journal");
        var id = c.ExecuteScalar<long>(@"INSERT INTO journal_entries(no, entry_date, at, source_type, source_id, doc_no, memo, user_id, reverses_id)
            VALUES(@no,@d,@at,@st,@sid,@doc,@memo,@uid,@rev) RETURNING id",
            new { no, d = Clock.BusinessDate(), at = Clock.NowIso(), st = sourceType, sid = sourceId, doc = docNo, memo, uid = ctx.UserId == 0 ? (long?)null : ctx.UserId, rev = reversesId }, tx);
        foreach (var l in _lines)
        {
            var accountId = l.accountId ?? AccountId(c, tx, l.code);
            c.Execute(@"INSERT INTO journal_lines(entry_id, account_id, debit_paisa, credit_paisa, party_type, party_id, shift_id, memo)
                        VALUES(@id,@a,@d,@cr,@pt,@pid,@s,@m)", new { id, a = accountId, d = l.debit, cr = l.credit, pt = l.partyType, pid = l.partyId, s = l.shiftId, m = l.memo }, tx);
        }
        return id;
    }

    public static long AccountId(SqliteConnection c, SqliteTransaction? tx, string code) =>
        c.ExecuteScalar<long?>("SELECT id FROM accounts WHERE code=@code", new { code }, tx) ?? throw new InvalidOperationException($"Account {code} missing.");

    /// <summary>Posts the exact opposite of an existing entry (reversal).</summary>
    public static long Reverse(SqliteConnection c, SqliteTransaction tx, SettingsService settings, Ctx ctx, long entryId, string sourceType, long sourceId, string docNo, string memo, long? cashShiftId)
    {
        var j = new Journal();
        var cashAccountIds = c.Query<long>("SELECT account_id FROM cash_accounts WHERE kind='CASH'", transaction: tx).ToHashSet();
        foreach (var l in c.Query<(long account_id, long debit_paisa, long credit_paisa, string? party_type, long? party_id, long? shift_id)>(
                     "SELECT account_id, debit_paisa, credit_paisa, party_type, party_id, shift_id FROM journal_lines WHERE entry_id=@entryId", new { entryId }, tx))
        {
            var shift = cashAccountIds.Contains(l.account_id) ? cashShiftId : null;
            j._lines.Add(("", l.account_id, l.credit_paisa, l.debit_paisa, l.party_type, l.party_id, shift, "reversal"));
        }
        return j.Post(c, tx, settings, ctx, sourceType, sourceId, docNo, memo, entryId);
    }
}

public sealed class CashAccountRow
{
    public long Id { get; set; }
    public long AccountId { get; set; }
    public string Name { get; set; } = "";
    public string Kind { get; set; } = "";
    public long EnforceBalance { get; set; }
    public long IsActive { get; set; }
    public string Code { get; set; } = "";
}

public static class CashAccounts
{
    public static CashAccountRow Get(SqliteConnection c, SqliteTransaction? tx, long id) =>
        c.QuerySingleOrDefault<CashAccountRow>("SELECT ca.*, a.code FROM cash_accounts ca JOIN accounts a ON a.id=ca.account_id WHERE ca.id=@id", new { id }, tx)
        ?? throw AppException.NotFound("Cash or bank account");

    public static CashAccountRow ForMethod(SqliteConnection c, SqliteTransaction? tx, string method, long? preferredId)
    {
        if (preferredId.HasValue && preferredId.Value > 0) return Get(c, tx, preferredId.Value);
        var kind = method switch { PaymentMethods.Cash => "CASH", PaymentMethods.Card => "CARD", PaymentMethods.Wallet => "WALLET", PaymentMethods.Bank => "BANK", _ => throw AppException.Validation($"Unknown payment method {method}.") };
        return c.QueryFirstOrDefault<CashAccountRow>("SELECT ca.*, a.code FROM cash_accounts ca JOIN accounts a ON a.id=ca.account_id WHERE ca.kind=@kind AND ca.is_active=1 ORDER BY ca.id", new { kind }, tx)
               ?? throw AppException.Validation($"No active {kind.ToLowerInvariant()} account is set up.");
    }

    public static long Balance(SqliteConnection c, SqliteTransaction? tx, long accountId) =>
        c.ExecuteScalar<long>("SELECT IFNULL(SUM(debit_paisa - credit_paisa),0) FROM journal_lines WHERE account_id=@accountId", new { accountId }, tx);

    public static void EnsureFunds(SqliteConnection c, SqliteTransaction tx, CashAccountRow acct, long amount)
    {
        if (acct.EnforceBalance == 0) return;
        var bal = Balance(c, tx, acct.AccountId);
        if (bal < amount) throw AppException.Validation($"{acct.Name} has {Money.FormatRs(Money.FromPaisa(bal))}. Balance enforcement is on, so this payment cannot exceed the available balance.");
    }

    /// <summary>The caller's open shift when the money moves through the cash drawer.</summary>
    public static long? ShiftFor(SqliteConnection c, SqliteTransaction tx, Ctx ctx, CashAccountRow acct) =>
        acct.Kind == "CASH" ? c.ExecuteScalar<long?>("SELECT id FROM shifts WHERE user_id=@u AND status='OPEN' ORDER BY id DESC LIMIT 1", new { u = ctx.UserId }, tx) : null;
}

/// <summary>The only code that changes stock (FR-035, FR-036) and the moving-average cost (ADR-007).</summary>
public static class StockLedger
{
    public static void Move(SqliteConnection c, SqliteTransaction tx, Ctx ctx, long batchId, string state, int delta, string sourceType, long sourceId, long? lineId, string docNo, long costE4)
    {
        if (delta == 0) return;
        c.Execute("INSERT INTO stock_balances(batch_id, state, qty) VALUES(@b,@s,0) ON CONFLICT(batch_id, state) DO NOTHING", new { b = batchId, s = state }, tx);
        var current = c.ExecuteScalar<long>("SELECT qty FROM stock_balances WHERE batch_id=@b AND state=@s", new { b = batchId, s = state }, tx);
        if (current + delta < 0)
        {
            var info = c.QuerySingle<(string name, string batch)>("SELECT m.name, b.batch_no FROM batches b JOIN medicines m ON m.id=b.medicine_id WHERE b.id=@batchId", new { batchId }, tx);
            throw new AppException(409, ErrorCodes.InsufficientStock, $"{info.name} batch {info.batch} has only {current} units {state.ToLowerInvariant()}; {-delta} needed.",
                new Dictionary<string, string> { ["batchId"] = batchId.ToString(CultureInfo.InvariantCulture), ["available"] = current.ToString(CultureInfo.InvariantCulture) });
        }
        c.Execute("UPDATE stock_balances SET qty = qty + @d WHERE batch_id=@b AND state=@s", new { d = delta, b = batchId, s = state }, tx);
        c.Execute(@"INSERT INTO stock_movements(batch_id, state, qty_delta, source_type, source_id, source_line_id, doc_no, cost_e4, at, business_date, user_id)
                    VALUES(@b,@s,@d,@st,@sid,@lid,@doc,@cost,@at,@bd,@u)",
            new { b = batchId, s = state, d = delta, st = sourceType, sid = sourceId, lid = lineId, doc = docNo, cost = costE4, at = Clock.NowIso(), bd = Clock.BusinessDate(), u = ctx.UserId == 0 ? (long?)null : ctx.UserId }, tx);
    }

    public static (long avgE4, long qoh) Cost(SqliteConnection c, SqliteTransaction? tx, long medicineId)
    {
        var r = c.QueryOne<(long avg, long qoh)>("SELECT avg_cost_e4, qty_on_hand FROM medicine_costs WHERE medicine_id=@medicineId", new { medicineId }, tx);
        return r == null ? (0, 0) : (r.Value.avg, r.Value.qoh);
    }

    private static void SetCost(SqliteConnection c, SqliteTransaction tx, long medicineId, long avgE4, long qoh) =>
        c.Execute(@"INSERT INTO medicine_costs(medicine_id, avg_cost_e4, qty_on_hand, updated_at) VALUES(@m,@a,@q,@t)
                    ON CONFLICT(medicine_id) DO UPDATE SET avg_cost_e4=excluded.avg_cost_e4, qty_on_hand=excluded.qty_on_hand, updated_at=excluded.updated_at",
            new { m = medicineId, a = avgE4, q = qoh, t = Clock.NowIso() }, tx);

    /// <summary>Stock received at a value (purchase, return in, positive adjustment).</summary>
    public static long CostIn(SqliteConnection c, SqliteTransaction tx, long medicineId, int qty, decimal value)
    {
        var (avg, qoh) = Cost(c, tx, medicineId);
        var newAvg = Costing.AddToAverage((int)qoh, Money.FromE4(avg), qty, value);
        var e4 = Money.ToE4(newAvg);
        SetCost(c, tx, medicineId, e4, qoh + qty);
        return e4;
    }

    /// <summary>Stock issued at the current average (sale, negative adjustment). Returns the average used.</summary>
    public static long CostOut(SqliteConnection c, SqliteTransaction tx, long medicineId, int qty)
    {
        var (avg, qoh) = Cost(c, tx, medicineId);
        SetCost(c, tx, medicineId, avg, qoh - qty);
        return avg;
    }

    /// <summary>Stock removed at a specific value (purchase return).</summary>
    public static void CostOutAtValue(SqliteConnection c, SqliteTransaction tx, long medicineId, int qty, decimal value)
    {
        var (avg, qoh) = Cost(c, tx, medicineId);
        var newAvg = Costing.RemoveFromAverage((int)qoh, Money.FromE4(avg), qty, value);
        SetCost(c, tx, medicineId, Money.ToE4(newAvg), qoh - qty);
    }

    public static long Balance(SqliteConnection c, SqliteTransaction? tx, long batchId, string state) =>
        c.ExecuteScalar<long?>("SELECT qty FROM stock_balances WHERE batch_id=@batchId AND state=@state", new { batchId, state }, tx) ?? 0;
}

/// <summary>Idempotent posting (ADR-008): the same key returns the stored response; a different payload is refused.</summary>
public static class Idempotency
{
    public static T? Find<T>(SqliteConnection c, SqliteTransaction tx, string key, string endpoint, string requestHash) where T : class
    {
        if (string.IsNullOrWhiteSpace(key)) throw AppException.Validation("Idempotency key is missing.");
        var row = c.QueryOne<(string endpoint, string request_hash, string response_json)>(
            "SELECT endpoint, request_hash, response_json FROM idempotency_keys WHERE key=@key", new { key }, tx);
        if (row == null) return null;
        if (row.Value.endpoint != endpoint || row.Value.request_hash != requestHash)
            throw new AppException(409, ErrorCodes.IdempotencyMismatch, "This request key was already used for a different document. Start a new document.");
        return Json.Deserialize<T>(row.Value.response_json);
    }

    public static void Save<T>(SqliteConnection c, SqliteTransaction tx, string key, string endpoint, string requestHash, T response) =>
        c.Execute("INSERT INTO idempotency_keys(key, endpoint, request_hash, response_json, created_at) VALUES(@key,@endpoint,@requestHash,@json,@t)",
            new { key, endpoint, requestHash, json = Json.Serialize(response), t = Clock.NowIso() }, tx);

    public static string HashOf(object request) => Hashing.Sha256Hex(Json.Serialize(request));
}
