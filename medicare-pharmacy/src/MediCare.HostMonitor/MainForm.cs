using System.ComponentModel;
using System.Diagnostics;
using System.Globalization;
using System.Net.NetworkInformation;
using System.Net.Sockets;
using System.Runtime.InteropServices;
using System.Security.Principal;

namespace MediCare.HostMonitor;

/// <summary>SCR-33 host monitor window and tray icon.</summary>
internal sealed class MainForm : Form
{
    private static readonly CultureInfo Inv = CultureInfo.InvariantCulture;
    private static readonly TimeSpan RefreshInterval = TimeSpan.FromSeconds(5);
    // The host rewrites host-status.json every 30 s; allow for one missed write before warning.
    private static readonly TimeSpan StaleAfter = TimeSpan.FromSeconds(75);
    // A counter long-polls the host every 25 s, so anything seen within 2 minutes is connected.
    private static readonly TimeSpan OnlineWindow = TimeSpan.FromMinutes(2);
    private static readonly TimeZoneInfo HostZone = FindHostZone();

    private readonly string _statusPath;
    private readonly string? _pathArg;
    private readonly string _dataDir;
    private readonly System.Windows.Forms.Timer _timer = new() { Interval = (int)RefreshInterval.TotalMilliseconds };
    private readonly HealthProbe _probe = new();
    private readonly NotifyIcon _tray = new();
    private readonly Icon _iconOk = Theme.MakeDotIcon(Theme.Ok, 32);
    private readonly Icon _iconBad = Theme.MakeDotIcon(Theme.Danger, 32);
    private readonly Icon _iconBusy = Theme.MakeDotIcon(Theme.Warn, 32);
    private readonly ToolTip _tips = new();

    private readonly FlowLayoutPanel _banner = new()
    {
        Dock = DockStyle.Fill,
        AutoSize = true,
        AutoSizeMode = AutoSizeMode.GrowAndShrink,
        WrapContents = true,
        Padding = new Padding(12, 4, 12, 4),
        Margin = new Padding(0, 0, 0, 12),
        Visible = false,
    };
    private readonly Label _bannerText = Theme.MakeLabel("");
    private readonly Button _elevateButton = Theme.MakeButton("Open as administrator");

    private readonly Label _stateText = Theme.MakeLabel("Checking…", Theme.State);
    private readonly Label _stateDetail = Theme.MakeLabel("", Theme.Small, Theme.InkMuted);
    private readonly Label _actionMessage = Theme.MakeLabel("", Theme.Body, Theme.InkMuted);
    private readonly Button _startButton = Theme.MakeButton("Start", Theme.ButtonKind.Accent);
    private readonly Button _stopButton = Theme.MakeButton("Stop", Theme.ButtonKind.Danger);
    private readonly Button _restartButton = Theme.MakeButton("Restart");

    private readonly TextBox _fingerprint = new()
    {
        ReadOnly = true,
        Multiline = true,
        BorderStyle = BorderStyle.None,
        BackColor = Theme.Surface,
        ForeColor = Theme.Ink,
        Font = Theme.Fingerprint,
        Dock = DockStyle.Fill,
        TabStop = true,
        WordWrap = true,
    };
    private readonly Button _copyButton = Theme.MakeButton("Copy fingerprint", Theme.ButtonKind.Accent);
    private readonly Label _addressText = Theme.MakeLabel("", Theme.Body, Theme.InkMuted);

    private readonly Label _version = ValueLabel();
    private readonly Label _database = ValueLabel();
    private readonly Label _backup = ValueLabel();
    private readonly Label _maintenance = ValueLabel();
    private readonly Label _api = ValueLabel();
    private readonly Label _sessions = ValueLabel();
    private readonly Label _updated = ValueLabel();

    private readonly Label _workstationsTitle = Theme.MakeLabel("CONNECTED WORKSTATIONS", Theme.Caption, Theme.InkMuted);
    private readonly ListView _workstations = new()
    {
        View = View.Details,
        FullRowSelect = true,
        HeaderStyle = ColumnHeaderStyle.Nonclickable,
        BorderStyle = BorderStyle.None,
        Dock = DockStyle.Fill,
        Font = Theme.Body,
        ForeColor = Theme.Ink,
        BackColor = Theme.Surface,
        MultiSelect = false,
    };

    private readonly Label _footer = Theme.MakeLabel("", Theme.Small, Theme.InkMuted);

