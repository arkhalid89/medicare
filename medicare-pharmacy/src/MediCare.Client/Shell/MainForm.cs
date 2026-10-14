using System.Threading;

namespace MediCare.Client.Shell;

/// <summary>
/// Application shell: navigation | header | screen | keycap strip | status bar (design-system.md).
/// Owns the idle lock, host events and the connection indicator.
/// </summary>
public sealed class MainForm : Form, IMessageFilter
{
    private readonly NavPanel _nav = new NavPanel();
    private readonly Panel _header = new Panel();
    private readonly Label _title = new Label();
    private readonly Label _subtitle = new Label();
    private readonly FlowLayoutPanel _headerRight = new FlowLayoutPanel();
    private readonly Badge _dateChip = new Badge();
    private readonly Badge _counterChip = new Badge();
    private readonly UiButton _userButton = new UiButton("", ButtonKind.Ghost);
    private readonly Panel _content = new Panel();
    private readonly KeycapBar _keys = new KeycapBar();
    private readonly Panel _status = new Panel();
    private readonly Label _statusLeft = new Label();
    private readonly Label _statusRight = new Label();
    private readonly Label _banner = new Label();
    private readonly System.Windows.Forms.Timer _clock = new System.Windows.Forms.Timer { Interval = 15000 };
    private readonly Dictionary<string, AppScreen> _screens = new Dictionary<string, AppScreen>();
    private readonly CancellationTokenSource _cts = new CancellationTokenSource();
    private List<NavItem> _items = new List<NavItem>();
    private AppScreen? _current;
    private DateTime _lastInput = DateTime.UtcNow;
    private bool _locked, _online = true;

    /// <summary>True when the person logged out (the program shows the login again); false when the window was closed.</summary>
    public bool LoggedOut { get; private set; }

    public MainForm()
    {
        Text = "MediCare Pharmacy";
        Icon = Program.AppIcon;
        Font = Theme.Ui;
        BackColor = Theme.Canvas;
        KeyPreview = true;
        AutoScaleMode = AutoScaleMode.None;
        MinimumSize = new Size(Theme.S(1100), Theme.S(680));
        StartPosition = FormStartPosition.CenterScreen;
        WindowState = FormWindowState.Maximized;

        BuildHeader();
        BuildStatus();
        _content.Dock = DockStyle.Fill;
        _content.BackColor = Theme.Canvas;
        _keys.Dock = DockStyle.Bottom;
        _banner.Dock = DockStyle.Top;
        _banner.Visible = false;
        _banner.Height = Theme.S(34);
        _banner.TextAlign = ContentAlignment.MiddleCenter;
        _banner.Font = Theme.UiBold;

        var right = new Panel { Dock = DockStyle.Fill };
        right.Stack(_header, _banner, _keys, _content);
        Controls.Add(right);
        Controls.Add(_nav);
        Controls.Add(_status);
        right.BringToFront();

        _nav.Navigate += item => Open(item.Key);
        _nav.BrandTitle = "MediCare Pharmacy";
        Session.SettingsChanged += OnSettingsChanged;
        Session.Api.AuthLost += ex => BeginInvokeSafe(() => OnAuthLost(ex));
        Session.Api.Reachability += ok => BeginInvokeSafe(() => SetOnline(ok));
        _clock.Tick += (_, _) => Tick();
        Application.AddMessageFilter(this);
    }

    // ---------------- layout ----------------

