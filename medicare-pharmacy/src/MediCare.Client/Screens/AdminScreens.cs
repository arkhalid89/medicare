using System.IO;
using System.Security.Cryptography;

namespace MediCare.Client.Screens;

/// <summary>SCR-24 Approvals: requests from every counter; approve or reject with your PIN.</summary>
public sealed class ApprovalsScreen : AppScreen
{
    private readonly UiCombo _status = new UiCombo();
    private readonly Grid _grid = new Grid();

    public override string Title => "Approvals";
    public override string Subtitle => "Requests sent from the counters. Your PIN confirms each decision.";

    public override IEnumerable<FKey> FKeys => new[]
    {
        new FKey(Keys.F5, "Refresh", () => Fire(Fill)),
        new FKey(Keys.F9, "Approve", () => Fire(() => Decide(true)), primary: true),
        new FKey(Keys.F10, "Reject", () => Fire(() => Decide(false))),
    };

    public ApprovalsScreen()
    {
        _status.SetItems(new[] { "PENDING", "APPROVED", "REJECTED", "USED", "All" });
        _status.SelectedIndexChanged += (_, _) => Fire(Fill);
        _grid.Col("Requested", 140, ColKind.DateTime);
        _grid.Col("What", 180);
        _grid.Col("Details", 0, ColKind.Text, 260);
        _grid.Col("Reason", 0, ColKind.Text, 160);
        _grid.Col("By", 130);
        _grid.Col("PC", 110);
        _grid.Col("Status", 90);
        _grid.Col("Decided by", 130);
        _grid.RowTone = o => o is ApprovalDto a && a.Status == "PENDING" ? Tone.Warn : Tone.None;
        var card = new Card { Dock = DockStyle.Fill, Padding = new Padding(1) };
        card.Controls.Add(_grid);
        var approve = new UiButton("Approve", ButtonKind.Accent, "F9");
        approve.Click += (_, _) => Fire(() => Decide(true));
        var reject = new UiButton("Reject", ButtonKind.Secondary, "F10");
        reject.Click += (_, _) => Fire(() => Decide(false));
        Compose(Box.Toolbar(new Control[] { _status.Sized(140) }, new Control[] { reject, approve }), Box.Gap(6), card);
    }

    protected override Task OnFirstShow() { _status.SelectedIndex = 0; return Task.CompletedTask; }
    protected override Task OnReshow() => Fill();

    public override void OnHostEvent(EventDto e)
    {
        if (Visible && (e.Type == EventTypes.ApprovalRequested || e.Type == EventTypes.ApprovalDecided)) Fire(Fill);
    }

    private async Task Fill()
    {
        var st = _status.SelectedItem as string;
        var list = await Session.Api.Get<List<ApprovalDto>>("approvals", new { status = st == "All" ? null : st });
        _grid.SetRows(list, a => new object?[] { a.CreatedAt, a.ActionLabel, a.Summary, a.Reason, a.Requester, a.Workstation, a.Status, a.DecidedBy });
        await Shell.RefreshBadges();
    }

    private async Task Decide(bool approve)
    {
        var a = _grid.Current<ApprovalDto>();
        if (a == null || a.Status != "PENDING") { Bar.Show("Choose a pending request.", Tone.Info); return; }
        using var d = new DecideDialog(a, approve);
        if (d.ShowDialog(FindForm()) == DialogResult.OK) await Fill();
    }

    private sealed class DecideDialog : DialogForm
    {
        private readonly UiText _pin = new UiText { UseSystemPasswordChar = true, MaxLength = 6 };
        private readonly UiText _reason = new UiText();

        public DecideDialog(ApprovalDto a, bool approve) : base(approve ? "Approve request" : "Reject request", 480)
        {
            var f = new FieldGrid(1);
            f.AddFull(new UiLabel($"{a.ActionLabel}: {a.Summary}", Theme.UiBold).Wrap(Theme.S(420)));
            f.AddFull(new UiLabel($"Requested by {a.Requester} at {a.Workstation}: {a.Reason}", Theme.Ui, Theme.InkMuted).Wrap(Theme.S(420)));
            f.Add("Your PIN", _pin);
            f.Add(approve ? "Note (optional)" : "Reason for rejecting", _reason);
            Body.Stack(f);
            AddOk(approve ? "Approve" : "Reject", async () =>
            {
                if (!approve && _reason.Text.Trim().Length == 0) { Fail("Enter why you are rejecting it; the cashier sees this."); return false; }
                await Session.Api.Post<ApprovalDto>($"approvals/{a.Id}/decide", new DecideApprovalRequest { Approve = approve, Pin = _pin.Text, Reason = _reason.Text.Trim().Length > 0 ? _reason.Text.Trim() : "Approved" });
                return true;
            }, approve ? ButtonKind.Primary : ButtonKind.Danger);
            AddCancel();
            FitToContent();
        }
    }
}

