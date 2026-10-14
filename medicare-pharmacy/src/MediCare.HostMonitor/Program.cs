namespace MediCare.HostMonitor;

internal static class Program
{
    private const string MutexName = @"Local\MediCarePharmacy.HostMonitor";
    private const string ShowEventName = @"Local\MediCarePharmacy.HostMonitor.Show";

    /// <summary>Passed when the monitor relaunches itself elevated, so the new copy waits for the old one to exit.</summary>
    public const string RestartedFlag = "--restarted";

    /// <summary>
    /// Usage: MediCare.HostMonitor.exe [path]
    /// path: host-status.json, or the host data folder that contains it.
    /// Default: %ProgramData%\MediCarePharmacy\Data\host-status.json
    /// </summary>
    [STAThread]
    private static void Main(string[] args)
    {
        var restarted = args.Contains(RestartedFlag, StringComparer.OrdinalIgnoreCase);
        var pathArg = args.FirstOrDefault(a => !a.StartsWith("--", StringComparison.Ordinal));

        Mutex mutex;
        try
        {
            mutex = new Mutex(false, MutexName);
        }
        catch (UnauthorizedAccessException)
        {
            // An elevated monitor owns the name; a non-elevated copy cannot even open it.
            ApplicationConfiguration.Initialize();
            MessageBox.Show("MediCare Host Monitor is already running. Look for its icon in the notification area (system tray).",
                "MediCare Host Monitor", MessageBoxButtons.OK, MessageBoxIcon.Information);
            return;
        }

        using (mutex)
        {
            if (!Acquire(mutex, restarted ? TimeSpan.FromSeconds(15) : TimeSpan.Zero))
            {
                SignalRunningInstance();
                return;
            }

            try
            {
                using var showEvent = new EventWaitHandle(false, EventResetMode.AutoReset, ShowEventName);
                ApplicationConfiguration.Initialize();
                using var form = new MainForm(ResolveStatusPath(pathArg), pathArg);
                var registration = ThreadPool.RegisterWaitForSingleObject(showEvent, (_, _) => form.ShowFromOtherInstance(), null, Timeout.Infinite, false);
                try
                {
                    Application.Run(form);
                }
                finally
                {
                    registration.Unregister(null);
                }
            }
            finally
            {
                mutex.ReleaseMutex();
            }
        }
    }

    public static string ResolveStatusPath(string? arg)
    {
        if (string.IsNullOrWhiteSpace(arg))
            return Path.Combine(Environment.GetFolderPath(Environment.SpecialFolder.CommonApplicationData), "MediCarePharmacy", "Data", "host-status.json");

        var path = Path.GetFullPath(Environment.ExpandEnvironmentVariables(arg.Trim().Trim('"')));
        return Directory.Exists(path) || !path.EndsWith(".json", StringComparison.OrdinalIgnoreCase)
            ? Path.Combine(path, "host-status.json")
            : path;
    }

    private static bool Acquire(Mutex mutex, TimeSpan wait)
    {
        try
        {
            return mutex.WaitOne(wait);
        }
        catch (AbandonedMutexException)
        {
            return true; // previous owner exited without releasing; we own it now
        }
    }

    private static void SignalRunningInstance()
    {
        try
        {
            using var ev = EventWaitHandle.OpenExisting(ShowEventName);
            ev.Set();
        }
        catch (Exception ex) when (ex is WaitHandleCannotBeOpenedException or UnauthorizedAccessException)
        {
            // The other instance is starting up or shutting down; nothing more to do.
        }
    }
}