    private void BuildHeader()
    {
        _header.Dock = DockStyle.Top;
        _header.Height = Theme.HeaderHeight;
        _header.BackColor = Theme.Surface;
        _header.Padding = new Padding(Theme.S(20), 0, Theme.S(12), 0);
        _header.Paint += (_, e) => { using var p = new Pen(Theme.Line); e.Graphics.DrawLine(p, 0, _header.Height - 1, _header.Width, _header.Height - 1); };
        _title.Font = Theme.Title;
        _title.ForeColor = Theme.Ink;
        _title.AutoSize = true;
        _title.Location = new Point(Theme.S(20), Theme.S(8));
        _subtitle.Font = Theme.Small;
        _subtitle.ForeColor = Theme.InkMuted;
        _subtitle.AutoSize = true;
        _subtitle.Location = new Point(Theme.S(22), Theme.S(34));
        _headerRight.Dock = DockStyle.Right;
        _headerRight.AutoSize = true;
        _headerRight.AutoSizeMode = AutoSizeMode.GrowAndShrink;
        _headerRight.WrapContents = false;
        _headerRight.Padding = new Padding(0, Theme.S(12), 0, 0);
        _headerRight.BackColor = Theme.Surface;
        _dateChip.Margin = new Padding(Theme.S(4), Theme.S(8), Theme.S(4), 0);
        _counterChip.Margin = new Padding(Theme.S(4), Theme.S(8), Theme.S(8), 0);
        _userButton.Click += (_, _) => ShowUserMenu();
        _headerRight.Controls.AddRange(new Control[] { _dateChip, _counterChip, _userButton });
        _header.Controls.Add(_title);
        _header.Controls.Add(_subtitle);
        _header.Controls.Add(_headerRight);
    }

    private void BuildStatus()
    {
        _status.Dock = DockStyle.Bottom;
        _status.Height = Theme.StatusHeight;
        _status.BackColor = Theme.SurfaceAlt;
        _status.Padding = new Padding(Theme.S(12), 0, Theme.S(12), 0);
        _statusLeft.Dock = DockStyle.Fill;
        _statusLeft.TextAlign = ContentAlignment.MiddleLeft;
        _statusLeft.Font = Theme.Small;
        _statusLeft.ForeColor = Theme.InkMuted;
        _statusRight.Dock = DockStyle.Right;
        _statusRight.AutoSize = false;
        _statusRight.Width = Theme.S(520);
        _statusRight.TextAlign = ContentAlignment.MiddleRight;
        _statusRight.Font = Theme.Small;
        _statusRight.ForeColor = Theme.InkMuted;
        _status.Controls.Add(_statusLeft);
        _status.Controls.Add(_statusRight);
    }

    protected override async void OnShown(EventArgs e)
    {
        base.OnShown(e);
        ApplyIdentity();
        _items = Navigation.Items(this).Where(i => i.Visible()).ToList();
        _nav.SetItems(_items);
        _clock.Start();
        _ = Task.Run(() => Session.Api.PollEvents(ev => BeginInvokeSafe(() => OnHostEvent(ev)), _cts.Token));
        var start = _items.FirstOrDefault(i => i.Key == "pos" && Session.Can(Perm.SalesPos)) ?? _items.FirstOrDefault(i => i.Key == "dashboard") ?? _items.FirstOrDefault();
        if (start != null) await OpenAsync(start.Key);
        await RefreshBadges();
        if (Theme.PreferredFontsMissing && Session.Can(Perm.SettingsManage))
            _statusRight.Text = "Fonts missing: run the installer's font step. " + _statusRight.Text;
    }

    private void ApplyIdentity()
    {
        var s = Session.Settings;
        _nav.BrandSub = s.PharmacyName;
        _nav.Invalidate();
        Text = $"MediCare Pharmacy - {s.PharmacyName}";
        _dateChip.Set((s.DayClosed ? "Day closed " : "Business day ") + FormatBusinessDate(s.BusinessDate), s.DayClosed ? Tone.Danger : Tone.Muted);
        _counterChip.Set($"Counter {Session.Counter}", Tone.Accent);
        _userButton.Text = $"{Session.User.FullName} ▾";
        _userButton.Width = _userButton.Measure();
        UpdateStatus();
    }

    private static string FormatBusinessDate(string iso) =>
        DateTime.TryParseExact(iso, "yyyy-MM-dd", CultureInfo.InvariantCulture, DateTimeStyles.None, out var d) ? Dates.Display(d) : iso;

