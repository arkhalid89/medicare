using System.Collections;
using System.Collections.Concurrent;
using System.Globalization;
using System.Reflection;
using static MediCare.Core.Data.NativeSqlite;

namespace MediCare.Core.Data;

public sealed class SqliteException : Exception
{
    public SqliteException(string message, string? sql = null) : base(sql == null ? message : message + " [SQL: " + Trim(sql) + "]") { }
    private static string Trim(string s) => s.Length > 300 ? s[..300] + "…" : s;
}

/// <summary>Named query arguments: new Args { ["id"] = 5 }. Keys without the "@".</summary>
public sealed class Args : Dictionary<string, object?>
{
    public Args() : base(StringComparer.OrdinalIgnoreCase) { }

    /// <summary>Adds @prefix0..n for an IN list and returns "@prefix0,@prefix1,…".</summary>
    public string In(string prefix, IEnumerable values)
    {
        var names = new List<string>();
        int i = 0;
        foreach (var v in values)
        {
            var name = prefix + i++;
            this[name] = v;
            names.Add("@" + name);
        }
        if (names.Count == 0)
        {
            this[prefix + "none"] = null;
            names.Add("@" + prefix + "none");
        }
        return string.Join(",", names);
    }
}

/// <summary>A result row (case-insensitive column names) with typed accessors.</summary>
public sealed class Row : Dictionary<string, object?>
{
    public Row() : base(StringComparer.OrdinalIgnoreCase) { }
    public string Str(string key) => TryGetValue(key, out var v) && v != null ? Convert.ToString(v, CultureInfo.InvariantCulture) ?? "" : "";
    public string? StrOrNull(string key) => TryGetValue(key, out var v) && v != null ? Convert.ToString(v, CultureInfo.InvariantCulture) : null;
    public long Long(string key) => TryGetValue(key, out var v) && v != null ? Convert.ToInt64(v, CultureInfo.InvariantCulture) : 0;
    public int Int(string key) => (int)Long(key);
    public long? LongOrNull(string key) => TryGetValue(key, out var v) && v != null ? Convert.ToInt64(v, CultureInfo.InvariantCulture) : null;
    public decimal Dec(string key) => TryGetValue(key, out var v) && v != null ? Math.Round(Convert.ToDecimal(v, CultureInfo.InvariantCulture), 2) : 0m;
    public bool Bool(string key) => Long(key) != 0;
}

/// <summary>
/// Thread-safe SQLite access with named parameters (values are always bound,
/// never concatenated into SQL), a tiny object mapper and nested transactions.
/// </summary>
public sealed class Database : IDisposable
{
    private IntPtr _db;
    private readonly object _gate = new();
    private int _txDepth;

    public string FilePath { get; }

    public Database(string filePath)
    {
        FilePath = filePath;
        var dir = Path.GetDirectoryName(filePath);
        if (!string.IsNullOrEmpty(dir)) Directory.CreateDirectory(dir);
        int rc = sqlite3_open_v2(Utf8Z(filePath), out _db, SQLITE_OPEN_READWRITE | SQLITE_OPEN_CREATE | SQLITE_OPEN_FULLMUTEX, IntPtr.Zero);
        if (rc != SQLITE_OK)
        {
            var msg = FromUtf8(sqlite3_errmsg(_db)) ?? "error " + rc;
            sqlite3_close_v2(_db);
            _db = IntPtr.Zero;
            throw new SqliteException("Cannot open database '" + filePath + "': " + msg);
        }
        sqlite3_busy_timeout(_db, 8000);
        Execute("PRAGMA foreign_keys = ON; PRAGMA journal_mode = WAL; PRAGMA synchronous = NORMAL; PRAGMA temp_store = MEMORY;");
    }

    public static string EngineVersion => FromUtf8(sqlite3_libversion()) ?? "?";

    public bool InTransaction => _txDepth > 0;

    /// <summary>Runs one or more statements without parameters (schema scripts, pragmas).</summary>
    public void Execute(string sql)
    {
        lock (_gate)
        {
            EnsureOpen();
            int rc = sqlite3_exec(_db, Utf8Z(sql), IntPtr.Zero, IntPtr.Zero, out var err);
            if (rc != SQLITE_OK)
            {
                var msg = FromUtf8(err) ?? ErrMsg();
                sqlite3_free(err);
                throw new SqliteException(msg, sql);
            }
        }
    }

