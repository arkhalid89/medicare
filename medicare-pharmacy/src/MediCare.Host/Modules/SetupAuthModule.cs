using System.Reflection;
using Dapper;
using MediCare.Contracts;
using MediCare.Host.Domain;
using MediCare.Host.Infrastructure;
using MediCare.Host.Security;

namespace MediCare.Host.Modules;

public static class SetupAuthModule
{
    public static string Version => Assembly.GetExecutingAssembly().GetName().Version?.ToString(3) ?? "1.0.0";

    public static void Map(WebApplication app)
    {
        var g = app.MapGroup("/api/v1");

        g.MapGet("/health", (Db db) => Results.Ok(new { ok = true, version = Version, maintenance = db.Maintenance, utc = Clock.UtcNow })).Anonymous();

        g.MapGet("/setup/status", (Db db, SettingsService s) => new SetupStatus
        {
            NeedsSetup = db.Read(c => c.ExecuteScalar<long>("SELECT COUNT(*) FROM users")) == 0,
            PharmacyName = s.Get("pharmacy.name"), ServerVersion = Version, SchemaVersion = db.SchemaVersion, Maintenance = db.Maintenance, ServerTimeUtc = Clock.UtcNow,
        }).Anonymous();

        g.MapPost("/setup", (SetupRequest r, Db db, SettingsService settings) =>
        {
            if (string.IsNullOrWhiteSpace(r.PharmacyName)) throw AppException.Validation("Enter the pharmacy name.");
            if (string.IsNullOrWhiteSpace(r.AdminFullName) || string.IsNullOrWhiteSpace(r.AdminUsername)) throw AppException.Validation("Enter the administrator's name and username.");
            Passwords.CheckPolicy(r.AdminUsername, r.AdminPassword, settings.GetInt("security.minPasswordLength", 10));
            Passwords.CheckPin(r.AdminPin);
            var pwd = Passwords.Hash(r.AdminPassword);
            var pin = Passwords.Hash(r.AdminPin);
            db.Write((c, tx) =>
            {
                if (c.ExecuteScalar<long>("SELECT COUNT(*) FROM users", transaction: tx) > 0) throw AppException.Conflict("Setup is already complete. Log in as the administrator.");
                var roleId = c.ExecuteScalar<long>("SELECT id FROM roles WHERE name='Administrator'", transaction: tx);
                c.Execute("INSERT INTO users(username, full_name, role_id, pwd_hash, pin_hash, created_at) VALUES(@u,@n,@r,@p,@pin,@t)",
                    new { u = r.AdminUsername.Trim(), n = r.AdminFullName.Trim(), r = roleId, p = pwd, pin, t = Clock.NowIso() }, tx);
                var sys = Ctx.System;
                settings.Set(c, tx, sys, "pharmacy.name", r.PharmacyName);
                settings.Set(c, tx, sys, "pharmacy.address", r.Address);
                settings.Set(c, tx, sys, "pharmacy.phone", r.Phone);
                settings.Set(c, tx, sys, "pharmacy.license", r.LicenseNo);
                Audit.Write(c, tx, sys, "SETUP_COMPLETED", "setup", r.AdminUsername, after: r.PharmacyName);
            });
            return new OkResult { Message = "Setup complete. Log in as " + r.AdminUsername + "." };
        }).Anonymous();

        g.MapGet("/pairing/fingerprint", (CertificateInfo cert) => new FingerprintDto { Sha256 = cert.Fingerprint, HostName = Environment.MachineName }).Anonymous();

        g.MapPost("/auth/login", (LoginRequest r, AuthService auth, Db db) =>
        {
            if (db.Read(c => c.ExecuteScalar<long>("SELECT COUNT(*) FROM users")) == 0)
                throw new AppException(409, ErrorCodes.SetupRequired, "First-run setup is not complete. Create the administrator first.");
            return auth.Login(r);
        }).Anonymous();

        g.MapPost("/auth/logout", (HttpContext http, AuthService auth) => { auth.Logout(http.Ctx()); return new OkResult(); }).MustChangeOk();
        g.MapPost("/auth/unlock", (HttpContext http, UnlockRequest r, AuthService auth) => { auth.Unlock(http.Ctx(), r.Password); return new OkResult(); }).MustChangeOk();
        g.MapPost("/auth/password", (HttpContext http, ChangePasswordRequest r, AuthService auth) => { auth.ChangePassword(http.Ctx(), r); return new OkResult { Message = "Password changed." }; }).MustChangeOk();

        g.MapGet("/auth/me", (HttpContext http, Db db, SettingsService s) =>
        {
            var ctx = http.Ctx();
            return new LoginResponse
            {
                User = new UserInfo { Id = ctx.UserId, Username = ctx.Username, FullName = ctx.FullName, RoleName = ctx.RoleName },
                Permissions = ctx.Permissions.OrderBy(p => p).ToList(), MustChangePassword = ctx.MustChangePassword, SessionTimeoutMin = ctx.TimeoutMin,
                MaxDiscountPercent = ctx.MaxDiscountPercent, WorkstationId = ctx.WorkstationId ?? 0, Settings = db.Read(c => s.ClientSettings(c, null)),
            };
        }).MustChangeOk();

        g.MapGet("/settings/client", (Db db, SettingsService s) => db.Read(c => s.ClientSettings(c, null))).Authenticated();

        g.MapGet("/events", async (long? after, EventBus bus, CancellationToken ct) =>
            await bus.WaitAsync(after ?? 0, TimeSpan.FromSeconds(25), ct)).Authenticated();
    }
}