    private void UpdateStatus()
    {
        var host = Session.Api.HostName;
        _statusLeft.Text = (_online ? "● Connected to host " : "● Host unreachable, retrying: ") + host + $"    {Session.Config.WorkstationName} · counter {Session.Counter}    {Session.User.FullName} ({Session.User.RoleName})";
        _statusLeft.ForeColor = _online ? Theme.Ok : Theme.Danger;
        var fw =
#if NETFRAMEWORK
            "Windows 7 build";
#else
            "Windows 10/11 build";
#endif
        _statusRight.Text = $"MediCare Pharmacy {Program.Version} · {fw}";
    }

    // ---------------- navigation ----------------

    public void Open(string key) => UiRun.Fire(this, () => OpenAsync(key));

    public async Task OpenAsync(string key)
    {
        var item = _items.FirstOrDefault(i => i.Key == key);
        if (item == null) { Msg.Error(this, "You do not have permission for that screen. Ask an administrator."); return; }
        if (!_screens.TryGetValue(key, out var screen))
        {
            screen = item.Create();
            screen.Shell = this;
            _screens[key] = screen;
        }
        if (_current != screen)
        {
            _content.SuspendLayout();
            if (_current != null) _content.Controls.Remove(_current);
            _content.Controls.Add(screen);
            _content.ResumeLayout(true);
            _current = screen;
        }
        _nav.Select(key);
        _title.Text = screen.Title;
        _subtitle.Text = screen.Subtitle;
        _keys.SetKeys(screen.FKeys);
        await screen.Activate();
    }

    /// <summary>Opens a screen and hands it a parameter (e.g. open a customer's ledger).</summary>
    public async Task<T?> OpenScreen<T>(string key) where T : AppScreen
    {
        await OpenAsync(key);
        return _current as T;
    }

    public void RefreshKeys()
    {
        if (_current != null)
        {
            _keys.SetKeys(_current.FKeys);
            _subtitle.Text = _current.Subtitle;
        }
    }

    protected override bool ProcessCmdKey(ref Message msg, Keys keyData)
    {
        if (_locked) return base.ProcessCmdKey(ref msg, keyData);
        if (keyData == (Keys.Control | Keys.L)) { Lock(); return true; }
        if (keyData == (Keys.Control | Keys.G)) { GoTo(); return true; }
        if (keyData == Keys.F1) { ShowHelp(); return true; }
        if (_current != null && IsCommandKey(keyData) && _current.HandleKey(keyData)) return true;
        return base.ProcessCmdKey(ref msg, keyData);
    }

    private static bool IsCommandKey(Keys k)
    {
        var code = k & Keys.KeyCode;
        if (code >= Keys.F1 && code <= Keys.F24) return true;
        return (k & (Keys.Control | Keys.Alt)) != 0;
    }

    private void GoTo()
    {
        using var d = new GoToDialog(_items);
        if (d.ShowDialog(this) == DialogResult.OK && d.Chosen != null) Open(d.Chosen.Key);
    }

    private void ShowHelp()
    {
        var keys = _current?.FKeys.Select(k => $"{k.KeyText,-12} {k.Label}") ?? Enumerable.Empty<string>();
        Msg.Info(this, string.Join(Environment.NewLine, new[]
        {
            "This screen:",
        }.Concat(keys).Concat(new[]
        {
            "",
            "Everywhere:",
            "Ctrl+G        Go to a screen",
            "Ctrl+L        Lock this PC",
            "F1            This help",
            "Enter / Esc   Confirm / cancel in dialogs",
        })), "Keyboard shortcuts");
    }

    private void ShowUserMenu()
    {
        var menu = new ContextMenuStrip { Font = Theme.Ui };
        menu.Items.Add("Change password or PIN", null, (_, _) => { using var d = new ChangePasswordForm(false); d.ShowDialog(this); });
        menu.Items.Add("Lock this PC (Ctrl+L)", null, (_, _) => Lock());
        menu.Items.Add("This PC: counter and printers", null, (_, _) => { using var d = new ThisPcDialog(); if (d.ShowDialog(this) == DialogResult.OK) ApplyIdentity(); });
        menu.Items.Add(new ToolStripSeparator());
        menu.Items.Add("Log out", null, (_, _) => UiRun.Fire(this, Logout));
        menu.Show(_userButton, new Point(0, _userButton.Height));
    }

