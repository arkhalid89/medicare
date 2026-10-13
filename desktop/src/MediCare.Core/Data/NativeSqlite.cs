using System.Runtime.InteropServices;
using System.Text;

namespace MediCare.Core.Data;

/// <summary>
/// P/Invoke bindings to winsqlite3.dll — the SQLite engine that ships with
/// Windows 10/11 (System32). Using it means the application carries no native
/// database dependency of its own.
/// </summary>
internal static class NativeSqlite
{
    private const string Lib = "winsqlite3.dll";

    public const int SQLITE_OK = 0;
    public const int SQLITE_ROW = 100;
    public const int SQLITE_DONE = 101;
    public const int SQLITE_INTEGER = 1;
    public const int SQLITE_FLOAT = 2;
    public const int SQLITE_TEXT = 3;
    public const int SQLITE_BLOB = 4;
    public const int SQLITE_NULL = 5;
    public const int SQLITE_OPEN_READWRITE = 0x02;
    public const int SQLITE_OPEN_CREATE = 0x04;
    public const int SQLITE_OPEN_FULLMUTEX = 0x10000;
    public static readonly IntPtr SQLITE_TRANSIENT = new(-1);

    [DllImport(Lib, CallingConvention = CallingConvention.Winapi)]
    public static extern int sqlite3_open_v2(byte[] filename, out IntPtr db, int flags, IntPtr vfs);

    [DllImport(Lib, CallingConvention = CallingConvention.Winapi)]
    public static extern int sqlite3_close_v2(IntPtr db);

    [DllImport(Lib, CallingConvention = CallingConvention.Winapi)]
    public static extern int sqlite3_prepare_v2(IntPtr db, byte[] sql, int nBytes, out IntPtr stmt, out IntPtr tail);

    [DllImport(Lib, CallingConvention = CallingConvention.Winapi)]
    public static extern int sqlite3_exec(IntPtr db, byte[] sql, IntPtr callback, IntPtr arg, out IntPtr errmsg);

    [DllImport(Lib, CallingConvention = CallingConvention.Winapi)]
    public static extern void sqlite3_free(IntPtr ptr);

    [DllImport(Lib, CallingConvention = CallingConvention.Winapi)]
    public static extern int sqlite3_step(IntPtr stmt);

    [DllImport(Lib, CallingConvention = CallingConvention.Winapi)]
    public static extern int sqlite3_finalize(IntPtr stmt);

    [DllImport(Lib, CallingConvention = CallingConvention.Winapi)]
    public static extern int sqlite3_bind_int64(IntPtr stmt, int index, long value);

    [DllImport(Lib, CallingConvention = CallingConvention.Winapi)]
    public static extern int sqlite3_bind_double(IntPtr stmt, int index, double value);

    [DllImport(Lib, CallingConvention = CallingConvention.Winapi)]
    public static extern int sqlite3_bind_text(IntPtr stmt, int index, byte[] value, int nBytes, IntPtr destructor);

    [DllImport(Lib, CallingConvention = CallingConvention.Winapi)]
    public static extern int sqlite3_bind_null(IntPtr stmt, int index);

    [DllImport(Lib, CallingConvention = CallingConvention.Winapi)]
    public static extern int sqlite3_bind_parameter_count(IntPtr stmt);

    [DllImport(Lib, CallingConvention = CallingConvention.Winapi)]
    public static extern IntPtr sqlite3_bind_parameter_name(IntPtr stmt, int index);

    [DllImport(Lib, CallingConvention = CallingConvention.Winapi)]
    public static extern int sqlite3_column_count(IntPtr stmt);

    [DllImport(Lib, CallingConvention = CallingConvention.Winapi)]
    public static extern IntPtr sqlite3_column_name(IntPtr stmt, int index);

    [DllImport(Lib, CallingConvention = CallingConvention.Winapi)]
    public static extern int sqlite3_column_type(IntPtr stmt, int index);

    [DllImport(Lib, CallingConvention = CallingConvention.Winapi)]
    public static extern long sqlite3_column_int64(IntPtr stmt, int index);

    [DllImport(Lib, CallingConvention = CallingConvention.Winapi)]
    public static extern double sqlite3_column_double(IntPtr stmt, int index);

    [DllImport(Lib, CallingConvention = CallingConvention.Winapi)]
    public static extern IntPtr sqlite3_column_text(IntPtr stmt, int index);

    [DllImport(Lib, CallingConvention = CallingConvention.Winapi)]
    public static extern int sqlite3_column_bytes(IntPtr stmt, int index);

    [DllImport(Lib, CallingConvention = CallingConvention.Winapi)]
    public static extern IntPtr sqlite3_errmsg(IntPtr db);

    [DllImport(Lib, CallingConvention = CallingConvention.Winapi)]
    public static extern long sqlite3_last_insert_rowid(IntPtr db);

    [DllImport(Lib, CallingConvention = CallingConvention.Winapi)]
    public static extern int sqlite3_changes(IntPtr db);

    [DllImport(Lib, CallingConvention = CallingConvention.Winapi)]
    public static extern int sqlite3_busy_timeout(IntPtr db, int ms);

    [DllImport(Lib, CallingConvention = CallingConvention.Winapi)]
    public static extern IntPtr sqlite3_libversion();

    [DllImport(Lib, CallingConvention = CallingConvention.Winapi)]
    public static extern IntPtr sqlite3_backup_init(IntPtr dest, byte[] destName, IntPtr source, byte[] sourceName);

    [DllImport(Lib, CallingConvention = CallingConvention.Winapi)]
    public static extern int sqlite3_backup_step(IntPtr backup, int pages);

    [DllImport(Lib, CallingConvention = CallingConvention.Winapi)]
    public static extern int sqlite3_backup_finish(IntPtr backup);

    public static byte[] Utf8Z(string s)
    {
        var bytes = Encoding.UTF8.GetBytes(s);
        Array.Resize(ref bytes, bytes.Length + 1);
        return bytes;
    }

    public static string? FromUtf8(IntPtr ptr, int length = -1)
    {
        if (ptr == IntPtr.Zero) return null;
        return length >= 0 ? Marshal.PtrToStringUTF8(ptr, length) : Marshal.PtrToStringUTF8(ptr);
    }
}