    private HostStatus? _status;
    private StatusFileState _lastReadState = StatusFileState.Missing;
    private HealthResult? _health;
    private ServiceState? _lastServiceState;
    private bool _busy;
    private bool _probing;
    private bool _exiting;
    private bool _trayHintShown;

    public MainForm(string statusPath, string? pathArg)
    {
        _statusPath = statusPath;
        _pathArg = pathArg;
        _dataDir = Path.GetDirectoryName(statusPath) ?? statusPath;

        Text = "MediCare Host Monitor";
        Icon = Icon.ExtractAssociatedIcon(Application.ExecutablePath) ?? _iconOk;
        BackColor = Theme.Canvas;
        ForeColor = Theme.Ink;
        Font = Theme.Body;
        AutoScaleMode = AutoScaleMode.Dpi;
        StartPosition = FormStartPosition.CenterScreen;
        ClientSize = new Size(1000, 760);
        MinimumSize = new Size(820, 640);

        BuildLayout();
        BuildTray();

        _startButton.Click += async (_, _) => await RunServiceAction("Starting the host service…", HostService.StartAsync);
        _stopButton.Click += async (_, _) => await ConfirmAndStop();
        _restartButton.Click += async (_, _) => await RunServiceAction("Restarting the host service…", HostService.RestartAsync);
        _copyButton.Click += (_, _) => CopyFingerprint();
        _elevateButton.Click += (_, _) => RestartElevated();
        _timer.Tick += (_, _) => RefreshAll();
    }

    // ---------------------------------------------------------------- layout

    private void BuildLayout()
    {
        var header = new Panel { Dock = DockStyle.Top, Height = 56, BackColor = Theme.Ink, Padding = new Padding(16, 0, 16, 0) };
        var title = new Label
        {
            Text = "MediCare Host Monitor",
            Font = Theme.Title,
            ForeColor = Color.White,
            AutoSize = false,
            Dock = DockStyle.Fill,
            TextAlign = ContentAlignment.MiddleLeft,
        };
        var machine = new Label
        {
            Text = Environment.MachineName,
            Font = Theme.Body,
            ForeColor = Theme.Line,
            AutoSize = false,
            Dock = DockStyle.Right,
            Width = 320,
            TextAlign = ContentAlignment.MiddleRight,
        };
        header.Controls.Add(title);
        header.Controls.Add(machine);

        var root = new TableLayoutPanel
        {
            Dock = DockStyle.Fill,
            Padding = new Padding(16),
            ColumnCount = 2,
            RowCount = 5,
            BackColor = Theme.Canvas,
        };
        root.ColumnStyles.Add(new ColumnStyle(SizeType.Percent, 46));
        root.ColumnStyles.Add(new ColumnStyle(SizeType.Percent, 54));
        root.RowStyles.Add(new RowStyle(SizeType.AutoSize));
        root.RowStyles.Add(new RowStyle(SizeType.AutoSize));
        root.RowStyles.Add(new RowStyle(SizeType.AutoSize));
        root.RowStyles.Add(new RowStyle(SizeType.Percent, 100));
        root.RowStyles.Add(new RowStyle(SizeType.AutoSize));

        // Banner: problems reading the status file, or a stale status.
        _bannerText.Margin = new Padding(0, 10, 16, 10);
        _bannerText.MaximumSize = new Size(720, 0);
        _elevateButton.Visible = false;
        _elevateButton.Margin = new Padding(0, 4, 0, 4);
        _banner.Controls.Add(_bannerText);
        _banner.Controls.Add(_elevateButton);
        root.Controls.Add(_banner, 0, 0);
        root.SetColumnSpan(_banner, 2);

        var serviceCard = BuildServiceCard();
        root.Controls.Add(serviceCard, 0, 1);
        root.SetColumnSpan(serviceCard, 2);

        var fingerprintCard = BuildFingerprintCard();
        root.Controls.Add(fingerprintCard, 0, 2);
        root.SetColumnSpan(fingerprintCard, 2);

        var details = BuildDetailsCard();
        details.Margin = new Padding(0, 0, 12, 12);
        root.Controls.Add(details, 0, 3);
        root.Controls.Add(BuildWorkstationsCard(), 1, 3);

        var footer = BuildFooter();
        root.Controls.Add(footer, 0, 4);
        root.SetColumnSpan(footer, 2);

        Controls.Add(root);
        Controls.Add(header);
    }

