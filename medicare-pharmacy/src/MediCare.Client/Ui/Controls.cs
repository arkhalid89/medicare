using System.ComponentModel;
using System.Drawing.Drawing2D;

namespace MediCare.Client.Ui;

public enum ButtonKind { Secondary, Primary, Accent, Danger, Ghost }

/// <summary>Flat button drawn from tokens. Optional keycap hint ("F4") on the right.</summary>
public class UiButton : Button
{
    private bool _down;
    private float _hoverT;
    private ButtonKind _kind;
    private string _icon = "";

    /// <summary>Optional line icon drawn before the text (see <see cref="Icons"/>).</summary>
    public string Icon
    {
        get => _icon;
        set { _icon = value ?? ""; if (Width < Measure()) Width = Measure(); Invalidate(); }
    }

    [DefaultValue(ButtonKind.Secondary)]
    public ButtonKind Kind { get => _kind; set { _kind = value; Invalidate(); } }

    private string _hint = "";

    /// <summary>Keyboard shortcut shown as a keycap, e.g. "F4" or "Ctrl+S". Changing it re-measures the width.</summary>
    public string Hint
    {
        get => _hint;
        set
        {
            var grow = Width > 0 && Width == Measure();
            _hint = value ?? "";
            if (grow || Width < Measure()) Width = Measure();
            Invalidate();
        }
    }

    public UiButton() : this("", ButtonKind.Secondary) { }

    public UiButton(string text, ButtonKind kind = ButtonKind.Secondary, string hint = "")
    {
        SetStyle(ControlStyles.UserPaint | ControlStyles.AllPaintingInWmPaint | ControlStyles.OptimizedDoubleBuffer | ControlStyles.ResizeRedraw, true);
        Text = text;
        _kind = kind;
        Hint = hint;
        Font = kind == ButtonKind.Primary || kind == ButtonKind.Accent ? Theme.UiBold : Theme.Ui;
        Height = Theme.ControlHeight;
        Cursor = Cursors.Hand;
        AutoSize = false;
        Width = Measure();
        Margin = new Padding(Theme.S(4));
    }

    public int Measure()
    {
        var w = TextRenderer.MeasureText(Text, Font).Width + Theme.S(28);
        if (_icon.Length > 0) w += Theme.S(24);
        if (Hint.Length > 0) w += TextRenderer.MeasureText(Hint, Theme.Keycap).Width + Theme.S(14);
        return Math.Max(w, Theme.S(84));
    }

    public UiButton Large()
    {
        Height = Theme.ControlHeightLg;
        Font = Kind == ButtonKind.Secondary || Kind == ButtonKind.Ghost ? Theme.Lg : Theme.LgBold;
        Width = Measure();
        return this;
    }

    protected override void OnMouseEnter(EventArgs e) { FadeHover(1f); base.OnMouseEnter(e); }
    protected override void OnMouseLeave(EventArgs e) { _down = false; FadeHover(0f); base.OnMouseLeave(e); }

    private void FadeHover(float to)
    {
        var from = _hoverT;
        Motion.Run(this, "hover", 140, t => { _hoverT = Motion.Lerp(from, to, t); Invalidate(); });
    }
    protected override void OnMouseDown(MouseEventArgs e) { _down = true; Invalidate(); base.OnMouseDown(e); }
    protected override void OnMouseUp(MouseEventArgs e) { _down = false; Invalidate(); base.OnMouseUp(e); }
    protected override void OnGotFocus(EventArgs e) { Invalidate(); base.OnGotFocus(e); }
    protected override void OnLostFocus(EventArgs e) { Invalidate(); base.OnLostFocus(e); }
    protected override void OnEnabledChanged(EventArgs e) { Invalidate(); base.OnEnabledChanged(e); }

