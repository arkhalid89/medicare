using System.Collections.Concurrent;
using System.Globalization;
using System.Reflection;
using System.Security.Cryptography;
using System.Text;
using System.Text.Json;
using System.Text.Json.Serialization;
using Dapper;
using MediCare.Contracts;
using Microsoft.Data.Sqlite;

namespace MediCare.Host.Infrastructure;

public sealed class MediCareOptions
{
    public string DataDir { get; set; } = "";
    public int Port { get; set; } = 8443;
    public bool UseHttps { get; set; } = true;
    public string BindAddress { get; set; } = "0.0.0.0";

    public string ResolveDataDir()
    {
        if (!string.IsNullOrWhiteSpace(DataDir)) return Path.GetFullPath(DataDir);
        if (OperatingSystem.IsWindows())
            return Path.Combine(Environment.GetFolderPath(Environment.SpecialFolder.CommonApplicationData), "MediCarePharmacy", "Data");
        return Path.Combine(Environment.GetFolderPath(Environment.SpecialFolder.UserProfile), "medicare-data");
    }
}

/// <summary>Domain error with a stable code (clients map codes to UX copy).</summary>
public sealed class AppException : Exception
{
    public AppException(int status, string code, string message, Dictionary<string, string>? details = null) : base(message)
    {
        Status = status; Code = code; Details = details;
    }
    public int Status { get; }
    public string Code { get; }
    public Dictionary<string, string>? Details { get; }

    public static AppException Validation(string message) => new(400, ErrorCodes.Validation, message);
    public static AppException NotFound(string what) => new(404, ErrorCodes.NotFound, $"{what} was not found.");
    public static AppException Conflict(string message) => new(409, ErrorCodes.Conflict, message);
}

public static class Clock
{
    public static Func<DateTime> UtcNowFn { get; set; } = () => DateTime.UtcNow;
    public static DateTime UtcNow => UtcNowFn();
    private static readonly CultureInfo Inv = CultureInfo.InvariantCulture;
    private static readonly TimeZoneInfo Pkt = FindPkt();

    private static TimeZoneInfo FindPkt()
    {
        foreach (var id in new[] { "Asia/Karachi", "Pakistan Standard Time" })
        {
            try { return TimeZoneInfo.FindSystemTimeZoneById(id); } catch { }
        }
        return TimeZoneInfo.CreateCustomTimeZone("PKT", TimeSpan.FromHours(5), "Pakistan Standard Time", "PKT");
    }

    public static string NowIso() => Iso(UtcNow);
    public static string Iso(DateTime utc) => utc.ToString("yyyy-MM-ddTHH:mm:ss.fffZ", Inv);
    public static DateTime NowPkt => TimeZoneInfo.ConvertTimeFromUtc(DateTime.SpecifyKind(UtcNow, DateTimeKind.Utc), Pkt);
    public static DateTime Today => NowPkt.Date;
    public static string BusinessDate() => NowPkt.ToString("yyyy-MM-dd", Inv);

    /// <summary>Converts a stored UTC ISO timestamp to Pakistan local time for display.</summary>
    public static DateTime ToPkt(string? isoUtc)
    {
        if (string.IsNullOrEmpty(isoUtc)) return default;
        var utc = DateTime.Parse(isoUtc, Inv, DateTimeStyles.AdjustToUniversal | DateTimeStyles.AssumeUniversal);
        return DateTime.SpecifyKind(TimeZoneInfo.ConvertTimeFromUtc(utc, Pkt), DateTimeKind.Unspecified);
    }

    public static DateTime? ToPktOrNull(string? isoUtc) => string.IsNullOrEmpty(isoUtc) ? null : ToPkt(isoUtc);

    public static DateTime ParseDate(string? yyyyMMdd) => string.IsNullOrEmpty(yyyyMMdd) ? default : DateTime.ParseExact(yyyyMMdd, "yyyy-MM-dd", Inv);
    public static DateTime? ParseDateOrNull(string? yyyyMMdd) => string.IsNullOrEmpty(yyyyMMdd) ? null : ParseDate(yyyyMMdd);
    public static string DateText(DateTime d) => d.ToString("yyyy-MM-dd", Inv);

    /// <summary>UTC range [from 00:00 PKT, to+1 00:00 PKT) for a business-date range.</summary>
    public static (string fromIso, string toIso) UtcRange(DateTime fromDate, DateTime toDate)
    {
        var f = TimeZoneInfo.ConvertTimeToUtc(DateTime.SpecifyKind(fromDate.Date, DateTimeKind.Unspecified), Pkt);
        var t = TimeZoneInfo.ConvertTimeToUtc(DateTime.SpecifyKind(toDate.Date.AddDays(1), DateTimeKind.Unspecified), Pkt);
        return (Iso(f), Iso(t));
    }
}

public static class Json
{
    public static readonly JsonSerializerOptions Options = new(JsonSerializerDefaults.Web)
    {
        DefaultIgnoreCondition = JsonIgnoreCondition.Never,
    };
    public static string Serialize<T>(T value) => JsonSerializer.Serialize(value, Options);
    public static T? Deserialize<T>(string json) => JsonSerializer.Deserialize<T>(json, Options);
}

