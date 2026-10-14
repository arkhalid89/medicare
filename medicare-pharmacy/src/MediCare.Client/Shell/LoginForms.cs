namespace MediCare.Client.Shell;

/// <summary>Teal brand panel used on the login, pairing and setup windows.</summary>
internal sealed class BrandPanel : Panel
{
    public string Heading { get; set; } = "MediCare Pharmacy";
    public string Lines { get; set; } = "";

    public BrandPanel()
    {
        Dock = DockStyle.Left;
        Width = Theme.S(320);
        BackColor = Theme.Accent;
        SetStyle(ControlStyles.OptimizedDoubleBuffer | ControlStyles.ResizeRedraw | ControlStyles.AllPaintingInWmPaint | ControlStyles.UserPaint, true);
    }

    protected override void OnPaint(PaintEventArgs e)
    {
        var g = e.Graphics;
        Theme.Smooth(g);
        g.Clear(BackColor);
        var pad = Theme.S(32);
        using (var b = new SolidBrush(Color.White))
        using (var path = Theme.Rounded(new Rectangle(pad, pad, Theme.S(48), Theme.S(48)), Theme.S(10)))
            g.FillPath(b, path);
        using (var p = new Pen(Theme.Accent, Theme.S(6)))
        {
            var c = new Point(pad + Theme.S(24), pad + Theme.S(24));
            g.DrawLine(p, c.X - Theme.S(13), c.Y, c.X + Theme.S(13), c.Y);
            g.DrawLine(p, c.X, c.Y - Theme.S(13), c.X, c.Y + Theme.S(13));
        }
        TextRenderer.DrawText(g, Heading, Theme.Title, new Rectangle(pad, pad + Theme.S(68), Width - pad * 2, Theme.S(30)), Color.White, TextFormatFlags.Left);
        TextRenderer.DrawText(g, Lines, Theme.Ui, new Rectangle(pad, pad + Theme.S(104), Width - pad * 2, Height - pad * 2 - Theme.S(140)), Theme.Hex("#D8EEEF"), TextFormatFlags.Left | TextFormatFlags.WordBreak);
        TextRenderer.DrawText(g, "Version " + Program.Version, Theme.Xs, new Rectangle(pad, Height - pad - Theme.S(16), Width - pad * 2, Theme.S(16)), Theme.Hex("#BFE3E5"), TextFormatFlags.Left);
    }
}

/// <summary>Base window for login / pairing / setup: brand on the left, form on the right.</summary>
internal abstract class EntryForm : Form
{
    protected readonly BrandPanel Brand = new BrandPanel();
    protected readonly Panel RightPane = new Panel();
    protected readonly MessageBar Bar = new MessageBar();
    protected readonly FlowLayoutPanel Actions = new FlowLayoutPanel();
    private UiButton? _primary;

    protected EntryForm(string title, int width = 860, int height = 540)
    {
        Text = title;
        Icon = Program.AppIcon;
        Font = Theme.Ui;
        BackColor = Theme.Surface;
        AutoScaleMode = AutoScaleMode.None;
        FormBorderStyle = FormBorderStyle.FixedSingle;
        MaximizeBox = false;
        StartPosition = FormStartPosition.CenterScreen;
        ClientSize = new Size(Theme.S(width), Theme.S(height));
        KeyPreview = true;
        RightPane.Dock = DockStyle.Fill;
        RightPane.Padding = new Padding(Theme.S(40), Theme.S(32), Theme.S(40), Theme.S(24));
        RightPane.BackColor = Theme.Surface;
        RightPane.AutoScroll = true;
        Actions.Dock = DockStyle.Bottom;
        Actions.Height = Theme.S(64);
        Actions.FlowDirection = FlowDirection.RightToLeft;
        Actions.Padding = new Padding(Theme.S(36), Theme.S(10), Theme.S(36), Theme.S(10));
        Actions.BackColor = Theme.Surface;
        Controls.AddRange(new Control[] { RightPane, Bar, Actions, Brand });
        // order: Brand (left), Actions (bottom), Bar (top), Right fills
        Brand.SendToBack();
    }

    protected UiButton Primary(string text, Func<Task> action)
    {
        _primary = new UiButton(text, ButtonKind.Primary, "Enter").Large();
        _primary.Click += async (_, _) => await Do(action);
        Actions.Controls.Add(_primary);
        return _primary;
    }

    protected UiButton Secondary(string text, Action action)
    {
        var b = new UiButton(text, ButtonKind.Ghost).Large();
        b.Click += (_, _) => action();
        Actions.Controls.Add(b);
        return b;
    }