    private Card BuildServiceCard()
    {
        var card = new Card { AutoSize = true, AutoSizeMode = AutoSizeMode.GrowAndShrink };
        var grid = new TableLayoutPanel { Dock = DockStyle.Fill, AutoSize = true, ColumnCount = 2, RowCount = 1, BackColor = Theme.Surface };
        grid.ColumnStyles.Add(new ColumnStyle(SizeType.Percent, 100));
        grid.ColumnStyles.Add(new ColumnStyle(SizeType.AutoSize));

        var left = new FlowLayoutPanel { FlowDirection = FlowDirection.TopDown, AutoSize = true, WrapContents = false, Dock = DockStyle.Fill, BackColor = Theme.Surface };
        left.Controls.Add(Theme.MakeLabel("HOST SERVICE", Theme.Caption, Theme.InkMuted));
        left.Controls.Add(_stateText);
        left.Controls.Add(_stateDetail);
        _actionMessage.MaximumSize = new Size(560, 0);
        left.Controls.Add(_actionMessage);

        var buttons = new FlowLayoutPanel { AutoSize = true, WrapContents = false, Anchor = AnchorStyles.Right | AnchorStyles.Top, BackColor = Theme.Surface, Margin = new Padding(0, 18, 0, 0) };
        buttons.Controls.Add(_startButton);
        buttons.Controls.Add(_stopButton);
        _restartButton.Margin = Padding.Empty;
        buttons.Controls.Add(_restartButton);

        grid.Controls.Add(left, 0, 0);
        grid.Controls.Add(buttons, 1, 0);
        card.Controls.Add(grid);
        return card;
    }

    private Card BuildFingerprintCard()
    {
        var card = new Card { AutoSize = true, AutoSizeMode = AutoSizeMode.GrowAndShrink };
        var grid = new TableLayoutPanel { Dock = DockStyle.Fill, AutoSize = true, ColumnCount = 2, RowCount = 4, BackColor = Theme.Surface };
        grid.ColumnStyles.Add(new ColumnStyle(SizeType.Percent, 100));
        grid.ColumnStyles.Add(new ColumnStyle(SizeType.AutoSize));
        grid.RowStyles.Add(new RowStyle(SizeType.AutoSize));
        grid.RowStyles.Add(new RowStyle(SizeType.AutoSize));
        grid.RowStyles.Add(new RowStyle(SizeType.Absolute, 72));
        grid.RowStyles.Add(new RowStyle(SizeType.AutoSize));

        grid.Controls.Add(Theme.MakeLabel("PAIRING: TLS CERTIFICATE FINGERPRINT (SHA-256)", Theme.Caption, Theme.InkMuted), 0, 0);
        var hint = Theme.MakeLabel("When you connect a counter PC, it must show exactly these characters. If even one differs, do not confirm.", Theme.Body, Theme.Ink);
        grid.Controls.Add(hint, 0, 1);
        grid.SetColumnSpan(hint, 2);

        _fingerprint.Margin = new Padding(0, 4, 16, 4);
        grid.Controls.Add(_fingerprint, 0, 2);
        _copyButton.Anchor = AnchorStyles.Right;
        _copyButton.Margin = Padding.Empty;
        grid.Controls.Add(_copyButton, 1, 2);

        _addressText.Margin = new Padding(0, 4, 0, 0);
        grid.Controls.Add(_addressText, 0, 3);
        grid.SetColumnSpan(_addressText, 2);

        card.Controls.Add(grid);
        return card;
    }

    private Card BuildDetailsCard()
    {
        var card = new Card();
        var grid = new TableLayoutPanel { Dock = DockStyle.Fill, ColumnCount = 2, BackColor = Theme.Surface, AutoScroll = true };
        grid.ColumnStyles.Add(new ColumnStyle(SizeType.AutoSize));
        grid.ColumnStyles.Add(new ColumnStyle(SizeType.Percent, 100));

        var title = Theme.MakeLabel("HOST DETAILS", Theme.Caption, Theme.InkMuted);
        title.Margin = new Padding(0, 0, 0, 8);
        grid.Controls.Add(title, 0, 0);
        grid.SetColumnSpan(title, 2);

        AddRow(grid, "Version", _version);
        AddRow(grid, "Database", _database);
        AddRow(grid, "Last backup", _backup);
        AddRow(grid, "Maintenance", _maintenance);
        AddRow(grid, "Host API", _api);
        AddRow(grid, "Signed-in users", _sessions);
        AddRow(grid, "Status file", _updated);

        card.Controls.Add(grid);
        return card;
    }

