namespace MediCare.Client.Ui;

/// <summary>Message boxes with the product's wording rules (say what happened and what to do).</summary>
public static class Msg
{
    public static void Info(IWin32Window? owner, string text, string title = "MediCare Pharmacy") =>
        MessageBox.Show(owner, text, title, MessageBoxButtons.OK, MessageBoxIcon.Information);

    public static void Error(IWin32Window? owner, string text, string title = "MediCare Pharmacy") =>
        MessageBox.Show(owner, text, title, MessageBoxButtons.OK, MessageBoxIcon.Warning);

    public static bool Confirm(IWin32Window? owner, string text, string title = "Please confirm") =>
        MessageBox.Show(owner, text, title, MessageBoxButtons.YesNo, MessageBoxIcon.Question, MessageBoxDefaultButton.Button2) == DialogResult.Yes;

    /// <summary>Asks for a single line of text (reason, note). Returns null when cancelled.</summary>
    public static string? Ask(IWin32Window? owner, string title, string label, string initial = "", bool required = true, bool password = false)
    {
        using var d = new PromptDialog(title, label, initial, required, password);
        return d.ShowDialog(owner) == DialogResult.OK ? d.Value : null;
    }

    private sealed class PromptDialog : DialogForm
    {
        private readonly UiText _box;
        public string Value => _box.Text.Trim();

        public PromptDialog(string title, string label, string initial, bool required, bool password) : base(title, 460)
        {
            var f = new FieldGrid(1);
            _box = f.Add(label, new UiText { Text = initial, UseSystemPasswordChar = password });
            Body.Stack(f);
            AddOk("OK", () =>
            {
                if (required && Value.Length == 0) { Fail("Enter a value or press Esc to cancel."); return Task.FromResult(false); }
                return Task.FromResult(true);
            });
            AddCancel();
            FitToContent();
        }
    }
}

/// <summary>Runs async UI work: busy cursor, friendly errors, never crashes the counter.</summary>
public static class UiRun
{
    public static async Task Run(Control owner, Func<Task> work, MessageBar? bar = null)
    {
        var form = owner.FindForm();
        var cursor = form?.Cursor ?? Cursors.Default;
        try
        {
            if (form != null) form.Cursor = Cursors.WaitCursor;
            bar?.Clear();
            await work();
        }
        catch (OperationCanceledException) { }
        catch (ApiException ex)
        {
            if (ex.IsAuth) return; // the shell shows the login / lock screen
            if (bar != null) bar.Show(ex.Message, ex.IsOffline ? Tone.Warn : Tone.Danger);
            else Msg.Error(form, ex.Message);
        }
        catch (Exception ex)
        {
            Log.Error("UI action failed", ex);
            var text = "Something went wrong on this PC: " + ex.Message + " The details are in the client log.";
            if (bar != null) bar.Show(text); else Msg.Error(form, text);
        }
        finally
        {
            if (form != null && !form.IsDisposed) form.Cursor = cursor;
        }
    }

    /// <summary>Fire-and-forget wrapper for event handlers.</summary>
    public static void Fire(Control owner, Func<Task> work, MessageBar? bar = null) => _ = Run(owner, work, bar);
}

/// <summary>
/// Standard dialog: title, message bar, body, button row. Enter = primary button, Esc = cancel.
/// The OK action runs async; if it returns false (or throws) the dialog stays open and shows the error inline.
/// </summary>
public class DialogForm : Form
{
    protected readonly Panel Body;
    protected readonly MessageBar Bar;
    protected readonly FlowLayoutPanel Buttons;
    private UiButton? _ok;
    private bool _busy;

    public DialogForm(string title, int width = 560)
    {
        Text = title;
        Font = Theme.Ui;
        BackColor = Theme.Canvas;
        ForeColor = Theme.Ink;
        FormBorderStyle = FormBorderStyle.FixedDialog;
        StartPosition = FormStartPosition.CenterParent;
        MaximizeBox = false;
        MinimizeBox = false;
        ShowInTaskbar = false;
        KeyPreview = true;
        AutoScaleMode = AutoScaleMode.None;
        ClientSize = new Size(Theme.S(width), Theme.S(300));

        Buttons = new FlowLayoutPanel
        {
            Dock = DockStyle.Bottom,
            FlowDirection = FlowDirection.RightToLeft,
            Height = Theme.S(60),
            Padding = new Padding(Theme.S(12), Theme.S(10), Theme.S(12), Theme.S(10)),
            BackColor = Theme.Surface,
            WrapContents = false,
        };
        Bar = new MessageBar();
        Body = new Panel { Dock = DockStyle.Fill, Padding = new Padding(Theme.S(20), Theme.S(16), Theme.S(8), Theme.S(8)), AutoScroll = true, BackColor = Theme.Canvas };
        var line = new Panel { Dock = DockStyle.Bottom, Height = 1, BackColor = Theme.Line };
        // Docking runs from the last control to the first: Bar (top), Buttons (bottom), line, then Body fills.
        Controls.AddRange(new Control[] { Body, line, Buttons, Bar });
    }

