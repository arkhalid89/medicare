using System.IO.Compression;
using System.Security.Cryptography;
using Dapper;
using MediCare.Contracts;
using MediCare.Host.Domain;
using MediCare.Host.Infrastructure;
using MediCare.Host.Security;
using Microsoft.Data.Sqlite;

namespace MediCare.Host.Modules;

public static class BackupModule
{
    public static void Map(WebApplication app)
    {
        var g = app.MapGroup("/api/v1");
        g.MapGet("/backups", (BackupService s) => s.List()).Perm(Perm.BackupRun);
        g.MapPost("/backups", (HttpContext http, BackupService s) => s.Create("MANUAL", http.Ctx())).Perm(Perm.BackupRun);
        g.MapGet("/backups/{id:long}/file", (long id, BackupService s) =>
        {
            var (path, name) = s.FilePath(id);
            return Results.File(path, "application/octet-stream", name);
        }).Perm(Perm.BackupRun);
        g.MapPost("/backups/{id:long}/copied", (long id, HttpContext http, BackupCopiedRequest r, BackupService s) => s.MarkCopied(http.Ctx(), id, r)).Perm(Perm.BackupRun);
        g.MapPost("/backups/upload", async (HttpRequest req, BackupService s) =>
        {
            var form = await req.ReadFormAsync();
            var file = form.Files.FirstOrDefault() ?? throw AppException.Validation("Choose the backup file.");
            await using var stream = file.OpenReadStream();
            return new OkResult { No = await s.SaveUpload(stream), Message = "Backup file uploaded to the host." };
        }).Perm(Perm.BackupRestore).DisableAntiforgery();
        g.MapPost("/restore", (HttpContext http, RestoreRequest r, BackupService s) => s.Restore(http.Ctx(), r)).Perm(Perm.BackupRestore);
    }
}

public sealed class BackupService(Db db, SettingsService settings, EventBus bus, AuthService auth, ILogger<BackupService> log)
{
    private readonly object _running = new();

    public string Folder
    {
        get
        {
            var f = settings.Get("backup.folder");
            var path = string.IsNullOrWhiteSpace(f) ? Path.Combine(db.DataDir, "Backups") : f;
            Directory.CreateDirectory(path);
            return path;
        }
    }

    public List<BackupDto> List() => db.Read(c => c.Query("SELECT * FROM backup_history ORDER BY id DESC LIMIT 300").Select(Map).ToList());

    private static BackupDto Map(dynamic r) => new()
    {
        Id = r.id, Kind = r.kind, FileName = r.file_name, SizeBytes = r.size_bytes, Sha256 = r.sha256, StartedAt = Clock.ToPkt((string)r.started_at),
        FinishedAt = Clock.ToPktOrNull((string?)r.finished_at), Result = r.result, CheckResult = r.check_result, Message = r.message, CopiedTo = r.copied_to,
    };