    protected async Task Do(Func<Task> action)
    {
        if (_primary != null) _primary.Enabled = false;
        Cursor = Cursors.WaitCursor;
        Bar.Clear();
        try { await action(); }
        catch (OperationCanceledException) { }
        catch (ApiException ex) { Bar.Show(ex.Message, ex.IsOffline ? Tone.Warn : Tone.Danger); }
        catch (Exception ex) { Log.Error("Entry form", ex); Bar.Show("Something went wrong on this PC: " + ex.Message); }
        finally
        {
            Cursor = Cursors.Default;
            if (_primary != null && !IsDisposed) _primary.Enabled = true;
        }
    }

    protected override bool ProcessCmdKey(ref Message msg, Keys keyData)
    {
        if (keyData == Keys.Enter && _primary != null && _primary.Enabled && !(ActiveControl is Button)) { _primary.PerformClick(); return true; }
        if (keyData == Keys.Escape) { DialogResult = DialogResult.Cancel; Close(); return true; }
        return base.ProcessCmdKey(ref msg, keyData);
    }
}

/// <summary>SCR-33 Pair this PC with the host: address, certificate fingerprint check, counter number.</summary>
internal sealed class PairingForm : EntryForm
{
    private readonly UiText _host = new UiText("PHARM-HOST or 192.168.1.10");
    private readonly IntBox _port = new IntBox { Text = "8443" };
    private readonly UiCheck _plain = new UiCheck("This host does not use HTTPS (not recommended)");
    private readonly IntBox _counter = new IntBox();
    private readonly UiText _name = new UiText();
    private readonly Label _fp = new UiLabel("", Theme.NumBold, Theme.Ink);
    private readonly UiCheck _confirm = new UiCheck("The fingerprint matches the one shown on the host PC (MediCare Host Monitor)");
    private string _seen = "";
    private string _url = "";

    public PairingForm(ClientConfig cfg) : base("Connect this PC to the MediCare host", 900, 600)
    {
        Brand.Heading = "Connect to the host";
        Brand.Lines = "Every counter PC talks to one host PC, which keeps the only copy of the data.\n\nType the host's name or IP address. MediCare then shows the host's security fingerprint: compare it with the fingerprint on the host PC's screen (MediCare Host Monitor) before you continue.\n\nThis protects the pharmacy from a stranger's computer pretending to be the host.";
        var uri = Uri.TryCreate(cfg.HostUrl, UriKind.Absolute, out var u) ? u : null;
        _host.Text = uri?.Host ?? "";
        if (uri != null) _port.Value = uri.Port;
        _plain.Checked = uri?.Scheme == "http";
        _counter.Value = cfg.CounterNo;
        _name.Text = cfg.WorkstationName;
        var f = new FieldGrid(2);
        f.Add("Host PC name or IP address", _host);
        f.Add("Port", _port);
        f.AddFull(_plain);
        f.Add("Counter number for this PC", _counter, hint: "Used in invoice numbers (SI-C2-...)");
        f.Add("This PC's name", _name);
        var test = new UiButton("Check host", ButtonKind.Secondary);
        test.Click += async (_, _) => await Do(Check);
        f.AddFull(test);
        f.AddFull(new UiLabel("Host fingerprint (SHA-256):", Theme.Small, Theme.InkMuted));
        _fp.MaximumSize = new Size(Theme.S(480), 0);
        f.AddFull(_fp);
        f.AddFull(_confirm);
        _confirm.Enabled = false;
        RightPane.Controls.Add(f);
        Primary("Save and continue", Save);
        Secondary("Cancel", () => { DialogResult = DialogResult.Cancel; Close(); });
        Config = cfg;
    }

    public ClientConfig Config { get; }

    private string BuildUrl()
    {
        var host = _host.Text.Trim();
        if (host.Length == 0) throw new ApiException(0, ErrorCodes.Validation, "Type the host PC's name or IP address.");
        return $"{(_plain.Checked ? "http" : "https")}://{host}:{(_port.Value > 0 ? _port.Value : 8443)}";
    }