public static class Hashing
{
    public static string Sha256Hex(string s) => Convert.ToHexString(SHA256.HashData(Encoding.UTF8.GetBytes(s))).ToLowerInvariant();
    public static string Sha256Hex(byte[] b) => Convert.ToHexString(SHA256.HashData(b)).ToLowerInvariant();
    public static string NewToken() => Convert.ToBase64String(RandomNumberGenerator.GetBytes(32)).Replace('+', '-').Replace('/', '_').TrimEnd('=');
}

/// <summary>
/// SQLite access (ADR-005). All writes go through one gate, each in a BEGIN IMMEDIATE transaction;
/// reads use separate pooled connections (WAL lets them run alongside the writer).
/// </summary>
public sealed class Db
{
    private readonly SemaphoreSlim _gate = new(1, 1);
    private string _cs = "";
    public string DbPath { get; private set; } = "";
    public string DataDir { get; }
    public volatile bool Maintenance;
    public int SchemaVersion { get; private set; }

    public Db(string dataDir)
    {
        DataDir = dataDir;
        Directory.CreateDirectory(dataDir);
        Open(Path.Combine(dataDir, "medicare.db"));
    }

    public void Open(string path)
    {
        DbPath = path;
        _cs = new SqliteConnectionStringBuilder { DataSource = path, Pooling = true, Mode = SqliteOpenMode.ReadWriteCreate }.ToString();
        using var c = new SqliteConnection(_cs);
        c.Open();
        c.Execute("PRAGMA journal_mode=WAL;");
    }

    private static void Pragmas(SqliteConnection c)
    {
        using var cmd = c.CreateCommand();
        // 64 MB page cache and memory-mapped reads per connection; synchronous stays FULL for money (ADR-005)
        cmd.CommandText = "PRAGMA foreign_keys=ON; PRAGMA busy_timeout=5000; PRAGMA synchronous=FULL; PRAGMA temp_store=MEMORY; PRAGMA cache_size=-65536; PRAGMA mmap_size=268435456;";
        cmd.ExecuteNonQuery();
    }

    public SqliteConnection OpenConnection()
    {
        var c = new SqliteConnection(_cs);
        c.Open();
        Pragmas(c);
        return c;
    }

    public T Read<T>(Func<SqliteConnection, T> work)
    {
        using var c = OpenConnection();
        return work(c);
    }

    public T Write<T>(Func<SqliteConnection, SqliteTransaction, T> work)
    {
        if (!_gate.Wait(TimeSpan.FromSeconds(30))) throw new AppException(503, ErrorCodes.Internal, "The host is busy. Try again in a moment.");
        try
        {
            using var c = OpenConnection();
            using var tx = c.BeginTransaction(deferred: false);
            var result = work(c, tx);
            tx.Commit();
            Interlocked.Increment(ref _writeVersion);
            return result;
        }
        finally { _gate.Release(); }
    }

    private long _writeVersion;

    /// <summary>Increases after every committed write; caches compare it to know whether data changed.</summary>
    public long WriteVersion => Interlocked.Read(ref _writeVersion);

    public void Write(Action<SqliteConnection, SqliteTransaction> work) => Write<int>((c, t) => { work(c, t); return 0; });

    /// <summary>Holds the write gate (used by restore so nothing is written while the file is replaced).</summary>
    public IDisposable LockWrites(TimeSpan timeout)
    {
        if (!_gate.Wait(timeout)) throw new AppException(503, ErrorCodes.Internal, "Could not stop writes: a posting is still running.");
        return new Releaser(_gate);
    }

    private sealed class Releaser(SemaphoreSlim s) : IDisposable { public void Dispose() => s.Release(); }

    public static void ClearPools() => SqliteConnection.ClearAllPools();

    // ---------- migrations ----------
    public int Migrate()
    {
        using var c = OpenConnection();
        c.Execute("CREATE TABLE IF NOT EXISTS schema_version(version INTEGER PRIMARY KEY, name TEXT NOT NULL, checksum TEXT NOT NULL, applied_at TEXT NOT NULL);");
        var applied = c.Query<(long version, string checksum)>("SELECT version, checksum FROM schema_version").ToDictionary(x => x.version, x => x.checksum);
        var asm = Assembly.GetExecutingAssembly();
        var scripts = asm.GetManifestResourceNames().Where(n => n.EndsWith(".sql", StringComparison.OrdinalIgnoreCase))
            .Select(n => (name: n, file: n.Split('.')[^2])).OrderBy(x => x.file).ToList();
        foreach (var (name, file) in scripts)
        {
            var version = int.Parse(file.Split('_')[0], CultureInfo.InvariantCulture);
            using var s = asm.GetManifestResourceStream(name)!;
            using var r = new StreamReader(s);
            var sql = r.ReadToEnd();
            var sum = Hashing.Sha256Hex(sql.Replace("\r\n", "\n"));
            if (applied.TryGetValue(version, out var existing))
            {
                if (existing != sum) throw new InvalidOperationException($"Migration {file} was changed after it was applied. Restore the original script.");
                continue;
            }
            using var tx = c.BeginTransaction();
            c.Execute(sql, transaction: tx);
            c.Execute("INSERT INTO schema_version(version, name, checksum, applied_at) VALUES(@v, @n, @s, @a)", new { v = version, n = file, s = sum, a = Clock.NowIso() }, tx);
            tx.Commit();
        }
        SchemaVersion = c.ExecuteScalar<int>("SELECT IFNULL(MAX(version),0) FROM schema_version");
        return SchemaVersion;
    }