    protected override void OnPaint(PaintEventArgs e)
    {
        var g = e.Graphics;
        Theme.Smooth(g);
        var behind = Theme.EffectiveBack(Parent);
        g.Clear(behind);
        Color back, hoverBack, fore, border;
        switch (Kind)
        {
            case ButtonKind.Primary: back = Theme.Primary; hoverBack = Theme.PrimaryHover; fore = Color.White; border = back; break;
            case ButtonKind.Accent: back = Theme.Accent; hoverBack = Theme.AccentHover; fore = Color.White; border = back; break;
            case ButtonKind.Danger: back = Theme.Danger; hoverBack = Theme.Hex("#9A1D14"); fore = Color.White; border = back; break;
            case ButtonKind.Ghost: back = behind; hoverBack = Theme.Select; fore = Theme.Accent; border = back; break;
            default: back = Theme.Surface; hoverBack = Theme.Select; fore = Theme.Ink; border = Theme.LineStrong; break;
        }
        back = Theme.Blend(back, hoverBack, _hoverT);
        border = Kind == ButtonKind.Secondary ? Theme.Blend(border, Theme.Accent, _hoverT) : back;
        if (_down) back = Theme.Blend(back, Color.Black, 0.12f);
        if (!Enabled) { back = Theme.SurfaceAlt; fore = Theme.LineStrong; border = Theme.Line; }
        var r = new Rectangle(0, 0, Width - 1, Height - 1);
        if (_down) r.Inflate(-1, -1);
        using (var path = Theme.Rounded(r, Theme.S(6)))
        {
            using (var b = new SolidBrush(back)) g.FillPath(b, path);
            using (var p = new Pen(border)) g.DrawPath(p, path);
        }
        if (Focused && ShowFocusCues)
            using (var path = Theme.Rounded(Rectangle.Inflate(r, -3, -3), Theme.S(4)))
            using (var p = new Pen(Kind == ButtonKind.Secondary || Kind == ButtonKind.Ghost ? Theme.Accent : Color.White, 1.5f) { DashStyle = DashStyle.Solid })
                g.DrawPath(p, path);

        var textRect = new Rectangle(Theme.S(10), 0, Width - Theme.S(20), Height);
        if (Hint.Length > 0)
        {
            var hs = TextRenderer.MeasureText(Hint, Theme.Keycap);
            var kr = new Rectangle(Width - hs.Width - Theme.S(16), (Height - hs.Height - Theme.S(4)) / 2, hs.Width + Theme.S(8), hs.Height + Theme.S(4));
            using (var path = Theme.Rounded(kr, Theme.S(3)))
            using (var b = new SolidBrush(Kind == ButtonKind.Secondary || Kind == ButtonKind.Ghost ? Theme.SurfaceAlt : Color.FromArgb(40, Color.White)))
                g.FillPath(b, path);
            TextRenderer.DrawText(g, Hint, Theme.Keycap, kr, fore, TextFormatFlags.NoPrefix | TextFormatFlags.HorizontalCenter | TextFormatFlags.VerticalCenter);
            textRect.Width = kr.Left - textRect.Left - Theme.S(4);
        }
        if (_icon.Length > 0)
        {
            var iconSize = Theme.S(16);
            var textW = Math.Min(TextRenderer.MeasureText(Text, Font).Width, textRect.Width - iconSize - Theme.S(6));
            var x = textRect.Left + Math.Max(0, (textRect.Width - iconSize - Theme.S(6) - textW) / 2);
            Icons.Draw(g, _icon, new RectangleF(x, (Height - iconSize) / 2f, iconSize, iconSize), fore, 2.2f);
            textRect = new Rectangle(x + iconSize + Theme.S(6), 0, textRect.Right - x - iconSize - Theme.S(6), Height);
            TextRenderer.DrawText(g, Text, Font, textRect, fore, TextFormatFlags.NoPrefix | TextFormatFlags.Left | TextFormatFlags.VerticalCenter | TextFormatFlags.EndEllipsis);
            return;
        }
        TextRenderer.DrawText(g, Text, Font, textRect, fore, TextFormatFlags.NoPrefix | TextFormatFlags.HorizontalCenter | TextFormatFlags.VerticalCenter | TextFormatFlags.EndEllipsis);
    }
}

/// <summary>A key shown on the F-key strip.</summary>
public sealed class FKey
{
    public Keys Key { get; }
    public string Label { get; }
    public Action Action { get; }
    public bool Primary { get; }
    public Func<bool>? Enabled { get; set; }

    public FKey(Keys key, string label, Action action, bool primary = false)
    {
        Key = key; Label = label; Action = action; Primary = primary;
    }

    public string KeyText => KeyName(Key);

    public static string KeyName(Keys k)
    {
        var mods = new List<string>();
        if ((k & Keys.Control) != 0) mods.Add("Ctrl");
        if ((k & Keys.Shift) != 0) mods.Add("Shift");
        if ((k & Keys.Alt) != 0) mods.Add("Alt");
        var key = k & Keys.KeyCode;
        var name = key switch
        {
            Keys.Return => "Enter",
            Keys.Escape => "Esc",
            Keys.Delete => "Del",
            Keys.Oemplus => "+",
            Keys.OemMinus => "-",
            _ => key.ToString(),
        };
        mods.Add(name);
        return string.Join("+", mods);
    }
}