/// <summary>SCR-29 Users and roles: accounts, resets, unlocks, role permission matrix.</summary>
public sealed class UsersScreen : AppScreen
{
    private readonly Grid _users = new Grid();
    private readonly Grid _roles = new Grid();
    private List<RoleDto> _roleList = new List<RoleDto>();

    public override string Title => "Users and roles";
    public override string Subtitle => "Who can log in, and what each role may do.";

    public override IEnumerable<FKey> FKeys => new[]
    {
        new FKey(Keys.Control | Keys.N, "New user", () => Fire(NewUser)),
        new FKey(Keys.Enter, "Edit user", () => Fire(EditUser)),
        new FKey(Keys.F6, "Reset password", () => Fire(Reset)),
        new FKey(Keys.F7, "Unlock", () => Fire(Unlock)),
        new FKey(Keys.F8, "Edit role", () => Fire(EditRole)),
    };

    public UsersScreen()
    {
        _users.Col("Name", 0, ColKind.Text, 180);
        _users.Col("Username", 130, ColKind.Mono);
        _users.Col("Role", 160);
        _users.Col("Active", 70);
        _users.Col("Locked", 70);
        _users.Col("Last login", 140, ColKind.DateTime);
        _users.Col("Last PC", 130);
        _users.RowTone = o => o is UserDto u ? (!u.IsActive ? Tone.Muted : u.Locked ? Tone.Danger : Tone.None) : Tone.None;
        _users.RowActivated += _ => Fire(EditUser);
        _roles.Col("Role", 0, ColKind.Text, 180);
        _roles.Col("Max discount", 110, ColKind.Percent);
        _roles.Col("Permissions", 110, ColKind.Int);
        _roles.RowActivated += _ => Fire(EditRole);
        var userCard = new Card("Users") { Dock = DockStyle.Fill, Padding = new Padding(1, Theme.S(36), 1, 1) };
        userCard.Controls.Add(_users);
        var roleCard = new Card("Roles") { Dock = DockStyle.Right, Width = Theme.S(420), Padding = new Padding(1, Theme.S(36), 1, 1) };
        roleCard.Controls.Add(_roles);
        var body = new Panel { Dock = DockStyle.Fill };
        body.Controls.AddRange(new Control[] { userCard, new Panel { Dock = DockStyle.Right, Width = Theme.S(12) }, roleCard });
        var add = new UiButton("New user", ButtonKind.Accent, "Ctrl+N");
        add.Click += (_, _) => Fire(NewUser);
        var reset = new UiButton("Reset password", ButtonKind.Secondary, "F6");
        reset.Click += (_, _) => Fire(Reset);
        var unlock = new UiButton("Unlock", ButtonKind.Secondary, "F7");
        unlock.Click += (_, _) => Fire(Unlock);
        var role = new UiButton("Edit role", ButtonKind.Secondary, "F8");
        role.Click += (_, _) => Fire(EditRole);
        Compose(Box.Toolbar(new Control[] { reset, unlock, role }, new Control[] { add }), Box.Gap(6), body);
    }

    protected override Task OnFirstShow() => Fill();

    private async Task Fill()
    {
        _roleList = await Session.Api.Get<List<RoleDto>>("roles");
        _roles.SetRows(_roleList, r => new object?[] { r.Name, r.MaxDiscountPercent.ToString("0.##", CultureInfo.InvariantCulture) + "%", r.Permissions.Count });
        var users = await Session.Api.Get<List<UserDto>>("users");
        _users.SetRows(users, u => new object?[] { u.FullName, u.Username, u.RoleName, u.IsActive ? "Yes" : "No", u.Locked ? "Locked" : "", u.LastLoginAt, u.LastWorkstation });
    }

    private async Task NewUser()
    {
        using var d = new UserDialog(null, _roleList);
        if (d.ShowDialog(FindForm()) == DialogResult.OK) await Fill();
    }

    private async Task EditUser()
    {
        var u = _users.Current<UserDto>();
        if (u == null) return;
        using var d = new UserDialog(u, _roleList);
        if (d.ShowDialog(FindForm()) == DialogResult.OK) await Fill();
    }

    private async Task Reset()
    {
        var u = _users.Current<UserDto>();
        if (u == null) return;
        var pwd = Msg.Ask(FindForm(), "Reset password for " + u.FullName, "Temporary password (they must change it at next login)", password: true);
        if (pwd == null) return;
        var r = await Session.Api.Post<OkResult>($"users/{u.Id}/reset-password", new ResetPasswordRequest { NewPassword = pwd });
        Bar.Show(r.Message ?? "Password reset.", Tone.Ok);
        await Fill();
    }

    private async Task Unlock()
    {
        var u = _users.Current<UserDto>();
        if (u == null) return;
        await Session.Api.Post<OkResult>($"users/{u.Id}/unlock", new { });
        Bar.Show($"{u.FullName} is unlocked.", Tone.Ok);
        await Fill();
    }

    private async Task EditRole()
    {
        var r = _roles.Current<RoleDto>();
        if (r == null) return;
        var catalog = await Session.Api.Get<List<PermissionInfo>>("permissions");
        using var d = new RoleDialog(r, catalog);
        if (d.ShowDialog(FindForm()) == DialogResult.OK) await Fill();
    }
}

