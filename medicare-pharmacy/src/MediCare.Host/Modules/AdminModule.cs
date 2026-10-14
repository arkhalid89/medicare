using Dapper;
using MediCare.Contracts;
using MediCare.Core;
using MediCare.Host.Domain;
using MediCare.Host.Infrastructure;
using MediCare.Host.Security;

namespace MediCare.Host.Modules;

public static class AdminModule
{
    public static void Map(WebApplication app)
    {
        var g = app.MapGroup("/api/v1");

        // ---------- users and roles ----------
        g.MapGet("/users", (Db db) => db.Read(c => c.Query<UserRow>("SELECT * FROM users ORDER BY full_name").Select(u => ToDto(c, u)).ToList())).Perm(Perm.UsersManage);

        g.MapPost("/users", (HttpContext http, UserSaveRequest r, Db db, SettingsService s, AuthService auth) =>
        {
            var ctx = http.Ctx();
            Validate(r, isNew: true, s);
            var pwd = Passwords.Hash(r.Password!);
            var pin = string.IsNullOrEmpty(r.Pin) ? null : Passwords.Hash(r.Pin);
            var id = db.Write((c, tx) =>
            {
                var newId = c.ExecuteScalar<long>(@"INSERT INTO users(username, full_name, role_id, pwd_hash, pin_hash, must_change_pwd, is_active, session_timeout_min, created_at)
                    VALUES(@u,@n,@r,@p,@pin,1,@a,@to,@t) RETURNING id",
                    new { u = r.Username.Trim(), n = r.FullName.Trim(), r = r.RoleId, p = pwd, pin, a = r.IsActive ? 1 : 0, to = r.SessionTimeoutMin, t = Clock.NowIso() }, tx);
                SaveOverrides(c, tx, newId, r);
                Audit.Write(c, tx, ctx, "USER_CREATED", "users", r.Username, after: Json.Serialize(new { r.FullName, r.RoleId, r.IsActive, r.GrantedPermissions, r.RevokedPermissions }));
                return newId;
            });
            auth.InvalidateAll();
            return new OkResult { Id = id, Message = "User created. They must change the password at first login." };
        }).Perm(Perm.UsersManage);

        g.MapPut("/users/{id:long}", (long id, HttpContext http, UserSaveRequest r, Db db, SettingsService s, AuthService auth) =>
        {
            var ctx = http.Ctx();
            Validate(r, isNew: false, s);
            db.Write((c, tx) =>
            {
                var old = c.QuerySingleOrDefault<UserRow>("SELECT * FROM users WHERE id=@id", new { id }, tx) ?? throw AppException.NotFound("User");
                if (id == ctx.UserId && !r.IsActive) throw AppException.Validation("You cannot deactivate your own account.");
                var adminRole = c.ExecuteScalar<long>("SELECT id FROM roles WHERE name='Administrator'", transaction: tx);
                if (old.RoleId == adminRole && (r.RoleId != adminRole || !r.IsActive) &&
                    c.ExecuteScalar<long>("SELECT COUNT(*) FROM users WHERE role_id=@a AND is_active=1 AND id<>@id", new { a = adminRole, id }, tx) == 0)
                    throw AppException.Validation("At least one active administrator must remain.");
                c.Execute("UPDATE users SET full_name=@n, role_id=@r, is_active=@a, session_timeout_min=@to WHERE id=@id",
                    new { n = r.FullName.Trim(), r = r.RoleId, a = r.IsActive ? 1 : 0, to = r.SessionTimeoutMin, id }, tx);
                if (!string.IsNullOrEmpty(r.Pin)) { Passwords.CheckPin(r.Pin); c.Execute("UPDATE users SET pin_hash=@p WHERE id=@id", new { p = Passwords.Hash(r.Pin), id }, tx); }
                c.Execute("DELETE FROM user_permission_overrides WHERE user_id=@id", new { id }, tx);
                SaveOverrides(c, tx, id, r);
                if (!r.IsActive) c.Execute("UPDATE sessions SET revoked_at=@t WHERE user_id=@id AND revoked_at IS NULL", new { t = Clock.NowIso(), id }, tx);
                Audit.Write(c, tx, ctx, "USER_CHANGED", "users", old.Username,
                    before: Json.Serialize(new { old.FullName, old.RoleId, Active = old.IsActive == 1 }),
                    after: Json.Serialize(new { r.FullName, r.RoleId, Active = r.IsActive, r.GrantedPermissions, r.RevokedPermissions }));
            });
            auth.InvalidateAll();
            return new OkResult { Id = id };
        }).Perm(Perm.UsersManage);

        g.MapPost("/users/{id:long}/reset-password", (long id, HttpContext http, ResetPasswordRequest r, Db db, SettingsService s, AuthService auth) =>
        {
            var ctx = http.Ctx();
            var user = db.Read(c => c.QuerySingleOrDefault<UserRow>("SELECT * FROM users WHERE id=@id", new { id })) ?? throw AppException.NotFound("User");
            Passwords.CheckPolicy(user.Username, r.NewPassword, s.GetInt("security.minPasswordLength", 10));
            if (!string.IsNullOrEmpty(r.NewPin)) Passwords.CheckPin(r.NewPin);
            var hash = Passwords.Hash(r.NewPassword);
            var pin = string.IsNullOrEmpty(r.NewPin) ? null : Passwords.Hash(r.NewPin);
            db.Write((c, tx) =>
            {
                c.Execute("UPDATE users SET pwd_hash=@h, must_change_pwd=1, failed_count=0, locked_until=NULL, pin_hash=COALESCE(@pin, pin_hash) WHERE id=@id", new { h = hash, pin, id }, tx);
                c.Execute("UPDATE sessions SET revoked_at=@t WHERE user_id=@id AND revoked_at IS NULL", new { t = Clock.NowIso(), id }, tx);
                Audit.Write(c, tx, ctx, "PASSWORD_RESET", "users", user.Username);
            });
            auth.InvalidateAll();
            return new OkResult { Message = $"Password reset. {user.FullName} must choose a new password at next login." };
        }).Perm(Perm.UsersManage);

        g.MapPost("/users/{id:long}/unlock", (long id, HttpContext http, Db db) =>
        {
            db.Write((c, tx) =>
            {
                var name = c.ExecuteScalar<string?>("SELECT username FROM users WHERE id=@id", new { id }, tx) ?? throw AppException.NotFound("User");
                c.Execute("UPDATE users SET failed_count=0, locked_until=NULL, pin_failed=0, pin_locked_until=NULL WHERE id=@id", new { id }, tx);
                Audit.Write(c, tx, http.Ctx(), "USER_UNLOCKED", "users", name);
            });
            return new OkResult();
        }).Perm(Perm.UsersManage);

        g.MapGet("/roles", (Db db) => db.Read(c => c.Query<(long id, string name, long max_discount_bp)>("SELECT id, name, max_discount_bp FROM roles ORDER BY id").Select(r => new RoleDto
        {
            Id = r.id, Name = r.name, MaxDiscountPercent = r.max_discount_bp / 100m,
            Permissions = c.Query<string>("SELECT permission FROM role_permissions WHERE role_id=@id ORDER BY permission", new { r.id }).ToList(),
        }).ToList())).Authenticated();

        g.MapPut("/roles/{id:long}", (long id, HttpContext http, RoleDto r, Db db, AuthService auth) =>
        {
            var ctx = http.Ctx();
            var valid = Perm.Catalog.Select(p => p.Key).ToHashSet();
            if (r.Permissions.Any(p => !valid.Contains(p))) throw AppException.Validation("Unknown permission in the list.");
            if (r.MaxDiscountPercent < 0 || r.MaxDiscountPercent > 100) throw AppException.Validation("Maximum discount must be between 0 and 100%.");
            db.Write((c, tx) =>
            {
                var name = c.ExecuteScalar<string?>("SELECT name FROM roles WHERE id=@id", new { id }, tx) ?? throw AppException.NotFound("Role");
                var before = c.Query<string>("SELECT permission FROM role_permissions WHERE role_id=@id", new { id }, tx).OrderBy(x => x).ToList();
                var perms = name == "Administrator" ? valid.ToList() : r.Permissions;
                c.Execute("DELETE FROM role_permissions WHERE role_id=@id", new { id }, tx);
                foreach (var p in perms.Distinct()) c.Execute("INSERT INTO role_permissions(role_id, permission) VALUES(@id,@p)", new { id, p }, tx);
                c.Execute("UPDATE roles SET max_discount_bp=@bp WHERE id=@id", new { bp = Money.PercentToBp(r.MaxDiscountPercent), id }, tx);
                Audit.Write(c, tx, ctx, "PERMISSIONS_CHANGED", "roles", name, string.Join(",", before), string.Join(",", perms.OrderBy(x => x)) + $"; max discount {r.MaxDiscountPercent}%");
            });
            auth.InvalidateAll();
            return new OkResult { Id = id };
        }).Perm(Perm.UsersManage);

        g.MapGet("/permissions", () => Perm.Catalog).Authenticated();

        g.MapGet("/approvers", (string? action, Db db) => db.Read(c =>
        {
            var perm = string.IsNullOrEmpty(action) ? null : ApprovalService.PermissionFor(action);
            return c.Query<UserRow>("SELECT * FROM users WHERE is_active=1 AND pin_hash IS NOT NULL ORDER BY full_name").ToList()
                .Where(u => perm == null || AuthService.EffectivePermissions(c, u.Id, u.RoleId).Contains(perm))
                .Select(u => new IdName { Id = u.Id, Name = $"{u.FullName} ({u.Username})" }).ToList();
        })).Authenticated();

        // ---------- approvals ----------
        g.MapPost("/approvals/inline", (HttpContext http, InlineApprovalRequest r, ApprovalService s) => s.Inline(http.Ctx(), r)).Authenticated();
        g.MapPost("/approvals", (HttpContext http, DeferredApprovalRequest r, ApprovalService s) => s.RequestDeferred(http.Ctx(), r)).Authenticated();
        g.MapGet("/approvals", (HttpContext http, string? status, ApprovalService s) => s.List(http.Ctx(), status)).Perm(Perm.ApprovalsView);
        g.MapGet("/approvals/mine", (HttpContext http, ApprovalService s) => s.List(http.Ctx(), null).Where(a => a.Requester == http.Ctx().FullName).ToList()).Authenticated();
        g.MapGet("/approvals/{id:long}", (long id, HttpContext http, ApprovalService s) => s.Get(http.Ctx(), id)).Authenticated();
        g.MapPost("/approvals/{id:long}/decide", (long id, HttpContext http, DecideApprovalRequest r, ApprovalService s) => s.Decide(http.Ctx(), id, r)).Perm(Perm.ApprovalsView);

        // ---------- settings, audit, host ----------
        g.MapGet("/settings", (SettingsService s) => s.List()).Perm(Perm.SettingsManage);
        g.MapPut("/settings", (HttpContext http, Dictionary<string, string> values, SettingsService s) => { s.Update(http.Ctx(), values); return new OkResult { Message = "Settings saved." }; }).Perm(Perm.SettingsManage);

        g.MapGet("/audit", (DateTime? from, DateTime? to, string? action, string? user, string? q, Db db) =>
        {
            var (f, t) = Clock.UtcRange(from ?? Clock.Today.AddDays(-7), to ?? Clock.Today);
            return db.Read(c => c.Query(@"SELECT a.* FROM audit_logs a WHERE a.at >= @f AND a.at < @t
                    AND (@action IS NULL OR a.action = @action) AND (@user IS NULL OR a.username = @user)
                    AND (@q IS NULL OR a.record_ref LIKE '%'||@q||'%' OR a.after_json LIKE '%'||@q||'%' OR a.reason LIKE '%'||@q||'%')
                    ORDER BY a.id DESC LIMIT 2000", new { f, t, action = string.IsNullOrEmpty(action) ? null : action, user = string.IsNullOrEmpty(user) ? null : user, q = string.IsNullOrEmpty(q) ? null : q })
                .Select(r => new AuditRow
                {
                    Id = r.id, At = Clock.ToPkt((string)r.at), User = r.username, Workstation = r.workstation, Action = r.action, Module = r.module,
                    RecordRef = r.record_ref, Before = r.before_json, After = r.after_json, Reason = r.reason, Approver = r.approver,
                }).ToList());
        }).Perm(Perm.AuditView);

        g.MapGet("/host/status", (Db db, AuthService auth, CertificateInfo cert) => Status(db, auth, cert)).Perm(Perm.HostStatus);

        g.MapPut("/workstations/{id:long}", (long id, HttpContext http, WorkstationDto w, Db db) =>
        {
            db.Write((c, tx) =>
            {
                var name = c.ExecuteScalar<string?>("SELECT name FROM workstations WHERE id=@id", new { id }, tx) ?? throw AppException.NotFound("Workstation");
                c.Execute("UPDATE workstations SET is_active=@a WHERE id=@id", new { a = w.IsActive ? 1 : 0, id }, tx);
                if (!w.IsActive) c.Execute("UPDATE sessions SET revoked_at=@t WHERE workstation_id=@id AND revoked_at IS NULL", new { t = Clock.NowIso(), id }, tx);
                Audit.Write(c, tx, http.Ctx(), w.IsActive ? "WORKSTATION_ENABLED" : "WORKSTATION_BLOCKED", "host", name);
            });
            return new OkResult();
        }).Perm(Perm.HostStatus);

        // ---------- configurable lists ----------
        g.MapGet("/lists/{kind}", (string kind, Db db) => new LookupListDto
        {
            Kind = kind, Values = db.Read(c => c.Query<string>("SELECT name FROM lists WHERE kind=@kind AND is_active=1 ORDER BY name", new { kind }).ToList()),
        }).Authenticated();

        g.MapPut("/lists/{kind}", (string kind, HttpContext http, LookupListDto dto, Db db) =>
        {
            if (!ListKinds.All.Contains(kind)) throw AppException.Validation("Unknown list.");
            var ctx = http.Ctx();
            var needed = kind == ListKinds.ExpenseCategory ? Perm.SettingsManage : Perm.MedicinesEdit;
            ctx.Require(kind == ListKinds.ExpenseCategory || kind == ListKinds.AdjustmentReason ? Perm.SettingsManage : needed);
            db.Write((c, tx) =>
            {
                var values = dto.Values.Select(v => v.Trim()).Where(v => v.Length > 0).Distinct(StringComparer.OrdinalIgnoreCase).ToList();
                c.Execute("UPDATE lists SET is_active=0 WHERE kind=@kind", new { kind }, tx);
                foreach (var v in values)
                    c.Execute("INSERT INTO lists(kind, name, is_active) VALUES(@kind,@v,1) ON CONFLICT(kind, name) DO UPDATE SET is_active=1", new { kind, v }, tx);
                Audit.Write(c, tx, ctx, "LIST_CHANGED", "settings", kind, after: string.Join(", ", values));
            });
            return new OkResult();
        }).Authenticated();

        // ---------- cash and bank accounts ----------
        g.MapGet("/cash-accounts", (Db db) => db.Read(c => c.Query<CashAccountRow>("SELECT ca.*, a.code FROM cash_accounts ca JOIN accounts a ON a.id=ca.account_id ORDER BY ca.id")
            .Select(a => new CashAccountDto { Id = a.Id, Name = a.Name, Kind = a.Kind, AccountCode = a.Code, EnforceBalance = a.EnforceBalance == 1, IsActive = a.IsActive == 1, Balance = Money.FromPaisa(CashAccounts.Balance(c, null, a.AccountId)) }).ToList())).Authenticated();

        g.MapPost("/cash-accounts", (HttpContext http, CashAccountDto dto, Db db) =>
        {
            if (string.IsNullOrWhiteSpace(dto.Name)) throw AppException.Validation("Enter the account name.");
            if (!new[] { "CASH", "BANK", "CARD", "WALLET" }.Contains(dto.Kind)) throw AppException.Validation("Kind must be CASH, BANK, CARD or WALLET.");
            var id = db.Write((c, tx) =>
            {
                var prefix = dto.Kind switch { "BANK" => "101", "CARD" => "102", "WALLET" => "103", _ => "100" };
                var next = c.Query<string>("SELECT code FROM accounts WHERE code LIKE @p", new { p = prefix + "%" }, tx).Select(s => int.Parse(s)).DefaultIfEmpty(int.Parse(prefix + "0")).Max() + 1;
                var accountId = c.ExecuteScalar<long>("INSERT INTO accounts(code, name, type, is_system) VALUES(@code,@n,'ASSET',0) RETURNING id", new { code = next.ToString(), n = dto.Name.Trim() }, tx);
                var newId = c.ExecuteScalar<long>("INSERT INTO cash_accounts(account_id, name, kind, enforce_balance) VALUES(@a,@n,@k,@e) RETURNING id",
                    new { a = accountId, n = dto.Name.Trim(), k = dto.Kind, e = dto.EnforceBalance ? 1 : 0 }, tx);
                Audit.Write(c, tx, http.Ctx(), "CASH_ACCOUNT_CREATED", "accounts", dto.Name, after: dto.Kind);
                return newId;
            });
            return new OkResult { Id = id };
        }).Perm(Perm.SettingsManage);

        g.MapPut("/cash-accounts/{id:long}", (long id, HttpContext http, CashAccountDto dto, Db db) =>
        {
            db.Write((c, tx) =>
            {
                var a = CashAccounts.Get(c, tx, id);
                c.Execute("UPDATE cash_accounts SET name=@n, enforce_balance=@e, is_active=@act WHERE id=@id", new { n = dto.Name.Trim(), e = dto.EnforceBalance ? 1 : 0, act = dto.IsActive ? 1 : 0, id }, tx);
                c.Execute("UPDATE accounts SET name=@n WHERE id=@a", new { n = dto.Name.Trim(), a = a.AccountId }, tx);
                Audit.Write(c, tx, http.Ctx(), "CASH_ACCOUNT_CHANGED", "accounts", a.Name, after: Json.Serialize(dto));
            });
            return new OkResult();
        }).Perm(Perm.SettingsManage);
    }

    public static HostStatusDto Status(Db db, AuthService auth, CertificateInfo cert) => new()
    {
        ServerVersion = SetupAuthModule.Version, SchemaVersion = db.SchemaVersion, MachineName = Environment.MachineName, DbPath = db.DbPath,
        DbSizeBytes = File.Exists(db.DbPath) ? new FileInfo(db.DbPath).Length + (File.Exists(db.DbPath + "-wal") ? new FileInfo(db.DbPath + "-wal").Length : 0) : 0,
        StartedAtUtc = System.Diagnostics.Process.GetCurrentProcess().StartTime.ToUniversalTime(), Maintenance = db.Maintenance, CertificateFingerprint = cert.Fingerprint,
        ActiveSessions = auth.ActiveSessions,
        Workstations = db.Read(c => c.Query("SELECT * FROM workstations ORDER BY name").Select(w => new WorkstationDto
        {
            Id = w.id, Name = w.name, Os = w.os, ClientVersion = w.client_version, IsActive = w.is_active == 1, LastSeenAt = Clock.ToPktOrNull((string?)w.last_seen_at),
            LastUser = w.last_user, CounterNo = (int)(long)w.counter_no,
        }).ToList()),
    };

    private static void Validate(UserSaveRequest r, bool isNew, SettingsService s)
    {
        if (string.IsNullOrWhiteSpace(r.Username) || r.Username.Trim().Length < 3) throw AppException.Validation("Username must be at least 3 characters.");
        if (string.IsNullOrWhiteSpace(r.FullName)) throw AppException.Validation("Enter the full name.");
        if (r.SessionTimeoutMin < 1 || r.SessionTimeoutMin > 240) throw AppException.Validation("Session timeout must be 1 to 240 minutes.");
        if (isNew)
        {
            if (string.IsNullOrEmpty(r.Password)) throw AppException.Validation("Enter a first password.");
            Passwords.CheckPolicy(r.Username, r.Password, s.GetInt("security.minPasswordLength", 10));
        }
        if (!string.IsNullOrEmpty(r.Pin)) Passwords.CheckPin(r.Pin);
        var valid = Perm.Catalog.Select(p => p.Key).ToHashSet();
        if (r.GrantedPermissions.Concat(r.RevokedPermissions).Any(p => !valid.Contains(p))) throw AppException.Validation("Unknown permission.");
    }

    private static void SaveOverrides(Microsoft.Data.Sqlite.SqliteConnection c, Microsoft.Data.Sqlite.SqliteTransaction tx, long userId, UserSaveRequest r)
    {
        foreach (var p in r.GrantedPermissions.Distinct()) c.Execute("INSERT OR REPLACE INTO user_permission_overrides(user_id, permission, granted) VALUES(@u,@p,1)", new { u = userId, p }, tx);
        foreach (var p in r.RevokedPermissions.Distinct()) c.Execute("INSERT OR REPLACE INTO user_permission_overrides(user_id, permission, granted) VALUES(@u,@p,0)", new { u = userId, p }, tx);
    }

    private static UserDto ToDto(Microsoft.Data.Sqlite.SqliteConnection c, UserRow u) => new()
    {
        Id = u.Id, Username = u.Username, FullName = u.FullName, RoleId = u.RoleId,
        RoleName = c.ExecuteScalar<string>("SELECT name FROM roles WHERE id=@r", new { r = u.RoleId }) ?? "",
        IsActive = u.IsActive == 1, LastLoginAt = Clock.ToPktOrNull(u.LastLoginAt), LastWorkstation = u.LastWorkstation, SessionTimeoutMin = (int)u.SessionTimeoutMin,
        Locked = u.LockedUntil != null && DateTime.Parse(u.LockedUntil).ToUniversalTime() > Clock.UtcNow,
        GrantedPermissions = c.Query<string>("SELECT permission FROM user_permission_overrides WHERE user_id=@id AND granted=1", new { id = u.Id }).ToList(),
        RevokedPermissions = c.Query<string>("SELECT permission FROM user_permission_overrides WHERE user_id=@id AND granted=0", new { id = u.Id }).ToList(),
    };
}