/// <summary>The keycap strip along the bottom of the window (direction C). Clicking a keycap runs it.</summary>
public sealed class KeycapBar : Control
{
    private List<FKey> _keys = new List<FKey>();
    private readonly List<(Rectangle rect, FKey key)> _hits = new List<(Rectangle, FKey)>();
    private int _hover = -1;
    private Keys _flashKey;
    private float _flashT;

    /// <summary>Briefly lights the keycap for a key that was just pressed on the keyboard.</summary>
    public void Flash(Keys key)
    {
        if (!_keys.Any(k => k.Key == key)) return;
        _flashKey = key;
        Motion.Run(this, "flash", 420, t => { _flashT = 1f - t; Invalidate(); }, () => _flashKey = Keys.None);
    }

    public KeycapBar()
    {
        SetStyle(ControlStyles.UserPaint | ControlStyles.AllPaintingInWmPaint | ControlStyles.OptimizedDoubleBuffer | ControlStyles.ResizeRedraw, true);
        Height = Theme.FKeyHeight;
        BackColor = Theme.Surface;
        Cursor = Cursors.Hand;
    }

    public void SetKeys(IEnumerable<FKey>? keys)
    {
        _keys = keys?.ToList() ?? new List<FKey>();
        Invalidate();
    }

    protected override void OnPaint(PaintEventArgs e)
    {
        var g = e.Graphics;
        Theme.Smooth(g);
        g.Clear(BackColor);
        using (var p = new Pen(Theme.Line)) g.DrawLine(p, 0, 0, Width, 0);
        _hits.Clear();
        int x = Theme.S(12);
        for (int i = 0; i < _keys.Count; i++)
        {
            var k = _keys[i];
            var enabled = k.Enabled?.Invoke() ?? true;
            var keySize = TextRenderer.MeasureText(k.KeyText, Theme.Keycap);
            var labelSize = TextRenderer.MeasureText(k.Label, Theme.Small);
            var capW = Math.Max(keySize.Width + Theme.S(10), Theme.S(30));
            var capH = Theme.S(24);
            var total = capW + Theme.S(6) + labelSize.Width + Theme.S(14);
            if (x + total > Width - Theme.S(8)) break;
            var y = (Height - capH) / 2;
            var cap = new Rectangle(x, y, capW, capH);
            var whole = new Rectangle(x - Theme.S(4), Theme.S(4), total, Height - Theme.S(8));
            var flash = k.Key == _flashKey ? _flashT : 0f;
            if ((_hover == i && enabled) || flash > 0)
                using (var path = Theme.Rounded(whole, Theme.S(6)))
                using (var b = new SolidBrush(Theme.Blend(_hover == i ? Theme.Select : Theme.Surface, Theme.AccentSoft, flash))) g.FillPath(b, path);
            if (flash > 0.5f) cap.Offset(0, 1);
            var capBack = k.Primary && enabled ? Theme.Primary : Theme.SurfaceAlt;
            var capFore = k.Primary && enabled ? Color.White : enabled ? Theme.Ink : Theme.LineStrong;
            using (var path = Theme.Rounded(cap, Theme.S(4)))
            {
                using (var b = new SolidBrush(capBack)) g.FillPath(b, path);
                using (var pen = new Pen(k.Primary && enabled ? Theme.Primary : Theme.LineStrong)) g.DrawPath(pen, path);
            }
            // keycap bottom edge
            using (var pen = new Pen(k.Primary && enabled ? Color.Black : Theme.LineStrong, Theme.S(2)))
                g.DrawLine(pen, cap.Left + Theme.S(3), cap.Bottom, cap.Right - Theme.S(3), cap.Bottom);
            TextRenderer.DrawText(g, k.KeyText, Theme.Keycap, cap, capFore, TextFormatFlags.NoPrefix | TextFormatFlags.HorizontalCenter | TextFormatFlags.VerticalCenter);
            var labelRect = new Rectangle(cap.Right + Theme.S(6), 0, labelSize.Width + Theme.S(4), Height);
            TextRenderer.DrawText(g, k.Label, Theme.Small, labelRect, enabled ? Theme.Ink : Theme.LineStrong, TextFormatFlags.NoPrefix | TextFormatFlags.VerticalCenter | TextFormatFlags.Left);
            _hits.Add((whole, k));
            x += total + Theme.S(6);
        }
    }

    protected override void OnMouseMove(MouseEventArgs e)
    {
        var idx = _hits.FindIndex(h => h.rect.Contains(e.Location));
        if (idx != _hover) { _hover = idx; Invalidate(); }
        base.OnMouseMove(e);
    }