public sealed class UserDialog : DialogForm
{
    private readonly UserDto? _u;
    private readonly UiText _name = new UiText();
    private readonly UiText _username = new UiText();
    private readonly UiCombo _role = new UiCombo();
    private readonly IntBox _timeout = new IntBox();
    private readonly UiText _password = new UiText { UseSystemPasswordChar = true };
    private readonly UiText _pin = new UiText { UseSystemPasswordChar = true, MaxLength = 6 };
    private readonly UiCheck _active = new UiCheck("Active", true);

    public UserDialog(UserDto? u, List<RoleDto> roles) : base(u == null ? "New user" : "Edit " + u.FullName, 560)
    {
        _u = u;
        _role.SetItems(roles);
        _name.Text = u?.FullName ?? "";
        _username.Text = u?.Username ?? "";
        _username.Enabled = u == null;
        if (u != null) _role.SelectWhere<RoleDto>(r => r.Id == u.RoleId);
        _timeout.Value = u?.SessionTimeoutMin ?? 10;
        _active.Checked = u?.IsActive ?? true;
        var f = new FieldGrid(2);
        f.Add("Full name", _name);
        f.Add("Username", _username);
        f.Add("Role", _role);
        f.Add("Lock screen after (minutes idle)", _timeout);
        if (u == null)
        {
            f.Add("Temporary password", _password, hint: "They choose their own at first login");
            f.Add("Approval PIN (managers only)", _pin, hint: "4 to 6 digits; leave empty if they do not approve");
        }
        else f.AddFull(_active);
        Body.Stack(f);
        AddOk("Save user", Save);
        AddCancel();
        FitToContent();
    }

    private async Task<bool> Save()
    {
        var role = _role.Selected<RoleDto>();
        if (_name.Text.Trim().Length == 0 || _username.Text.Trim().Length == 0 || role == null) { Fail("Enter the name, username and role."); return false; }
        var req = new UserSaveRequest
        {
            Username = _username.Text.Trim(), FullName = _name.Text.Trim(), RoleId = role.Id, IsActive = _active.Checked, SessionTimeoutMin = _timeout.Value,
            Password = _u == null ? _password.Text : null, Pin = _u == null && _pin.Text.Length > 0 ? _pin.Text : null,
            GrantedPermissions = _u?.GrantedPermissions ?? new List<string>(), RevokedPermissions = _u?.RevokedPermissions ?? new List<string>(),
        };
        var r = _u == null ? await Session.Api.Post<OkResult>("users", req) : await Session.Api.Put<OkResult>($"users/{_u.Id}", req);
        if (r.Message != null) Msg.Info(this, r.Message);
        return true;
    }
}

/// <summary>Permission matrix for one role, grouped by area.</summary>
public sealed class RoleDialog : DialogForm
{
    private readonly RoleDto _r;
    private readonly MoneyBox _discount = new MoneyBox();
    private readonly CheckedListBox _perms = new CheckedListBox();
    private readonly List<PermissionInfo> _catalog;

    public RoleDialog(RoleDto r, List<PermissionInfo> catalog) : base("Role: " + r.Name, 620)
    {
        _r = r;
        _catalog = catalog.OrderBy(p => p.Group).ToList();
        _discount.Value = r.MaxDiscountPercent;
        _perms.CheckOnClick = true;
        _perms.Font = Theme.Ui;
        _perms.Height = Theme.S(420);
        _perms.Dock = DockStyle.Top;
        _perms.BorderStyle = BorderStyle.FixedSingle;
        foreach (var p in _catalog) _perms.Items.Add($"{p.Group}: {p.Description}", r.Permissions.Contains(p.Key));
        var f = new FieldGrid(2);
        f.Add("Maximum discount without approval (%)", _discount);
        var admin = r.Name == "Administrator";
        if (admin) { _perms.Enabled = false; f.AddFull(new UiLabel("The Administrator role always has every permission.", Theme.Small, Theme.InkMuted)); }
        Body.Stack(f, _perms);
        AddOk("Save role", async () =>
        {
            _r.MaxDiscountPercent = _discount.Value;
            if (!admin) _r.Permissions = _catalog.Where((p, i) => _perms.GetItemChecked(i)).Select(p => p.Key).ToList();
            await Session.Api.Put<OkResult>($"roles/{_r.Id}", _r);
            return true;
        });
        AddCancel();
        FitToContent();
    }
}

/// <summary>SCR-30 Audit log: sensitive actions with before/after and approver.</summary>
public sealed class AuditScreen : AppScreen
{
    private readonly UiDate _from = new UiDate();
    private readonly UiDate _to = new UiDate();
    private readonly UiText _q = new UiText("Document, medicine, reason…");
    private readonly UiText _action = new UiText("Action e.g. PRICE_CHANGE");
    private readonly Grid _grid = new Grid();