    public async Task Logout()
    {
        if (_screens.Values.OfType<IHasUnsavedWork>().Any(s => s.HasUnsavedWork) &&
            !Msg.Confirm(this, "There is unsaved work (for example items in the sale). Log out anyway? Held sales stay on the host.")) return;
        try { await Session.Api.Post<OkResult>("auth/logout", new { }); } catch (ApiException) { }
        LoggedOut = true;
        Close();
    }

    // ---------------- lock, idle, events ----------------

    public bool PreFilterMessage(ref Message m)
    {
        // keyboard and mouse input (WM_KEYDOWN, WM_MOUSEMOVE, WM_LBUTTONDOWN...)
        if (m.Msg == 0x0100 || m.Msg == 0x0104 || m.Msg == 0x0201 || m.Msg == 0x0204 || m.Msg == 0x020A || m.Msg == 0x0200)
            _lastInput = DateTime.UtcNow;
        return false;
    }

    private void Tick()
    {
        var timeout = Session.Login?.SessionTimeoutMin ?? 10;
        if (!_locked && timeout > 0 && (DateTime.UtcNow - _lastInput).TotalMinutes >= timeout) Lock();
        if (!_online) UiRun.Fire(this, async () => { await Session.Api.Get<object>("health"); });
    }

    public void Lock()
    {
        if (_locked) return;
        _locked = true;
        try
        {
            using var lf = new LockForm();
            var r = lf.ShowDialog(this);
            if (lf.SwitchUser) { UiRun.Fire(this, Logout); return; }
        }
        finally
        {
            _locked = false;
            _lastInput = DateTime.UtcNow;
        }
        _current?.FocusFirst();
    }

    private void OnAuthLost(ApiException ex)
    {
        if (_locked || LoggedOut || IsDisposed) return;
        if (ex.Code == ErrorCodes.SessionExpired || ex.Code == ErrorCodes.Unauthorized)
        {
            Msg.Error(this, ex.Code == ErrorCodes.SessionExpired ? "Your session expired. Please log in again. Held sales and posted documents are safe on the host." : "You were logged out (the administrator may have changed your account). Please log in again.");
            LoggedOut = true;
            Close();
        }
    }

    private void SetOnline(bool ok)
    {
        _online = ok;
        UpdateStatus();
        if (!ok) ShowBanner($"Host {Session.Api.HostName} is unreachable. Nothing can be saved until it is back. Retrying every 15 seconds.", Tone.Danger);
        else if (_banner.Tag as string == "offline") HideBanner();
        if (!ok) _banner.Tag = "offline";
    }

    public void ShowBanner(string text, Tone tone)
    {
        _banner.Text = text;
        _banner.BackColor = Theme.Back(tone);
        _banner.ForeColor = Theme.Fore(tone);
        _banner.Tag = null;
        _banner.Visible = true;
    }

    public void HideBanner() { _banner.Visible = false; _banner.Tag = null; }

    private void OnHostEvent(EventDto e)
    {
        switch (e.Type)
        {
            case EventTypes.ApprovalRequested:
            case EventTypes.ApprovalDecided:
                UiRun.Fire(this, RefreshBadges);
                break;
            case EventTypes.SettingsChanged:
                UiRun.Fire(this, async () => Session.UpdateSettings(await Session.Api.Get<ClientSettings>("settings/client")));
                break;
            case EventTypes.Maintenance:
                ShowBanner("The host is restoring a backup. Finish what you are typing; you will be asked to log in again shortly.", Tone.Warn);
                break;
            case EventTypes.Restored:
                ShowBanner("Data was restored from a backup. Log in again to continue.", Tone.Warn);
                break;
            case EventTypes.SessionRevoked:
                UiRun.Fire(this, async () => Session.UpdateLogin(await Session.Api.Get<LoginResponse>("auth/me")));
                break;
        }
        foreach (var s in _screens.Values) s.OnHostEvent(e);
    }

