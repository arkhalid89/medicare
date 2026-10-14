using System.Drawing.Drawing2D;

namespace MediCare.Client.Ui;

/// <summary>Number shortening for chart axes: 950, 12.5k, 1.2M.</summary>
public static class Short
{
    public static string Num(decimal v)
    {
        var a = Math.Abs(v);
        if (a >= 1_000_000m) return (v / 1_000_000m).ToString("0.#", CultureInfo.InvariantCulture) + "M";
        if (a >= 1_000m) return (v / 1_000m).ToString("0.#", CultureInfo.InvariantCulture) + "k";
        return v.ToString("0", CultureInfo.InvariantCulture);
    }

    /// <summary>A round axis maximum at or above <paramref name="max"/> (1, 2, 2.5, 5 × 10^n).</summary>
    public static decimal NiceCeiling(decimal max)
    {
        if (max <= 0) return 1;
        var exp = (decimal)Math.Pow(10, Math.Floor(Math.Log10((double)max)));
        var f = max / exp;
        var nice = f <= 1 ? 1 : f <= 2 ? 2 : f <= 2.5m ? 2.5m : f <= 5 ? 5 : 10;
        return nice * exp;
    }
}

/// <summary>
/// Area chart with a gradient fill (GDI+, no third-party charting). Grows in when the data changes;
/// hovering shows a crosshair and the exact figure for that day.
/// </summary>
public sealed class TrendChart : Control
{
    private List<KeyValuePair<string, decimal>> _data = new List<KeyValuePair<string, decimal>>();
    private float _reveal = 1f;
    private int _hover = -1;
    private PointF[] _points = new PointF[0];

    public Func<decimal, string> Format { get; set; } = v => Money.FormatRs(v);
    public string EmptyText { get; set; } = "No sales recorded yet.";

    public TrendChart()
    {
        SetStyle(ControlStyles.UserPaint | ControlStyles.AllPaintingInWmPaint | ControlStyles.OptimizedDoubleBuffer | ControlStyles.ResizeRedraw, true);
        BackColor = Theme.Surface;
        Cursor = Cursors.Cross;
    }

    public void SetData(IEnumerable<KeyValuePair<string, decimal>> data)
    {
        var list = data.ToList();
        var changed = list.Count != _data.Count || list.Where((d, i) => d.Key != _data[i].Key || d.Value != _data[i].Value).Any();
        _data = list;
        if (changed) Motion.Run(this, "reveal", 800, t => { _reveal = t; Invalidate(); });
        else Invalidate();
    }

    private Rectangle Plot => new Rectangle(Theme.S(52), Theme.S(14), Math.Max(10, Width - Theme.S(52) - Theme.S(14)), Math.Max(10, Height - Theme.S(14) - Theme.S(28)));