    protected override void OnMouseLeave(EventArgs e) { _hover = -1; Invalidate(); base.OnMouseLeave(e); }

    protected override void OnMouseClick(MouseEventArgs e)
    {
        var hit = _hits.FirstOrDefault(h => h.rect.Contains(e.Location));
        if (hit.key != null && (hit.key.Enabled?.Invoke() ?? true)) hit.key.Action();
        base.OnMouseClick(e);
    }
}

/// <summary>White panel with a 1px border and optional title.</summary>
public class Card : Panel
{
    public string Heading { get; set; } = "";

    public Card(string heading = "")
    {
        Heading = heading;
        SetStyle(ControlStyles.OptimizedDoubleBuffer | ControlStyles.ResizeRedraw | ControlStyles.AllPaintingInWmPaint | ControlStyles.UserPaint, true);
        BackColor = Theme.Surface;
        Padding = new Padding(Theme.S(14), heading.Length > 0 ? Theme.S(38) : Theme.S(12), Theme.S(14), Theme.S(12));
        Margin = new Padding(Theme.S(6));
    }

    // cards whose content runs to the edge (grids) get small corners so the content does not poke out
    private int Radius => Padding.Left >= Theme.S(8) ? Theme.S(10) : Theme.S(3);

    protected override void OnPaintBackground(PaintEventArgs e)
    {
        var g = e.Graphics;
        Theme.Smooth(g);
        g.Clear(Theme.EffectiveBack(Parent));
        var r = new Rectangle(0, 0, Width - 1, Height - 1);
        using var path = Theme.Rounded(r, Radius);
        using (var b = new SolidBrush(BackColor)) g.FillPath(b, path);
        using (var p = new Pen(Theme.Line)) g.DrawPath(p, path);
    }

    protected override void OnPaint(PaintEventArgs e)
    {
        if (Heading.Length == 0) return;
        var g = e.Graphics;
        using (var b = new SolidBrush(Theme.Accent))
        using (var path = Theme.Rounded(new Rectangle(Theme.S(14), Theme.S(14), Theme.S(4), Theme.S(12)), Theme.S(2)))
            g.FillPath(b, path);
        TextRenderer.DrawText(g, Heading.ToUpperInvariant(), Theme.Xs, new Rectangle(Theme.S(24), Theme.S(10), Width - Theme.S(38), Theme.S(20)), Theme.InkMuted, TextFormatFlags.NoPrefix | TextFormatFlags.Left | TextFormatFlags.VerticalCenter | TextFormatFlags.EndEllipsis);
    }
}

/// <summary>
/// Dashboard figure: icon, label, big value (counts up when it changes), sub line and tone.
/// Lifts and shows an arrow on hover when it opens a detail screen.
/// </summary>
public sealed class KpiTile : Control
{
    private static readonly System.Text.RegularExpressions.Regex Number = new System.Text.RegularExpressions.Regex(@"\d[\d,]*(\.\d+)?");
    private string _target = "";
    private float _hoverT, _appear = 1f;
    private bool _hover;

    public string Label { get; set; } = "";
    public string Value { get; private set; } = "";
    public string Sub { get; set; } = "";
    public string Icon { get; set; } = "";
    public Tone Tone { get; set; }
    public event Action? Activated;

    public KpiTile()
    {
        SetStyle(ControlStyles.UserPaint | ControlStyles.AllPaintingInWmPaint | ControlStyles.OptimizedDoubleBuffer | ControlStyles.ResizeRedraw | ControlStyles.SupportsTransparentBackColor, true);
        BackColor = Color.Transparent;
        Size = new Size(Theme.S(232), Theme.S(112));
        Margin = new Padding(Theme.S(4));
    }

    public void Set(string value, string sub = "", Tone tone = Tone.None)
    {
        var old = _target;
        _target = value; Sub = sub; Tone = tone;
        Cursor = Activated != null ? Cursors.Hand : Cursors.Default;
        if (value == old) { Invalidate(); return; }

        var m = Number.Match(value);
        // count up only for plain figures ("Rs 12,345.00", "42"), never for dates or times
        if (m.Success && value.Length - m.Length <= 4 && decimal.TryParse(m.Value.Replace(",", ""), NumberStyles.Number, CultureInfo.InvariantCulture, out var to))
        {
            var om = Number.Match(old);
            var from = om.Success && old.Length - om.Length <= 4 && decimal.TryParse(om.Value.Replace(",", ""), NumberStyles.Number, CultureInfo.InvariantCulture, out var f) ? f : 0m;
            var decimals = m.Groups[1].Success ? m.Groups[1].Value.Length - 1 : 0;
            var format = (m.Value.Contains(",") || to >= 1000 ? "#,##0" : "0") + (decimals > 0 ? "." + new string('0', decimals) : "");
            var prefix = value.Substring(0, m.Index);
            var suffix = value.Substring(m.Index + m.Length);
            Motion.Run(this, "count", 700, t =>
            {
                var v = t >= 1f ? to : from + (to - from) * (decimal)t;
                Value = prefix + v.ToString(format, CultureInfo.InvariantCulture) + suffix;
                Invalidate();
            }, () => { Value = value; Invalidate(); });
        }
        else Value = value;
        if (old.Length == 0) Motion.Run(this, "appear", 420, t => { _appear = t; Invalidate(); });
        Invalidate();
    }

