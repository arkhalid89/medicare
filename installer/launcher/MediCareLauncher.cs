// ============================================================================
//  MediCare Practice — desktop launcher (MediCare.exe)
//
//  Starts the bundled MariaDB and the application server, creates the
//  database on first run, opens the application in the default browser and
//  stays in the system tray. Exiting from the tray stops all servers.
//
//  Two modes (data\launcher.ini → server_mode):
//    local : this PC only — PHP's built-in server on 127.0.0.1 (v1 behaviour)
//    lan   : hospital server — nginx on all network cards + a pool of php-cgi
//            workers, so department PCs (front desk, lab, pharmacy …) open the
//            application in their browser. MariaDB always stays on 127.0.0.1.
//
//    MediCare.exe          start (or, if already running, just open the app)
//    MediCare.exe --tray   start without opening the browser (Windows startup)
//    MediCare.exe --stop   stop all servers of this installation (uninstaller)
//
//  Compiled with the C# compiler that ships with Windows (.NET Framework 4.x),
//  so the clinic PC needs nothing else installed.
// ============================================================================
using System;
using System.Collections.Generic;
using System.Diagnostics;
using System.Drawing;
using System.IO;
using System.Net;
using System.Net.Sockets;
using System.Text;
using System.Threading;
using System.Windows.Forms;
using Microsoft.Win32;

namespace MediCare
{
    internal static class Program
    {
        [STAThread]
        private static int Main(string[] args)
        {
            Paths paths = new Paths(AppDomain.CurrentDomain.BaseDirectory);
            List<string> a = new List<string>(args);

            if (a.Contains("--stop"))
            {
                Services.StopAll(paths, true);
                return 0;
            }

            bool created;
            using (Mutex mutex = new Mutex(true, "Local\\MediCarePracticeLauncher_" + paths.Base.GetHashCode().ToString("X"), out created))
            {
                if (!created)
                {
                    // Already running: just bring the application up in the browser.
                    Config cfg = Config.Load(paths);
                    Services.OpenBrowser(cfg.WebPort);
                    return 0;
                }
                Application.EnableVisualStyles();
                Application.SetCompatibleTextRenderingDefault(false);
                Application.Run(new LauncherContext(paths, !a.Contains("--tray")));
            }
            return 0;
        }
    }

    // ------------------------------------------------------------------ paths
    internal sealed class Paths
    {
        public readonly string Base, App, Public, Php, PhpIni, MariaBin, Data, DataDir, Sessions, Temp, Logs, Backups, Lock, MyIni;
        public readonly string PhpCgi, NginxDir, NginxExe, NginxData, NginxConf;

        public Paths(string baseDir)
        {
            Base = baseDir.TrimEnd('\\');
            App = Path.Combine(Base, "app");
            Public = Path.Combine(App, "public");
            Php = Path.Combine(Base, @"runtime\php\php.exe");
            PhpIni = Path.Combine(Base, @"runtime\php\php.ini");
            MariaBin = Path.Combine(Base, @"runtime\mariadb\bin");
            Data = Path.Combine(Base, "data");
            DataDir = Path.Combine(Data, "mysql");
            Sessions = Path.Combine(Data, "sessions");
            Temp = Path.Combine(Data, "tmp");
            Logs = Path.Combine(Base, "logs");
            Backups = Path.Combine(App, @"storage\backups");
            Lock = Path.Combine(App, @"storage\installed.lock");
            MyIni = Path.Combine(Data, "my.ini");
            PhpCgi = Path.Combine(Base, @"runtime\php\php-cgi.exe");
            NginxDir = Path.Combine(Base, @"runtime\nginx");
            NginxExe = Path.Combine(NginxDir, "nginx.exe");
            NginxData = Path.Combine(Data, "nginx");
            NginxConf = Path.Combine(NginxData, "nginx.conf");
        }

        /// <summary>True when the LAN web server (nginx + php-cgi) is bundled.</summary>
        public bool HasNginx { get { return File.Exists(NginxExe) && File.Exists(PhpCgi); } }

        public string MariaExe(string preferred, string fallback)
        {
            string p = Path.Combine(MariaBin, preferred);
            return File.Exists(p) ? p : Path.Combine(MariaBin, fallback);
        }
    }