    /// <summary>Online, consistent backup of the live WAL database (ADR-012). Never overwrites an existing file.</summary>
    public BackupDto Create(string kind, Ctx? ctx, bool gateHeld = false)
    {
        lock (_running)
        {
            var started = Clock.NowIso();
            var stamp = Clock.NowPkt.ToString("yyyyMMdd-HHmmss");
            var folder = kind == "HOURLY" ? Path.Combine(Folder, "hourly") : kind == "SAFETY" ? Path.Combine(db.DataDir, "pre-restore") : Folder;
            Directory.CreateDirectory(folder);
            var fileName = $"medicare-{kind.ToLowerInvariant()}-{stamp}.mcbak";
            var path = Path.Combine(folder, fileName);
            for (int i = 2; File.Exists(path); i++) path = Path.Combine(folder, fileName = $"medicare-{kind.ToLowerInvariant()}-{stamp}-{i}.mcbak");
            var temp = Path.Combine(Path.GetTempPath(), $"medicare-bk-{Guid.NewGuid():N}.db");
            string result = "OK", check = "", message = "", sha = "";
            long size = 0;
            try
            {
                using (var source = db.OpenConnection())
                using (var dest = new SqliteConnection($"Data Source={temp};Pooling=False"))
                {
                    dest.Open();
                    source.BackupDatabase(dest);
                }
                using (var verify = new SqliteConnection($"Data Source={temp};Mode=ReadOnly;Pooling=False"))
                {
                    verify.Open();
                    check = verify.ExecuteScalar<string>(kind == "HOURLY" ? "PRAGMA quick_check" : "PRAGMA integrity_check") ?? "";
                    if (check != "ok") throw new InvalidOperationException("Backup copy failed its integrity check: " + check);
                    var manifest = new
                    {
                        app = "MediCare Pharmacy", version = SetupAuthModule.Version, schemaVersion = db.SchemaVersion, host = Environment.MachineName, createdUtc = started, kind,
                        counts = new
                        {
                            medicines = verify.ExecuteScalar<long>("SELECT COUNT(*) FROM medicines"),
                            sales = verify.ExecuteScalar<long>("SELECT COUNT(*) FROM sales_headers"),
                            purchases = verify.ExecuteScalar<long>("SELECT COUNT(*) FROM purchases"),
                            journal = verify.ExecuteScalar<long>("SELECT COUNT(*) FROM journal_entries"),
                        },
                        dbSha256 = Hashing.Sha256Hex(File.ReadAllBytes(temp)),
                    };
                    verify.Close();
                    SqliteConnection.ClearPool(verify);
                    using var zip = ZipFile.Open(path, ZipArchiveMode.Create);
                    zip.CreateEntryFromFile(temp, "medicare.db", CompressionLevel.Optimal);
                    var entry = zip.CreateEntry("manifest.json");
                    using var w = new StreamWriter(entry.Open());
                    w.Write(Json.Serialize(manifest));
                }
                size = new FileInfo(path).Length;
                using (var fs = File.OpenRead(path)) sha = Convert.ToHexString(SHA256.HashData(fs)).ToLowerInvariant();
                check = "integrity ok; SHA-256 recorded";
            }
            catch (Exception ex)
            {
                result = "FAILED";
                message = ex.Message;
                log.LogError(ex, "Backup {Kind} failed", kind);
                try { if (File.Exists(path)) File.Delete(path); } catch { }
            }
            finally
            {
                try { if (File.Exists(temp)) File.Delete(temp); } catch { }
            }
            var row = new { kind, file = Path.GetFileName(path), path, size, sha, schema = db.SchemaVersion, started, finished = Clock.NowIso(), result, check, message, u = ctx?.UserId == 0 ? null : ctx?.UserId };
            const string insert = @"INSERT INTO backup_history(kind, file_name, path, size_bytes, sha256, schema_version, started_at, finished_at, result, check_result, message, user_id)
                                    VALUES(@kind,@file,@path,@size,@sha,@schema,@started,@finished,@result,@check,@message,@u) RETURNING id";
            long id;
            if (gateHeld)
            {
                using var c = db.OpenConnection();
                using var tx = c.BeginTransaction();
                id = c.ExecuteScalar<long>(insert, row, tx);
                Audit.Write(c, tx, ctx ?? Ctx.System, result == "OK" ? "BACKUP" : "BACKUP_FAILED", "backup", Path.GetFileName(path), after: $"{kind} {size / 1048576.0:0.0} MB", reason: message);
                tx.Commit();
            }
            else
            {
                id = db.Write((c, tx) =>
                {
                    var newId = c.ExecuteScalar<long>(insert, row, tx);
                    Audit.Write(c, tx, ctx ?? Ctx.System, result == "OK" ? "BACKUP" : "BACKUP_FAILED", "backup", Path.GetFileName(path), after: $"{kind} {size / 1048576.0:0.0} MB", reason: message);
                    return newId;
                });
            }
            if (result == "OK" && kind is "DAILY" or "HOURLY") Prune(kind);
            var dto = db.Read(c => Map(c.QuerySingle("SELECT * FROM backup_history WHERE id=@id", new { id })));
            if (result != "OK" && kind == "MANUAL") throw new AppException(500, ErrorCodes.Internal, $"Backup failed: {message}. Nothing was overwritten.");
            return dto;
        }
    }

