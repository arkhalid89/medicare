using System.Collections.Concurrent;
using System.Security.Cryptography;
using Dapper;
using MediCare.Contracts;
using MediCare.Host.Domain;
using MediCare.Host.Infrastructure;
using Microsoft.Data.Sqlite;

namespace MediCare.Host.Security;

/// <summary>PBKDF2-HMAC-SHA256, 600k iterations, 16-byte salt (ADR-009). Format: pbkdf2$iter$salt$hash.</summary>
public static class Passwords
{
    public static int Iterations { get; set; } = 600_000;

    public static string Hash(string secret)
    {
        var salt = RandomNumberGenerator.GetBytes(16);
        var hash = Rfc2898DeriveBytes.Pbkdf2(secret, salt, Iterations, HashAlgorithmName.SHA256, 32);
        return $"pbkdf2${Iterations}${Convert.ToBase64String(salt)}${Convert.ToBase64String(hash)}";
    }

    public static bool Verify(string secret, string? stored)
    {
        if (string.IsNullOrEmpty(stored)) return false;
        var parts = stored.Split('$');
        if (parts.Length != 4 || parts[0] != "pbkdf2") return false;
        var iter = int.Parse(parts[1]);
        var salt = Convert.FromBase64String(parts[2]);
        var expected = Convert.FromBase64String(parts[3]);
        var actual = Rfc2898DeriveBytes.Pbkdf2(secret, salt, iter, HashAlgorithmName.SHA256, expected.Length);
        return CryptographicOperations.FixedTimeEquals(actual, expected);
    }

    public static void CheckPolicy(string username, string password, int minLength)
    {
        if (password.Length < minLength) throw AppException.Validation($"Password must be at least {minLength} characters.");
        if (password.Equals(username, StringComparison.OrdinalIgnoreCase)) throw AppException.Validation("Password cannot be the same as the username.");
        if (!password.Any(char.IsLetter) || !password.Any(char.IsDigit)) throw AppException.Validation("Password must contain letters and numbers.");
    }

    public static void CheckPin(string pin)
    {
        if (pin.Length < 4 || pin.Length > 6 || !pin.All(char.IsDigit)) throw AppException.Validation("Approval PIN must be 4 to 6 digits.");
    }
}

/// <summary>The authenticated caller of a request.</summary>
public sealed class Ctx
{
    public long SessionId { get; init; }
    public long UserId { get; init; }
    public string Username { get; init; } = "";
    public string FullName { get; init; } = "";
    public long RoleId { get; init; }
    public string RoleName { get; init; } = "";
    public HashSet<string> Permissions { get; init; } = new();
    public long? WorkstationId { get; init; }
    public string WorkstationName { get; init; } = "";
    public int CounterNo { get; init; }
    public decimal MaxDiscountPercent { get; init; }
    public bool MustChangePassword { get; set; }
    public int TimeoutMin { get; init; }
    public bool Has(string p) => Permissions.Contains(p);
    public void Require(string p)
    {
        if (!Has(p)) throw new AppException(403, ErrorCodes.PermissionDenied, "You do not have permission for this action.");
    }

    /// <summary>System context for background jobs.</summary>
    public static Ctx System { get; } = new() { UserId = 0, Username = "system", FullName = "System", WorkstationName = Environment.MachineName };
}

public sealed record RequirePermAttribute(string Permission);
public sealed record AllowAnonymousApi;
public sealed record AllowWhenMustChange;

public static class EndpointExtensions
{
    public static RouteHandlerBuilder Perm(this RouteHandlerBuilder b, string permission) => b.WithMetadata(new RequirePermAttribute(permission));
    public static RouteHandlerBuilder Authenticated(this RouteHandlerBuilder b) => b.WithMetadata(new RequirePermAttribute(""));
    public static RouteHandlerBuilder Anonymous(this RouteHandlerBuilder b) => b.WithMetadata(new AllowAnonymousApi());
    public static RouteHandlerBuilder MustChangeOk(this RouteHandlerBuilder b) => b.WithMetadata(new AllowWhenMustChange()).WithMetadata(new RequirePermAttribute(""));
    public static Ctx Ctx(this HttpContext http) => (Ctx)http.Items["ctx"]!;
}