    /// <summary>Executes a single statement; returns the number of changed rows.</summary>
    public int Run(string sql, object? args = null)
    {
        lock (_gate)
        {
            var stmt = Prepare(sql, args);
            try
            {
                int rc = sqlite3_step(stmt);
                if (rc != SQLITE_DONE && rc != SQLITE_ROW) throw new SqliteException(ErrMsg(), sql);
                return sqlite3_changes(_db);
            }
            finally { sqlite3_finalize(stmt); }
        }
    }

    public List<Row> Rows(string sql, object? args = null)
    {
        lock (_gate)
        {
            var stmt = Prepare(sql, args);
            try
            {
                var list = new List<Row>();
                int cols = sqlite3_column_count(stmt);
                var names = new string[cols];
                for (int i = 0; i < cols; i++) names[i] = FromUtf8(sqlite3_column_name(stmt, i)) ?? "c" + i;
                while (true)
                {
                    int rc = sqlite3_step(stmt);
                    if (rc == SQLITE_DONE) break;
                    if (rc != SQLITE_ROW) throw new SqliteException(ErrMsg(), sql);
                    var row = new Row();
                    for (int i = 0; i < cols; i++) row[names[i]] = ReadColumn(stmt, i);
                    list.Add(row);
                }
                return list;
            }
            finally { sqlite3_finalize(stmt); }
        }
    }

    public Row? Row(string sql, object? args = null)
    {
        var rows = Rows(sql, args);
        return rows.Count > 0 ? rows[0] : null;
    }

    public object? Scalar(string sql, object? args = null)
    {
        var row = Row(sql, args);
        return row == null || row.Count == 0 ? null : row.Values.First();
    }

    public long Long(string sql, object? args = null) => Scalar(sql, args) is { } v ? Convert.ToInt64(v, CultureInfo.InvariantCulture) : 0;
    public decimal Dec(string sql, object? args = null) => Scalar(sql, args) is { } v ? Math.Round(Convert.ToDecimal(v, CultureInfo.InvariantCulture), 2) : 0m;
    public string? Str(string sql, object? args = null) => Scalar(sql, args) is { } v ? Convert.ToString(v, CultureInfo.InvariantCulture) : null;
    public bool Exists(string sql, object? args = null) => Scalar(sql, args) != null;

    public List<T> Query<T>(string sql, object? args = null) where T : new() => Rows(sql, args).Select(Mapper.Map<T>).ToList();
    public T? One<T>(string sql, object? args = null) where T : class, new() { var r = Row(sql, args); return r == null ? null : Mapper.Map<T>(r); }

    public long Insert(string table, IDictionary<string, object?> data)
    {
        var cols = data.Keys.ToList();
        var sql = "INSERT INTO " + table + " (" + string.Join(",", cols) + ") VALUES (" + string.Join(",", cols.Select(c => "@" + c)) + ")";
        lock (_gate)
        {
            Run(sql, data);
            return sqlite3_last_insert_rowid(_db);
        }
    }

    public int Update(string table, IDictionary<string, object?> data, string where, Args? whereArgs = null)
    {
        var args = new Args();
        foreach (var kv in data) args["v_" + kv.Key] = kv.Value;
        if (whereArgs != null) foreach (var kv in whereArgs) args[kv.Key] = kv.Value;
        var sql = "UPDATE " + table + " SET " + string.Join(", ", data.Keys.Select(c => c + " = @v_" + c)) + " WHERE " + where;
        return Run(sql, args);
    }

    public void Transaction(Action work) => Transaction(() => { work(); return true; });

    /// <summary>Runs work in a transaction; nested calls join the outer one.</summary>
    public T Transaction<T>(Func<T> work)
    {
        lock (_gate)
        {
            if (_txDepth > 0)
            {
                _txDepth++;
                try { return work(); }
                finally { _txDepth--; }
            }
            Execute("BEGIN IMMEDIATE");
            _txDepth = 1;
            try
            {
                var result = work();
                Execute("COMMIT");
                return result;
            }
            catch
            {
                try { Execute("ROLLBACK"); } catch { /* already rolled back */ }
                throw;
            }
            finally { _txDepth = 0; }
        }
    }