    private static void AddRow(TableLayoutPanel grid, string name, Label value)
    {
        var row = grid.RowCount;
        grid.RowCount = row + 1;
        grid.RowStyles.Add(new RowStyle(SizeType.Absolute, 30));
        var key = Theme.MakeLabel(name, Theme.Body, Theme.InkMuted);
        key.Margin = new Padding(0, 5, 16, 0);
        grid.Controls.Add(key, 0, row);
        grid.Controls.Add(value, 1, row);
    }

    private Card BuildWorkstationsCard()
    {
        var card = new Card();
        _workstations.Columns.Add("Workstation", 130);
        _workstations.Columns.Add("State", 120);
        _workstations.Columns.Add("User", 100);
        _workstations.Columns.Add("Windows", 110);
        _workstations.Columns.Add("Version", 70);
        _workstations.Columns.Add("Last seen", 130);
        _workstationsTitle.Dock = DockStyle.Top;
        _workstationsTitle.Padding = new Padding(0, 0, 0, 8);
        card.Controls.Add(_workstations);
        card.Controls.Add(_workstationsTitle);
        return card;
    }

    private FlowLayoutPanel BuildFooter()
    {
        var footer = new FlowLayoutPanel { Dock = DockStyle.Fill, AutoSize = true, WrapContents = false, BackColor = Theme.Canvas, Margin = Padding.Empty };
        var openData = Theme.MakeButton("Open data folder");
        var openLogs = Theme.MakeButton("Open logs folder");
        openData.Click += (_, _) => OpenFolder(_dataDir);
        openLogs.Click += (_, _) => OpenFolder(Path.Combine(_dataDir, "logs"));
        footer.Controls.Add(openData);
        footer.Controls.Add(openLogs);
        _footer.Margin = new Padding(8, 10, 0, 0);
        footer.Controls.Add(_footer);
        return footer;
    }

    private static Label ValueLabel() => new()
    {
        AutoSize = false,
        AutoEllipsis = true,
        Dock = DockStyle.Fill,
        Font = Theme.Body,
        ForeColor = Theme.Ink,
        TextAlign = ContentAlignment.MiddleLeft,
        Margin = Padding.Empty,
    };

    private void BuildTray()
    {
        var menu = new ContextMenuStrip();
        var open = new ToolStripMenuItem("Open", null, (_, _) => ShowWindow()) { Font = new Font(menu.Font, FontStyle.Bold) };
        var exit = new ToolStripMenuItem("Exit", null, (_, _) => ExitApp());
        menu.Items.Add(open);
        menu.Items.Add(new ToolStripSeparator());
        menu.Items.Add(exit);

        _tray.ContextMenuStrip = menu;
        _tray.Icon = _iconBusy;
        _tray.Text = "MediCare Host Monitor";
        _tray.Visible = true;
        _tray.DoubleClick += (_, _) => ShowWindow();
        _tray.BalloonTipClicked += (_, _) => ShowWindow();
    }

    // ---------------------------------------------------------------- lifecycle

    protected override void OnShown(EventArgs e)
    {
        base.OnShown(e);
        RefreshAll();
        _timer.Start();
    }

    protected override void OnFormClosing(FormClosingEventArgs e)
    {
        if (!_exiting && e.CloseReason == CloseReason.UserClosing)
        {
            // Closing the window keeps monitoring from the tray; use the tray menu's Exit to quit.
            e.Cancel = true;
            Hide();
            if (!_trayHintShown)
            {
                _trayHintShown = true;
                _tray.ShowBalloonTip(4000, "MediCare Host Monitor", "Still running in the notification area. Right-click the icon and choose Exit to close it.", ToolTipIcon.Info);
            }
            return;
        }
        base.OnFormClosing(e);
    }

    protected override void Dispose(bool disposing)
    {
        if (disposing)
        {
            _timer.Dispose();
            _tray.Visible = false;
            _tray.ContextMenuStrip?.Dispose();
            _tray.Dispose();
            _probe.Dispose();
            _tips.Dispose();
            _iconOk.Dispose();
            _iconBad.Dispose();
            _iconBusy.Dispose();
        }
        base.Dispose(disposing);
    }

    public void ShowFromOtherInstance()
    {
        if (IsHandleCreated && !IsDisposed) BeginInvoke(ShowWindow);
    }

    private void ShowWindow()
    {
        Show();
        if (WindowState == FormWindowState.Minimized) WindowState = FormWindowState.Normal;
        Activate();
        RefreshAll();
    }