    public static int LatestScriptVersion() =>
        Assembly.GetExecutingAssembly().GetManifestResourceNames().Where(n => n.EndsWith(".sql", StringComparison.OrdinalIgnoreCase))
            .Select(n => int.Parse(n.Split('.')[^2].Split('_')[0], CultureInfo.InvariantCulture)).DefaultIfEmpty(0).Max();
}

public static class DapperExtensions
{
    /// <summary>First row as a nullable value (works for value tuples, which Dapper cannot map to Nullable&lt;T&gt; directly).</summary>
    public static T? QueryOne<T>(this System.Data.IDbConnection c, string sql, object? param = null, System.Data.IDbTransaction? tx = null) where T : struct
    {
        foreach (var row in c.Query<T>(sql, param, tx)) return row;
        return null;
    }
}

public static class DapperSetup
{
    private static int _done;
    public static void Configure()
    {
        if (Interlocked.Exchange(ref _done, 1) == 1) return;
        SqlMapper.RemoveTypeMap(typeof(DateTime));
        SqlMapper.RemoveTypeMap(typeof(DateTime?));
        SqlMapper.AddTypeHandler(new DateTimeHandler());
        SqlMapper.RemoveTypeMap(typeof(bool));
        SqlMapper.RemoveTypeMap(typeof(bool?));
        SqlMapper.AddTypeHandler(new BoolHandler());
        DefaultTypeMap.MatchNamesWithUnderscores = true;
    }

    private sealed class DateTimeHandler : SqlMapper.TypeHandler<DateTime>
    {
        public override DateTime Parse(object value) => value switch
        {
            string s when s.Length == 10 => DateTime.ParseExact(s, "yyyy-MM-dd", CultureInfo.InvariantCulture),
            string s => DateTime.Parse(s, CultureInfo.InvariantCulture, DateTimeStyles.AdjustToUniversal | DateTimeStyles.AssumeUniversal),
            DateTime d => d,
            _ => Convert.ToDateTime(value, CultureInfo.InvariantCulture)
        };
        public override void SetValue(System.Data.IDbDataParameter parameter, DateTime value) => parameter.Value = value.ToString("yyyy-MM-dd", CultureInfo.InvariantCulture);
    }

    private sealed class BoolHandler : SqlMapper.TypeHandler<bool>
    {
        public override bool Parse(object value) => value switch { long l => l != 0, int i => i != 0, bool b => b, string s => s == "1" || s.Equals("true", StringComparison.OrdinalIgnoreCase), _ => Convert.ToBoolean(value, CultureInfo.InvariantCulture) };
        public override void SetValue(System.Data.IDbDataParameter parameter, bool value) => parameter.Value = value ? 1 : 0;
    }
}

/// <summary>In-memory event feed for long-polling clients (approvals, settings, maintenance).</summary>
public sealed class EventBus
{
    private readonly object _lock = new();
    private readonly LinkedList<EventDto> _events = new();
    private long _seq;
    private TaskCompletionSource _signal = new(TaskCreationOptions.RunContinuationsAsynchronously);

    public long LastSeq { get { lock (_lock) return _seq; } }

    public void Publish(string type, object? payload = null)
    {
        TaskCompletionSource toSignal;
        lock (_lock)
        {
            _events.AddLast(new EventDto { Seq = ++_seq, Type = type, Payload = payload == null ? null : Json.Serialize(payload), At = Clock.UtcNow });
            while (_events.Count > 500) _events.RemoveFirst();
            toSignal = _signal;
            _signal = new TaskCompletionSource(TaskCreationOptions.RunContinuationsAsynchronously);
        }
        toSignal.TrySetResult();
    }

    public async Task<EventsResponse> WaitAsync(long after, TimeSpan timeout, CancellationToken ct)
    {
        Task wait;
        lock (_lock)
        {
            if (after > _seq) after = 0;
            var list = _events.Where(e => e.Seq > after).ToList();
            if (list.Count > 0) return new EventsResponse { LastSeq = _seq, Events = list };
            wait = _signal.Task;
        }
        await Task.WhenAny(wait, Task.Delay(timeout, ct));
        lock (_lock) return new EventsResponse { LastSeq = _seq, Events = _events.Where(e => e.Seq > after).ToList() };
    }
}