    protected override void OnPaint(PaintEventArgs e)
    {
        var g = e.Graphics;
        Theme.Smooth(g);
        g.Clear(BackColor);
        if (_data.Count == 0 || _data.All(d => d.Value == 0))
        {
            var size = Theme.S(28);
            Icons.Draw(g, "chart", new RectangleF((Width - size) / 2f, Height / 2f - size - Theme.S(4), size, size), Theme.LineStrong, 1.8f);
            TextRenderer.DrawText(g, EmptyText, Theme.Small, new Rectangle(0, Height / 2 + Theme.S(4), Width, Theme.S(22)), Theme.InkMuted, TextFormatFlags.NoPrefix | TextFormatFlags.HorizontalCenter);
            _points = new PointF[0];
            return;
        }
        var plot = Plot;
        var max = Short.NiceCeiling(_data.Max(d => d.Value) / 4) * 4;

        // grid and axis labels
        using (var grid = new Pen(Theme.Line) { DashStyle = DashStyle.Dash })
            for (int i = 0; i <= 4; i++)
            {
                var y = plot.Bottom - plot.Height * i / 4;
                if (i > 0) g.DrawLine(grid, plot.Left, y, plot.Right, y);
                TextRenderer.DrawText(g, Short.Num(max * i / 4), Theme.Xs, new Rectangle(0, y - Theme.S(9), plot.Left - Theme.S(8), Theme.S(18)), Theme.InkMuted, TextFormatFlags.NoPrefix | TextFormatFlags.Right | TextFormatFlags.VerticalCenter);
            }
        using (var axis = new Pen(Theme.LineStrong)) g.DrawLine(axis, plot.Left, plot.Bottom, plot.Right, plot.Bottom);

        var n = _data.Count;
        _points = new PointF[n];
        for (int i = 0; i < n; i++)
        {
            var x = n == 1 ? plot.Left + plot.Width / 2f : plot.Left + plot.Width * i / (float)(n - 1);
            var y = plot.Bottom - plot.Height * (float)(_data[i].Value / max) * _reveal;
            _points[i] = new PointF(x, y);
        }

        if (n >= 2)
        {
            using (var area = new GraphicsPath())
            {
                area.AddLines(_points);
                area.AddLine(_points[n - 1].X, _points[n - 1].Y, _points[n - 1].X, plot.Bottom);
                area.AddLine(_points[n - 1].X, plot.Bottom, _points[0].X, plot.Bottom);
                area.CloseFigure();
                using var fill = new LinearGradientBrush(new Rectangle(plot.Left, plot.Top - 1, 1, plot.Height + 2), Theme.Alpha(Theme.Accent, 110), Theme.Alpha(Theme.Accent, 4), LinearGradientMode.Vertical);
                g.FillPath(fill, area);
            }
            using var line = new Pen(Theme.Accent, Theme.S(2) + 0.5f) { LineJoin = LineJoin.Round, StartCap = LineCap.Round, EndCap = LineCap.Round };
            g.DrawLines(line, _points);
        }

        // x labels: as many as fit, always ending on today
        var step = Math.Max(1, (int)Math.Ceiling(n * Theme.S(52) / (double)plot.Width));
        for (int i = n - 1; i >= 0; i -= step)
        {
            var label = i == n - 1 ? "Today" : _data[i].Key;
            var r = new Rectangle(Math.Min((int)_points[i].X - Theme.S(30), Width - Theme.S(60)), plot.Bottom + Theme.S(6), Theme.S(60), Theme.S(18));
            var align = r.Right >= Width ? TextFormatFlags.Right : TextFormatFlags.HorizontalCenter;
            TextRenderer.DrawText(g, label, Theme.Xs, r, i == n - 1 ? Theme.Accent : Theme.InkMuted, TextFormatFlags.NoPrefix | align);
        }

        // points
        using (var surface = new SolidBrush(Theme.Surface))
        using (var ring = new Pen(Theme.Accent, Theme.S(2)))
            for (int i = 0; i < n; i++)
            {
                var rad = i == _hover ? Theme.S(5) : Theme.S(3);
                g.FillEllipse(surface, _points[i].X - rad, _points[i].Y - rad, rad * 2, rad * 2);
                g.DrawEllipse(ring, _points[i].X - rad, _points[i].Y - rad, rad * 2, rad * 2);
            }
        var last = _points[n - 1];
        using (var halo = new SolidBrush(Theme.Alpha(Theme.Accent, 45))) g.FillEllipse(halo, last.X - Theme.S(9), last.Y - Theme.S(9), Theme.S(18), Theme.S(18));
        using (var dot = new SolidBrush(Theme.Accent)) g.FillEllipse(dot, last.X - Theme.S(4), last.Y - Theme.S(4), Theme.S(8), Theme.S(8));

        if (_hover >= 0 && _hover < n) DrawTip(g, plot, _hover);
    }

    private void DrawTip(Graphics g, Rectangle plot, int i)
    {
        var p = _points[i];
        using (var cross = new Pen(Theme.Alpha(Theme.Accent, 120)) { DashStyle = DashStyle.Dot })
            g.DrawLine(cross, p.X, plot.Top, p.X, plot.Bottom);
        var title = i == _data.Count - 1 ? _data[i].Key + " (today)" : _data[i].Key;
        var value = Format(_data[i].Value);
        var ts = TextRenderer.MeasureText(title, Theme.Xs);
        var vs = TextRenderer.MeasureText(value, Theme.NumBold);
        var w = Math.Max(ts.Width, vs.Width) + Theme.S(20);
        var h = ts.Height + vs.Height + Theme.S(12);
        var x = Math.Max(0, Math.Min(Width - w - 1, (int)p.X - w / 2));
        var y = (int)p.Y - h - Theme.S(12);
        if (y < 0) y = (int)p.Y + Theme.S(12);
        var box = new Rectangle(x, y, w, h);
        using (var path = Theme.Rounded(box, Theme.S(6)))
        using (var b = new SolidBrush(Theme.Ink))
            g.FillPath(b, path);
        TextRenderer.DrawText(g, title, Theme.Xs, new Rectangle(box.X + Theme.S(10), box.Y + Theme.S(6), w, ts.Height), Theme.NavInk, TextFormatFlags.NoPrefix);
        TextRenderer.DrawText(g, value, Theme.NumBold, new Rectangle(box.X + Theme.S(10), box.Y + Theme.S(6) + ts.Height, w, vs.Height), Color.White, TextFormatFlags.NoPrefix);
    }