    private void Prune(string kind)
    {
        var keep = kind == "HOURLY" ? settings.GetInt("backup.keepHourly", 24) : settings.GetInt("backup.keepDaily", 30);
        var folder = kind == "HOURLY" ? Path.Combine(Folder, "hourly") : Folder;
        var files = Directory.GetFiles(folder, $"medicare-{kind.ToLowerInvariant()}-*.mcbak").OrderByDescending(f => f).Skip(Math.Max(1, keep)).ToList();
        foreach (var f in files)
        {
            try { File.Delete(f); } catch (Exception ex) { log.LogWarning(ex, "Could not delete old backup {File}", f); }
        }
    }

    public (string path, string name) FilePath(long id)
    {
        var r = db.Read(c => c.QueryOne<(string path, string file_name, string result)>("SELECT path, file_name, result FROM backup_history WHERE id=@id", new { id })) ?? throw AppException.NotFound("Backup");
        if (r.result != "OK" || !File.Exists(r.path)) throw AppException.NotFound("Backup file (it may have been removed by retention)");
        return (r.path, r.file_name);
    }

    public OkResult MarkCopied(Ctx ctx, long id, BackupCopiedRequest r)
    {
        db.Write((c, tx) =>
        {
            var file = c.ExecuteScalar<string?>("SELECT file_name FROM backup_history WHERE id=@id", new { id }, tx) ?? throw AppException.NotFound("Backup");
            if (r.Verified) c.Execute("UPDATE backup_history SET copied_to=@d WHERE id=@id", new { d = r.Destination, id }, tx);
            Audit.Write(c, tx, ctx, r.Verified ? "BACKUP_COPIED" : "BACKUP_COPY_FAILED", "backup", file, after: r.Destination, reason: r.Message);
        });
        return new OkResult();
    }

    public async Task<string> SaveUpload(Stream stream)
    {
        var dir = Path.Combine(Folder, "uploads");
        Directory.CreateDirectory(dir);
        var id = Guid.NewGuid().ToString("N");
        await using var fs = File.Create(Path.Combine(dir, id + ".mcbak"));
        await stream.CopyToAsync(fs);
        return id;
    }

