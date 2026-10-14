using System.Collections.Concurrent;
using System.Globalization;
using Dapper;
using MediCare.Contracts;
using MediCare.Core;
using MediCare.Host.Infrastructure;
using MediCare.Host.Security;
using Microsoft.Data.Sqlite;

namespace MediCare.Host.Domain;

public sealed class SettingsService(Db db, EventBus bus)
{
    private sealed record Def(string Key, string Group, string Label, string Type, string Default, string Hint = "", string[]? Choices = null);

    private static readonly List<Def> Defs = new()
    {
        new("pharmacy.name", "Pharmacy", "Pharmacy name", "text", "MediCare Pharmacy"),
        new("pharmacy.address", "Pharmacy", "Address", "text", ""),
        new("pharmacy.phone", "Pharmacy", "Telephone", "text", ""),
        new("pharmacy.license", "Pharmacy", "Drug sale licence no.", "text", "", "Printed on receipts"),
        new("pharmacy.ntn", "Pharmacy", "NTN", "text", ""),
        new("pharmacy.licenceType", "Pharmacy", "Licence type", "choice", "PHARMACY", "Medical store licences cannot stock Schedule G", new[] { "PHARMACY", "MEDICAL_STORE" }),
        new("tax.inclusive", "Tax", "Prices include tax", "bool", "false"),
        new("stock.nearExpiryDays", "Thresholds", "Near-expiry alert (days)", "int", "90"),
        new("stock.batchRequired", "Thresholds", "Batch number and expiry required at goods-in", "bool", "true"),
        new("approval.refundAbove", "Thresholds", "Refund approval above (Rs)", "money", "500"),
        new("approval.adjustAbove", "Thresholds", "Adjustment approval above (Rs)", "money", "1000"),
        new("approval.adjustPercent", "Thresholds", "Adjustment approval above (% of batch)", "percent", "5"),
        new("approval.expenseAbove", "Thresholds", "Expense approval above (Rs)", "money", "5000"),
        new("security.maxFailedLogins", "Security", "Failed logins before lock", "int", "5"),
        new("security.lockMinutes", "Security", "Lock duration (minutes)", "int", "15"),
        new("security.minPasswordLength", "Security", "Minimum password length", "int", "10"),
        new("security.sessionGraceMin", "Security", "Server session grace after screen lock (minutes)", "int", "60"),
        new("receipt.paper", "Receipt", "Receipt paper", "choice", "80mm", "", new[] { "80mm", "58mm", "A4" }),
        new("receipt.urduFont", "Receipt", "Urdu font", "choice", "Noto Naskh Arabic", "", new[] { "Noto Naskh Arabic", "Noto Nastaliq Urdu", "Jameel Noori Nastaleeq" }),
        new("receipt.footerUrdu1", "Receipt", "Urdu footer line 1", "text", "دوائی کی واپسی یا تبدیلی صرف بل کے ساتھ ۷ دن کے اندر"),
        new("receipt.footerUrdu2", "Receipt", "Urdu footer line 2", "text", "فریج میں رکھی جانے والی ادویات واپس نہیں ہوں گی"),
        new("receipt.footerEnglish", "Receipt", "English footer", "text", "Thank you. Get well soon."),
        new("receipt.showBatch", "Receipt", "Print batch and expiry", "bool", "false"),
        new("payments.methods", "Payments", "Allowed payment methods", "text", "CASH,CARD,WALLET,BANK,CREDIT", "Comma separated: CASH, CARD, WALLET, BANK, CREDIT"),
        new("shift.requireForSales", "Payments", "Require an open shift before selling", "bool", "false"),
        new("numbering.sale", "Numbering", "Sale invoice", "text", "SI-C{counter}-{yyMMdd}-{seq:4}"),
        new("numbering.salesReturn", "Numbering", "Sales return", "text", "SR-C{counter}-{yyMMdd}-{seq:4}"),
        new("numbering.purchase", "Numbering", "Purchase invoice", "text", "PI-{yyMMdd}-{seq:4}"),
        new("numbering.purchaseReturn", "Numbering", "Purchase return", "text", "PR-{yyMMdd}-{seq:4}"),
        new("numbering.receipt", "Numbering", "Customer receipt", "text", "CR-{yyMMdd}-{seq:4}"),
        new("numbering.payment", "Numbering", "Supplier payment", "text", "SP-{yyMMdd}-{seq:4}"),
        new("numbering.expense", "Numbering", "Expense voucher", "text", "EX-{yyMMdd}-{seq:4}"),
        new("numbering.cash", "Numbering", "Cash entry", "text", "CE-{yyMMdd}-{seq:4}"),
        new("numbering.adjustment", "Numbering", "Stock adjustment", "text", "SA-{yyMMdd}-{seq:4}"),
        new("numbering.count", "Numbering", "Stock count", "text", "SC-{yyMMdd}-{seq:3}"),
        new("numbering.reversal", "Numbering", "Reversal", "text", "RV-{yyMMdd}-{seq:4}"),
        new("numbering.journal", "Numbering", "Journal entry", "text", "JV-{yyMMdd}-{seq:5}"),
        new("backup.dailyTime", "Backup", "Daily backup time (HH:mm)", "time", "03:00"),
        new("backup.keepDaily", "Backup", "Daily backups to keep", "int", "30"),
        new("backup.hourly", "Backup", "Hourly backups on host", "bool", "true"),
        new("backup.keepHourly", "Backup", "Hourly backups to keep", "int", "24"),
        new("backup.folder", "Backup", "Backup folder on host", "text", "", "Leave empty for <data folder>\\Backups"),
        new("shortcut.find", "Shortcuts", "Search medicine", "text", "F2"),
        new("shortcut.customer", "Shortcuts", "Select customer", "text", "F3"),
        new("shortcut.pay", "Shortcuts", "Payment", "text", "F4"),
        new("shortcut.hold", "Shortcuts", "Hold / recall", "text", "F5"),
        new("shortcut.discount", "Shortcuts", "Discount", "text", "F6"),
        new("shortcut.batch", "Shortcuts", "Choose batch", "text", "F7"),
        new("shortcut.reprint", "Shortcuts", "Reprint", "text", "F8"),
        new("shortcut.return", "Shortcuts", "Return", "text", "F9"),
    };