public sealed class AuthService(Db db, SettingsService settings, EventBus bus)
{
    private sealed class Entry
    {
        public required Ctx Ctx;
        public DateTime LastSeenUtc;
        public bool Dirty;
    }

    private readonly ConcurrentDictionary<string, Entry> _cache = new();

    public void InvalidateAll() => _cache.Clear();

    public static HashSet<string> EffectivePermissions(SqliteConnection c, long userId, long roleId, SqliteTransaction? tx = null)
    {
        var perms = c.Query<string>("SELECT permission FROM role_permissions WHERE role_id=@roleId", new { roleId }, tx).ToHashSet();
        foreach (var (p, g) in c.Query<(string, long)>("SELECT permission, granted FROM user_permission_overrides WHERE user_id=@userId", new { userId }, tx))
        {
            if (g != 0) perms.Add(p); else perms.Remove(p);
        }
        return perms;
    }

    public LoginResponse Login(LoginRequest req)
    {
        if (string.IsNullOrWhiteSpace(req.Username) || string.IsNullOrEmpty(req.Password))
            throw AppException.Validation("Enter both username and password.");
        var maxFail = settings.GetInt("security.maxFailedLogins", 5);
        var lockMin = settings.GetInt("security.lockMinutes", 15);

        // Verify outside the write gate (PBKDF2 is slow on purpose).
        var user = db.Read(c => c.QuerySingleOrDefault<UserRow>("SELECT * FROM users WHERE username=@u", new { u = req.Username.Trim() }));
        var ok = user != null && Passwords.Verify(req.Password, user.PwdHash);

        // Failures are recorded and committed first, then reported (throwing inside the
        // transaction would roll back the failed-attempt counter and the audit row).
        AppException? failure = null;
        var response = db.Write<LoginResponse?>((c, tx) =>
        {
            if (user == null)
            {
                Audit.Write(c, tx, null, "LOGIN_FAILED", "auth", req.Username, reason: "unknown user", workstation: req.WorkstationName);
                failure = new AppException(401, ErrorCodes.BadCredentials, "Username or password is wrong.");
                return null;
            }
            var now = Clock.UtcNow;
            if (user.LockedUntil != null && DateTime.Parse(user.LockedUntil).ToUniversalTime() > now)
                throw new AppException(423, ErrorCodes.AccountLocked, $"This account is locked after {maxFail} wrong attempts. Try again later or ask an administrator to unlock it.");
            if (!ok)
            {
                var fails = user.FailedCount + 1;
                string? lockUntil = fails >= maxFail ? Clock.Iso(now.AddMinutes(lockMin)) : null;
                c.Execute("UPDATE users SET failed_count=@f, locked_until=@l WHERE id=@id", new { f = fails >= maxFail ? 0 : fails, l = lockUntil, id = user.Id }, tx);
                Audit.Write(c, tx, null, "LOGIN_FAILED", "auth", user.Username, after: $"attempt {fails} of {maxFail}", workstation: req.WorkstationName);
                failure = lockUntil != null
                    ? new AppException(423, ErrorCodes.AccountLocked, $"Account locked for {lockMin} minutes after {maxFail} wrong attempts. An administrator can unlock it.")
                    : new AppException(401, ErrorCodes.BadCredentials, $"Username or password is wrong. {maxFail - fails} attempts left.");
                return null;
            }
            if (user.IsActive == 0) throw new AppException(403, ErrorCodes.AccountInactive, "This account is inactive. Ask an administrator.");

            // workstation
            long? wsId = null;
            if (!string.IsNullOrWhiteSpace(req.MachineId))
            {
                var ws = c.QueryOne<(long id, long active)>("SELECT id, is_active FROM workstations WHERE machine_id=@m", new { m = req.MachineId }, tx);
                if (ws == null)
                {
                    wsId = c.ExecuteScalar<long>("INSERT INTO workstations(machine_id, name, os, client_version, counter_no, created_at, last_seen_at, last_user) VALUES(@m,@n,@o,@v,@cn,@t,@t,@u) RETURNING id",
                        new { m = req.MachineId, n = req.WorkstationName, o = req.Os, v = req.ClientVersion, cn = req.CounterNo, t = Clock.NowIso(), u = user.Username }, tx);
                }
                else
                {
                    if (ws.Value.active == 0) throw new AppException(403, ErrorCodes.PermissionDenied, $"Workstation {req.WorkstationName} has been blocked by the administrator.");
                    wsId = ws.Value.id;
                    c.Execute("UPDATE workstations SET name=@n, os=@o, client_version=@v, counter_no=@cn, last_seen_at=@t, last_user=@u WHERE id=@id",
                        new { n = req.WorkstationName, o = req.Os, v = req.ClientVersion, cn = req.CounterNo, t = Clock.NowIso(), u = user.Username, id = wsId }, tx);
                }
            }

            var token = Hashing.NewToken();
            var sessionId = c.ExecuteScalar<long>("INSERT INTO sessions(token_hash, user_id, workstation_id, created_at, last_seen_at) VALUES(@h,@u,@w,@t,@t) RETURNING id",
                new { h = Hashing.Sha256Hex(token), u = user.Id, w = wsId, t = Clock.NowIso() }, tx);
            c.Execute("UPDATE users SET failed_count=0, locked_until=NULL, last_login_at=@t, last_workstation=@w WHERE id=@id",
                new { t = Clock.NowIso(), w = req.WorkstationName, id = user.Id }, tx);
            var ctx = BuildCtx(c, tx, sessionId, user.Id, wsId, req.WorkstationName, req.CounterNo);
            Audit.Write(c, tx, ctx, "LOGIN", "auth", user.Username);
            _cache[Hashing.Sha256Hex(token)] = new Entry { Ctx = ctx, LastSeenUtc = Clock.UtcNow };
            return new LoginResponse
            {
                Token = token,
                User = new UserInfo { Id = ctx.UserId, Username = ctx.Username, FullName = ctx.FullName, RoleName = ctx.RoleName },
                Permissions = ctx.Permissions.OrderBy(p => p).ToList(),
                MustChangePassword = ctx.MustChangePassword,
                SessionTimeoutMin = ctx.TimeoutMin,
                MaxDiscountPercent = ctx.MaxDiscountPercent,
                WorkstationId = wsId ?? 0,
                Settings = settings.ClientSettings(c, tx),
            };
        });
        if (failure != null) throw failure;
        return response!;
    }