    private void ExitApp()
    {
        _exiting = true;
        _tray.Visible = false;
        Close();
    }

    // ---------------------------------------------------------------- refresh

    private void RefreshAll()
    {
        var read = HostStatusReader.Read(_statusPath);
        _lastReadState = read.State;
        // An unreadable file is usually caught mid-replace: keep the last good values until the next tick.
        if (read.State == StatusFileState.Ok) _status = read.Status;
        else if (read.State != StatusFileState.Unreadable) _status = null;

        var service = HostService.Query();
        RenderService(service);
        RenderBanner(read, service);
        RenderFingerprint();
        RenderDetails();
        RenderWorkstations();
        _footer.Text = _statusPath;
        UpdateTray(service);
        _ = ProbeAsync(service);
    }

    private async Task ProbeAsync(ServiceState service)
    {
        if (_probing) return;
        if (service != ServiceState.Running)
        {
            _health = null;
            RenderDetails();
            return;
        }
        _probing = true;
        try
        {
            var port = _status?.Port ?? 8443;
            var https = _status?.Https ?? true;
            _health = await _probe.CheckAsync(port, https, _status?.CertificateFingerprint);
            if (!IsDisposed) RenderDetails();
        }
        finally
        {
            _probing = false;
        }
    }

    private void RenderService(ServiceState state)
    {
        var (text, color, detail) = state switch
        {
            ServiceState.Running => ("Running", Theme.Ok, "Counter PCs can connect and post."),
            ServiceState.Stopped => ("Stopped", Theme.Danger, "Counter PCs cannot post sales. Press Start."),
            ServiceState.Starting => ("Starting…", Theme.Warn, "The host is starting (first start after an upgrade can take a minute)."),
            ServiceState.Stopping => ("Stopping…", Theme.Warn, "The host is finishing current work."),
            ServiceState.Paused => ("Paused", Theme.Warn, "Counter PCs cannot post sales."),
            ServiceState.NotInstalled => ("Not installed", Theme.Danger, $"The Windows service \"{HostService.Name}\" was not found. Run the MediCare Host installer."),
            _ => ("Unknown", Theme.Danger, "Windows did not report the service state."),
        };
        _stateText.Text = "● " + text;
        _stateText.ForeColor = color;
        _stateDetail.Text = $"Windows service {HostService.Name} · {detail}";

        _startButton.Enabled = !_busy && state is ServiceState.Stopped or ServiceState.Paused;
        _stopButton.Enabled = !_busy && state is ServiceState.Running or ServiceState.Starting or ServiceState.Paused;
        _restartButton.Enabled = !_busy && state == ServiceState.Running;

        if (_lastServiceState == ServiceState.Running && state != ServiceState.Running && !_busy)
            _tray.ShowBalloonTip(6000, "MediCare host is not running", $"Service state: {text}. Counter PCs cannot post sales.", ToolTipIcon.Error);
        _lastServiceState = state;
    }

    private void RenderBanner(StatusReadResult read, ServiceState service)
    {
        string? message = null;
        var severe = false;
        _elevateButton.Visible = false;

        switch (read.State)
        {
            case StatusFileState.AccessDenied:
                message = "Host Monitor cannot read the host's data folder. It is protected so that only administrators and the host service can open it.";
                severe = true;
                _elevateButton.Visible = !IsElevated();
                break;
            case StatusFileState.Missing:
                message = service == ServiceState.Running
                    ? "The host has not written its status file yet. It appears about 30 seconds after the service starts."
                    : $"No status file found at {_statusPath}. Start the host service; the file is created about 30 seconds later.";
                severe = service != ServiceState.Running;
                break;
            case StatusFileState.Unreadable when _status == null:
                message = "The status file could not be read: " + read.Error;
                break;
        }

        if (message == null && _status?.UpdatedUtc is { } updated)
        {
            var age = DateTime.UtcNow - ToUtc(updated);
            if (age > StaleAfter)
            {
                message = $"The status below is {FormatAge(age)} old. The host may be stopped, busy with a backup or restore, or in maintenance mode.";
                severe = service != ServiceState.Running;
            }
        }

        _banner.Visible = message != null;
        _bannerText.Text = message ?? "";
        _banner.BackColor = severe ? Theme.DangerBg : Theme.WarnBg;
        _bannerText.ForeColor = severe ? Theme.Danger : Theme.Warn;
    }