    private ConcurrentDictionary<string, string> _values = new();

    public void Load() => _values = new ConcurrentDictionary<string, string>(db.Read(c => c.Query<(string key, string value)>("SELECT key, value FROM app_settings")).ToDictionary(x => x.key, x => x.value));

    public string Get(string key)
    {
        if (_values.TryGetValue(key, out var v)) return v;
        return Defs.FirstOrDefault(d => d.Key == key)?.Default ?? "";
    }

    public int GetInt(string key, int fallback) => int.TryParse(Get(key), NumberStyles.Integer, CultureInfo.InvariantCulture, out var v) ? v : fallback;
    public decimal GetDecimal(string key, decimal fallback) => decimal.TryParse(Get(key), NumberStyles.Number, CultureInfo.InvariantCulture, out var v) ? v : fallback;
    public bool GetBool(string key) => Get(key).Equals("true", StringComparison.OrdinalIgnoreCase) || Get(key) == "1";

    public List<SettingItem> List() => Defs.Select(d => new SettingItem
    {
        Key = d.Key, Group = d.Group, Label = d.Label, Type = d.Type, Value = Get(d.Key), Hint = d.Hint, Choices = d.Choices?.ToList() ?? new List<string>(),
    }).ToList();

    public void Set(SqliteConnection c, SqliteTransaction tx, Ctx ctx, string key, string value)
    {
        var def = Defs.FirstOrDefault(d => d.Key == key) ?? throw AppException.Validation($"Unknown setting {key}.");
        value = (value ?? "").Trim();
        switch (def.Type)
        {
            case "int": if (!int.TryParse(value, out var i) || i < 0) throw AppException.Validation($"{def.Label} must be a whole number."); break;
            case "money": case "percent": if (!decimal.TryParse(value, NumberStyles.Number, CultureInfo.InvariantCulture, out var m) || m < 0) throw AppException.Validation($"{def.Label} must be a number."); break;
            case "bool": value = value.Equals("true", StringComparison.OrdinalIgnoreCase) || value == "1" ? "true" : "false"; break;
            case "choice": if (def.Choices != null && !def.Choices.Contains(value)) throw AppException.Validation($"{def.Label} must be one of {string.Join(", ", def.Choices)}."); break;
            case "time": if (!TimeSpan.TryParseExact(value, "hh\\:mm", CultureInfo.InvariantCulture, out _)) throw AppException.Validation($"{def.Label} must be HH:mm."); break;
        }
        if (key.StartsWith("numbering.") && !value.Contains("{seq")) throw AppException.Validation($"{def.Label} format must contain {{seq}}.");
        var old = Get(key);
        if (old == value) return;
        c.Execute("INSERT INTO app_settings(key, value, updated_by, updated_at) VALUES(@key,@value,@u,@t) ON CONFLICT(key) DO UPDATE SET value=excluded.value, updated_by=excluded.updated_by, updated_at=excluded.updated_at",
            new { key, value, u = ctx.UserId == 0 ? (long?)null : ctx.UserId, t = Clock.NowIso() }, tx);
        Audit.Write(c, tx, ctx, "SETTING_CHANGED", "settings", key, old, value);
        _values[key] = value;
    }