    // ----------------------------------------------------------- launcher.ini
    internal sealed class Config
    {
        public int WebPort = 8765;
        public int DbPort = 33106;
        public bool Demo;
        public string Mode = "local";
        public int Workers = 8;
        private string file;

        public bool Lan { get { return Mode == "lan"; } }

        public static Config Load(Paths p)
        {
            Config c = new Config();
            c.file = Path.Combine(p.Data, "launcher.ini");
            if (File.Exists(c.file))
            {
                foreach (string raw in File.ReadAllLines(c.file))
                {
                    string line = raw.Trim();
                    int eq = line.IndexOf('=');
                    if (eq <= 0 || line.StartsWith(";") || line.StartsWith("[")) continue;
                    string key = line.Substring(0, eq).Trim().ToLowerInvariant();
                    string val = line.Substring(eq + 1).Trim();
                    int n;
                    if (key == "web_port" && int.TryParse(val, out n)) c.WebPort = n;
                    if (key == "db_port" && int.TryParse(val, out n)) c.DbPort = n;
                    if (key == "demo") c.Demo = val == "1";
                    if (key == "server_mode") c.Mode = val.ToLowerInvariant() == "lan" ? "lan" : "local";
                    if (key == "web_workers" && int.TryParse(val, out n) && n >= 2 && n <= 64) c.Workers = n;
                }
            }
            return c;
        }

        public void Save()
        {
            Directory.CreateDirectory(Path.GetDirectoryName(file));
            File.WriteAllText(file,
                "[launcher]\r\n; Ports used by MediCare Practice on this computer.\r\n" +
                "; server_mode=local  -> only this PC (127.0.0.1)\r\n" +
                "; server_mode=lan    -> hospital server: other PCs open http://<this-PC-IP>:" + WebPort + "/\r\n" +
                "web_port=" + WebPort + "\r\ndb_port=" + DbPort + "\r\ndemo=" + (Demo ? "1" : "0") + "\r\n" +
                "server_mode=" + Mode + "\r\nweb_workers=" + Workers + "\r\n");
        }
    }

    // --------------------------------------------------------------- services
    internal static class Services
    {
        public static void Log(Paths p, string message)
        {
            try
            {
                Directory.CreateDirectory(p.Logs);
                File.AppendAllText(Path.Combine(p.Logs, "launcher.log"), DateTime.Now.ToString("yyyy-MM-dd HH:mm:ss") + "  " + message + "\r\n");
            }
            catch { }
        }

        public static bool PortOpen(int port)
        {
            try
            {
                using (TcpClient c = new TcpClient())
                {
                    IAsyncResult r = c.BeginConnect("127.0.0.1", port, null, null);
                    bool ok = r.AsyncWaitHandle.WaitOne(400) && c.Connected;
                    if (ok) c.EndConnect(r);
                    return ok;
                }
            }
            catch { return false; }
        }

        public static int FreePort(int start)
        {
            for (int port = start; port < start + 200; port++)
            {
                if (!PortOpen(port)) return port;
            }
            throw new Exception("No free network port found near " + start + ".");
        }

        public static void OpenBrowser(int port)
        {
            try { Process.Start("http://127.0.0.1:" + port + "/"); } catch { }
        }

        /// <summary>IPv4 addresses of this PC on the hospital network.</summary>
        public static List<string> LanAddresses()
        {
            List<string> list = new List<string>();
            try
            {
                foreach (IPAddress ip in Dns.GetHostAddresses(Dns.GetHostName()))
                {
                    if (ip.AddressFamily == AddressFamily.InterNetwork && !IPAddress.IsLoopback(ip) && !ip.ToString().StartsWith("169.254."))
                        list.Add(ip.ToString());
                }
            }
            catch { }
            return list;
        }

        /// <summary>Opens the web port in Windows Firewall (asks for administrator rights).</summary>
        public static void AllowFirewall(int port)
        {
            try
            {
                ProcessStartInfo si = new ProcessStartInfo("cmd.exe",
                    "/c netsh advfirewall firewall delete rule name=\"MediCare Practice (LAN)\" & netsh advfirewall firewall add rule name=\"MediCare Practice (LAN)\" dir=in action=allow protocol=TCP localport=" + port + " profile=private,domain");
                si.Verb = "runas";
                si.UseShellExecute = true;
                si.WindowStyle = ProcessWindowStyle.Hidden;
                Process pr = Process.Start(si);
                if (pr != null) pr.WaitForExit(30000);
            }
            catch { }
        }