    protected override void OnClick(EventArgs e) { Activated?.Invoke(); base.OnClick(e); }

    protected override void OnMouseEnter(EventArgs e) { _hover = true; Fade(1f); base.OnMouseEnter(e); }
    protected override void OnMouseLeave(EventArgs e) { _hover = false; Fade(0f); base.OnMouseLeave(e); }

    private void Fade(float to)
    {
        if (Activated == null) return;
        var from = _hoverT;
        Motion.Run(this, "hover", 160, t => { _hoverT = Motion.Lerp(from, to, t); Invalidate(); });
    }

    protected override void OnPaint(PaintEventArgs e)
    {
        var g = e.Graphics;
        Theme.Smooth(g);
        g.Clear(Theme.EffectiveBack(Parent));
        var lift = (int)Math.Round(_hoverT * Theme.S(3));
        var body = new Rectangle(Theme.S(2), Theme.S(3) - lift, Width - Theme.S(5), Height - Theme.S(8));
        var radius = Theme.S(10);
        Theme.Shadow(g, body, radius, 0.8f + _hoverT * 1.4f);
        using (var path = Theme.Rounded(body, radius))
        {
            using (var b = new SolidBrush(Theme.Surface)) g.FillPath(b, path);
            using (var p = new Pen(Theme.Blend(Theme.Line, Theme.Accent, _hoverT * 0.8f))) g.DrawPath(p, path);
        }
        // tone stripe along the top edge
        if (Tone != Tone.None)
        {
            var clip = g.Clip;
            using (var path = Theme.Rounded(body, radius)) g.SetClip(path);
            using (var b = new SolidBrush(Theme.Fore(Tone))) g.FillRectangle(b, body.Left, body.Top, body.Width, Theme.S(3));
            g.Clip = clip;
        }

        var pad = Theme.S(16);
        var chip = Theme.S(34);
        var iconRect = new Rectangle(body.Right - pad - chip + Theme.S(4), body.Top + Theme.S(14), chip, chip);
        if (Icon.Length > 0)
        {
            var tone = Tone == Tone.None ? Tone.Accent : Tone;
            using (var b = new SolidBrush(Theme.Back(tone)))
            using (var path = Theme.Rounded(iconRect, Theme.S(9)))
                g.FillPath(b, path);
            var inset = Theme.S(8);
            Icons.Draw(g, Icon, new RectangleF(iconRect.X + inset, iconRect.Y + inset, chip - inset * 2, chip - inset * 2), Theme.Fore(tone), 2.2f);
        }
        var textW = body.Width - pad * 2 - (Icon.Length > 0 ? chip : 0);
        TextRenderer.DrawText(g, Label.ToUpperInvariant(), Theme.Xs, new Rectangle(body.Left + pad, body.Top + Theme.S(14), textW, Theme.S(18)), Theme.InkMuted, TextFormatFlags.NoPrefix | TextFormatFlags.Left | TextFormatFlags.EndEllipsis);
        var rise = (int)((1f - _appear) * Theme.S(10));
        var valueColor = Theme.Blend(Theme.Surface, Tone == Tone.Danger ? Theme.Danger : Theme.Ink, _appear);
        TextRenderer.DrawText(g, Value, Theme.Kpi, new Rectangle(body.Left + pad - Theme.S(2), body.Top + Theme.S(34) + rise, body.Width - pad * 2, Theme.S(36)), valueColor, TextFormatFlags.NoPrefix | TextFormatFlags.Left | TextFormatFlags.EndEllipsis);
        var subColor = Tone == Tone.None || Tone == Tone.Ok ? Theme.InkMuted : Theme.Fore(Tone);
        var subRect = new Rectangle(body.Left + pad, body.Top + Theme.S(74), body.Width - pad * 2 - (Activated != null ? Theme.S(20) : 0), Theme.S(22));
        TextRenderer.DrawText(g, Sub, Theme.Small, subRect, Theme.Blend(Theme.Surface, subColor, _appear), TextFormatFlags.NoPrefix | TextFormatFlags.Left | TextFormatFlags.EndEllipsis);
        if (Activated != null && (_hoverT > 0.01f || _hover))
        {
            var a = Theme.S(16);
            var shift = (int)((1f - _hoverT) * Theme.S(6));
            Icons.Draw(g, "arrow", new RectangleF(body.Right - pad - a + Theme.S(4) - shift, subRect.Top + (subRect.Height - a) / 2f, a, a), Theme.Blend(Theme.Surface, Theme.Accent, _hoverT), 2.4f);
        }
    }
}