    /// <summary>Administrator restore (FR-105): maintenance, safety backup, validate, swap, checks, audit, notify.</summary>
    public OkResult Restore(Ctx ctx, RestoreRequest r)
    {
        if (r.ConfirmPhrase != "RESTORE") throw AppException.Validation("Type RESTORE in capital letters to confirm.");
        var hash = db.Read(c => c.ExecuteScalar<string>("SELECT pwd_hash FROM users WHERE id=@id", new { id = ctx.UserId }));
        if (!Passwords.Verify(r.AdminPassword, hash)) throw new AppException(401, ErrorCodes.BadCredentials, "Administrator password is wrong.");
        string source;
        if (r.BackupId.HasValue) source = FilePath(r.BackupId.Value).path;
        else if (!string.IsNullOrEmpty(r.UploadId) && r.UploadId.All(char.IsLetterOrDigit)) source = Path.Combine(Folder, "uploads", r.UploadId + ".mcbak");
        else throw AppException.Validation("Choose a backup to restore.");
        if (!File.Exists(source)) throw AppException.NotFound("Backup file");

        var work = Path.Combine(Path.GetTempPath(), $"medicare-restore-{Guid.NewGuid():N}");
        Directory.CreateDirectory(work);
        var candidate = Path.Combine(work, "medicare.db");
        int schema;
        string createdUtc;
        try
        {
            using (var zip = ZipFile.OpenRead(source))
            {
                var dbEntry = zip.GetEntry("medicare.db") ?? throw AppException.Validation("This file is not a MediCare backup (database missing).");
                var man = zip.GetEntry("manifest.json") ?? throw AppException.Validation("This file is not a MediCare backup (manifest missing).");
                using var mr = new StreamReader(man.Open());
                var manifest = System.Text.Json.JsonDocument.Parse(mr.ReadToEnd()).RootElement;
                schema = manifest.GetProperty("schemaVersion").GetInt32();
                createdUtc = manifest.GetProperty("createdUtc").GetString() ?? "";
                if (schema > Db.LatestScriptVersion()) throw AppException.Validation($"This backup is from a newer MediCare version (schema {schema}). Upgrade the host first.");
                dbEntry.ExtractToFile(candidate);
                if (manifest.TryGetProperty("dbSha256", out var sh) && sh.GetString() != Hashing.Sha256Hex(File.ReadAllBytes(candidate)))
                    throw AppException.Validation("The backup file is damaged (checksum mismatch). Choose another backup.");
            }
            using (var check = new SqliteConnection($"Data Source={candidate};Pooling=False"))
            {
                check.Open();
                var ic = check.ExecuteScalar<string>("PRAGMA integrity_check");
                if (ic != "ok") throw AppException.Validation("The backup failed its integrity check: " + ic);
                check.Close();
            }
        }
        catch (InvalidDataException)
        {
            throw AppException.Validation("This file is not a valid MediCare backup.");
        }

        db.Maintenance = true;
        bus.Publish(EventTypes.Maintenance, new { message = "Restore in progress. All counters are logged out." });
        try
        {
            using (db.LockWrites(TimeSpan.FromSeconds(60)))
            {
                var safety = Create("SAFETY", ctx, gateHeld: true);
                if (safety.Result != "OK") throw new AppException(500, ErrorCodes.Internal, "Could not create the safety backup, so the restore was stopped. " + safety.Message);
                using (var c = db.OpenConnection()) c.Execute("PRAGMA wal_checkpoint(TRUNCATE);");
                Db.ClearPools();
                var keep = Path.Combine(db.DataDir, "pre-restore", $"medicare-live-{Clock.NowPkt:yyyyMMdd-HHmmss}.db");
                Directory.CreateDirectory(Path.GetDirectoryName(keep)!);
                File.Copy(db.DbPath, keep);
                foreach (var ext in new[] { "-wal", "-shm" }) if (File.Exists(db.DbPath + ext)) File.Delete(db.DbPath + ext);
                File.Copy(candidate, db.DbPath, overwrite: true);
                try
                {
                    db.Open(db.DbPath);
                    db.Migrate();
                    using var c = db.OpenConnection();
                    var ic = c.ExecuteScalar<string>("PRAGMA integrity_check");
                    var fk = c.Query("PRAGMA foreign_key_check").Count();
                    if (ic != "ok" || fk > 0) throw new InvalidOperationException($"Post-restore checks failed: integrity {ic}, foreign-key problems {fk}.");
                    using var tx = c.BeginTransaction();
                    c.Execute("UPDATE sessions SET revoked_at=@t WHERE revoked_at IS NULL", new { t = Clock.NowIso() }, tx);
                    Audit.Write(c, tx, ctx, "RESTORE", "backup", Path.GetFileName(source), after: $"backup created {createdUtc}; safety copy {safety.FileName}; previous live file kept at {keep}");
                    tx.Commit();
                }
                catch
                {
                    Db.ClearPools();
                    File.Copy(keep, db.DbPath, overwrite: true);
                    foreach (var ext in new[] { "-wal", "-shm" }) if (File.Exists(db.DbPath + ext)) File.Delete(db.DbPath + ext);
                    db.Open(db.DbPath);
                    db.Migrate();
                    throw;
                }
            }
        }
        finally
        {
            db.Maintenance = false;
            settings.Load();
            auth.InvalidateAll();
            try { Directory.Delete(work, true); } catch { }
        }
        var when = Clock.ToPkt(createdUtc);
        bus.Publish(EventTypes.Restored, new { message = $"Data restored to {Core.Dates.DisplayTime(when)}. Sales after that time are not in the system." });
        return new OkResult { Message = $"Restore complete. Data is now as of {Core.Dates.DisplayTime(when)}. Everyone must log in again. Sales made after that time are not in the system." };
    }
}