    public override string Title => "Audit log";
    public override string Subtitle => "Every sensitive action, who did it, from which PC, and who approved it. Entries cannot be edited.";

    public override IEnumerable<FKey> FKeys => new[]
    {
        new FKey(Keys.F5, "Show", () => Fire(Fill)),
        new FKey(Keys.Control | Keys.P, "Print", () => TablePrinter.Print(FindForm()!, PrintTable.FromGrid(_grid, "Audit log", $"{Dates.Display(_from.Value)} to {Dates.Display(_to.Value)}"))),
    };

    public AuditScreen()
    {
        _from.Value = DateTime.Today.AddDays(-7);
        _to.Value = DateTime.Today;
        _grid.Col("When", 140, ColKind.DateTime);
        _grid.Col("User", 110);
        _grid.Col("PC", 100);
        _grid.Col("Action", 160, ColKind.Mono);
        _grid.Col("Record", 0, ColKind.Text, 160);
        _grid.Col("Before", 0, ColKind.Text, 120);
        _grid.Col("After", 0, ColKind.Text, 160);
        _grid.Col("Reason", 0, ColKind.Text, 120);
        _grid.Col("Approver", 110);
        _grid.RowActivated += o =>
        {
            var a = (AuditRow)o;
            Msg.Info(FindForm(), $"{Dates.DisplayTime(a.At)}  {a.User} @ {a.Workstation}\n{a.Action} · {a.Module} · {a.RecordRef}\n\nBefore:\n{a.Before}\n\nAfter:\n{a.After}\n\nReason: {a.Reason}\nApprover: {a.Approver}", "Audit entry " + a.Id);
        };
        var show = new UiButton("Show", ButtonKind.Accent, "F5");
        show.Click += (_, _) => Fire(Fill);
        var card = new Card { Dock = DockStyle.Fill, Padding = new Padding(1) };
        card.Controls.Add(_grid);
        Compose(Box.Toolbar(new Control[] { Box.Caption("From"), _from.Sized(140), Box.Caption("To"), _to.Sized(140), _action.Sized(200), _q.Sized(220), show }), Box.Gap(6), card);
    }

    protected override Task OnFirstShow() => Fill();

    private async Task Fill()
    {
        var list = await Session.Api.Get<List<AuditRow>>("audit", new { from = _from.Value.Date, to = _to.Value.Date, action = _action.Text.Trim().ToUpperInvariant(), q = _q.Text.Trim() });
        _grid.SetRows(list, a => new object?[] { a.At, a.User, a.Workstation, a.Action, a.RecordRef, a.Before, a.After, a.Reason, a.Approver });
    }
}

/// <summary>SCR-31 Backup and restore: history, back up now, copy to USB with verification, restore.</summary>
public sealed class BackupScreen : AppScreen
{
    private readonly Grid _grid = new Grid();
    private readonly Label _status = new UiLabel("", Theme.Ui, Theme.InkMuted);

    public override string Title => "Backup and restore";
    public override string Subtitle => "Automatic backups run on the host. Take one copy off the premises every day (USB).";

    public override IEnumerable<FKey> FKeys => new[]
    {
        new FKey(Keys.F5, "Refresh", () => Fire(Fill)),
        new FKey(Keys.Control | Keys.B, "Back up now", () => Fire(BackupNow), primary: true),
        new FKey(Keys.Control | Keys.U, "Copy to USB", () => Fire(CopyToUsb)),
        new FKey(Keys.Control | Keys.R, "Restore…", () => Fire(Restore)) { Enabled = () => Session.Can(Perm.BackupRestore) },
    };

    public BackupScreen()
    {
        _grid.Col("Started", 140, ColKind.DateTime);
        _grid.Col("Kind", 90);
        _grid.Col("File", 0, ColKind.Mono, 260);
        _grid.Col("Size", 90);
        _grid.Col("Result", 80);
        _grid.Col("Check", 100);
        _grid.Col("Copied to", 0, ColKind.Text, 160);
        _grid.Col("Message", 0, ColKind.Text, 160);
        _grid.RowTone = o => o is BackupDto b && b.Result != "OK" ? Tone.Danger : Tone.None;
        var now = new UiButton("Back up now", ButtonKind.Accent, "Ctrl+B");
        now.Click += (_, _) => Fire(BackupNow);
        var usb = new UiButton("Copy to USB / folder…", ButtonKind.Primary, "Ctrl+U");
        usb.Click += (_, _) => Fire(CopyToUsb);
        var restore = new UiButton("Restore…", ButtonKind.Danger) { Enabled = Session.Can(Perm.BackupRestore) };
        restore.Click += (_, _) => Fire(Restore);
        var card = new Card { Dock = DockStyle.Fill, Padding = new Padding(1) };
        card.Controls.Add(_grid);
        Compose(Box.Toolbar(new Control[] { now, usb }, new Control[] { restore }), _status.Docked(DockStyle.Top), Box.Gap(6), card);
    }

    protected override Task OnFirstShow() => Fill();