    protected override void OnMouseMove(MouseEventArgs e)
    {
        var hit = -1;
        if (_points.Length > 0)
        {
            var best = float.MaxValue;
            for (int i = 0; i < _points.Length; i++)
            {
                var d = Math.Abs(_points[i].X - e.X);
                if (d < best) { best = d; hit = i; }
            }
        }
        if (hit != _hover) { _hover = hit; Invalidate(); }
        base.OnMouseMove(e);
    }

    protected override void OnMouseLeave(EventArgs e) { _hover = -1; Invalidate(); base.OnMouseLeave(e); }
}

/// <summary>Donut chart with a legend. Segments sweep in; hovering a segment or legend row pulls it out and shows its share.</summary>
public sealed class DonutChart : Control
{
    private List<KeyValuePair<string, decimal>> _data = new List<KeyValuePair<string, decimal>>();
    private float _reveal = 1f, _pop;
    private int _hover = -1;
    private readonly List<Rectangle> _legend = new List<Rectangle>();
    private RectangleF _ring;

    public Func<decimal, string> Format { get; set; } = v => Money.FormatRs(v);
    public string CenterLabel { get; set; } = "Total";
    public string EmptyText { get; set; } = "No payments yet today.";

    public DonutChart()
    {
        SetStyle(ControlStyles.UserPaint | ControlStyles.AllPaintingInWmPaint | ControlStyles.OptimizedDoubleBuffer | ControlStyles.ResizeRedraw, true);
        BackColor = Theme.Surface;
    }

    public void SetData(IEnumerable<KeyValuePair<string, decimal>> data)
    {
        var list = data.Where(d => d.Value > 0).OrderByDescending(d => d.Value).ToList();
        var changed = list.Count != _data.Count || list.Where((d, i) => d.Key != _data[i].Key || d.Value != _data[i].Value).Any();
        _data = list;
        if (changed) Motion.Run(this, "reveal", 900, t => { _reveal = t; Invalidate(); }, ease: Motion.EaseInOut);
        else Invalidate();
    }

    private static string Pretty(string method) =>
        method.Length == 0 ? method : char.ToUpperInvariant(method[0]) + method.Substring(1).ToLowerInvariant().Replace('_', ' ');