        /// <summary>Runs a helper program hidden and waits for it.</summary>
        public static int RunHidden(string exe, string arguments, string workDir, Dictionary<string, string> env, string logFile, int timeoutMs)
        {
            ProcessStartInfo si = new ProcessStartInfo(exe, arguments);
            si.UseShellExecute = false;
            si.CreateNoWindow = true;
            si.WorkingDirectory = workDir;
            si.RedirectStandardOutput = true;
            si.RedirectStandardError = true;
            if (env != null) foreach (KeyValuePair<string, string> kv in env) si.EnvironmentVariables[kv.Key] = kv.Value;
            using (Process pr = Process.Start(si))
            {
                StringBuilder output = new StringBuilder();
                pr.OutputDataReceived += delegate(object s, DataReceivedEventArgs e) { if (e.Data != null) lock (output) output.AppendLine(e.Data); };
                pr.ErrorDataReceived += delegate(object s, DataReceivedEventArgs e) { if (e.Data != null) lock (output) output.AppendLine(e.Data); };
                pr.BeginOutputReadLine();
                pr.BeginErrorReadLine();
                if (!pr.WaitForExit(timeoutMs))
                {
                    try { pr.Kill(); } catch { }
                    throw new Exception(Path.GetFileName(exe) + " did not finish in time.");
                }
                pr.WaitForExit();
                if (logFile != null) File.AppendAllText(logFile, DateTime.Now + "  " + Path.GetFileName(exe) + " " + arguments + "\r\n" + output + "\r\n");
                return pr.ExitCode;
            }
        }

        /// <summary>Stops MariaDB gracefully, then any leftover process started from this installation.</summary>
        /// <summary>True when a database server started from THIS installation is running.</summary>
        private static bool OwnDatabaseRunning(Paths p)
        {
            string bin = p.MariaBin.ToLowerInvariant();
            foreach (string name in new[] { "mariadbd", "mysqld" })
            {
                foreach (Process pr in Process.GetProcessesByName(name))
                {
                    try
                    {
                        if (pr.MainModule.FileName.ToLowerInvariant().StartsWith(bin)) return true;
                    }
                    catch { }
                }
            }
            return false;
        }

        public static void StopAll(Paths p, bool includeLaunchers)
        {
            Config cfg = Config.Load(p);
            string admin = p.MariaExe("mariadb-admin.exe", "mysqladmin.exe");
            // Never send "shutdown" to a port unless our own server is the one running:
            // another MediCare copy (or any MySQL) may be listening there.
            if (File.Exists(admin) && PortOpen(cfg.DbPort) && OwnDatabaseRunning(p))
            {
                try { RunHidden(admin, "--host=127.0.0.1 --port=" + cfg.DbPort + " --user=root shutdown", p.MariaBin, null, null, 20000); }
                catch (Exception ex) { Log(p, "shutdown failed: " + ex.Message); }
                for (int i = 0; i < 40 && PortOpen(cfg.DbPort); i++) Thread.Sleep(250);
            }
            string runtime = Path.Combine(p.Base, "runtime").ToLowerInvariant();
            int self = Process.GetCurrentProcess().Id;
            foreach (Process pr in Process.GetProcesses())
            {
                try
                {
                    if (pr.Id == self) continue;
                    string path = pr.MainModule.FileName.ToLowerInvariant();
                    bool ours = path.StartsWith(runtime) || (includeLaunchers && path == Path.Combine(p.Base, "MediCare.exe").ToLowerInvariant());
                    if (ours)
                    {
                        pr.Kill();
                        pr.WaitForExit(5000);
                    }
                }
                catch { }
            }
        }
    }

    // ---------------------------------------------------------------- splash
    internal sealed class Splash : Form
    {
        private readonly Label status;