    private async Task Check()
    {
        _url = BuildUrl();
        using var api = new ApiClient(_url, "");
        var status = await api.Get<SetupStatus>("setup/status");
        if (_plain.Checked)
        {
            _seen = "";
            _fp.Text = "No certificate (plain HTTP). Use only on an isolated pharmacy network.";
            _confirm.Enabled = false;
            _confirm.Checked = true;
        }
        else
        {
            var reported = await api.Get<FingerprintDto>("pairing/fingerprint");
            _seen = api.LastSeenFingerprint;
            if (ApiClient.Normalise(reported.Sha256) != ApiClient.Normalise(_seen))
                throw new ApiException(0, ApiException.CertMismatch, "The certificate this PC received does not match what the host reports. Something on the network is interfering. Do not continue; call your technician.");
            _fp.Text = _seen;
            _confirm.Enabled = true;
            _confirm.Checked = false;
        }
        Bar.Show($"Found host \"{(status.PharmacyName.Length > 0 ? status.PharmacyName : "new installation")}\" (version {status.ServerVersion}).{(_plain.Checked ? "" : " Compare the fingerprint now.")}", Tone.Ok);
    }

    private async Task Save()
    {
        if (_url.Length == 0 || _url != BuildUrl()) await Check();
        if (!_plain.Checked && !_confirm.Checked)
        {
            Bar.Show("Compare the fingerprint with the host PC's screen, then tick the box to confirm.", Tone.Warn);
            return;
        }
        if (_counter.Value < 1 || _counter.Value > 99) { Bar.Show("Counter number must be between 1 and 99."); return; }
        Config.HostUrl = _url;
        Config.Fingerprint = _seen;
        Config.CounterNo = _counter.Value;
        Config.WorkstationName = _name.Text.Trim().Length > 0 ? _name.Text.Trim() : Environment.MachineName;
        Config.Save();
        DialogResult = DialogResult.OK;
        Close();
    }
}

/// <summary>SCR-34 First-run setup: pharmacy profile and the first administrator.</summary>
internal sealed class SetupForm : EntryForm
{
    private readonly UiText _pharmacy = new UiText();
    private readonly UiText _address = new UiText();
    private readonly UiText _phone = new UiText();
    private readonly UiText _license = new UiText("Drug sale licence no.");
    private readonly UiText _admin = new UiText();
    private readonly UiText _username = new UiText();
    private readonly UiText _password = new UiText { UseSystemPasswordChar = true };
    private readonly UiText _password2 = new UiText { UseSystemPasswordChar = true };
    private readonly UiText _pin = new UiText { UseSystemPasswordChar = true, MaxLength = 6 };

    public SetupForm() : base("MediCare Pharmacy: first-time setup", 920, 640)
    {
        Brand.Heading = "Welcome";
        Brand.Lines = "This host has no data yet. Enter the pharmacy's details and create the first administrator.\n\nThe administrator can then add staff, import the medicine list, and set prices, tax and receipts in Settings.\n\nKeep the administrator password somewhere safe. Nobody can recover it.";
        var f = new FieldGrid(2);
        f.Add("Pharmacy name (on receipts)", _pharmacy, 2);
        f.Add("Address", _address, 2);
        f.Add("Phone", _phone);
        f.Add("Licence number", _license);
        f.AddFull(new UiLabel("First administrator", Theme.UiBold));
        f.Add("Full name", _admin);
        f.Add("Username", _username, hint: "Used to log in, e.g. rehan");
        f.Add("Password", _password, hint: "At least 8 characters, letters and digits");
        f.Add("Repeat password", _password2);
        f.Add("Approval PIN (4-6 digits)", _pin, hint: "Used to approve discounts and refunds at a counter");
        RightPane.Controls.Add(f);
        Primary("Create pharmacy", Create);
    }

    private async Task Create()
    {
        if (_pharmacy.Text.Trim().Length == 0) { Bar.Show("Enter the pharmacy name."); _pharmacy.Focus(); return; }
        if (_admin.Text.Trim().Length == 0 || _username.Text.Trim().Length == 0) { Bar.Show("Enter the administrator's name and username."); return; }
        if (_password.Text != _password2.Text) { Bar.Show("The two passwords are different."); _password2.Focus(); return; }
        await Session.Api.Post<OkResult>("setup", new SetupRequest
        {
            PharmacyName = _pharmacy.Text.Trim(), Address = _address.Text.Trim(), Phone = _phone.Text.Trim(), LicenseNo = _license.Text.Trim(),
            AdminFullName = _admin.Text.Trim(), AdminUsername = _username.Text.Trim(), AdminPassword = _password.Text, AdminPin = _pin.Text,
        });
        Session.Config.LastUsername = _username.Text.Trim();
        Session.Config.Save();
        Msg.Info(this, "The pharmacy is set up. Log in as the administrator to continue.");
        DialogResult = DialogResult.OK;
        Close();
    }
}

