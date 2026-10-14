using System.Drawing.Drawing2D;

namespace MediCare.Client.Ui;

/// <summary>
/// Notification that slides in at the top right of a window and leaves by itself.
/// Never takes the keyboard focus, so a cashier can keep scanning.
/// </summary>
public sealed class Toast : Control
{
    private static readonly List<Toast> Open = new List<Toast>();
    private readonly string _title, _text;
    private readonly Tone _tone;
    private readonly Action? _onClick;
    private readonly Timer _life = new Timer();
    private float _slide;
    private bool _closing;

    private Toast(string title, string text, Tone tone, Action? onClick, int ms)
    {
        SetStyle(ControlStyles.UserPaint | ControlStyles.AllPaintingInWmPaint | ControlStyles.OptimizedDoubleBuffer | ControlStyles.ResizeRedraw, true);
        SetStyle(ControlStyles.Selectable, false);
        TabStop = false;
        _title = title; _text = text; _tone = tone; _onClick = onClick;
        BackColor = Theme.Ink;
        Cursor = Cursors.Hand;
        Width = Theme.S(360);
        using (var g = CreateGraphics())
        {
            var textH = text.Length == 0 ? 0 : TextRenderer.MeasureText(g, text, Theme.Small, new Size(Width - Theme.S(80), 0), TextFormatFlags.WordBreak | TextFormatFlags.NoPrefix).Height;
            Height = Math.Max(Theme.S(60), Theme.S(36) + textH + Theme.S(10));
        }
        _life.Interval = ms;
        _life.Tick += (_, _) => Dismiss();
    }

    /// <summary>Shows a toast on the window that contains <paramref name="anchor"/>.</summary>
    public static void Show(Control anchor, string title, string text = "", Tone tone = Tone.Ok, Action? onClick = null, int ms = 4500)
    {
        var form = anchor as Form ?? anchor.FindForm();
        if (form == null || form.IsDisposed) return;
        if (Open.Count(t => t.Parent == form) >= 4) Open.First(t => t.Parent == form).Dismiss();
        var toast = new Toast(title, text, tone, onClick, ms) { Anchor = AnchorStyles.Top | AnchorStyles.Right };
        form.Controls.Add(toast);
        toast.BringToFront();
        Open.Add(toast);
        Restack(form);
        Motion.Run(toast, "slide", 320, t => { toast._slide = t; toast.Place(); }, ease: Motion.EaseOut);
        toast._life.Start();
    }

    private int _targetY;

    private void Place()
    {
        if (Parent == null) return;
        var finalX = Parent.ClientSize.Width - Width - Theme.S(20);
        Location = new Point(finalX + (int)((1f - _slide) * (Width + Theme.S(30))), _targetY);
    }

    private static void Restack(Control form)
    {
        var y = Theme.HeaderHeight + Theme.S(12);
        foreach (var t in Open.Where(t => t.Parent == form && !t._closing))
        {
            t._targetY = y;
            t.Place();
            y += t.Height + Theme.S(8);
        }
    }

    public void Dismiss()
    {
        if (_closing) return;
        _closing = true;
        _life.Stop();
        var form = Parent;
        Motion.Run(this, "slide", 220, t => { _slide = 1f - t; Place(); }, () =>
        {
            Open.Remove(this);
            Parent?.Controls.Remove(this);
            Dispose();
            if (form != null) Restack(form);
        });
    }

    private Color ToneColor => _tone switch
    {
        Tone.Ok => Theme.Hex("#5BD08A"),
        Tone.Warn => Theme.Hex("#F2B84B"),
        Tone.Danger => Theme.Hex("#F47067"),
        Tone.Info => Theme.Hex("#6CB6FF"),
        _ => Theme.NavGlow,
    };

    protected override void OnResize(EventArgs e)
    {
        base.OnResize(e);
        using var path = Theme.Rounded(new Rectangle(0, 0, Width, Height), Theme.S(10));
        Region = new Region(path);
    }