        public Splash(Icon icon)
        {
            FormBorderStyle = FormBorderStyle.None;
            StartPosition = FormStartPosition.CenterScreen;
            Size = new Size(420, 170);
            BackColor = Color.FromArgb(10, 36, 99);
            ShowInTaskbar = true;
            Text = "MediCare Practice";
            Icon = icon;
            TopMost = true;

            PictureBox logo = new PictureBox();
            logo.Image = icon.ToBitmap();
            logo.SizeMode = PictureBoxSizeMode.StretchImage;
            logo.SetBounds(24, 28, 48, 48);
            Controls.Add(logo);

            Label title = new Label();
            title.Text = "MediCare Practice";
            title.ForeColor = Color.White;
            title.Font = new Font("Segoe UI", 15f, FontStyle.Bold);
            title.SetBounds(86, 26, 320, 32);
            Controls.Add(title);

            Label sub = new Label();
            sub.Text = "Medical Practice & Prescription Management";
            sub.ForeColor = Color.FromArgb(201, 162, 39);
            sub.Font = new Font("Segoe UI", 9f);
            sub.SetBounds(88, 58, 320, 20);
            Controls.Add(sub);

            ProgressBar bar = new ProgressBar();
            bar.Style = ProgressBarStyle.Marquee;
            bar.MarqueeAnimationSpeed = 25;
            bar.SetBounds(24, 104, 372, 8);
            Controls.Add(bar);

            status = new Label();
            status.ForeColor = Color.FromArgb(201, 211, 234);
            status.Font = new Font("Segoe UI", 9f);
            status.SetBounds(24, 120, 372, 24);
            status.Text = "Starting…";
            Controls.Add(status);
        }

        public void SetStatus(string text)
        {
            if (InvokeRequired) { BeginInvoke(new Action<string>(SetStatus), text); return; }
            status.Text = text;
        }
    }

    // ------------------------------------------------------- tray application
    internal sealed class LauncherContext : ApplicationContext
    {
        private readonly Paths p;
        private readonly bool openBrowser;
        private readonly NotifyIcon tray;
        private readonly Splash splash;
        private readonly Icon icon;
        private Config cfg;
        private Process mysqld, php, nginx;
        private readonly List<Process> cgis = new List<Process>();
        private ToolStripMenuItem lanItem, modeItem;
        private System.Windows.Forms.Timer watchdog;
        private volatile bool stopping;

        public LauncherContext(Paths paths, bool open)
        {
            p = paths;
            openBrowser = open;
            icon = Icon.ExtractAssociatedIcon(Application.ExecutablePath) ?? SystemIcons.Application;

            ContextMenuStrip menu = new ContextMenuStrip();
            ToolStripMenuItem openItem = new ToolStripMenuItem("Open MediCare Practice", null, delegate { Services.OpenBrowser(cfg.WebPort); });
            openItem.Font = new Font(openItem.Font, FontStyle.Bold);
            menu.Items.Add(openItem);
            lanItem = new ToolStripMenuItem("Network address: (starting)", null, delegate { CopyLanAddress(); });
            lanItem.Visible = false;
            menu.Items.Add(lanItem);
            modeItem = new ToolStripMenuItem("Server mode…", null, delegate { ToggleMode(); });
            menu.Items.Add(modeItem);
            menu.Items.Add(new ToolStripSeparator());
            menu.Items.Add("Open backups folder", null, delegate { OpenFolder(p.Backups); });
            menu.Items.Add("Open logs folder", null, delegate { OpenFolder(p.Logs); });
            menu.Items.Add(new ToolStripSeparator());
            menu.Items.Add("Stop server and exit", null, delegate { ExitRequested(); });

            tray = new NotifyIcon();
            tray.Icon = icon;
            tray.Text = "MediCare Practice — starting";
            tray.ContextMenuStrip = menu;
            tray.DoubleClick += delegate { if (cfg != null) Services.OpenBrowser(cfg.WebPort); };
            tray.Visible = true;

            splash = new Splash(icon);
            // Create the window handle now: the start-up thread marshals UI
            // updates through it even when the splash stays hidden (--tray).
            IntPtr handle = splash.Handle;
            if (handle != IntPtr.Zero && openBrowser) splash.Show();

            SystemEvents.SessionEnding += delegate { StopServices(); };

            Thread t = new Thread(StartServices);
            t.IsBackground = true;
            t.Start();
        }