/// <summary>Small coloured chip ("Expired", "FEFO", "Posted").</summary>
public sealed class Badge : Label
{
    private Tone _tone;

    public Badge(string text = "", Tone tone = Tone.Muted)
    {
        AutoSize = true;
        Font = Theme.Xs;
        Padding = new Padding(Theme.S(6), Theme.S(2), Theme.S(6), Theme.S(2));
        Margin = new Padding(Theme.S(4));
        Set(text, tone);
    }

    public void Set(string text, Tone tone)
    {
        Text = text.ToUpperInvariant();
        _tone = tone;
        BackColor = Theme.Back(tone);
        ForeColor = Theme.Fore(tone);
        Visible = text.Length > 0;
    }

    public Tone Tone => _tone;
}

/// <summary>Themed single-line text box.</summary>
public class UiText : TextBox
{
    public UiText(string cue = "")
    {
        Font = Theme.Ui;
        BorderStyle = BorderStyle.FixedSingle;
        BackColor = Theme.Surface;
        ForeColor = Theme.Ink;
        Margin = new Padding(0);
        if (cue.Length > 0) Theme.Cue(this, cue);
    }

    public UiText Multi(int lines)
    {
        Multiline = true;
        ScrollBars = ScrollBars.Vertical;
        Height = Theme.S(22) * lines + Theme.S(8);
        AcceptsReturn = true;
        return this;
    }
}

/// <summary>Amount entry: accepts digits, one decimal point and thousand separators; right aligned, monospace.</summary>
public class MoneyBox : UiText
{
    public bool AllowNegative { get; set; }
    public int Decimals { get; set; } = 2;

    public MoneyBox()
    {
        Font = Theme.Num;
        TextAlign = HorizontalAlignment.Right;
    }

    public decimal Value
    {
        get => Money.TryParse(Text, out var v) ? v : 0m;
        set => Text = value == 0 && !Focused ? "" : value.ToString(Decimals == 2 ? "#,##0.00" : "#,##0.####", CultureInfo.InvariantCulture);
    }

    public bool HasValue => Money.TryParse(Text, out _);

    protected override void OnKeyPress(KeyPressEventArgs e)
    {
        var c = e.KeyChar;
        if (char.IsControl(c) || char.IsDigit(c) || c == ',' || (c == '.' && !Text.Contains(".")) || (AllowNegative && c == '-' && SelectionStart == 0)) { base.OnKeyPress(e); return; }
        e.Handled = true;
    }

    protected override void OnEnter(EventArgs e)
    {
        base.OnEnter(e);
        BeginInvoke(new Action(SelectAll));
    }

    protected override void OnLeave(EventArgs e)
    {
        base.OnLeave(e);
        if (Money.TryParse(Text, out var v)) Text = v.ToString(Decimals == 2 ? "#,##0.00" : "#,##0.####", CultureInfo.InvariantCulture);
    }
}

/// <summary>Whole-number entry.</summary>
public class IntBox : UiText
{
    public IntBox()
    {
        Font = Theme.Num;
        TextAlign = HorizontalAlignment.Right;
    }

    public int Value
    {
        get => int.TryParse(Text.Replace(",", ""), NumberStyles.Integer, CultureInfo.InvariantCulture, out var v) ? v : 0;
        set => Text = value.ToString(CultureInfo.InvariantCulture);
    }

    protected override void OnKeyPress(KeyPressEventArgs e)
    {
        if (!char.IsControl(e.KeyChar) && !char.IsDigit(e.KeyChar)) e.Handled = true;
        base.OnKeyPress(e);
    }

    protected override void OnEnter(EventArgs e)
    {
        base.OnEnter(e);
        BeginInvoke(new Action(SelectAll));
    }
}