    private void RenderFingerprint()
    {
        var fp = _status?.CertificateFingerprint;
        if (string.IsNullOrWhiteSpace(fp))
        {
            _fingerprint.Text = "Not available yet";
            _fingerprint.ForeColor = Theme.InkMuted;
            _copyButton.Enabled = false;
        }
        else
        {
            var display = SplitFingerprint(fp.Trim());
            if (_fingerprint.Text != display) _fingerprint.Text = display;
            _fingerprint.ForeColor = Theme.Ink;
            _copyButton.Enabled = true;
        }

        var port = _status?.Port ?? 8443;
        var addresses = LocalIPv4Addresses();
        var name = _status?.MachineName ?? Environment.MachineName;
        _addressText.Text = addresses.Count == 0
            ? $"Counters connect to: {name}, port {port}"
            : $"Counters connect to: {string.Join(" or ", addresses)} ({name}), port {port}";
    }

    /// <summary>32 bytes = 32 hex pairs; show them as two lines of 16 so staff can compare them half at a time.</summary>
    private static string SplitFingerprint(string fp)
    {
        var parts = fp.Split(':');
        if (parts.Length != 32) return fp;
        return string.Join(":", parts.Take(16)) + ":" + Environment.NewLine + string.Join(":", parts.Skip(16));
    }

    private void RenderDetails()
    {
        var s = _status;
        if (s == null)
        {
            foreach (var l in new[] { _version, _database, _backup, _sessions, _updated }) SetValue(l, "—", Theme.InkMuted);
        }
        else
        {
            SetValue(_version, $"{s.ServerVersion ?? "?"} (database schema {s.SchemaVersion?.ToString(Inv) ?? "?"})");
            SetValue(_database, $"{FormatBytes(s.DbSizeBytes)} · {s.DbPath ?? "?"}");
            if (s.LastBackup?.At is { } at)
            {
                var ok = string.Equals(s.LastBackup.Result, "OK", StringComparison.OrdinalIgnoreCase);
                var kind = Title(s.LastBackup.Kind);
                SetValue(_backup, $"{at.ToString("dd-MMM-yyyy HH:mm", Inv)} · {kind} · {(ok ? "OK" : "FAILED: " + s.LastBackup.Result)}", ok ? Theme.Ok : Theme.Danger);
            }
            else
            {
                SetValue(_backup, "No backup has been made yet", Theme.Warn);
            }
            SetValue(_sessions, (s.ActiveSessions ?? 0).ToString(Inv));
            SetValue(_updated, s.UpdatedUtc is { } u ? $"Updated {ToUtc(u).ToLocalTime().ToString("HH:mm:ss", Inv)} ({FormatAge(DateTime.UtcNow - ToUtc(u))} ago)" : "—");
        }

        // Prefer the status file's flag (newer hosts); otherwise ask the live API.
        var maintenance = s?.Maintenance ?? _health?.Maintenance;
        if (maintenance == true) SetValue(_maintenance, "ON: counters cannot post (backup restore or upgrade in progress)", Theme.Warn);
        else if (maintenance == false) SetValue(_maintenance, "Off");
        else SetValue(_maintenance, "Unknown", Theme.InkMuted);

        var port = s?.Port ?? 8443;
        var scheme = (s?.Https ?? true) ? "HTTPS" : "HTTP (not encrypted)";
        if (_lastServiceState != ServiceState.Running) SetValue(_api, $"Port {port} {scheme} · service not running", Theme.InkMuted);
        else if (_health == null) SetValue(_api, $"Port {port} {scheme} · checking…", Theme.InkMuted);
        else if (_health.Responding) SetValue(_api, $"Responding on port {port} {scheme} · {_health.ElapsedMs.ToString(Inv)} ms", Theme.Ok);
        else SetValue(_api, $"Not responding on port {port}: {_health.Error}", Theme.Danger);
    }

    private void SetValue(Label label, string text, Color? color = null)
    {
        label.Text = text;
        label.ForeColor = color ?? Theme.Ink;
        _tips.SetToolTip(label, text);
    }