    private async Task Fill()
    {
        var list = await Session.Api.Get<List<BackupDto>>("backups");
        _grid.SetRows(list, b => new object?[] { b.StartedAt, b.Kind, b.FileName, $"{b.SizeBytes / 1048576.0:0.0} MB", b.Result, b.CheckResult, b.CopiedTo, b.Message });
        var lastUsb = list.Where(b => !string.IsNullOrEmpty(b.CopiedTo)).Select(b => (DateTime?)b.StartedAt).FirstOrDefault();
        _status.Text = $"{list.Count} backups kept on the host. Last off-site copy: {(lastUsb.HasValue ? Dates.DisplayTime(lastUsb.Value) : "never")}.";
        _status.ForeColor = lastUsb == null || (DateTime.Now - lastUsb.Value).TotalDays > 2 ? Theme.Warn : Theme.InkMuted;
    }

    private async Task BackupNow()
    {
        Bar.Show("Backing up… counters can keep selling.", Tone.Info);
        var b = await Session.Api.Post<BackupDto>("backups", new { }, slow: true);
        Bar.Show(b.Result == "OK" ? $"Backup {b.FileName} finished and checked ({b.CheckResult})." : $"Backup failed: {b.Message}", b.Result == "OK" ? Tone.Ok : Tone.Danger);
        await Fill();
        _grid.SelectRow(0);
    }

    /// <summary>Downloads the chosen backup to a USB drive or folder, then re-reads the copy and compares its SHA-256.</summary>
    private async Task CopyToUsb()
    {
        var b = _grid.Current<BackupDto>();
        if (b == null || b.Result != "OK")
        {
            if (!Msg.Confirm(FindForm(), "Take a fresh backup now and copy it?")) return;
            await BackupNow();
            b = _grid.Current<BackupDto>();
            if (b == null || b.Result != "OK") return;
        }
        using var fbd = new FolderBrowserDialog { Description = "Choose the USB drive (or folder) for the backup copy", ShowNewFolderButton = true };
        var removable = DriveInfo.GetDrives().FirstOrDefault(d => d.DriveType == DriveType.Removable && d.IsReady);
        if (removable != null) fbd.SelectedPath = removable.RootDirectory.FullName;
        if (fbd.ShowDialog(FindForm()) != DialogResult.OK) return;
        Bar.Show("Copying and verifying…", Tone.Info);
        string path;
        try { path = await Session.Api.DownloadTo($"backups/{b.Id}/file", fbd.SelectedPath); }
        catch (IOException ex) { await Report(b, fbd.SelectedPath, false, ex.Message); throw; }
        var hash = await Task.Run(() =>
        {
            using var sha = SHA256.Create();
            using var fs = File.OpenRead(path);
            return string.Concat(sha.ComputeHash(fs).Select(x => x.ToString("x2")));
        });
        var ok = string.Equals(hash, b.Sha256, StringComparison.OrdinalIgnoreCase);
        await Report(b, path, ok, ok ? "SHA-256 verified" : $"SHA-256 mismatch: copy {hash}, host {b.Sha256}");
        if (ok) Bar.Show($"Copied to {path} and verified (SHA-256 matches). Keep this USB away from the pharmacy overnight.", Tone.Ok);
        else Bar.Show("The copy does not match the backup on the host. Use another USB drive and try again.", Tone.Danger);
        await Fill();
    }

    private static Task Report(BackupDto b, string dest, bool ok, string message) =>
        Session.Api.Post<OkResult>($"backups/{b.Id}/copied", new BackupCopiedRequest { Destination = dest, Verified = ok, Message = message });

    private async Task Restore()
    {
        using var d = new RestoreDialog(_grid.Items<BackupDto>().Where(x => x.Result == "OK").ToList());
        if (d.ShowDialog(FindForm()) != DialogResult.OK) return;
        Msg.Info(FindForm(), "The data was restored. Every PC must log in again. Sales made after the backup time are not in the system; re-enter them from the paper receipts.");
        await Shell.Logout();
    }
}

/// <summary>Restore from a host backup or a file on USB: admin password and the typed word RESTORE.</summary>
public sealed class RestoreDialog : DialogForm
{
    private readonly UiCombo _backup = new UiCombo();
    private readonly Label _file = new UiLabel("", Theme.Small, Theme.InkMuted);
    private readonly UiText _password = new UiText { UseSystemPasswordChar = true };
    private readonly UiText _phrase = new UiText("Type RESTORE");
    private string? _uploadId;

    private sealed class Choice
    {
        public BackupDto B { get; }
        public Choice(BackupDto b) { B = b; }
        public override string ToString() => $"{Dates.DisplayTime(B.StartedAt)} · {B.Kind} · {B.FileName}";
    }