/// <summary>Drop-down list (no free text).</summary>
public class UiCombo : ComboBox
{
    public UiCombo()
    {
        DropDownStyle = ComboBoxStyle.DropDownList;
        Font = Theme.Ui;
        FlatStyle = FlatStyle.Flat;
        BackColor = Theme.Surface;
        IntegralHeight = false;
        MaxDropDownItems = 16;
    }

    public UiCombo Editable()
    {
        DropDownStyle = ComboBoxStyle.DropDown;
        AutoCompleteMode = AutoCompleteMode.SuggestAppend;
        AutoCompleteSource = AutoCompleteSource.ListItems;
        return this;
    }

    public void SetItems<T>(IEnumerable<T> items, T? select = default)
    {
        BeginUpdate();
        Items.Clear();
        foreach (var i in items) Items.Add(i!);
        EndUpdate();
        if (select != null) SelectedItem = select;
        else if (Items.Count > 0 && DropDownStyle == ComboBoxStyle.DropDownList) SelectedIndex = 0;
    }

    public T? Selected<T>() where T : class => SelectedItem as T;

    public void SelectWhere<T>(Func<T, bool> match)
    {
        for (int i = 0; i < Items.Count; i++)
            if (Items[i] is T t && match(t)) { SelectedIndex = i; return; }
    }
}

/// <summary>Date entry dd-MMM-yyyy.</summary>
public class UiDate : DateTimePicker
{
    public UiDate()
    {
        Format = DateTimePickerFormat.Custom;
        CustomFormat = "dd-MMM-yyyy";
        Font = Theme.Ui;
    }

    public UiDate Optional()
    {
        ShowCheckBox = true;
        Checked = false;
        return this;
    }

    public DateTime? Date => ShowCheckBox && !Checked ? (DateTime?)null : Value.Date;
}

public sealed class UiCheck : CheckBox
{
    public UiCheck(string text, bool value = false)
    {
        Text = text;
        Checked = value;
        Font = Theme.Ui;
        AutoSize = true;
        ForeColor = Theme.Ink;
        Margin = new Padding(0, Theme.S(6), Theme.S(12), Theme.S(6));
    }
}

/// <summary>Muted helper text or a heading line.</summary>
public sealed class UiLabel : Label
{
    public UiLabel(string text = "", Font? font = null, Color? color = null)
    {
        Text = text;
        Font = font ?? Theme.Ui;
        ForeColor = color ?? Theme.Ink;
        AutoSize = true;
        BackColor = Color.Transparent;
        Margin = new Padding(0, Theme.S(4), Theme.S(8), Theme.S(4));
    }

    public UiLabel Wrap(int width)
    {
        AutoSize = false;
        MaximumSize = new Size(width, 0);
        AutoSize = true;
        return this;
    }
}

/// <summary>Inline message bar (errors, warnings, info, success) at the top of a dialog or screen. Flashes when shown.</summary>
public sealed class MessageBar : Label
{
    private Tone _tone = Tone.Danger;
    private float _flash;

    public MessageBar()
    {
        SetStyle(ControlStyles.UserPaint | ControlStyles.AllPaintingInWmPaint | ControlStyles.OptimizedDoubleBuffer | ControlStyles.ResizeRedraw, true);
        AutoSize = false;
        Dock = DockStyle.Top;
        Height = Theme.S(36);
        Padding = new Padding(Theme.S(40), 0, Theme.S(12), 0);
        TextAlign = ContentAlignment.MiddleLeft;
        Font = Theme.Ui;
        Visible = false;
    }

    public void Show(string text, Tone tone = Tone.Danger)
    {
        Text = text;
        _tone = tone;
        BackColor = Theme.Back(tone);
        ForeColor = Theme.Fore(tone);
        using (var g = CreateGraphics())
        {
            // before the first layout Width can be tiny; measure against the parent's width instead
            var width = Math.Max(Parent?.ClientSize.Width ?? Width, Width) - Padding.Horizontal;
            var size = TextRenderer.MeasureText(g, text, Font, new Size(Math.Max(width, 300), 0), TextFormatFlags.WordBreak);
            Height = Math.Max(Theme.S(36), size.Height + Theme.S(14));
        }
        Visible = true;
        Motion.Run(this, "flash", 600, t => { _flash = 1f - t; Invalidate(); });
    }

    public void Clear() { Visible = false; Text = ""; }