    private void RenderWorkstations()
    {
        var list = _status?.Workstations ?? new List<WorkstationInfo>();
        var hostNow = HostNow();
        var rows = list
            .Select(w => (w, online: w.IsActive != false && w.LastSeenAt is { } seen && hostNow - seen <= OnlineWindow))
            .OrderByDescending(x => x.online)
            .ThenBy(x => x.w.Name, StringComparer.OrdinalIgnoreCase)
            .ToList();

        var online = rows.Count(r => r.online);
        _workstationsTitle.Text = rows.Count == 0
            ? "WORKSTATIONS · none paired yet"
            : $"WORKSTATIONS · {online.ToString(Inv)} connected of {rows.Count.ToString(Inv)} paired";

        var selected = _workstations.SelectedItems.Count > 0 ? _workstations.SelectedItems[0].Text : null;
        _workstations.BeginUpdate();
        _workstations.Items.Clear();
        foreach (var (w, isOnline) in rows)
        {
            var state = w.IsActive == false ? "Disabled" : isOnline ? "Connected" : "Not connected";
            var item = new ListViewItem(w.Name ?? "?") { UseItemStyleForSubItems = false };
            var stateCell = item.SubItems.Add(state);
            stateCell.ForeColor = isOnline ? Theme.Ok : Theme.InkMuted;
            item.SubItems.Add(w.LastUser ?? "");
            item.SubItems.Add(w.Os ?? "");
            item.SubItems.Add(w.ClientVersion ?? "");
            item.SubItems.Add(w.LastSeenAt?.ToString("dd-MMM-yyyy HH:mm", Inv) ?? "never");
            item.Selected = item.Text == selected;
            _workstations.Items.Add(item);
        }
        _workstations.EndUpdate();
    }

    private void UpdateTray(ServiceState service)
    {
        var (icon, text) = service switch
        {
            ServiceState.Running when _status?.Maintenance == true || _health?.Maintenance == true => (_iconBusy, "Running (maintenance)"),
            ServiceState.Running => (_iconOk, "Running"),
            ServiceState.Starting or ServiceState.Stopping => (_iconBusy, service == ServiceState.Starting ? "Starting" : "Stopping"),
            ServiceState.NotInstalled => (_iconBad, "Not installed"),
            _ => (_iconBad, service.ToString()),
        };
        if (!ReferenceEquals(_tray.Icon, icon)) _tray.Icon = icon;
        _tray.Text = $"MediCare host: {text}";
    }

    // ---------------------------------------------------------------- actions

    private async Task ConfirmAndStop()
    {
        var answer = MessageBox.Show(this,
            "Stopping the host stops every counter PC from posting sales until it is started again.\n\nStop the MediCare host service now?",
            "Stop host service", MessageBoxButtons.YesNo, MessageBoxIcon.Warning, MessageBoxDefaultButton.Button2);
        if (answer == DialogResult.Yes) await RunServiceAction("Stopping the host service…", HostService.StopAsync);
    }

    private async Task RunServiceAction(string progress, Func<Task<ServiceActionResult>> action)
    {
        _busy = true;
        _actionMessage.ForeColor = Theme.InkMuted;
        _actionMessage.Text = progress;
        RenderService(HostService.Query());
        UseWaitCursor = true;
        ServiceActionResult result;
        try
        {
            result = await action();
        }
        finally
        {
            _busy = false;
            UseWaitCursor = false;
        }

        _actionMessage.Text = result.Message;
        _actionMessage.ForeColor = result.Ok ? Theme.Ok : Theme.Danger;
        RefreshAll();

        if (result.NeedsAdmin && !IsElevated())
        {
            var answer = MessageBox.Show(this,
                result.Message + "\n\nOpen Host Monitor as administrator now? Windows will ask for an administrator password.",
                "Administrator rights needed", MessageBoxButtons.YesNo, MessageBoxIcon.Information);
            if (answer == DialogResult.Yes) RestartElevated();
        }
        else if (!result.Ok)
        {
            MessageBox.Show(this, result.Message, "MediCare Host Monitor", MessageBoxButtons.OK, MessageBoxIcon.Warning);
        }
    }

    private void CopyFingerprint()
    {
        var fp = _status?.CertificateFingerprint?.Trim();
        if (string.IsNullOrEmpty(fp)) return;
        try
        {
            Clipboard.SetText(fp);
            _actionMessage.ForeColor = Theme.Ok;
            _actionMessage.Text = "Fingerprint copied to the clipboard.";
        }
        catch (ExternalException)
        {
            _actionMessage.ForeColor = Theme.Danger;
            _actionMessage.Text = "The clipboard is in use by another program. Try again.";
        }
    }