    public void Update(Ctx ctx, Dictionary<string, string> values)
    {
        db.Write((c, tx) => { foreach (var kv in values) Set(c, tx, ctx, kv.Key, kv.Value); });
        bus.Publish(EventTypes.SettingsChanged);
    }

    public ClientSettings ClientSettings(SqliteConnection c, SqliteTransaction? tx)
    {
        var date = Clock.BusinessDate();
        return new ClientSettings
        {
            PharmacyName = Get("pharmacy.name"), Address = Get("pharmacy.address"), Phone = Get("pharmacy.phone"), LicenseNo = Get("pharmacy.license"), Ntn = Get("pharmacy.ntn"),
            TaxInclusive = GetBool("tax.inclusive"), NearExpiryDays = GetInt("stock.nearExpiryDays", 90), RefundApprovalAbove = GetDecimal("approval.refundAbove", 500),
            ReceiptFooterUrdu = new[] { Get("receipt.footerUrdu1"), Get("receipt.footerUrdu2") }.Where(s => s.Length > 0).ToList(),
            ReceiptFooterEnglish = Get("receipt.footerEnglish"), ReceiptShowBatch = GetBool("receipt.showBatch"), ReceiptUrduFont = Get("receipt.urduFont"),
            PaymentMethods = Get("payments.methods").Split(',', StringSplitOptions.RemoveEmptyEntries | StringSplitOptions.TrimEntries).ToList(),
            Shortcuts = Defs.Where(d => d.Key.StartsWith("shortcut.")).ToDictionary(d => d.Key.Substring(9), d => Get(d.Key)),
            BusinessDate = date,
            DayClosed = c.ExecuteScalar<long>("SELECT COUNT(*) FROM day_closings WHERE business_date=@date", new { date }, tx) > 0,
        };
    }
}

/// <summary>Inline (PIN at the counter) and deferred approvals; single-use tokens bound to action + context (ADR-009, FR-009/010).</summary>
public sealed class ApprovalService(Db db, EventBus bus)
{
    public static string PermissionFor(string action) => action switch
    {
        ApprovalActions.Discount => Perm.DiscountApprove,
        ApprovalActions.Refund => Perm.ReturnApprove,
        ApprovalActions.CreditLimit => Perm.CreditApprove,
        ApprovalActions.Adjustment => Perm.StockAdjustApprove,
        ApprovalActions.WriteOff => Perm.StockAdjustApprove,
        ApprovalActions.Reversal => Perm.DocsReverse,
        ApprovalActions.CashDifference => Perm.CashDiffApprove,
        ApprovalActions.Expense => Perm.ExpensesApprove,
        ApprovalActions.AfterDayClose => Perm.AfterDayCloseApprove,
        _ => throw AppException.Validation($"Unknown approval action {action}."),
    };

    private static void CheckApprover(SqliteConnection c, SqliteTransaction tx, UserRow approver, string action, decimal value)
    {
        var perms = AuthService.EffectivePermissions(c, approver.Id, approver.RoleId, tx);
        if (!perms.Contains(PermissionFor(action)))
            throw new AppException(403, ErrorCodes.PermissionDenied, $"{approver.FullName} cannot approve {ApprovalActions.Label(action).ToLowerInvariant()}.");
        if (action == ApprovalActions.Discount)
        {
            var maxBp = c.ExecuteScalar<long>("SELECT max_discount_bp FROM roles WHERE id=@r", new { r = approver.RoleId }, tx);
            if (value > maxBp / 100m) throw new AppException(403, ErrorCodes.PermissionDenied, $"{approver.FullName}'s discount limit is {maxBp / 100m:0.##}%.");
        }
    }