    protected override void OnPaint(PaintEventArgs e)
    {
        var g = e.Graphics;
        Theme.Smooth(g);
        g.Clear(BackColor);
        using (var b = new SolidBrush(ToneColor)) g.FillRectangle(b, 0, 0, Theme.S(4), Height);
        var chip = Theme.S(30);
        var chipRect = new Rectangle(Theme.S(16), Theme.S(14), chip, chip);
        using (var b = new SolidBrush(Theme.Alpha(ToneColor, 40))) g.FillEllipse(b, chipRect);
        var icon = _tone switch { Tone.Ok => "check", Tone.Danger => "alert", Tone.Warn => "alert", _ => "info" };
        Icons.Draw(g, icon, new RectangleF(chipRect.X + Theme.S(6), chipRect.Y + Theme.S(6), chip - Theme.S(12), chip - Theme.S(12)), ToneColor, 2.4f);
        var x = chipRect.Right + Theme.S(12);
        TextRenderer.DrawText(g, _title, Theme.UiBold, new Rectangle(x, Theme.S(12), Width - x - Theme.S(30), Theme.S(22)), Color.White, TextFormatFlags.NoPrefix | TextFormatFlags.EndEllipsis);
        if (_text.Length > 0)
            TextRenderer.DrawText(g, _text, Theme.Small, new Rectangle(x, Theme.S(34), Width - x - Theme.S(16), Height - Theme.S(38)), Theme.NavInk, TextFormatFlags.NoPrefix | TextFormatFlags.WordBreak);
        Icons.Draw(g, "x", new RectangleF(Width - Theme.S(24), Theme.S(12), Theme.S(12), Theme.S(12)), Theme.Hex("#7F969A"), 2.4f);
    }

    protected override void OnMouseEnter(EventArgs e) { _life.Stop(); base.OnMouseEnter(e); }
    protected override void OnMouseLeave(EventArgs e) { if (!_closing) _life.Start(); base.OnMouseLeave(e); }

    protected override void OnMouseClick(MouseEventArgs e)
    {
        base.OnMouseClick(e);
        var onClose = e.X > Width - Theme.S(34) && e.Y < Theme.S(34);
        Dismiss();
        if (!onClose) _onClick?.Invoke();
    }

    protected override void Dispose(bool disposing)
    {
        if (disposing) { _life.Dispose(); Open.Remove(this); }
        base.Dispose(disposing);
    }
}

/// <summary>Thin progress line under the header while the host is working on a request (only after 250 ms, so quick calls do not flicker).</summary>
public sealed class BusyBar : Control
{
    private readonly Timer _timer = new Timer { Interval = 30 };
    private int _since;
    private bool _busy;
    private float _phase;

    public BusyBar()
    {
        SetStyle(ControlStyles.UserPaint | ControlStyles.AllPaintingInWmPaint | ControlStyles.OptimizedDoubleBuffer, true);
        SetStyle(ControlStyles.Selectable, false);
        Height = Math.Max(2, Theme.S(3));
        BackColor = Theme.Canvas;
        _timer.Tick += (_, _) =>
        {
            _phase += 0.018f;
            if (_phase > 1f) _phase -= 1f;
            Invalidate();
        };
    }

    public bool Busy
    {
        get => _busy;
        set
        {
            if (value == _busy) return;
            _busy = value;
            if (value) { _since = Environment.TickCount; _phase = 0; _timer.Start(); }
            else { _timer.Stop(); Invalidate(); }
        }
    }

    protected override void OnPaint(PaintEventArgs e)
    {
        var g = e.Graphics;
        g.Clear(BackColor);
        if (!_busy || Environment.TickCount - _since < 250) return;
        if (!Motion.Enabled)
        {
            using var solid = new SolidBrush(Theme.Accent);
            g.FillRectangle(solid, 0, 0, Width, Height);
            return;
        }
        var seg = Math.Max(Theme.S(80), Width / 3);
        var x = (int)(-seg + (Width + seg) * Motion.EaseInOut(_phase));
        var r = new Rectangle(x, 0, seg, Height);
        using var b = new LinearGradientBrush(new Rectangle(r.X - 1, 0, r.Width + 2, Height), Theme.Alpha(Theme.Accent, 0), Theme.Accent, LinearGradientMode.Horizontal);
        var blend = new ColorBlend
        {
            Colors = new[] { Theme.Alpha(Theme.Accent, 0), Theme.Accent, Theme.NavGlow, Theme.Alpha(Theme.Accent, 0) },
            Positions = new[] { 0f, 0.4f, 0.7f, 1f },
        };
        b.InterpolationColors = blend;
        g.FillRectangle(b, r);
    }

    protected override void Dispose(bool disposing)
    {
        if (disposing) _timer.Dispose();
        base.Dispose(disposing);
    }
}