/// <summary>SCR-01 Login.</summary>
internal sealed class LoginForm : EntryForm
{
    private readonly UiText _user = new UiText();
    private readonly UiText _password = new UiText { UseSystemPasswordChar = true };
    private readonly UiLabel _hostLine = new UiLabel("", Theme.Small, Theme.InkMuted);
    public bool Repair { get; private set; }

    public LoginForm(SetupStatus? status) : base("MediCare Pharmacy: log in", 820, 480)
    {
        Brand.Heading = status?.PharmacyName.Length > 0 ? status!.PharmacyName : "MediCare Pharmacy";
        Brand.Lines = $"Counter {Session.Counter} · {Session.Config.WorkstationName}\n\nScan, sell and keep stock right, with one truth on the host.";
        _hostLine.Text = status == null ? $"Host {Session.Api.HostName} is not answering." : $"Connected to host {Session.Api.HostName} · version {status.ServerVersion}" + (status.Maintenance ? " · MAINTENANCE: restore in progress" : "");
        _hostLine.ForeColor = status == null || status.Maintenance ? Theme.Danger : Theme.Ok;
        _user.Text = Session.Config.LastUsername;
        var title = new UiLabel("Log in", Theme.Title);
        var f = new FieldGrid(1);
        f.Add("Username", _user);
        f.Add("Password", _password);
        RightPane.Stack(title, Box.Gap(16), f, Box.Gap(8), _hostLine);
        Primary("Log in", DoLogin);
        Secondary("Connection settings", () => { Repair = true; DialogResult = DialogResult.Retry; Close(); });
        Shown += (_, _) => (_user.Text.Length > 0 ? _password : _user).Focus();
    }

    private async Task DoLogin()
    {
        if (_user.Text.Trim().Length == 0 || _password.Text.Length == 0) { Bar.Show("Enter your username and password."); return; }
        try
        {
            var login = await Session.Api.Post<LoginResponse>("auth/login", new LoginRequest
            {
                Username = _user.Text.Trim(), Password = _password.Text, MachineId = Session.Config.MachineId, WorkstationName = Session.Config.WorkstationName,
                Os = Environment.OSVersion.VersionString, ClientVersion = Program.Version, CounterNo = Session.Config.CounterNo,
            });
            Session.SetLogin(login);
            Session.Config.LastUsername = login.User.Username;
            try { Session.Config.Save(); } catch (Exception ex) { Log.Error("Saving config", ex); }
            if (login.MustChangePassword)
            {
                using var d = new ChangePasswordForm(true);
                if (d.ShowDialog(this) != DialogResult.OK) { Session.SetLogin(null); return; }
                Session.UpdateLogin(await Session.Api.Get<LoginResponse>("auth/me"));
            }
            DialogResult = DialogResult.OK;
            Close();
        }
        finally { _password.Clear(); }
    }
}

/// <summary>Change own password (forced on first login) and optionally the approval PIN.</summary>
internal sealed class ChangePasswordForm : DialogForm
{
    private readonly UiText _current = new UiText { UseSystemPasswordChar = true };
    private readonly UiText _new = new UiText { UseSystemPasswordChar = true };
    private readonly UiText _repeat = new UiText { UseSystemPasswordChar = true };
    private readonly UiText _pin = new UiText { UseSystemPasswordChar = true, MaxLength = 6 };

    public ChangePasswordForm(bool forced) : base(forced ? "Choose your own password" : "Change password or PIN", 460)
    {
        var f = new FieldGrid(1);
        if (forced) f.AddFull(new UiLabel("Your administrator set a temporary password. Choose your own before you continue.", Theme.Ui, Theme.InkMuted).Wrap(Theme.S(400)));
        f.Add(forced ? "Temporary password" : "Current password", _current);
        f.Add("New password", _new, hint: "At least 8 characters with letters and digits; not your username");
        f.Add("Repeat new password", _repeat);
        f.Add("New approval PIN (optional)", _pin, hint: "Only managers who approve need a PIN. Leave empty to keep it.");
        Body.Stack(f);
        AddOk("Save", Save);
        AddCancel();
        FitToContent();
    }

    private async Task<bool> Save()
    {
        if (_new.Text != _repeat.Text) { Fail("The two new passwords are different."); return false; }
        await Session.Api.Post<OkResult>("auth/password", new ChangePasswordRequest { CurrentPassword = _current.Text, NewPassword = _new.Text, NewPin = _pin.Text.Length > 0 ? _pin.Text : null });
        Msg.Info(this, "Saved. Use the new password next time you log in.");
        return true;
    }
}