    /// <summary>Online backup of the whole database into a new file (SQLite backup API).</summary>
    public void BackupTo(string targetFile)
    {
        lock (_gate)
        {
            EnsureOpen();
            if (File.Exists(targetFile)) File.Delete(targetFile);
            int rc = sqlite3_open_v2(Utf8Z(targetFile), out var dest, SQLITE_OPEN_READWRITE | SQLITE_OPEN_CREATE, IntPtr.Zero);
            if (rc != SQLITE_OK) throw new SqliteException("Cannot create backup file: " + (FromUtf8(sqlite3_errmsg(dest)) ?? rc.ToString()));
            try
            {
                var bk = sqlite3_backup_init(dest, Utf8Z("main"), _db, Utf8Z("main"));
                if (bk == IntPtr.Zero) throw new SqliteException("Backup failed: " + FromUtf8(sqlite3_errmsg(dest)));
                sqlite3_backup_step(bk, -1);
                rc = sqlite3_backup_finish(bk);
                if (rc != SQLITE_OK) throw new SqliteException("Backup failed: " + FromUtf8(sqlite3_errmsg(dest)));
            }
            finally { sqlite3_close_v2(dest); }
        }
    }

    /// <summary>Copies another database file into this one (restore).</summary>
    public void RestoreFrom(string sourceFile)
    {
        lock (_gate)
        {
            EnsureOpen();
            int rc = sqlite3_open_v2(Utf8Z(sourceFile), out var src, SQLITE_OPEN_READWRITE, IntPtr.Zero);
            if (rc != SQLITE_OK) throw new SqliteException("Cannot open backup file.");
            try
            {
                var bk = sqlite3_backup_init(_db, Utf8Z("main"), src, Utf8Z("main"));
                if (bk == IntPtr.Zero) throw new SqliteException("Restore failed: " + ErrMsg());
                sqlite3_backup_step(bk, -1);
                rc = sqlite3_backup_finish(bk);
                if (rc != SQLITE_OK) throw new SqliteException("Restore failed: " + ErrMsg());
            }
            finally { sqlite3_close_v2(src); }
        }
    }

    public void Dispose()
    {
        lock (_gate)
        {
            if (_db != IntPtr.Zero)
            {
                try { Execute("PRAGMA wal_checkpoint(TRUNCATE);"); } catch { /* best effort */ }
                sqlite3_close_v2(_db);
                _db = IntPtr.Zero;
            }
        }
    }

    // ------------------------------------------------------------------

    private void EnsureOpen()
    {
        if (_db == IntPtr.Zero) throw new ObjectDisposedException(nameof(Database));
    }

    private string ErrMsg() => FromUtf8(sqlite3_errmsg(_db)) ?? "unknown SQLite error";

    private IntPtr Prepare(string sql, object? args)
    {
        EnsureOpen();
        var bytes = Utf8Z(sql);
        int rc = sqlite3_prepare_v2(_db, bytes, bytes.Length, out var stmt, out _);
        if (rc != SQLITE_OK) throw new SqliteException(ErrMsg(), sql);
        try
        {
            Bind(stmt, args, sql);
        }
        catch
        {
            sqlite3_finalize(stmt);
            throw;
        }
        return stmt;
    }

    private static void Bind(IntPtr stmt, object? args, string sql)
    {
        int count = sqlite3_bind_parameter_count(stmt);
        if (count == 0) return;
        var values = ToDictionary(args);
        for (int i = 1; i <= count; i++)
        {
            var name = FromUtf8(sqlite3_bind_parameter_name(stmt, i)) ?? "";
            var key = name.TrimStart('@', ':', '$');
            if (!values.TryGetValue(key, out var value))
                throw new SqliteException("Missing value for parameter " + name, sql);
            BindValue(stmt, i, value);
        }
    }