        private static void OpenFolder(string path)
        {
            try { Directory.CreateDirectory(path); Process.Start("explorer.exe", "\"" + path + "\""); } catch { }
        }

        private void StartServices()
        {
            try
            {
                Directory.CreateDirectory(p.Data);
                Directory.CreateDirectory(p.Sessions);
                Directory.CreateDirectory(p.Temp);
                Directory.CreateDirectory(p.Logs);
                cfg = Config.Load(p);
                Services.Log(p, "Starting from " + p.Base);

                // Leftovers from an unclean shutdown (power cut) are stopped first.
                Services.StopAll(p, false);

                // -------- MariaDB -------------------------------------------
                bool freshDatabase = false;
                if (!Directory.Exists(Path.Combine(p.DataDir, "mysql")))
                {
                    splash.SetStatus("Creating the database for first use…");
                    freshDatabase = true;
                    if (Directory.Exists(p.DataDir)) Directory.Delete(p.DataDir, true);
                    string installer = p.MariaExe("mariadb-install-db.exe", "mysql_install_db.exe");
                    int code = Services.RunHidden(installer, "--datadir=\"" + p.DataDir + "\" --port=" + cfg.DbPort,
                        p.MariaBin, null, Path.Combine(p.Logs, "setup.log"), 300000);
                    if (code != 0) throw new Exception("The database could not be initialised (see logs\\setup.log).");
                }

                if (Services.PortOpen(cfg.DbPort)) cfg.DbPort = Services.FreePort(cfg.DbPort + 1);
                if (Services.PortOpen(cfg.WebPort))
                {
                    // Department PCs bookmark the server address: in LAN mode the port never moves.
                    if (cfg.Lan) throw new Exception("Port " + cfg.WebPort + " is used by another program. Close it, or change web_port in data\\launcher.ini (and the bookmarks on the other PCs).");
                    cfg.WebPort = Services.FreePort(cfg.WebPort + 1);
                }
                cfg.Save();

                splash.SetStatus("Starting the database server…");
                WriteMyIni();
                StartMysqld();
                WaitForPort(cfg.DbPort, 90, "The database server did not start (see logs\\mysql.err).", mysqld);

                // -------- Application database ------------------------------
                if (freshDatabase && File.Exists(p.Lock)) File.Delete(p.Lock);
                if (!File.Exists(p.Lock))
                {
                    splash.SetStatus(cfg.Demo ? "Installing tables, master data and demo patients…" : "Installing tables and master data…");
                    int code = Services.RunHidden(p.Php, PhpArgs() + " \"" + Path.Combine(p.App, @"database\install.php") + "\"" + (cfg.Demo ? " --demo" : ""),
                        p.App, AppEnv(), Path.Combine(p.Logs, "setup.log"), 600000);
                    if (code != 0 || !File.Exists(p.Lock)) throw new Exception("Application setup failed (see logs\\setup.log).");
                    cfg.Demo = false;
                    cfg.Save();
                }

                // -------- Web server ----------------------------------------
                splash.SetStatus("Starting the application server…");
                StartPhp();
                WaitForPort(cfg.WebPort, 30, "The application server did not start (see logs\\nginx-error.log / php-error.log).", UsesNginx() ? nginx : php);
                Services.Log(p, "Ready on http://127.0.0.1:" + cfg.WebPort + " (database port " + cfg.DbPort + ", mode " + cfg.Mode + (cfg.Lan ? ", LAN " + string.Join(", ", Services.LanAddresses().ToArray()) : "") + ")");

                splash.BeginInvoke(new Action(Ready));
            }
            catch (Exception ex)
            {
                Services.Log(p, "ERROR: " + ex);
                splash.BeginInvoke(new Action(delegate
                {
                    splash.Hide();
                    MessageBox.Show(ex.Message + "\n\nLogs: " + p.Logs, "MediCare Practice could not start", MessageBoxButtons.OK, MessageBoxIcon.Error);
                    StopServices();
                    tray.Visible = false;
                    ExitThread();
                }));
            }
        }