    protected override void OnPaint(PaintEventArgs e)
    {
        var g = e.Graphics;
        Theme.Smooth(g);
        g.Clear(BackColor);
        _legend.Clear();
        var total = _data.Sum(d => d.Value);
        if (total <= 0)
        {
            var size = Theme.S(28);
            Icons.Draw(g, "cash", new RectangleF((Width - size) / 2f, Height / 2f - size - Theme.S(4), size, size), Theme.LineStrong, 1.8f);
            TextRenderer.DrawText(g, EmptyText, Theme.Small, new Rectangle(0, Height / 2 + Theme.S(4), Width, Theme.S(22)), Theme.InkMuted, TextFormatFlags.NoPrefix | TextFormatFlags.HorizontalCenter);
            return;
        }
        var withLegend = Width >= Theme.S(300);
        var d = Math.Min(Height - Theme.S(20), withLegend ? (int)(Width * 0.5) : Width - Theme.S(20));
        d = Math.Max(d, Theme.S(60));
        _ring = new RectangleF(withLegend ? Theme.S(12) : (Width - d) / 2f, (Height - d) / 2f, d, d);
        var thickness = d * 0.2f;

        float start = -90;
        for (int i = 0; i < _data.Count; i++)
        {
            var sweep = (float)(_data[i].Value / total) * 360f * _reveal;
            if (sweep <= 0.05f) { start += sweep; continue; }
            var grow = i == _hover ? Theme.S(5) * _pop : 0f;
            var outer = RectangleF.Inflate(_ring, grow, grow);
            var inner = RectangleF.Inflate(_ring, -thickness, -thickness);
            using (var path = new GraphicsPath())
            {
                path.AddArc(outer, start, sweep);
                path.AddArc(inner, start + sweep, -sweep);
                path.CloseFigure();
                using var b = new SolidBrush(Theme.Palette[i % Theme.Palette.Length]);
                g.FillPath(b, path);
                using var gap = new Pen(Theme.Surface, Theme.S(2));
                g.DrawPath(gap, path);
            }
            start += sweep;
        }

        // centre text
        var center = RectangleF.Inflate(_ring, -thickness - Theme.S(4), -thickness - Theme.S(4));
        string top, mid;
        if (_hover >= 0 && _hover < _data.Count)
        {
            top = Pretty(_data[_hover].Key);
            mid = (_data[_hover].Value / total * 100m).ToString("0", CultureInfo.InvariantCulture) + "%";
        }
        else { top = CenterLabel; mid = "Rs " + Short.Num(total); }
        var cr = Rectangle.Round(center);
        TextRenderer.DrawText(g, top.ToUpperInvariant(), Theme.Xs, new Rectangle(cr.X, cr.Y + cr.Height / 2 - Theme.S(22), cr.Width, Theme.S(18)), Theme.InkMuted, TextFormatFlags.NoPrefix | TextFormatFlags.HorizontalCenter | TextFormatFlags.EndEllipsis);
        TextRenderer.DrawText(g, mid, Theme.NumLg, new Rectangle(cr.X - Theme.S(10), cr.Y + cr.Height / 2 - Theme.S(4), cr.Width + Theme.S(20), Theme.S(26)), Theme.Ink, TextFormatFlags.NoPrefix | TextFormatFlags.HorizontalCenter);

        if (!withLegend) return;
        var lx = (int)_ring.Right + Theme.S(20);
        var rowH = Theme.S(42);
        var ly = Math.Max(Theme.S(4), (Height - rowH * _data.Count) / 2);
        for (int i = 0; i < _data.Count; i++)
        {
            var row = new Rectangle(lx - Theme.S(8), ly + i * rowH, Width - lx, rowH - Theme.S(4));
            if (row.Bottom > Height) break;
            if (i == _hover)
                using (var b = new SolidBrush(Theme.Select))
                using (var path = Theme.Rounded(row, Theme.S(6)))
                    g.FillPath(b, path);
            using (var b = new SolidBrush(Theme.Palette[i % Theme.Palette.Length]))
                g.FillEllipse(b, lx, row.Top + Theme.S(8), Theme.S(10), Theme.S(10));
            var pct = (_data[i].Value / total * 100m).ToString("0", CultureInfo.InvariantCulture) + "%";
            TextRenderer.DrawText(g, Pretty(_data[i].Key), Theme.UiBold, new Rectangle(lx + Theme.S(18), row.Top + Theme.S(2), row.Width - Theme.S(80), Theme.S(20)), Theme.Ink, TextFormatFlags.NoPrefix | TextFormatFlags.EndEllipsis);
            TextRenderer.DrawText(g, pct, Theme.NumBold, new Rectangle(row.Right - Theme.S(64), row.Top + Theme.S(2), Theme.S(56), Theme.S(20)), Theme.Ink, TextFormatFlags.NoPrefix | TextFormatFlags.Right);
            TextRenderer.DrawText(g, Format(_data[i].Value), Theme.NumSmall, new Rectangle(lx + Theme.S(18), row.Top + Theme.S(20), row.Width - Theme.S(24), Theme.S(16)), Theme.InkMuted, TextFormatFlags.NoPrefix | TextFormatFlags.EndEllipsis);
            _legend.Add(row);
        }
    }

    private int HitTest(Point p)
    {
        for (int i = 0; i < _legend.Count; i++) if (_legend[i].Contains(p)) return i;
        var total = _data.Sum(d => d.Value);
        if (total <= 0 || _ring.Width <= 0) return -1;
        var cx = _ring.X + _ring.Width / 2; var cy = _ring.Y + _ring.Height / 2;
        var dx = p.X - cx; var dy = p.Y - cy;
        var dist = Math.Sqrt(dx * dx + dy * dy);
        if (dist > _ring.Width / 2 + Theme.S(6) || dist < _ring.Width / 2 * 0.6) return -1;
        var angle = (Math.Atan2(dy, dx) * 180 / Math.PI + 90 + 360) % 360;
        double acc = 0;
        for (int i = 0; i < _data.Count; i++)
        {
            acc += (double)(_data[i].Value / total) * 360;
            if (angle <= acc) return i;
        }
        return -1;
    }

    protected override void OnMouseMove(MouseEventArgs e)
    {
        var hit = HitTest(e.Location);
        if (hit != _hover)
        {
            _hover = hit;
            Cursor = hit >= 0 ? Cursors.Hand : Cursors.Default;
            Motion.Run(this, "pop", 180, t => { _pop = t; Invalidate(); });
        }
        base.OnMouseMove(e);
    }

    protected override void OnMouseLeave(EventArgs e) { _hover = -1; Invalidate(); base.OnMouseLeave(e); }
}