    public RestoreDialog(List<BackupDto> backups) : base("Restore data from a backup", 640)
    {
        _backup.SetItems(backups.Select(b => new Choice(b)));
        var upload = new UiButton("Use a backup file from USB…");
        upload.Click += (_, _) => UiRun.Fire(this, Upload, Bar);
        var f = new FieldGrid(1);
        f.AddFull(new UiLabel("Restoring replaces ALL current data with the backup. Everything entered after the backup's time is lost. The host makes a safety copy of the current data first.", Theme.UiBold, Theme.Danger).Wrap(Theme.S(580)));
        f.Add("Backup on the host", _backup);
        f.AddFull(upload);
        f.AddFull(_file);
        f.Add("Your administrator password", _password);
        f.Add("Type RESTORE to confirm", _phrase);
        Body.Stack(f);
        AddOk("Restore now", Run, ButtonKind.Danger);
        AddCancel();
        FitToContent();
    }

    private async Task Upload()
    {
        using var ofd = new OpenFileDialog { Filter = "MediCare backup (*.mcbak)|*.mcbak", Title = "Choose the backup file" };
        if (ofd.ShowDialog(this) != DialogResult.OK) return;
        var r = await Session.Api.Upload<OkResult>("backups/upload", ofd.FileName);
        _uploadId = r.No;
        _file.Text = "Will restore " + Path.GetFileName(ofd.FileName) + " (uploaded to the host).";
        _backup.Enabled = false;
    }

    private async Task<bool> Run()
    {
        var c = _backup.Selected<Choice>();
        if (_uploadId == null && c == null) { Fail("Choose a backup."); return false; }
        if (_phrase.Text != "RESTORE") { Fail("Type RESTORE in capital letters to confirm."); return false; }
        await Session.Api.Post<OkResult>("restore", new RestoreRequest { BackupId = _uploadId == null ? c!.B.Id : (long?)null, UploadId = _uploadId, AdminPassword = _password.Text, ConfirmPhrase = _phrase.Text }, slow: true);
        return true;
    }
}

/// <summary>SCR-32 Settings: pharmacy profile, tax, thresholds, receipt, numbering, payments, shortcuts, lists, cash accounts.</summary>
public sealed class SettingsScreen : AppScreen
{
    private readonly TabControl _tabs = new TabControl { Dock = DockStyle.Fill };
    private readonly Dictionary<string, Func<string>> _values = new Dictionary<string, Func<string>>();
    private List<SettingItem> _items = new List<SettingItem>();

    public override string Title => "Settings";
    public override string Subtitle => "Changes apply to every counter within a few seconds.";

    public override IEnumerable<FKey> FKeys => new[] { new FKey(Keys.Control | Keys.S, "Save", () => Fire(Save), primary: true) };

    public SettingsScreen()
    {
        _tabs.Font = Theme.Ui;
        _tabs.Padding = new Point(Theme.S(12), Theme.S(6));
        var save = new UiButton("Save settings", ButtonKind.Primary, "Ctrl+S");
        save.Click += (_, _) => Fire(Save);
        Compose(Box.Toolbar(new Control[] { save }), Box.Gap(6), _tabs);
    }

    protected override async Task OnFirstShow()
    {
        _items = await Session.Api.Get<List<SettingItem>>("settings");
        _tabs.TabPages.Clear();
        _values.Clear();
        foreach (var g in _items.GroupBy(i => i.Group))
        {
            var page = new TabPage(g.Key) { BackColor = Theme.Canvas, AutoScroll = true, Padding = new Padding(Theme.S(16)) };
            var f = new FieldGrid(2);
            foreach (var s in g)
            {
                Control input;
                switch (s.Type)
                {
                    case "bool":
                        var cb = new UiCheck(s.Label, s.Value == "true" || s.Value == "1");
                        _values[s.Key] = () => cb.Checked ? "true" : "false";
                        f.AddFull(cb);
                        continue;
                    case "choice":
                        var c = new UiCombo();
                        c.SetItems(s.Choices);
                        c.SelectedItem = s.Value;
                        _values[s.Key] = () => c.SelectedItem as string ?? s.Value;
                        input = c;
                        break;
                    case "multiline":
                        var m = new UiText().Multi(3);
                        m.Text = s.Value.Replace("\n", Environment.NewLine);
                        if (s.Key.IndexOf("urdu", StringComparison.OrdinalIgnoreCase) >= 0) { m.RightToLeft = RightToLeft.Yes; m.Font = Theme.Urdu(16); }
                        _values[s.Key] = () => m.Text.Replace("\r\n", "\n");
                        input = m;
                        break;
                    case "money":
                    case "percent":
                        var mb = new MoneyBox { Text = s.Value };
                        _values[s.Key] = () => mb.Value.Inv();
                        input = mb;
                        break;
                    case "int":
                        var ib = new IntBox { Text = s.Value };
                        _values[s.Key] = () => ib.Text.Trim();
                        input = ib;
                        break;
                    default:
                        var t = new UiText { Text = s.Value };
                        if (s.Key.IndexOf("urdu", StringComparison.OrdinalIgnoreCase) >= 0 && s.Key.StartsWith("receipt.footer", StringComparison.Ordinal)) { t.RightToLeft = RightToLeft.Yes; t.Font = Theme.Urdu(16); }
                        _values[s.Key] = () => t.Text.Trim();
                        input = t;
                        break;
                }
                f.Add(s.Label, input, s.Type == "multiline" ? 2 : 1, s.Hint);
            }
            page.Controls.Add(f);
            _tabs.TabPages.Add(page);
        }
        AddListsPage();
        AddAccountsPage();
    }