    protected override void OnPaint(PaintEventArgs e)
    {
        var g = e.Graphics;
        Theme.Smooth(g);
        g.Clear(Theme.Blend(BackColor, Theme.Alpha(ForeColor, 255), _flash * 0.22f));
        using (var b = new SolidBrush(ForeColor)) g.FillRectangle(b, 0, 0, Theme.S(4), Height);
        var icon = _tone switch { Tone.Ok => "check", Tone.Danger => "alert", Tone.Warn => "alert", _ => "info" };
        var size = Theme.S(18);
        Icons.Draw(g, icon, new RectangleF(Theme.S(14), (Height - size) / 2f, size, size), ForeColor, 2.2f);
        var r = new Rectangle(Padding.Left, 0, Width - Padding.Horizontal, Height);
        TextRenderer.DrawText(g, Text, Font, r, ForeColor, TextFormatFlags.NoPrefix | TextFormatFlags.Left | TextFormatFlags.VerticalCenter | TextFormatFlags.WordBreak);
    }
}

/// <summary>
/// Label-above-input form layout in N columns. Keeps every editor consistent and keyboard friendly
/// (tab order = the order fields are added).
/// </summary>
public sealed class FieldGrid : TableLayoutPanel
{
    private int _col, _row;
    private readonly int _cols;

    public FieldGrid(int columns = 2)
    {
        _cols = columns;
        ColumnCount = columns;
        for (int i = 0; i < columns; i++) ColumnStyles.Add(new ColumnStyle(SizeType.Percent, 100f / columns));
        AutoSize = true;
        AutoSizeMode = AutoSizeMode.GrowAndShrink;
        Dock = DockStyle.Top;
        BackColor = Color.Transparent;
        Padding = new Padding(0);
        GrowStyle = TableLayoutPanelGrowStyle.AddRows;
    }

    public T Add<T>(string label, T control, int span = 1, string hint = "") where T : Control
    {
        span = Math.Min(span, _cols);
        if (_col + span > _cols) { _col = 0; _row++; }
        var cell = new TableLayoutPanel { ColumnCount = 1, RowCount = hint.Length > 0 ? 3 : 2, AutoSize = true, AutoSizeMode = AutoSizeMode.GrowAndShrink, Dock = DockStyle.Fill, Margin = new Padding(0, 0, Theme.S(12), Theme.S(8)), BackColor = Color.Transparent };
        cell.ColumnStyles.Add(new ColumnStyle(SizeType.Percent, 100));
        cell.Controls.Add(new UiLabel(label, Theme.Small, Theme.InkMuted) { Margin = new Padding(0, 0, 0, Theme.S(3)) }, 0, 0);
        control.Dock = DockStyle.Top;
        control.Margin = new Padding(0);
        if (control is UiText t && !t.Multiline) control.Height = Theme.ControlHeight;
        cell.Controls.Add(control, 0, 1);
        if (hint.Length > 0) cell.Controls.Add(new UiLabel(hint, Theme.Xs, Theme.InkMuted) { Margin = new Padding(0, Theme.S(2), 0, 0) }, 0, 2);
        while (RowCount <= _row) { RowCount++; RowStyles.Add(new RowStyle(SizeType.AutoSize)); }
        Controls.Add(cell, _col, _row);
        if (span > 1) SetColumnSpan(cell, span);
        _col += span;
        if (_col >= _cols) { _col = 0; _row++; }
        return control;
    }

    /// <summary>Adds a control spanning the full width without a label.</summary>
    public T AddFull<T>(T control) where T : Control
    {
        if (_col != 0) { _col = 0; _row++; }
        while (RowCount <= _row) { RowCount++; RowStyles.Add(new RowStyle(SizeType.AutoSize)); }
        control.Margin = new Padding(0, 0, Theme.S(12), Theme.S(8));
        Controls.Add(control, 0, _row);
        SetColumnSpan(control, _cols);
        _row++;
        return control;
    }

    public void Break() { if (_col != 0) { _col = 0; _row++; } }
}

/// <summary>Horizontal row of buttons (right or left aligned).</summary>
public sealed class ButtonRow : FlowLayoutPanel
{
    public ButtonRow(bool rightAligned = false)
    {
        FlowDirection = rightAligned ? FlowDirection.RightToLeft : FlowDirection.LeftToRight;
        AutoSize = true;
        AutoSizeMode = AutoSizeMode.GrowAndShrink;
        WrapContents = false;
        Dock = DockStyle.Top;
        BackColor = Color.Transparent;
        Padding = new Padding(0);
        Margin = new Padding(0);
    }

    public UiButton Add(string text, ButtonKind kind, Action onClick, string hint = "")
    {
        var b = new UiButton(text, kind, hint);
        b.Click += (_, _) => onClick();
        Controls.Add(b);
        return b;
    }

    public T AddControl<T>(T c) where T : Control
    {
        Controls.Add(c);
        return c;
    }
}