        private void Ready()
        {
            splash.Hide();
            string addr = LanAddress();
            tray.Text = cfg.Lan ? ("MediCare — server " + addr).Substring(0, Math.Min(63, ("MediCare — server " + addr).Length)) : "MediCare Practice — running (port " + cfg.WebPort + ")";
            modeItem.Text = cfg.Lan ? "Switch to this-PC-only mode…" : "Make this PC the hospital server (LAN)…";
            if (cfg.Lan)
            {
                lanItem.Text = "Network address: " + addr + "  (click to copy)";
                lanItem.Visible = true;
            }
            if (openBrowser) Services.OpenBrowser(cfg.WebPort);
            tray.ShowBalloonTip(5000, "MediCare Practice is running",
                cfg.Lan ? "Other PCs on the network open " + addr + " in their browser." : "Double-click this icon to open the application. Right-click to stop the server.", ToolTipIcon.Info);

            watchdog = new System.Windows.Forms.Timer();
            watchdog.Interval = 10000;
            watchdog.Tick += delegate { Watch(); };
            watchdog.Start();
        }

        /// <summary>Restarts a server that stopped unexpectedly.</summary>
        private void Watch()
        {
            if (stopping) return;
            try
            {
                if (mysqld == null || mysqld.HasExited)
                {
                    Services.Log(p, "Database server stopped unexpectedly - restarting");
                    StartMysqld();
                }
                if (UsesNginx())
                {
                    if (nginx == null || nginx.HasExited)
                    {
                        Services.Log(p, "Web server stopped unexpectedly - restarting");
                        StartNginx();
                    }
                    for (int i = 0; i < cgis.Count; i++)
                    {
                        if (cgis[i] == null || cgis[i].HasExited) cgis[i] = StartCgi(9100 + i);
                    }
                }
                else if (php == null || php.HasExited)
                {
                    Services.Log(p, "Application server stopped unexpectedly - restarting");
                    StartPhp();
                }
            }
            catch (Exception ex) { Services.Log(p, "Watchdog: " + ex.Message); }
        }

        private void WriteMyIni()
        {
            string fwd = p.DataDir.Replace('\\', '/');
            File.WriteAllText(p.MyIni,
                "[mysqld]\r\n" +
                "datadir=\"" + fwd + "\"\r\n" +
                "port=" + cfg.DbPort + "\r\n" +
                "bind-address=127.0.0.1\r\n" +
                "skip-name-resolve\r\n" +
                "character-set-server=utf8mb4\r\n" +
                "collation-server=utf8mb4_unicode_ci\r\n" +
                "innodb_buffer_pool_size=256M\r\n" +
                "max_allowed_packet=64M\r\n" +
                "log-error=\"" + Path.Combine(p.Logs, "mysql.err").Replace('\\', '/') + "\"\r\n" +
                "[client]\r\nport=" + cfg.DbPort + "\r\nhost=127.0.0.1\r\n");
        }

        private void StartMysqld()
        {
            ProcessStartInfo si = new ProcessStartInfo(p.MariaExe("mariadbd.exe", "mysqld.exe"), "--defaults-file=\"" + p.MyIni + "\"");
            si.UseShellExecute = false;
            si.CreateNoWindow = true;
            si.WorkingDirectory = p.MariaBin;
            mysqld = Process.Start(si);
        }

        private string PhpArgs()
        {
            string ext = Path.Combine(Path.GetDirectoryName(p.Php), "ext");
            return "-c \"" + p.PhpIni + "\" -d extension_dir=\"" + ext + "\" -d session.save_path=\"" + p.Sessions + "\"" +
                   " -d sys_temp_dir=\"" + p.Temp + "\" -d upload_tmp_dir=\"" + p.Temp + "\" -d error_log=\"" + Path.Combine(p.Logs, "php-error.log") + "\"";
        }

        private Dictionary<string, string> AppEnv()
        {
            Dictionary<string, string> env = new Dictionary<string, string>();
            env["APP_BASE_URL"] = "http://127.0.0.1:" + cfg.WebPort;
            env["APP_DEBUG"] = "0";
            env["DB_HOST"] = "127.0.0.1";
            env["DB_PORT"] = cfg.DbPort.ToString();
            env["DB_NAME"] = "medicare_practice";
            env["DB_USER"] = "root";
            env["DB_PASS"] = "";
            return env;
        }