    /// <summary>Checks the approver's PIN. A wrong PIN is counted in its own committed write before the error is
    /// raised, so the counter and the audit row survive (throwing inside a posting would roll them back).</summary>
    private void VerifyPin(UserRow approver, string pin)
    {
        var now = Clock.UtcNow;
        if (approver.PinLockedUntil != null && DateTime.Parse(approver.PinLockedUntil).ToUniversalTime() > now)
            throw new AppException(423, ErrorCodes.AccountLocked, $"{approver.FullName}'s PIN is locked for 15 minutes after 3 wrong tries.");
        if (Passwords.Verify(pin, approver.PinHash)) return;
        var fails = db.Write((c, tx) =>
        {
            var f = c.ExecuteScalar<long>("SELECT pin_failed FROM users WHERE id=@id", new { id = approver.Id }, tx) + 1;
            c.Execute("UPDATE users SET pin_failed=@f, pin_locked_until=@l WHERE id=@id",
                new { f = f >= 3 ? 0 : f, l = f >= 3 ? Clock.Iso(now.AddMinutes(15)) : null, id = approver.Id }, tx);
            Audit.Write(c, tx, null, "APPROVAL_PIN_FAILED", "approvals", approver.Username);
            return f;
        });
        throw new AppException(401, ErrorCodes.BadCredentials, fails >= 3 ? "Wrong PIN. The PIN is now locked for 15 minutes." : $"Wrong PIN. {3 - fails} tries left.");
    }

    public ApprovalTokenResponse Inline(Ctx ctx, InlineApprovalRequest r)
    {
        if (string.IsNullOrWhiteSpace(r.ContextKey)) throw AppException.Validation("Approval context is missing.");
        if (string.IsNullOrWhiteSpace(r.Reason)) throw AppException.Validation("Enter a reason for the approval.");
        var approver = db.Read(c => c.QuerySingleOrDefault<UserRow>("SELECT * FROM users WHERE username=@u AND is_active=1", new { u = r.ApproverUsername }))
                       ?? throw AppException.Validation("Choose an active approver.");
        PermissionFor(r.Action);
        VerifyPin(approver, r.Pin);
        return db.Write((c, tx) =>
        {
            c.Execute("UPDATE users SET pin_failed=0, pin_locked_until=NULL WHERE id=@id", new { id = approver.Id }, tx);
            CheckApprover(c, tx, approver, r.Action, r.Value);
            var token = Hashing.NewToken();
            var expires = Clock.UtcNow.AddMinutes(5);
            c.Execute(@"INSERT INTO approvals(action, context_key, value_text, summary, request_reason, requester_id, workstation_id, approver_id, status, token_hash, decision_reason, created_at, decided_at, expires_at)
                        VALUES(@a,@k,@v,@s,@rr,@req,@ws,@ap,'APPROVED',@th,@rr,@t,@t,@exp)",
                new { a = r.Action, k = r.ContextKey, v = r.Value.ToString(CultureInfo.InvariantCulture), s = ApprovalActions.Label(r.Action), rr = r.Reason, req = ctx.UserId, ws = ctx.WorkstationId, ap = approver.Id, th = Hashing.Sha256Hex(token), t = Clock.NowIso(), exp = Clock.Iso(expires) }, tx);
            Audit.Write(c, tx, ctx, "APPROVAL_GRANTED", "approvals", $"{r.Action}:{r.ContextKey}", after: r.Value.ToString(CultureInfo.InvariantCulture), reason: r.Reason, approver: approver.FullName);
            return new ApprovalTokenResponse { Token = token, ApproverName = approver.FullName, ExpiresAt = Clock.ToPkt(Clock.Iso(expires)) };
        });
    }