    private static void BindValue(IntPtr stmt, int i, object? value)
    {
        switch (value)
        {
            case null:
            case DBNull:
                sqlite3_bind_null(stmt, i); break;
            case bool b:
                sqlite3_bind_int64(stmt, i, b ? 1 : 0); break;
            case int or long or short or byte or uint or ushort or sbyte:
                sqlite3_bind_int64(stmt, i, Convert.ToInt64(value, CultureInfo.InvariantCulture)); break;
            case Enum e:
                sqlite3_bind_int64(stmt, i, Convert.ToInt64(e, CultureInfo.InvariantCulture)); break;
            case decimal or double or float:
                sqlite3_bind_double(stmt, i, Convert.ToDouble(value, CultureInfo.InvariantCulture)); break;
            case DateTime dt:
                BindText(stmt, i, dt.ToString("yyyy-MM-dd HH:mm:ss", CultureInfo.InvariantCulture)); break;
            case DateOnly d:
                BindText(stmt, i, d.ToString("yyyy-MM-dd", CultureInfo.InvariantCulture)); break;
            default:
                BindText(stmt, i, Convert.ToString(value, CultureInfo.InvariantCulture) ?? ""); break;
        }
    }

    private static void BindText(IntPtr stmt, int i, string s)
    {
        var bytes = System.Text.Encoding.UTF8.GetBytes(s);
        sqlite3_bind_text(stmt, i, bytes, bytes.Length, SQLITE_TRANSIENT);
    }

    private static object? ReadColumn(IntPtr stmt, int i)
    {
        switch (sqlite3_column_type(stmt, i))
        {
            case SQLITE_INTEGER: return sqlite3_column_int64(stmt, i);
            case SQLITE_FLOAT: return sqlite3_column_double(stmt, i);
            case SQLITE_NULL: return null;
            default:
                var ptr = sqlite3_column_text(stmt, i);
                var len = sqlite3_column_bytes(stmt, i);
                return FromUtf8(ptr, len);
        }
    }

    private static readonly ConcurrentDictionary<Type, PropertyInfo[]> PropCache = new();

    private static IDictionary<string, object?> ToDictionary(object? args)
    {
        switch (args)
        {
            case null: return new Args();
            case IDictionary<string, object?> d:
                return d is Args ? d : new Dictionary<string, object?>(d, StringComparer.OrdinalIgnoreCase);
        }
        var result = new Args();
        foreach (var p in PropCache.GetOrAdd(args.GetType(), t => t.GetProperties()))
            result[p.Name] = p.GetValue(args);
        return result;
    }
}

/// <summary>Maps rows to objects: snake_case columns → PascalCase properties.</summary>
public static class Mapper
{
    private static readonly ConcurrentDictionary<Type, Dictionary<string, PropertyInfo>> Cache = new();

    private static string Norm(string s) => s.Replace("_", "").ToLowerInvariant();

    public static T Map<T>(Row row) where T : new()
    {
        var props = Cache.GetOrAdd(typeof(T), t => t.GetProperties().Where(p => p.CanWrite)
            .GroupBy(p => Norm(p.Name)).ToDictionary(g => g.Key, g => g.First()));
        var obj = new T();
        foreach (var kv in row)
        {
            if (props.TryGetValue(Norm(kv.Key), out var p))
                p.SetValue(obj, ConvertTo(kv.Value, p.PropertyType));
        }
        return obj;
    }

    public static object? ConvertTo(object? value, Type type)
    {
        var target = Nullable.GetUnderlyingType(type) ?? type;
        if (value == null) return type.IsValueType && Nullable.GetUnderlyingType(type) == null ? Activator.CreateInstance(type) : null;
        if (target.IsInstanceOfType(value)) return value;
        var ci = CultureInfo.InvariantCulture;
        if (target == typeof(string)) return Convert.ToString(value, ci);
        if (target == typeof(bool)) return Convert.ToInt64(value, ci) != 0;
        if (target == typeof(decimal)) return Math.Round(Convert.ToDecimal(value, ci), 2);
        if (target == typeof(DateTime))
            return DateTime.TryParse(Convert.ToString(value, ci), ci, DateTimeStyles.None, out var dt) ? dt : default(DateTime);
        if (target == typeof(DateOnly))
            return DateOnly.TryParse(Convert.ToString(value, ci)?[..Math.Min(10, Convert.ToString(value, ci)!.Length)], ci, DateTimeStyles.None, out var d) ? d : default(DateOnly);
        if (target.IsEnum) return Enum.ToObject(target, Convert.ToInt64(value, ci));
        return Convert.ChangeType(value, target, ci);
    }
}