/// <summary>Scheduled backups, nightly self-check, housekeeping and the host status file (read by the host monitor).</summary>
public sealed class JobsService(Db db, SettingsService settings, BackupService backups, SelfCheckService selfCheck, AuthService auth, CertificateInfo cert, MediCareOptions options, ILogger<JobsService> log) : BackgroundService
{
    protected override async Task ExecuteAsync(CancellationToken ct)
    {
        string? lastDaily = db.Read(c => c.ExecuteScalar<string?>("SELECT MAX(started_at) FROM backup_history WHERE kind='DAILY' AND result='OK'"));
        var lastDailyDate = lastDaily == null ? "" : Clock.DateText(Clock.ToPkt(lastDaily));
        var lastHourly = Clock.NowPkt.ToString("yyyyMMddHH");
        var lastSelfCheck = "";
        while (!ct.IsCancellationRequested)
        {
            try
            {
                if (!db.Maintenance)
                {
                    auth.FlushLastSeen();
                    var now = Clock.NowPkt;
                    var today = Clock.DateText(now);
                    if (TimeSpan.TryParse(settings.Get("backup.dailyTime"), out var at) && now.TimeOfDay >= at && lastDailyDate != today)
                    {
                        lastDailyDate = today;
                        backups.Create("DAILY", null);
                    }
                    var hourKey = now.ToString("yyyyMMddHH");
                    if (settings.GetBool("backup.hourly") && hourKey != lastHourly && now.Minute < 10)
                    {
                        lastHourly = hourKey;
                        if (db.Read(c => c.ExecuteScalar<long>("SELECT COUNT(*) FROM stock_movements")) > 0 || db.Read(c => c.ExecuteScalar<long>("SELECT COUNT(*) FROM journal_entries")) > 0)
                            backups.Create("HOURLY", null);
                    }
                    if (TimeSpan.TryParse(settings.Get("backup.dailyTime"), out var at2) && now.TimeOfDay >= at2.Add(TimeSpan.FromMinutes(5)) && lastSelfCheck != today)
                    {
                        lastSelfCheck = today;
                        selfCheck.Run();
                    }
                    db.Write((c, tx) =>
                    {
                        c.Execute("DELETE FROM holds WHERE expires_at < @t", new { t = Clock.NowIso() }, tx);
                        c.Execute("DELETE FROM idempotency_keys WHERE created_at < @t", new { t = Clock.Iso(Clock.UtcNow.AddDays(-30)) }, tx);
                        c.Execute("UPDATE approvals SET status='EXPIRED' WHERE status='APPROVED' AND expires_at < @t", new { t = Clock.NowIso() }, tx);
                    });
                    WriteStatus();
                }
            }
            catch (Exception ex)
            {
                log.LogError(ex, "Background job failed");
            }
            await Task.Delay(TimeSpan.FromSeconds(30), ct).ContinueWith(_ => { });
        }
    }

    private void WriteStatus()
    {
        var status = AdminModule.Status(db, auth, cert);
        var last = db.Read(c => c.QueryOne<(string started_at, string result, string kind)>("SELECT started_at, result, kind FROM backup_history ORDER BY id DESC LIMIT 1"));
        var payload = new
        {
            status.ServerVersion, status.SchemaVersion, status.MachineName, status.DbPath, status.DbSizeBytes, status.CertificateFingerprint, status.ActiveSessions,
            Port = options.Port, Https = options.UseHttps, UpdatedUtc = Clock.UtcNow,
            LastBackup = last == null ? null : new { At = Clock.ToPkt(last.Value.started_at), last.Value.result, last.Value.kind },
            Workstations = status.Workstations.Select(w => new { w.Name, w.Os, w.ClientVersion, w.LastSeenAt, w.LastUser, w.IsActive }),
        };
        var path = Path.Combine(db.DataDir, "host-status.json");
        File.WriteAllText(path + ".tmp", Json.Serialize(payload));
        File.Move(path + ".tmp", path, overwrite: true);
    }
}