    public ApprovalDto RequestDeferred(Ctx ctx, DeferredApprovalRequest r)
    {
        PermissionFor(r.Action);
        var id = db.Write((c, tx) =>
        {
            var newId = c.ExecuteScalar<long>(@"INSERT INTO approvals(action, context_key, value_text, summary, request_reason, requester_id, workstation_id, status, created_at)
                        VALUES(@a,@k,@v,@s,@rr,@req,@ws,'PENDING',@t) RETURNING id",
                new { a = r.Action, k = r.ContextKey, v = r.Value.ToString(CultureInfo.InvariantCulture), s = r.Summary, rr = r.Reason, req = ctx.UserId, ws = ctx.WorkstationId, t = Clock.NowIso() }, tx);
            Audit.Write(c, tx, ctx, "APPROVAL_REQUESTED", "approvals", $"{r.Action}:{r.ContextKey}", after: r.Summary, reason: r.Reason);
            return newId;
        });
        bus.Publish(EventTypes.ApprovalRequested, new { id, action = r.Action });
        return Get(ctx, id);
    }

    private const string SelectSql = @"SELECT a.id, a.action, a.context_key, a.value_text, a.summary, a.request_reason, a.status, a.created_at, a.token_plain, a.requester_id,
        u.full_name AS requester, IFNULL(w.name,'') AS workstation, ap.full_name AS approver
        FROM approvals a JOIN users u ON u.id=a.requester_id LEFT JOIN workstations w ON w.id=a.workstation_id LEFT JOIN users ap ON ap.id=a.approver_id";

    private static ApprovalDto Map(dynamic r, long viewerId) => new()
    {
        Id = r.id, Action = r.action, ActionLabel = ApprovalActions.Label((string)r.action), ContextKey = r.context_key,
        Value = decimal.Parse((string)r.value_text, CultureInfo.InvariantCulture), Summary = r.summary, Reason = r.request_reason, Status = r.status,
        CreatedAt = Clock.ToPkt((string)r.created_at), Requester = r.requester, Workstation = r.workstation, DecidedBy = r.approver,
        Token = (long)r.requester_id == viewerId && (string)r.status == "APPROVED" ? (string?)r.token_plain : null,
    };

    public List<ApprovalDto> List(Ctx ctx, string? status)
    {
        var rows = db.Read(c => c.Query(SelectSql + " WHERE (@s IS NULL OR a.status=@s) AND (a.status='PENDING' OR a.created_at >= @since) ORDER BY a.id DESC LIMIT 200",
            new { s = string.IsNullOrEmpty(status) ? null : status, since = Clock.Iso(Clock.UtcNow.AddDays(-7)) }).ToList());
        return rows.Select(r => (ApprovalDto)Map(r, ctx.UserId)).ToList();
    }

    public int PendingCount() => db.Read(c => c.ExecuteScalar<int>("SELECT COUNT(*) FROM approvals WHERE status='PENDING'"));

    public ApprovalDto Get(Ctx ctx, long id)
    {
        var r = db.Read(c => c.QuerySingleOrDefault(SelectSql + " WHERE a.id=@id", new { id })) ?? throw AppException.NotFound("Approval");
        return (ApprovalDto)Map(r, ctx.UserId);
    }

    public ApprovalDto Decide(Ctx ctx, long id, DecideApprovalRequest r)
    {
        var approver = db.Read(c => c.QuerySingle<UserRow>("SELECT * FROM users WHERE id=@id", new { id = ctx.UserId }));
        VerifyPin(approver, r.Pin);
        db.Write((c, tx) =>
        {
            var a = c.QueryOne<(string action, string context_key, string value_text, string status, long requester_id)>(
                "SELECT action, context_key, value_text, status, requester_id FROM approvals WHERE id=@id", new { id }, tx) ?? throw AppException.NotFound("Approval");
            if (a.status != "PENDING") throw AppException.Conflict("This request was already decided.");
            c.Execute("UPDATE users SET pin_failed=0, pin_locked_until=NULL WHERE id=@id", new { id = approver.Id }, tx);
            if (r.Approve) CheckApprover(c, tx, approver, a.action, decimal.Parse(a.value_text, CultureInfo.InvariantCulture));
            string? token = r.Approve ? Hashing.NewToken() : null;
            c.Execute(@"UPDATE approvals SET status=@s, approver_id=@ap, decision_reason=@dr, decided_at=@t, token_hash=@th, token_plain=@tp, expires_at=@exp WHERE id=@id",
                new { s = r.Approve ? "APPROVED" : "REJECTED", ap = ctx.UserId, dr = r.Reason, t = Clock.NowIso(), th = token == null ? null : Hashing.Sha256Hex(token), tp = token, exp = Clock.Iso(Clock.UtcNow.AddHours(12)), id }, tx);
            Audit.Write(c, tx, ctx, r.Approve ? "APPROVAL_GRANTED" : "APPROVAL_REJECTED", "approvals", $"{a.action}:{a.context_key}", reason: r.Reason, approver: ctx.FullName);
        });
        bus.Publish(EventTypes.ApprovalDecided, new { id, approved = r.Approve });
        return Get(ctx, id);
    }