/// <summary>Gradient welcome strip on the dashboard: greeting, business day and quick actions.</summary>
public sealed class HeroBanner : Control
{
    private sealed class QuickAction
    {
        public string Text = "", Icon = "", Hint = "";
        public Action Run = null!;
        public Rectangle Rect;
    }

    private readonly List<QuickAction> _actions = new List<QuickAction>();
    private int _hover = -1;
    private float _hoverT;

    public string Heading { get; set; } = "";
    public string Sub { get; set; } = "";

    public HeroBanner()
    {
        SetStyle(ControlStyles.UserPaint | ControlStyles.AllPaintingInWmPaint | ControlStyles.OptimizedDoubleBuffer | ControlStyles.ResizeRedraw | ControlStyles.SupportsTransparentBackColor, true);
        BackColor = Color.Transparent;
        Height = Theme.S(108);
    }

    public void AddAction(string text, string icon, string hint, Action run) =>
        _actions.Add(new QuickAction { Text = text, Icon = icon, Hint = hint, Run = run });

    public void ClearActions() { _actions.Clear(); Invalidate(); }

    protected override void OnPaint(PaintEventArgs e)
    {
        var g = e.Graphics;
        Theme.Smooth(g);
        g.Clear(Theme.EffectiveBack(Parent));
        var body = new Rectangle(Theme.S(4), Theme.S(2), Width - Theme.S(9), Height - Theme.S(8));
        Theme.Shadow(g, body, Theme.S(12), 1.2f);
        using (var path = Theme.Rounded(body, Theme.S(12)))
        {
            using (var b = new LinearGradientBrush(body, Theme.Accent, Theme.AccentDeep, LinearGradientMode.ForwardDiagonal)) g.FillPath(b, path);
            var clip = g.Clip;
            g.SetClip(path);
            // decoration: soft rings and a pharmacy cross on the right
            using (var ring = new Pen(Color.FromArgb(22, Color.White), Theme.S(18)))
            {
                g.DrawEllipse(ring, body.Right - Theme.S(170), body.Top - Theme.S(90), Theme.S(240), Theme.S(240));
                g.DrawEllipse(ring, body.Right - Theme.S(420), body.Bottom - Theme.S(40), Theme.S(160), Theme.S(160));
            }
            using (var cross = new SolidBrush(Color.FromArgb(18, Color.White)))
            {
                var cx = body.Right - Theme.S(60); var cy = body.Top + Theme.S(54); var arm = Theme.S(14); var len = Theme.S(46);
                g.FillRectangle(cross, cx - arm, cy - len, arm * 2, len * 2);
                g.FillRectangle(cross, cx - len, cy - arm, len * 2, arm * 2);
            }
            g.Clip = clip;
        }

        // actions, right to left
        var x = body.Right - Theme.S(20);
        var pillH = Theme.S(40);
        var y = body.Top + (body.Height - pillH) / 2;
        foreach (var a in _actions) a.Rect = Rectangle.Empty;
        for (int i = _actions.Count - 1; i >= 0; i--)
        {
            var a = _actions[i];
            var textW = TextRenderer.MeasureText(a.Text, Theme.UiBold).Width;
            var hintW = a.Hint.Length > 0 ? TextRenderer.MeasureText(a.Hint, Theme.Keycap).Width + Theme.S(14) : 0;
            var w = Theme.S(16) + Theme.S(18) + Theme.S(8) + textW + hintW + Theme.S(14);
            if (x - w < body.Left + Theme.S(360)) break;
            a.Rect = new Rectangle(x - w, y, w, pillH);
            x -= w + Theme.S(10);
        }
        var textRight = x;
        for (int i = 0; i < _actions.Count; i++)
        {
            var a = _actions[i];
            if (a.Rect.IsEmpty) continue;
            var hot = i == _hover ? _hoverT : 0f;
            using (var path = Theme.Rounded(a.Rect, pillH / 2))
            {
                using (var b = new SolidBrush(Color.FromArgb((int)(34 + 196 * hot), Color.White))) g.FillPath(b, path);
                using (var p = new Pen(Color.FromArgb(70, Color.White))) g.DrawPath(p, path);
            }
            var fore = Theme.Blend(Color.White, Theme.AccentDeep, hot);
            var ix = a.Rect.Left + Theme.S(16);
            Icons.Draw(g, a.Icon, new RectangleF(ix, a.Rect.Top + (pillH - Theme.S(18)) / 2f, Theme.S(18), Theme.S(18)), fore, 2.2f);
            var tx = ix + Theme.S(26);
            var textW = TextRenderer.MeasureText(a.Text, Theme.UiBold).Width;
            TextRenderer.DrawText(g, a.Text, Theme.UiBold, new Rectangle(tx, a.Rect.Top, textW + Theme.S(4), pillH), fore, TextFormatFlags.NoPrefix | TextFormatFlags.VerticalCenter | TextFormatFlags.Left);
            if (a.Hint.Length > 0)
            {
                var hs = TextRenderer.MeasureText(a.Hint, Theme.Keycap);
                var kr = new Rectangle(tx + textW + Theme.S(8), a.Rect.Top + (pillH - hs.Height - Theme.S(4)) / 2, hs.Width + Theme.S(6), hs.Height + Theme.S(4));
                using (var path = Theme.Rounded(kr, Theme.S(3)))
                using (var b = new SolidBrush(Color.FromArgb(50, hot > 0.5f ? Theme.AccentDeep : Color.White)))
                    g.FillPath(b, path);
                TextRenderer.DrawText(g, a.Hint, Theme.Keycap, kr, fore, TextFormatFlags.NoPrefix | TextFormatFlags.HorizontalCenter | TextFormatFlags.VerticalCenter);
            }
        }

        var left = body.Left + Theme.S(26);
        TextRenderer.DrawText(g, Heading, Theme.Title, new Rectangle(left, body.Top + Theme.S(22), textRight - left, Theme.S(30)), Color.White, TextFormatFlags.NoPrefix | TextFormatFlags.EndEllipsis);
        TextRenderer.DrawText(g, Sub, Theme.Ui, new Rectangle(left, body.Top + Theme.S(56), textRight - left, Theme.S(22)), Theme.Hex("#D8EEEF"), TextFormatFlags.NoPrefix | TextFormatFlags.EndEllipsis);
    }