    private void OpenFolder(string folder)
    {
        // Inside a protected data folder we cannot tell whether a folder exists; Explorer then shows its own permission prompt.
        if (_lastReadState != StatusFileState.AccessDenied && !Directory.Exists(folder))
        {
            MessageBox.Show(this, $"The folder does not exist yet:\n{folder}\n\nIt is created the first time the host service starts.",
                "MediCare Host Monitor", MessageBoxButtons.OK, MessageBoxIcon.Information);
            return;
        }
        try
        {
            Process.Start(new ProcessStartInfo("explorer.exe", $"\"{folder}\"") { UseShellExecute = true });
        }
        catch (Win32Exception ex)
        {
            MessageBox.Show(this, "Windows could not open the folder: " + ex.Message, "MediCare Host Monitor", MessageBoxButtons.OK, MessageBoxIcon.Warning);
        }
    }

    private void RestartElevated()
    {
        var args = _pathArg == null ? Program.RestartedFlag : $"\"{_pathArg}\" {Program.RestartedFlag}";
        try
        {
            Process.Start(new ProcessStartInfo(Environment.ProcessPath ?? Application.ExecutablePath, args) { UseShellExecute = true, Verb = "runas" });
            ExitApp();
        }
        catch (Win32Exception)
        {
            // The user cancelled the UAC prompt; stay as we are.
        }
    }

    // ---------------------------------------------------------------- helpers

    private static bool IsElevated()
    {
        using var identity = WindowsIdentity.GetCurrent();
        return new WindowsPrincipal(identity).IsInRole(WindowsBuiltInRole.Administrator);
    }

    private static DateTime ToUtc(DateTime value) => value.Kind switch
    {
        DateTimeKind.Utc => value,
        DateTimeKind.Local => value.ToUniversalTime(),
        _ => DateTime.SpecifyKind(value, DateTimeKind.Utc),
    };

    /// <summary>Current time on the host's business clock (ADR-015: Asia/Karachi), which last-seen times use.</summary>
    private DateTime HostNow()
    {
        var utc = _status?.UpdatedUtc is { } u ? ToUtc(u) : DateTime.UtcNow;
        return TimeZoneInfo.ConvertTimeFromUtc(utc, HostZone);
    }

    private static TimeZoneInfo FindHostZone()
    {
        foreach (var id in new[] { "Pakistan Standard Time", "Asia/Karachi" })
        {
            try
            {
                return TimeZoneInfo.FindSystemTimeZoneById(id);
            }
            catch (Exception ex) when (ex is TimeZoneNotFoundException or InvalidTimeZoneException)
            {
            }
        }
        return TimeZoneInfo.CreateCustomTimeZone("PKT", TimeSpan.FromHours(5), "Pakistan Standard Time", "PKT");
    }

    private static List<string> LocalIPv4Addresses()
    {
        try
        {
            return NetworkInterface.GetAllNetworkInterfaces()
                .Where(n => n.OperationalStatus == OperationalStatus.Up && n.NetworkInterfaceType is not NetworkInterfaceType.Loopback and not NetworkInterfaceType.Tunnel)
                .SelectMany(n => n.GetIPProperties().UnicastAddresses)
                .Where(a => a.Address.AddressFamily == AddressFamily.InterNetwork && !a.Address.ToString().StartsWith("169.254.", StringComparison.Ordinal))
                .Select(a => a.Address.ToString())
                .Distinct()
                .ToList();
        }
        catch (NetworkInformationException)
        {
            return new List<string>();
        }
    }

    private static string FormatBytes(long? bytes)
    {
        if (bytes is not { } b) return "?";
        string[] units = { "bytes", "KB", "MB", "GB" };
        double v = b;
        var i = 0;
        while (v >= 1024 && i < units.Length - 1) { v /= 1024; i++; }
        return i == 0 ? $"{b.ToString(Inv)} bytes" : $"{v.ToString("0.0", Inv)} {units[i]}";
    }

    private static string FormatAge(TimeSpan age)
    {
        if (age < TimeSpan.Zero) age = TimeSpan.Zero;
        if (age.TotalSeconds < 90) return $"{(int)age.TotalSeconds} s";
        if (age.TotalMinutes < 90) return $"{(int)age.TotalMinutes} min";
        if (age.TotalHours < 48) return $"{(int)age.TotalHours} h";
        return $"{(int)age.TotalDays} days";
    }

    private static string Title(string? kind) =>
        string.IsNullOrEmpty(kind) ? "Backup" : char.ToUpperInvariant(kind[0]) + kind[1..].ToLowerInvariant();
}