    /// <summary>
    /// Returns null when the caller can act without approval, or the id of the consumed approval.
    /// Throws APPROVAL_REQUIRED (with action/value details) when no valid token was supplied.
    /// </summary>
    public static long? Require(SqliteConnection c, SqliteTransaction tx, Ctx ctx, IEnumerable<string?>? tokens, string action, string contextKey, decimal value, bool callerMayActAlone = true)
    {
        if (callerMayActAlone && ctx.Has(PermissionFor(action)) && (action != ApprovalActions.Discount || value <= ctx.MaxDiscountPercent)) return null;
        foreach (var t in tokens ?? Enumerable.Empty<string?>())
        {
            if (string.IsNullOrWhiteSpace(t)) continue;
            var a = c.QueryOne<(long id, string action, string context_key, string value_text, string status, string? expires_at)>(
                "SELECT id, action, context_key, value_text, status, expires_at FROM approvals WHERE token_hash=@h", new { h = Hashing.Sha256Hex(t!) }, tx);
            if (a == null || a.Value.action != action) continue;
            if (a.Value.status != "APPROVED") throw new AppException(409, ErrorCodes.ApprovalInvalid, "This approval was already used or rejected. Ask for a new approval.");
            if (a.Value.expires_at != null && DateTime.Parse(a.Value.expires_at).ToUniversalTime() < Clock.UtcNow)
                throw new AppException(409, ErrorCodes.ApprovalInvalid, "The approval expired. Ask the manager to approve again.");
            if (a.Value.context_key != contextKey) throw new AppException(409, ErrorCodes.ApprovalInvalid, "The approval was given for a different document.");
            if (decimal.Parse(a.Value.value_text, CultureInfo.InvariantCulture) < value)
                throw new AppException(409, ErrorCodes.ApprovalInvalid, $"The approval covers {a.Value.value_text}, but {value.ToString(CultureInfo.InvariantCulture)} is needed. Ask again.");
            c.Execute("UPDATE approvals SET status='USED', used_at=@t, token_plain=NULL WHERE id=@id", new { t = Clock.NowIso(), id = a.Value.id }, tx);
            return a.Value.id;
        }
        throw new AppException(403, ErrorCodes.ApprovalRequired, $"{ApprovalActions.Label(action)} needs a manager's approval.",
            new Dictionary<string, string> { ["action"] = action, ["contextKey"] = contextKey, ["value"] = value.ToString(CultureInfo.InvariantCulture) });
    }

    public static string ApproverName(SqliteConnection c, SqliteTransaction tx, long? approvalId) =>
        approvalId == null ? "" : c.ExecuteScalar<string?>("SELECT u.full_name FROM approvals a JOIN users u ON u.id=a.approver_id WHERE a.id=@approvalId", new { approvalId }, tx) ?? "";
}

public static class Guards
{
    /// <summary>Postings on a closed business day need the AFTER_DAY_CLOSE approval (FR-089).</summary>
    public static long? DayOpen(SqliteConnection c, SqliteTransaction tx, Ctx ctx, IEnumerable<string?>? tokens, string contextKey)
    {
        var date = Clock.BusinessDate();
        var closed = c.ExecuteScalar<long>("SELECT COUNT(*) FROM day_closings WHERE business_date=@date", new { date }, tx) > 0;
        if (!closed) return null;
        return ApprovalService.Require(c, tx, ctx, tokens, ApprovalActions.AfterDayClose, contextKey, 0, callerMayActAlone: false);
    }

    public static decimal Rs(long paisa) => Money.FromPaisa(paisa);
    public static long P(decimal rs) => Money.ToPaisa(rs);
}