    protected override void OnMouseMove(MouseEventArgs e)
    {
        var hit = _actions.FindIndex(a => a.Rect.Contains(e.Location));
        if (hit != _hover)
        {
            _hover = hit;
            Cursor = hit >= 0 ? Cursors.Hand : Cursors.Default;
            Motion.Run(this, "hover", 160, t => { _hoverT = t; Invalidate(); });
        }
        base.OnMouseMove(e);
    }

    protected override void OnMouseLeave(EventArgs e) { _hover = -1; Invalidate(); base.OnMouseLeave(e); }

    protected override void OnMouseClick(MouseEventArgs e)
    {
        base.OnMouseClick(e);
        var hit = _actions.FirstOrDefault(a => a.Rect.Contains(e.Location));
        hit?.Run();
    }
}

/// <summary>
/// Shown in the empty cart after a sale is saved: animated tick, invoice number, net amount and, in large
/// type, the change to give. Not focusable, so the next scan goes straight to the scan box.
/// </summary>
public sealed class SaleDoneView : Control
{
    private string _no = "", _paidBy = "";
    private decimal _net, _change, _shownChange;
    private bool _printed = true;
    private float _t = 1f;

    public SaleDoneView()
    {
        SetStyle(ControlStyles.UserPaint | ControlStyles.AllPaintingInWmPaint | ControlStyles.OptimizedDoubleBuffer | ControlStyles.ResizeRedraw, true);
        SetStyle(ControlStyles.Selectable, false);
        TabStop = false;
        BackColor = Theme.Surface;
        Visible = false;
        Cursor = Cursors.Hand;
    }

    public void ShowSale(string no, decimal net, decimal change, string paidBy, bool printed)
    {
        _no = no; _net = net; _change = change; _paidBy = paidBy; _printed = printed;
        Visible = true;
        BringToFront();
        Motion.Run(this, "tick", 900, t => { _t = t; _shownChange = change * (decimal)Math.Min(1f, t * 1.4f); Invalidate(); }, () => { _shownChange = change; Invalidate(); }, Motion.Linear);
    }

    protected override void OnClick(EventArgs e) { Visible = false; base.OnClick(e); }

