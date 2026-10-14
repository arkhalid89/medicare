using System.IO;

namespace MediCare.Client.Infrastructure;

/// <summary>Small file log for the client (errors and printing problems). Never logs passwords, PINs or tokens.</summary>
public static class Log
{
    private static readonly object Gate = new object();

    public static void Info(string message) => Write("INF", message, null);
    public static void Error(string message, Exception? ex) => Write("ERR", message, ex);

    private static void Write(string level, string message, Exception? ex)
    {
        try
        {
            lock (Gate)
            {
                Directory.CreateDirectory(ClientConfig.LogDir);
                var file = Path.Combine(ClientConfig.LogDir, $"client-{DateTime.Now:yyyyMMdd}.log");
                File.AppendAllText(file, $"{DateTime.Now:yyyy-MM-dd HH:mm:ss.fff} [{level}] {message}{(ex == null ? "" : Environment.NewLine + ex)}{Environment.NewLine}");
            }
        }
        catch { /* logging must never break the counter */ }
    }
}