/// <summary>SCR-02 Session lock: re-enter the password to continue; the cart and open screens are kept.</summary>
internal sealed class LockForm : DialogForm
{
    private readonly UiText _password = new UiText { UseSystemPasswordChar = true };
    public bool SwitchUser { get; private set; }

    public LockForm() : base("Locked", 440)
    {
        ControlBox = false;
        var f = new FieldGrid(1);
        f.AddFull(new UiLabel($"{Session.User.FullName}, this PC was locked.", Theme.UiBold));
        f.AddFull(new UiLabel("Your work is kept. Enter your password to continue.", Theme.Ui, Theme.InkMuted));
        f.Add("Password", _password);
        Body.Stack(f);
        AddOk("Unlock", Unlock);
        AddButton("Switch user", () => { SwitchUser = true; DialogResult = DialogResult.Abort; Close(); }, ButtonKind.Ghost);
        FitToContent();
    }

    private async Task<bool> Unlock()
    {
        try
        {
            await Session.Api.Post<OkResult>("auth/unlock", new UnlockRequest { Password = _password.Text });
            return true;
        }
        finally { _password.Clear(); }
    }

    protected override void OnFormClosing(FormClosingEventArgs e)
    {
        if (DialogResult != DialogResult.OK && !SwitchUser) e.Cancel = true;
        base.OnFormClosing(e);
    }
}

/// <summary>Per-PC options: counter number, receipt printer and width, A4 printer, density.</summary>
internal sealed class ThisPcDialog : DialogForm
{
    private readonly IntBox _counter = new IntBox();
    private readonly UiCombo _receipt = new UiCombo();
    private readonly UiCombo _width = new UiCombo();
    private readonly UiCombo _a4 = new UiCombo();
    private readonly UiCheck _auto = new UiCheck("Print the receipt automatically after each sale");
    private readonly UiCheck _drawer = new UiCheck("Open the cash drawer on cash sales (printer-driven drawer)");
    private readonly UiCheck _compact = new UiCheck("Compact layout (more rows on small screens; applies after restart)");

    public ThisPcDialog() : base("This PC", 520)
    {
        var cfg = Session.Config;
        var printers = Printers.List();
        _receipt.SetItems(new[] { "(Windows default printer)" }.Concat(printers));
        _a4.SetItems(new[] { "(Windows default printer)" }.Concat(printers));
        _width.SetItems(new[] { "80 mm", "58 mm" });
        _counter.Value = cfg.CounterNo;
        if (cfg.ReceiptPrinter.Length > 0) _receipt.SelectedItem = cfg.ReceiptPrinter;
        if (cfg.A4Printer.Length > 0) _a4.SelectedItem = cfg.A4Printer;
        _width.SelectedIndex = cfg.ReceiptWidthMm == 58 ? 1 : 0;
        _auto.Checked = cfg.AutoPrintReceipt;
        _drawer.Checked = cfg.OpenCashDrawer;
        _compact.Checked = cfg.Compact;
        var f = new FieldGrid(2);
        f.Add("Counter number", _counter, hint: "Takes effect at the next log in");
        f.Add("Receipt width", _width);
        f.Add("Receipt printer", _receipt, 2);
        f.Add("A4 printer (invoices, reports, statements)", _a4, 2);
        f.AddFull(_auto);
        f.AddFull(_drawer);
        f.AddFull(_compact);
        var test = new UiButton("Print a test receipt");
        test.Click += (_, _) => { Apply(); Printers.TestReceipt(this); };
        f.AddFull(test);
        Body.Stack(f);
        AddOk("Save", () => { Apply(); Session.Config.Save(); return Task.FromResult(true); });
        AddCancel();
        FitToContent();
    }

    private void Apply()
    {
        var cfg = Session.Config;
        if (_counter.Value >= 1 && _counter.Value <= 99) cfg.CounterNo = _counter.Value;
        cfg.ReceiptPrinter = _receipt.SelectedIndex > 0 ? _receipt.SelectedItem as string ?? "" : "";
        cfg.A4Printer = _a4.SelectedIndex > 0 ? _a4.SelectedItem as string ?? "" : "";
        cfg.ReceiptWidthMm = _width.SelectedIndex == 1 ? 58 : 80;
        cfg.AutoPrintReceipt = _auto.Checked;
        cfg.OpenCashDrawer = _drawer.Checked;
        cfg.Compact = _compact.Checked;
    }
}