        private bool UsesNginx() { return cfg.Lan && p.HasNginx; }

        private void StartPhp()
        {
            if (UsesNginx())
            {
                cgis.Clear();
                for (int i = 0; i < cfg.Workers; i++) cgis.Add(StartCgi(9100 + i));
                WriteNginxConf();
                StartNginx();
                php = null;
                return;
            }
            // LAN without the bundled nginx: PHP's own server on all interfaces (one request at a time).
            string bind = cfg.Lan ? "0.0.0.0" : "127.0.0.1";
            ProcessStartInfo si = new ProcessStartInfo(p.Php, PhpArgs() + " -S " + bind + ":" + cfg.WebPort + " -t \"" + p.Public + "\"");
            si.UseShellExecute = false;
            si.CreateNoWindow = true;
            si.WorkingDirectory = p.App;
            foreach (KeyValuePair<string, string> kv in AppEnv()) si.EnvironmentVariables[kv.Key] = kv.Value;
            php = Process.Start(si);
        }

        /// <summary>One FastCGI worker (restarted by the watchdog; recycles itself after 1000 requests).</summary>
        private Process StartCgi(int port)
        {
            ProcessStartInfo si = new ProcessStartInfo(p.PhpCgi, PhpArgs() + " -b 127.0.0.1:" + port);
            si.UseShellExecute = false;
            si.CreateNoWindow = true;
            si.WorkingDirectory = p.App;
            foreach (KeyValuePair<string, string> kv in AppEnv()) si.EnvironmentVariables[kv.Key] = kv.Value;
            si.EnvironmentVariables["PHP_FCGI_MAX_REQUESTS"] = "1000";
            si.EnvironmentVariables["PHP_FCGI_CHILDREN"] = "0";
            return Process.Start(si);
        }

        private void WriteNginxConf()
        {
            Directory.CreateDirectory(p.NginxData);
            foreach (string d in new[] { "client_temp", "fastcgi_temp", "proxy_temp", "uwsgi_temp", "scgi_temp", "logs" })
                Directory.CreateDirectory(Path.Combine(p.NginxData, d));
            Func<string, string> f = delegate(string x) { return x.Replace('\\', '/'); };
            StringBuilder up = new StringBuilder();
            for (int i = 0; i < cfg.Workers; i++) up.Append("        server 127.0.0.1:" + (9100 + i) + " max_fails=0;\r\n");
            string conf =
                "worker_processes 2;\r\n" +
                "error_log \"" + f(Path.Combine(p.Logs, "nginx-error.log")) + "\" warn;\r\n" +
                "pid \"" + f(Path.Combine(p.NginxData, "nginx.pid")) + "\";\r\n" +
                "events { worker_connections 1024; }\r\n" +
                "http {\r\n" +
                "    include \"" + f(Path.Combine(p.NginxDir, @"conf\mime.types")) + "\";\r\n" +
                "    default_type application/octet-stream;\r\n" +
                "    sendfile on;\r\n    keepalive_timeout 65;\r\n    server_tokens off;\r\n    client_max_body_size 512m;\r\n" +
                "    access_log off;\r\n" +
                "    client_body_temp_path \"" + f(Path.Combine(p.NginxData, "client_temp")) + "\";\r\n" +
                "    fastcgi_temp_path \"" + f(Path.Combine(p.NginxData, "fastcgi_temp")) + "\";\r\n" +
                "    proxy_temp_path \"" + f(Path.Combine(p.NginxData, "proxy_temp")) + "\";\r\n" +
                "    uwsgi_temp_path \"" + f(Path.Combine(p.NginxData, "uwsgi_temp")) + "\";\r\n" +
                "    scgi_temp_path \"" + f(Path.Combine(p.NginxData, "scgi_temp")) + "\";\r\n" +
                "    upstream medicare_php {\r\n" + up + "    }\r\n" +
                "    server {\r\n" +
                "        listen " + cfg.WebPort + ";\r\n" +
                "        root \"" + f(p.Public) + "\";\r\n" +
                "        index index.php;\r\n" +
                "        add_header X-Content-Type-Options nosniff always;\r\n" +
                "        location ~ /\\. { deny all; }\r\n" +
                "        location ^~ /uploads/ { location ~ \\.php$ { deny all; } }\r\n" +
                "        location / { try_files $uri $uri/ /index.php?$query_string; }\r\n" +
                "        location ~ \\.php$ {\r\n" +
                "            try_files $uri =404;\r\n" +
                "            fastcgi_pass medicare_php;\r\n" +
                "            fastcgi_next_upstream error timeout;\r\n" +
                "            fastcgi_read_timeout 600;\r\n" +
                "            include \"" + f(Path.Combine(p.NginxDir, @"conf\fastcgi_params")) + "\";\r\n" +
                "            fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;\r\n" +
                "        }\r\n" +
                "    }\r\n" +
                "}\r\n";
            File.WriteAllText(p.NginxConf, conf);
        }