    protected override void OnPaint(PaintEventArgs e)
    {
        var g = e.Graphics;
        Theme.Smooth(g);
        g.Clear(BackColor);
        var cx = Width / 2;
        var d = Theme.S(76);
        var top = Math.Max(Theme.S(12), Height / 2 - Theme.S(_change > 0 ? 170 : 120));
        var circle = new Rectangle(cx - d / 2, top, d, d);

        // ring draws, fills, then the tick strokes in
        var ringT = Math.Min(1f, _t / 0.45f);
        var pop = _t < 0.45f ? 1f : 1f + 0.08f * (float)Math.Sin(Math.Min(1f, (_t - 0.45f) / 0.3f) * Math.PI);
        var c = RectangleF.Inflate(circle, d * (pop - 1f) / 2, d * (pop - 1f) / 2);
        using (var fill = new SolidBrush(Theme.Blend(Theme.Surface, Theme.OkBg, ringT))) g.FillEllipse(fill, c);
        using (var ring = new Pen(Theme.Ok, Theme.S(4)) { StartCap = LineCap.Round, EndCap = LineCap.Round })
        {
            if (ringT > 0) g.DrawArc(ring, c, -90, 360 * ringT);
            var tickT = Math.Max(0f, Math.Min(1f, (_t - 0.4f) / 0.35f));
            if (tickT > 0)
            {
                var p1 = new PointF(c.Left + c.Width * 0.28f, c.Top + c.Height * 0.52f);
                var p2 = new PointF(c.Left + c.Width * 0.44f, c.Top + c.Height * 0.67f);
                var p3 = new PointF(c.Left + c.Width * 0.73f, c.Top + c.Height * 0.36f);
                var first = Math.Min(1f, tickT / 0.4f);
                g.DrawLine(ring, p1, new PointF(p1.X + (p2.X - p1.X) * first, p1.Y + (p2.Y - p1.Y) * first));
                if (tickT > 0.4f)
                {
                    var second = (tickT - 0.4f) / 0.6f;
                    g.DrawLine(ring, p2, new PointF(p2.X + (p3.X - p2.X) * second, p2.Y + (p3.Y - p2.Y) * second));
                }
            }
        }

        var textT = Math.Max(0f, Math.Min(1f, (_t - 0.25f) / 0.4f));
        var y = circle.Bottom + Theme.S(14) + (int)((1f - textT) * Theme.S(8));
        Color Fade(Color col) => Theme.Blend(Theme.Surface, col, textT);
        void Center(string text, Font font, Color color, int height)
        {
            TextRenderer.DrawText(g, text, font, new Rectangle(0, y, Width, height), Fade(color), TextFormatFlags.NoPrefix | TextFormatFlags.HorizontalCenter | TextFormatFlags.EndEllipsis);
            y += height;
        }
        Center("Sale saved", Theme.Title, Theme.Ink, Theme.S(30));
        Center($"{_no}  ·  {_paidBy}", Theme.Small, Theme.InkMuted, Theme.S(22));
        Center("Rs " + Money.Format(_net), Theme.NumLg, Theme.Accent, Theme.S(30));
        if (_change > 0)
        {
            y += Theme.S(10);
            var w = Math.Min(Width - Theme.S(40), Theme.S(380));
            var box = new Rectangle(cx - w / 2, y, w, Theme.S(96));
            using (var path = Theme.Rounded(box, Theme.S(12)))
            {
                using (var b = new SolidBrush(Fade(Theme.WarnBg))) g.FillPath(b, path);
                using (var p = new Pen(Fade(Theme.Gold), Theme.S(2))) g.DrawPath(p, path);
            }
            TextRenderer.DrawText(g, "GIVE CHANGE", Theme.UiBold, new Rectangle(box.X, box.Y + Theme.S(10), box.Width, Theme.S(22)), Fade(Theme.Warn), TextFormatFlags.NoPrefix | TextFormatFlags.HorizontalCenter);
            TextRenderer.DrawText(g, "Rs " + Money.Format(_shownChange), Theme.Total, new Rectangle(box.X, box.Y + Theme.S(30), box.Width, Theme.S(60)), Fade(Theme.Warn), TextFormatFlags.NoPrefix | TextFormatFlags.HorizontalCenter | TextFormatFlags.VerticalCenter);
            y = box.Bottom + Theme.S(10);
        }
        if (!_printed) Center("The receipt did not print. Check the printer, then press F8 to reprint.", Theme.UiBold, Theme.Danger, Theme.S(24));
        y = Math.Max(y + Theme.S(8), Height - Theme.S(40));
        Center("Scan the next medicine to start a new sale", Theme.Small, Theme.InkMuted, Theme.S(22));
    }
}