    protected override Task OnReshow() => Task.CompletedTask;

    private async Task Save()
    {
        var changed = _items.Where(i => _values.ContainsKey(i.Key) && _values[i.Key]() != i.Value).ToDictionary(i => i.Key, i => _values[i.Key]());
        if (changed.Count == 0) { Bar.Show("Nothing changed.", Tone.Info); return; }
        var r = await Session.Api.Put<OkResult>("settings", changed);
        foreach (var kv in changed) _items.First(i => i.Key == kv.Key).Value = kv.Value;
        Bar.Show(r.Message ?? "Settings saved.", Tone.Ok);
        Session.UpdateSettings(await Session.Api.Get<ClientSettings>("settings/client"));
    }

    private void AddListsPage()
    {
        var page = new TabPage("Lists") { BackColor = Theme.Canvas, Padding = new Padding(Theme.S(16)) };
        var kind = new UiCombo();
        kind.SetItems(ListKinds.All);
        var box = new UiText().Multi(14);
        box.Dock = DockStyle.Top;
        var save = new UiButton("Save list", ButtonKind.Accent);
        kind.SelectedIndexChanged += (_, _) => Fire(async () => box.Text = string.Join(Environment.NewLine, (await Session.Api.Get<LookupListDto>($"lists/{kind.SelectedItem}")).Values));
        save.Click += (_, _) => Fire(async () =>
        {
            await Session.Api.Put<OkResult>($"lists/{kind.SelectedItem}", new LookupListDto { Kind = (string)kind.SelectedItem!, Values = box.Text.SplitTrim('\n').ToList() });
            Bar.Show("List saved.", Tone.Ok);
        });
        page.Stack(Box.Toolbar(new Control[] { kind.Sized(220), save }), new UiLabel("One value per line (manufacturers, categories, dosage forms, units, expense categories, adjustment reasons).", Theme.Small, Theme.InkMuted), box);
        _tabs.TabPages.Add(page);
        kind.SelectedIndex = 0;
    }

    private void AddAccountsPage()
    {
        var page = new TabPage("Cash and bank accounts") { BackColor = Theme.Canvas, Padding = new Padding(Theme.S(16)) };
        var grid = new Grid();
        grid.Col("Name", 0, ColKind.Text, 200);
        grid.Col("Kind", 100);
        grid.Col("Ledger code", 110, ColKind.Mono);
        grid.Col("Balance", 120, ColKind.Money);
        grid.Col("Active", 70);
        async Task Load() => grid.SetRows(await Session.Api.Get<List<CashAccountDto>>("cash-accounts"), a => new object?[] { a.Name, a.Kind, a.AccountCode, a.Balance, a.IsActive ? "Yes" : "No" });
        var add = new UiButton("Add account", ButtonKind.Accent);
        add.Click += (_, _) => Fire(async () =>
        {
            using var d = new CashAccountDialog(null);
            if (d.ShowDialog(FindForm()) == DialogResult.OK) await Load();
        });
        grid.RowActivated += o => Fire(async () =>
        {
            using var d = new CashAccountDialog((CashAccountDto)o);
            if (d.ShowDialog(FindForm()) == DialogResult.OK) await Load();
        });
        var host = new Panel { Dock = DockStyle.Fill };
        host.Controls.Add(grid);
        page.Stack(Box.Toolbar(new Control[] { add }), host);
        _tabs.TabPages.Add(page);
        Fire(Load);
    }
}

public sealed class CashAccountDialog : DialogForm
{
    public CashAccountDialog(CashAccountDto? a) : base(a == null ? "New cash / bank account" : a.Name, 460)
    {
        var name = new UiText { Text = a?.Name ?? "" };
        var kind = new UiCombo();
        kind.SetItems(new[] { "CASH", "BANK", "CARD", "WALLET" });
        kind.SelectedItem = a?.Kind ?? "BANK";
        kind.Enabled = a == null;
        var enforce = new UiCheck("Do not allow the balance to go below zero", a?.EnforceBalance ?? true);
        var active = new UiCheck("Active", a?.IsActive ?? true);
        var f = new FieldGrid(2);
        f.Add("Name (e.g. Meezan Bank 0123)", name, 2);
        f.Add("Kind", kind);
        f.AddFull(enforce);
        f.AddFull(active);
        Body.Stack(f);
        AddOk("Save", async () =>
        {
            if (name.Text.Trim().Length == 0) { Fail("Enter the account name."); return false; }
            var dto = a ?? new CashAccountDto();
            dto.Name = name.Text.Trim();
            dto.Kind = kind.SelectedItem as string ?? "BANK";
            dto.EnforceBalance = enforce.Checked;
            dto.IsActive = active.Checked;
            if (a == null) await Session.Api.Post<OkResult>("cash-accounts", dto);
            else await Session.Api.Put<OkResult>($"cash-accounts/{a.Id}", dto);
            return true;
        });
        AddCancel();
        FitToContent();
    }
}