    public void Fail(string message, Tone tone = Tone.Danger) => Bar.Show(message, tone);

    /// <summary>Adds the primary button. <paramref name="onOk"/> returns true to close with DialogResult.OK.</summary>
    protected UiButton AddOk(string text, Func<Task<bool>> onOk, ButtonKind kind = ButtonKind.Primary)
    {
        _ok = new UiButton(text, kind, "Enter");
        _ok.Click += async (_, _) => await RunOk(onOk);
        Buttons.Controls.Add(_ok);
        return _ok;
    }

    protected UiButton AddCancel(string text = "Cancel")
    {
        var b = new UiButton(text, ButtonKind.Secondary, "Esc") { DialogResult = DialogResult.Cancel };
        Buttons.Controls.Add(b);
        CancelButton = b;
        return b;
    }

    protected UiButton AddButton(string text, Action onClick, ButtonKind kind = ButtonKind.Secondary, string hint = "")
    {
        var b = new UiButton(text, kind, hint);
        b.Click += (_, _) => onClick();
        Buttons.Controls.Add(b);
        return b;
    }

    protected async Task RunOk(Func<Task<bool>> onOk)
    {
        if (_busy) return;
        _busy = true;
        if (_ok != null) _ok.Enabled = false;
        var ok = false;
        try
        {
            Bar.Clear();
            Cursor = Cursors.WaitCursor;
            ok = await onOk();
        }
        catch (OperationCanceledException) { }
        catch (ApiException ex) { if (!ex.IsAuth) Fail(ex.Message, ex.IsOffline ? Tone.Warn : Tone.Danger); }
        catch (Exception ex) { Log.Error("Dialog action failed", ex); Fail("Something went wrong on this PC: " + ex.Message); }
        finally
        {
            _busy = false;
            Cursor = Cursors.Default;
            if (_ok != null && !IsDisposed) _ok.Enabled = true;
        }
        if (ok) { DialogResult = DialogResult.OK; Close(); }
    }

    protected override bool ProcessCmdKey(ref Message msg, Keys keyData)
    {
        if (keyData == Keys.Enter && _ok != null && _ok.Enabled)
        {
            var active = ActiveControl;
            while (active is ContainerControl cc && cc.ActiveControl != null) active = cc.ActiveControl;
            var multiline = active is TextBox tb && tb.Multiline;
            var inGrid = active is DataGridView || active?.Parent is DataGridView;
            var inButton = active is Button;
            if (!multiline && !inGrid && !inButton) { _ok.PerformClick(); return true; }
        }
        return base.ProcessCmdKey(ref msg, keyData);
    }

    /// <summary>Sizes the dialog to its body content (up to 85% of the screen height).</summary>
    protected void FitToContent(int minHeight = 160)
    {
        Body.PerformLayout();
        var content = Body.Controls.Cast<Control>().Where(c => c.Visible).Select(c => c.Bottom).DefaultIfEmpty(0).Max() + Body.Padding.Vertical;
        var max = (int)(System.Windows.Forms.Screen.PrimaryScreen!.WorkingArea.Height * 0.85);
        var h = Math.Min(Math.Max(Theme.S(minHeight), content + Buttons.Height + Theme.S(16)), max);
        ClientSize = new Size(ClientSize.Width, h);
    }

    protected override void OnShown(EventArgs e)
    {
        base.OnShown(e);
        var first = FirstFocusable(Body);
        first?.Focus();
    }

    private static Control? FirstFocusable(Control root)
    {
        foreach (Control c in root.Controls.Cast<Control>().OrderBy(c => c.TabIndex))
        {
            if (!c.Visible || !c.Enabled) continue;
            if ((c is TextBox || c is ComboBox || c is DateTimePicker || c is DataGridView || c is CheckBox) && c.CanFocus) return c;
            var inner = FirstFocusable(c);
            if (inner != null) return inner;
        }
        return null;
    }
}