        private void StartNginx()
        {
            // Forward slashes: a trailing backslash before a quote would escape it on the command line.
            ProcessStartInfo si = new ProcessStartInfo(p.NginxExe, "-p \"" + p.NginxData.Replace('\\', '/') + "/\" -c \"" + p.NginxConf.Replace('\\', '/') + "\"");
            si.UseShellExecute = false;
            si.CreateNoWindow = true;
            si.WorkingDirectory = p.NginxDir;
            nginx = Process.Start(si);
        }

        private string LanAddress()
        {
            List<string> ips = Services.LanAddresses();
            return "http://" + (ips.Count > 0 ? ips[0] : Environment.MachineName) + ":" + cfg.WebPort + "/";
        }

        private void CopyLanAddress()
        {
            try { Clipboard.SetText(LanAddress()); tray.ShowBalloonTip(2500, "Address copied", LanAddress(), ToolTipIcon.Info); } catch { }
        }

        /// <summary>Switches between this-PC-only and hospital-server mode (restart needed).</summary>
        private void ToggleMode()
        {
            bool toLan = !cfg.Lan;
            string text = toLan
                ? "Make this PC the hospital server?\n\nOther computers on the network will open MediCare at\n" + LanAddress() +
                  "\n\nWindows will ask for administrator permission to open port " + cfg.WebPort + " in the firewall. Every user signs in with their own account.\nMediCare restarts now."
                : "Switch back to this-PC-only mode?\n\nOther computers will no longer reach MediCare. MediCare restarts now.";
            if (MessageBox.Show(text, "MediCare Practice", MessageBoxButtons.YesNo, MessageBoxIcon.Question) != DialogResult.Yes) return;
            cfg.Mode = toLan ? "lan" : "local";
            cfg.Save();
            if (toLan) Services.AllowFirewall(cfg.WebPort);
            StopServices();
            tray.Visible = false;
            try { Process.Start(Application.ExecutablePath); } catch { }
            ExitThread();
        }

        private static void WaitForPort(int port, int seconds, string error, Process owner)
        {
            for (int i = 0; i < seconds * 4; i++)
            {
                if (Services.PortOpen(port)) return;
                if (owner != null && owner.HasExited) throw new Exception(error);
                Thread.Sleep(250);
            }
            throw new Exception(error);
        }

        private void ExitRequested()
        {
            DialogResult r = MessageBox.Show("Stop MediCare Practice?\n\nThe application will not be available until you start it again from the desktop icon.",
                "MediCare Practice", MessageBoxButtons.YesNo, MessageBoxIcon.Question);
            if (r != DialogResult.Yes) return;
            tray.Text = "MediCare Practice — stopping";
            StopServices();
            tray.Visible = false;
            ExitThread();
        }

        private void StopServices()
        {
            if (stopping) return;
            stopping = true;
            if (watchdog != null) watchdog.Stop();
            try { if (php != null && !php.HasExited) php.Kill(); } catch { }
            try { if (nginx != null && !nginx.HasExited) nginx.Kill(); } catch { }
            foreach (Process c in cgis) { try { if (c != null && !c.HasExited) c.Kill(); } catch { } }
            Services.StopAll(p, false);
            Services.Log(p, "Stopped");
        }

        protected override void Dispose(bool disposing)
        {
            if (disposing)
            {
                tray.Dispose();
                splash.Dispose();
            }
            base.Dispose(disposing);
        }
    }
}