/// <summary>SCR-33 Host and workstations: host health, connected PCs (block / counter), self-check.</summary>
public sealed class HostScreen : AppScreen
{
    private readonly Label _info = new UiLabel("", Theme.Ui);
    private readonly Grid _ws = new Grid();
    private readonly Grid _check = new Grid();

    public override string Title => "Host and workstations";
    public override string Subtitle => "The host PC, the counters connected to it, and the integrity self-check.";

    public override IEnumerable<FKey> FKeys => new[]
    {
        new FKey(Keys.F5, "Refresh", () => Fire(Fill)),
        new FKey(Keys.F6, "Run self-check", () => Fire(RunCheck)),
        new FKey(Keys.Enter, "Edit PC", () => Fire(EditWs)),
    };

    public HostScreen()
    {
        _info.AutoSize = false;
        _info.Dock = DockStyle.Fill;
        var infoCard = new Card("Host") { Dock = DockStyle.Top, Height = Theme.S(150) };
        infoCard.Controls.Add(_info);
        _ws.Col("PC", 0, ColKind.Text, 160);
        _ws.Col("Counter", 70, ColKind.Int);
        _ws.Col("Windows", 0, ColKind.Text, 160);
        _ws.Col("Client", 80);
        _ws.Col("Last seen", 140, ColKind.DateTime);
        _ws.Col("Last user", 120);
        _ws.Col("Allowed", 80);
        _ws.RowTone = o => o is WorkstationDto w && !w.IsActive ? Tone.Danger : Tone.None;
        _ws.RowActivated += _ => Fire(EditWs);
        var wsCard = new Card("Workstations") { Dock = DockStyle.Fill, Padding = new Padding(1, Theme.S(36), 1, 1) };
        wsCard.Controls.Add(_ws);
        _check.Col("Check", 0, ColKind.Text, 200);
        _check.Col("Result", 80);
        _check.Col("Detail", 0, ColKind.Text, 260);
        _check.RowTone = o => o is SelfCheckItem i && !i.Ok ? Tone.Danger : Tone.None;
        var checkCard = new Card("Self-check (stock, journals, invoices, audit chain, backup)") { Dock = DockStyle.Bottom, Height = Theme.S(240), Padding = new Padding(1, Theme.S(36), 1, 1) };
        checkCard.Controls.Add(_check);
        var run = new UiButton("Run self-check now", ButtonKind.Secondary, "F6");
        run.Click += (_, _) => Fire(RunCheck);
        Compose(Box.Toolbar(new Control[] { run }), infoCard, Box.Gap(8), checkCard, wsCard);
        checkCard.SendToBack();
    }

    protected override Task OnFirstShow() => Fill();

    private async Task Fill()
    {
        var s = await Session.Api.Get<HostStatusDto>("host/status");
        _info.Text = $"{s.MachineName} · MediCare Host {s.ServerVersion} · database schema {s.SchemaVersion} · {s.DbSizeBytes / 1048576.0:0.0} MB\n" +
                     $"Running since {Dates.DisplayTime(s.StartedAtUtc.ToLocalTime())} · {s.ActiveSessions} active sessions{(s.Maintenance ? " · MAINTENANCE" : "")}\n" +
                     $"Database: {s.DbPath}\nCertificate fingerprint (compare when pairing a PC):\n{s.CertificateFingerprint}";
        _ws.SetRows(s.Workstations, w => new object?[] { w.Name, w.CounterNo, w.Os, w.ClientVersion, w.LastSeenAt, w.LastUser, w.IsActive ? "Yes" : "Blocked" });
        var last = await Session.Api.Get<SelfCheckResult?>("selfcheck/last");
        if (last != null) _check.SetRows(last.Items, i => new object?[] { i.Check, i.Ok ? "OK" : "PROBLEM", i.Detail });
    }

    private async Task RunCheck()
    {
        var r = await Session.Api.Post<SelfCheckResult>("selfcheck", new { }, slow: true);
        _check.SetRows(r.Items, i => new object?[] { i.Check, i.Ok ? "OK" : "PROBLEM", i.Detail });
        Bar.Show(r.Ok ? "Self-check passed: the books, stock and audit chain agree." : "Self-check found problems. See the red rows and call support before closing the day.", r.Ok ? Tone.Ok : Tone.Danger);
    }

    private async Task EditWs()
    {
        var w = _ws.Current<WorkstationDto>();
        if (w == null) return;
        var block = w.IsActive && Msg.Confirm(FindForm(), $"Block {w.Name}? It will not be able to log in until allowed again.");
        var allow = !w.IsActive && Msg.Confirm(FindForm(), $"Allow {w.Name} to log in again?");
        if (!block && !allow) return;
        w.IsActive = allow;
        await Session.Api.Put<OkResult>($"workstations/{w.Id}", w);
        await Fill();
    }
}