    public async Task RefreshBadges()
    {
        var approvals = _items.FirstOrDefault(i => i.Key == "approvals");
        if (approvals == null) return;
        var pending = await Session.Api.Get<List<ApprovalDto>>("approvals", new { status = "PENDING" });
        approvals.Badge = pending.Count;
        _nav.Invalidate();
    }

    private void OnSettingsChanged()
    {
        if (IsDisposed) return;
        ApplyIdentity();
        RefreshKeys();
    }

    private void BeginInvokeSafe(Action a)
    {
        if (IsDisposed || !IsHandleCreated) return;
        try { BeginInvoke(a); } catch (ObjectDisposedException) { } catch (InvalidOperationException) { }
    }

    protected override void OnFormClosing(FormClosingEventArgs e)
    {
        if (!LoggedOut && e.CloseReason == CloseReason.UserClosing)
        {
            if (_screens.Values.OfType<IHasUnsavedWork>().Any(s => s.HasUnsavedWork) &&
                !Msg.Confirm(this, "There is unsaved work on this PC (for example items in the sale). Close MediCare anyway?"))
            {
                e.Cancel = true;
                return;
            }
            try { Session.Api.Post<OkResult>("auth/logout", new { }).Wait(3000); } catch { }
        }
        base.OnFormClosing(e);
    }

    protected override void OnFormClosed(FormClosedEventArgs e)
    {
        Application.RemoveMessageFilter(this);
        Session.SettingsChanged -= OnSettingsChanged;
        _cts.Cancel();
        _clock.Stop();
        base.OnFormClosed(e);
    }
}

/// <summary>Screens with work that would be lost on logout (POS cart, unsaved editors).</summary>
public interface IHasUnsavedWork
{
    bool HasUnsavedWork { get; }
}

/// <summary>Ctrl+G: type part of a screen name, Enter to open.</summary>
public sealed class GoToDialog : DialogForm
{
    private readonly UiText _search = new UiText("Type a screen name");
    private readonly ListBox _list = new ListBox();
    private readonly List<NavItem> _all;
    public NavItem? Chosen { get; private set; }

    public GoToDialog(List<NavItem> items) : base("Go to screen", 420)
    {
        _all = items;
        _list.Font = Theme.Lg;
        _list.Height = Theme.S(320);
        _list.Dock = DockStyle.Top;
        _list.BorderStyle = BorderStyle.FixedSingle;
        _list.IntegralHeight = false;
        _search.Dock = DockStyle.Top;
        _search.Height = Theme.ControlHeight;
        Body.Stack(_search, Box.Gap(8), _list);
        Fill("");
        _search.TextChanged += (_, _) => Fill(_search.Text);
        _search.KeyDown += (_, e) =>
        {
            if (e.KeyCode == Keys.Down && _list.Items.Count > 0) { _list.SelectedIndex = Math.Min(_list.SelectedIndex + 1, _list.Items.Count - 1); e.Handled = true; }
            if (e.KeyCode == Keys.Up && _list.Items.Count > 0) { _list.SelectedIndex = Math.Max(_list.SelectedIndex - 1, 0); e.Handled = true; }
        };
        _list.DoubleClick += (_, _) => UiRun.Fire(this, () => RunOk(Pick));
        AddOk("Open", Pick);
        AddCancel();
        FitToContent();
    }

    private void Fill(string q)
    {
        _list.BeginUpdate();
        _list.Items.Clear();
        foreach (var i in _all.Where(i => q.Length == 0 || i.Title.Has(q) || i.Group.Has(q)))
            _list.Items.Add(new Entry(i));
        _list.EndUpdate();
        if (_list.Items.Count > 0) _list.SelectedIndex = 0;
    }

    private Task<bool> Pick()
    {
        Chosen = (_list.SelectedItem as Entry)?.Item;
        return Task.FromResult(Chosen != null);
    }

    private sealed class Entry
    {
        public NavItem Item { get; }
        public Entry(NavItem i) { Item = i; }
        public override string ToString() => $"{Item.Title}    ({Item.Group})";
    }
}