    private static Ctx BuildCtx(SqliteConnection c, SqliteTransaction? tx, long sessionId, long userId, long? wsId, string wsName, int counterNo)
    {
        var u = c.QuerySingle<(long id, string username, string full_name, long role_id, string role_name, long max_bp, long must, long timeout)>(
            "SELECT u.id, u.username, u.full_name, u.role_id, r.name, r.max_discount_bp, u.must_change_pwd, u.session_timeout_min FROM users u JOIN roles r ON r.id=u.role_id WHERE u.id=@userId", new { userId }, tx);
        return new Ctx
        {
            SessionId = sessionId, UserId = u.id, Username = u.username, FullName = u.full_name, RoleId = u.role_id, RoleName = u.role_name,
            Permissions = EffectivePermissions(c, u.id, u.role_id, tx), WorkstationId = wsId, WorkstationName = wsName, CounterNo = counterNo,
            MaxDiscountPercent = u.max_bp / 100m, MustChangePassword = u.must != 0, TimeoutMin = (int)u.timeout,
        };
    }

    /// <summary>Resolves a bearer token. Server-side expiry = user timeout + grace (the client locks the screen at the timeout).</summary>
    public Ctx Resolve(string token)
    {
        var hash = Hashing.Sha256Hex(token);
        var grace = settings.GetInt("security.sessionGraceMin", 60);
        if (!_cache.TryGetValue(hash, out var e))
        {
            var row = db.Read(c => c.QuerySingleOrDefault<(long id, long user_id, long? ws, string last_seen, string? revoked, long active, string? wsname, long? counter)>(
                @"SELECT s.id, s.user_id, s.workstation_id, s.last_seen_at, s.revoked_at, u.is_active, w.name, w.counter_no
                  FROM sessions s JOIN users u ON u.id=s.user_id LEFT JOIN workstations w ON w.id=s.workstation_id WHERE s.token_hash=@hash", new { hash }));
            if (row.id == 0 || row.revoked != null || row.active == 0) throw new AppException(401, ErrorCodes.Unauthorized, "Please log in again.");
            var ctx = db.Read(c => BuildCtx(c, null, row.id, row.user_id, row.ws, row.wsname ?? "", (int)(row.counter ?? 0)));
            e = new Entry { Ctx = ctx, LastSeenUtc = DateTime.Parse(row.last_seen).ToUniversalTime() };
            _cache[hash] = e;
        }
        var now = Clock.UtcNow;
        if ((now - e.LastSeenUtc).TotalMinutes > e.Ctx.TimeoutMin + grace)
        {
            _cache.TryRemove(hash, out _);
            db.Write((c, tx) => c.Execute("UPDATE sessions SET revoked_at=@t WHERE id=@id", new { t = Clock.NowIso(), id = e.Ctx.SessionId }, tx));
            throw new AppException(401, ErrorCodes.SessionExpired, "Your session expired. Log in again.");
        }
        e.LastSeenUtc = now;
        e.Dirty = true;
        return e.Ctx;
    }

    public void Logout(Ctx ctx)
    {
        foreach (var kv in _cache.Where(kv => kv.Value.Ctx.SessionId == ctx.SessionId).ToList()) _cache.TryRemove(kv.Key, out _);
        db.Write((c, tx) =>
        {
            c.Execute("UPDATE sessions SET revoked_at=@t WHERE id=@id", new { t = Clock.NowIso(), id = ctx.SessionId }, tx);
            Audit.Write(c, tx, ctx, "LOGOUT", "auth", ctx.Username);
        });
    }

    public void RevokeAll(string reason)
    {
        _cache.Clear();
        db.Write((c, tx) => c.Execute("UPDATE sessions SET revoked_at=@t WHERE revoked_at IS NULL", new { t = Clock.NowIso() }, tx));
        bus.Publish(EventTypes.SessionRevoked, new { reason });
    }

    public void Unlock(Ctx ctx, string password)
    {
        var hash = db.Read(c => c.ExecuteScalar<string>("SELECT pwd_hash FROM users WHERE id=@id", new { id = ctx.UserId }));
        if (!Passwords.Verify(password, hash))
        {
            db.Write((c, tx) => Audit.Write(c, tx, ctx, "UNLOCK_FAILED", "auth", ctx.Username));
            throw new AppException(401, ErrorCodes.BadCredentials, "Password is wrong.");
        }
    }

    public void ChangePassword(Ctx ctx, ChangePasswordRequest req)
    {
        var row = db.Read(c => c.QuerySingle<UserRow>("SELECT * FROM users WHERE id=@id", new { id = ctx.UserId }));
        if (!Passwords.Verify(req.CurrentPassword, row.PwdHash)) throw new AppException(401, ErrorCodes.BadCredentials, "Current password is wrong.");
        Passwords.CheckPolicy(row.Username, req.NewPassword, settings.GetInt("security.minPasswordLength", 10));
        var history = Json.Deserialize<List<string>>(row.PwdHistory) ?? new();
        if (Passwords.Verify(req.NewPassword, row.PwdHash) || history.Any(h => Passwords.Verify(req.NewPassword, h)))
            throw AppException.Validation("Choose a password you have not used for your last 3 changes.");
        if (!string.IsNullOrEmpty(req.NewPin)) Passwords.CheckPin(req.NewPin);
        var newHash = Passwords.Hash(req.NewPassword);
        var pinHash = string.IsNullOrEmpty(req.NewPin) ? null : Passwords.Hash(req.NewPin);
        history.Insert(0, row.PwdHash);
        db.Write((c, tx) =>
        {
            c.Execute("UPDATE users SET pwd_hash=@h, must_change_pwd=0, pwd_history=@hist, pin_hash=COALESCE(@p, pin_hash) WHERE id=@id",
                new { h = newHash, hist = Json.Serialize(history.Take(3).ToList()), p = pinHash, id = ctx.UserId }, tx);
            Audit.Write(c, tx, ctx, "PASSWORD_CHANGED", "auth", ctx.Username);
        });
        ctx.MustChangePassword = false;
    }

    public void FlushLastSeen()
    {
        var dirty = _cache.Values.Where(e => e.Dirty).ToList();
        if (dirty.Count == 0) return;
        db.Write((c, tx) =>
        {
            foreach (var e in dirty)
            {
                c.Execute("UPDATE sessions SET last_seen_at=@t WHERE id=@id", new { t = Clock.Iso(e.LastSeenUtc), id = e.Ctx.SessionId }, tx);
                if (e.Ctx.WorkstationId != null) c.Execute("UPDATE workstations SET last_seen_at=@t, last_user=@u WHERE id=@id", new { t = Clock.Iso(e.LastSeenUtc), u = e.Ctx.Username, id = e.Ctx.WorkstationId }, tx);
                e.Dirty = false;
            }
        });
    }

    public int ActiveSessions => _cache.Count;
}

public sealed class UserRow
{
    public long Id { get; set; }
    public string Username { get; set; } = "";
    public string FullName { get; set; } = "";
    public long RoleId { get; set; }
    public string PwdHash { get; set; } = "";
    public string? PinHash { get; set; }
    public long MustChangePwd { get; set; }
    public long IsActive { get; set; }
    public long FailedCount { get; set; }
    public string? LockedUntil { get; set; }
    public long PinFailed { get; set; }
    public string? PinLockedUntil { get; set; }
    public long SessionTimeoutMin { get; set; }
    public string? LastLoginAt { get; set; }
    public string? LastWorkstation { get; set; }
    public string PwdHistory { get; set; } = "[]";
    public string CreatedAt { get; set; } = "";
}

/// <summary>Resolves the session and enforces endpoint permissions on the host (FR-008).</summary>
public sealed class AuthMiddleware(RequestDelegate next)
{
    public async Task Invoke(HttpContext http, AuthService auth, Db db)
    {
        var endpoint = http.GetEndpoint();
        var path = http.Request.Path.Value ?? "";
        if (!path.StartsWith("/api/", StringComparison.OrdinalIgnoreCase) || endpoint == null)
        {
            await next(http);
            return;
        }
        if (db.Maintenance && !path.Contains("/setup/status", StringComparison.OrdinalIgnoreCase) && !path.Contains("/health", StringComparison.OrdinalIgnoreCase))
            throw new AppException(503, ErrorCodes.Maintenance, "The host is in maintenance (restore in progress). Wait a few minutes, then log in again.");
        if (endpoint.Metadata.GetMetadata<AllowAnonymousApi>() != null)
        {
            await next(http);
            return;
        }
        var perm = endpoint.Metadata.GetMetadata<RequirePermAttribute>()
                   ?? throw new AppException(500, ErrorCodes.Internal, $"Endpoint {path} has no permission declared.");
        var header = http.Request.Headers.Authorization.ToString();
        if (!header.StartsWith("Bearer ", StringComparison.Ordinal)) throw new AppException(401, ErrorCodes.Unauthorized, "Please log in.");
        var ctx = auth.Resolve(header.Substring(7).Trim());
        if (ctx.MustChangePassword && endpoint.Metadata.GetMetadata<AllowWhenMustChange>() == null)
            throw new AppException(403, ErrorCodes.MustChangePassword, "Change your password before continuing.");
        if (perm.Permission.Length > 0 && !ctx.Has(perm.Permission))
        {
            db.Write((c, tx) => Audit.Write(c, tx, ctx, "PERMISSION_DENIED", "auth", $"{http.Request.Method} {path}", after: perm.Permission));
            throw new AppException(403, ErrorCodes.PermissionDenied, "You do not have permission for this action. Ask an administrator.");
        }
        http.Items["ctx"] = ctx;
        await next(http);
    }
}
