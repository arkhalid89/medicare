using System.Reflection;
using System.Runtime.InteropServices;
using System.Threading;

namespace MediCare.Client;

internal static class Program
{
    public static string Version { get; } =
        (Assembly.GetExecutingAssembly().GetCustomAttribute<AssemblyInformationalVersionAttribute>()?.InformationalVersion ?? "1.0.0").Split('+')[0];

    private static Icon? _icon;
    public static Icon AppIcon => _icon ??= LoadIcon();

    private static Icon LoadIcon()
    {
        try { return Icon.ExtractAssociatedIcon(Application.ExecutablePath) ?? SystemIcons.Application; }
        catch { return SystemIcons.Application; }
    }

#if NETFRAMEWORK
    [DllImport("user32.dll")]
    private static extern bool SetProcessDPIAware();
#endif

    [STAThread]
    private static void Main()
    {
#if NETFRAMEWORK
        try { SetProcessDPIAware(); } catch { }
#else
        Application.SetHighDpiMode(HighDpiMode.SystemAware);
#endif
        Application.EnableVisualStyles();
        Application.SetCompatibleTextRenderingDefault(false);
        Application.SetUnhandledExceptionMode(UnhandledExceptionMode.CatchException);
        Application.ThreadException += (_, e) => Crash(e.Exception);
        AppDomain.CurrentDomain.UnhandledException += (_, e) => Crash(e.ExceptionObject as Exception);
        TaskScheduler.UnobservedTaskException += (_, e) => { Log.Error("Unobserved task", e.Exception); e.SetObserved(); };

        using var single = new Mutex(true, @"Local\MediCarePharmacyClient", out var first);
        if (!first)
        {
            MessageBox.Show("MediCare Pharmacy is already open on this PC. Look for it on the taskbar.", "MediCare Pharmacy", MessageBoxButtons.OK, MessageBoxIcon.Information);
            return;
        }

        Session.Config = ClientConfig.Load();
        Theme.Init(Session.Config.Compact);
        Log.Info($"Client {Version} starting on {Environment.OSVersion}");
        Run();
    }

    private static void Run()
    {
        var cfg = Session.Config;
        while (true)
        {
            if (!cfg.IsPaired)
            {
                using var pair = new PairingForm(cfg);
                if (pair.ShowDialog() != DialogResult.OK) return;
            }
            Session.Connect(cfg.HostUrl, cfg.Fingerprint);

            SetupStatus? status = null;
            try { status = Session.Api.Get<SetupStatus>("setup/status").GetAwaiter().GetResult(); }
            catch (ApiException ex) when (ex.Code == ApiException.CertMismatch)
            {
                if (MessageBox.Show(ex.Message + "\n\nPair this PC with the host again now?", "Security warning", MessageBoxButtons.YesNo, MessageBoxIcon.Warning) == DialogResult.Yes)
                {
                    cfg.Fingerprint = "";
                    continue;
                }
                return;
            }
            catch (ApiException) { /* offline: the login window says so and retries on Log in */ }

            if (status?.NeedsSetup == true)
            {
                using var setup = new SetupForm();
                if (setup.ShowDialog() != DialogResult.OK) return;
                status.NeedsSetup = false;
            }

            using (var login = new LoginForm(status))
            {
                var r = login.ShowDialog();
                if (r == DialogResult.Retry) { ForcePairing(cfg); continue; }
                if (r != DialogResult.OK) return;
            }

            bool again;
            using (var main = new MainForm())
            {
                Application.Run(main);
                again = main.LoggedOut;
            }
            Session.SetLogin(null);
            if (!again) return;
        }
    }

    private static void ForcePairing(ClientConfig cfg)
    {
        using var pair = new PairingForm(cfg);
        pair.ShowDialog();
    }

    private static void Crash(Exception? ex)
    {
        Log.Error("Unhandled", ex);
        try
        {
            MessageBox.Show("MediCare hit an unexpected problem on this PC and logged it. Posted sales are safe on the host.\n\n" + ex?.Message,
                "MediCare Pharmacy", MessageBoxButtons.OK, MessageBoxIcon.Error);
        }
        catch { }
    }
}
