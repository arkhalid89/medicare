namespace MediCare.Client.Shell;

public sealed class NavItem
{
    public string Key { get; }
    public string Group { get; }
    public string Title { get; }
    public Func<bool> Visible { get; }
    public Func<AppScreen> Create { get; }
    public int Badge { get; set; }

    public NavItem(string key, string group, string title, Func<bool> visible, Func<AppScreen> create)
    {
        Key = key; Group = group; Title = title; Visible = visible; Create = create;
    }
}

/// <summary>Left navigation (dark ink), grouped, permission filtered, with badges. Scrolls with the wheel.</summary>
public sealed class NavPanel : Control
{
    private List<NavItem> _items = new List<NavItem>();
    private readonly List<(Rectangle r, NavItem item)> _hits = new List<(Rectangle, NavItem)>();
    private string _selected = "";
    private NavItem? _hover;
    private int _offset, _contentHeight;
    private readonly Dictionary<string, float> _hoverT = new Dictionary<string, float>();
    // the selection pill slides from the old item to the new one (content coordinates)
    private float _pillFrom = float.NaN, _pillT = 1f, _pillY = float.NaN;

    public string BrandTitle { get; set; } = "MediCare";
    public string BrandSub { get; set; } = "";
    public event Action<NavItem>? Navigate;

    public NavPanel()
    {
        SetStyle(ControlStyles.UserPaint | ControlStyles.AllPaintingInWmPaint | ControlStyles.OptimizedDoubleBuffer | ControlStyles.ResizeRedraw | ControlStyles.Selectable, true);
        BackColor = Theme.Nav;
        Width = Theme.NavWidth;
        Dock = DockStyle.Left;
        Cursor = Cursors.Hand;
        TabStop = false;
    }

    public void SetItems(IEnumerable<NavItem> items)
    {
        _items = items.ToList();
        Invalidate();
    }

    public void Select(string key)
    {
        if (key == _selected) return;
        _selected = key;
        _pillFrom = _pillY;
        Motion.Run(this, "pill", 260, t => { _pillT = t; Invalidate(); });
    }

    private void SetHover(NavItem? item)
    {
        if (item == _hover) return;
        var old = _hover;
        _hover = item;
        void Fade(NavItem i, float to)
        {
            var from = _hoverT.TryGetValue(i.Key, out var v) ? v : 0f;
            Motion.Run(this, "h:" + i.Key, 150, t => { _hoverT[i.Key] = Motion.Lerp(from, to, t); Invalidate(); });
        }
        if (old != null) Fade(old, 0f);
        if (item != null) Fade(item, 1f);
        Invalidate();
    }

    private int BrandHeight => Theme.S(64);

    protected override void OnPaint(PaintEventArgs e)
    {
        var g = e.Graphics;
        Theme.Smooth(g);
        g.Clear(BackColor);
        _hits.Clear();
        var pad = Theme.S(14);
        // brand
        var logo = new Rectangle(pad, Theme.S(16), Theme.S(30), Theme.S(30));
        using (var b = new System.Drawing.Drawing2D.LinearGradientBrush(logo, Theme.NavGlow, Theme.Accent, System.Drawing.Drawing2D.LinearGradientMode.ForwardDiagonal))
        using (var path = Theme.Rounded(logo, Theme.S(8)))
            g.FillPath(b, path);
        using (var p = new Pen(Color.White, Theme.S(3)))
        {
            var cx = pad + Theme.S(15); var cy = Theme.S(31);
            g.DrawLine(p, cx - Theme.S(8), cy, cx + Theme.S(8), cy);
            g.DrawLine(p, cx, cy - Theme.S(8), cx, cy + Theme.S(8));
        }
        TextRenderer.DrawText(g, BrandTitle, Theme.UiBold, new Rectangle(pad + Theme.S(40), Theme.S(14), Width - pad * 2 - Theme.S(40), Theme.S(20)), Color.White, TextFormatFlags.NoPrefix | TextFormatFlags.Left | TextFormatFlags.EndEllipsis);
        TextRenderer.DrawText(g, BrandSub, Theme.Xs, new Rectangle(pad + Theme.S(40), Theme.S(34), Width - pad * 2 - Theme.S(40), Theme.S(18)), Theme.NavInk, TextFormatFlags.NoPrefix | TextFormatFlags.Left | TextFormatFlags.EndEllipsis);

        using (var sep = new Pen(Theme.NavActive)) g.DrawLine(sep, pad, BrandHeight - 1, Width - pad, BrandHeight - 1);
        g.SetClip(new Rectangle(0, BrandHeight, Width, Height - BrandHeight));
        int y = BrandHeight + Theme.S(4) - _offset;
        var h = Theme.S(Theme.Compact ? 30 : 34);
        DrawPill(g, y, h);
        string? group = null;
        foreach (var item in _items)
        {
            if (item.Group != group)
            {
                group = item.Group;
                if (group.Length > 0)
                {
                    y += Theme.S(8);
                    TextRenderer.DrawText(g, group.ToUpperInvariant(), Theme.Xs, new Rectangle(pad, y, Width - pad * 2, Theme.S(20)), Theme.Hex("#7F969A"), TextFormatFlags.NoPrefix | TextFormatFlags.Left | TextFormatFlags.VerticalCenter);
                    y += Theme.S(22);
                }
            }
            var r = new Rectangle(Theme.S(8), y, Width - Theme.S(16), h);
            var selected = item.Key == _selected;
            var hot = _hoverT.TryGetValue(item.Key, out var hv) ? hv : 0f;
            if (!selected && hot > 0.01f)
                using (var b = new SolidBrush(Theme.Blend(Theme.Nav, Theme.NavHover, hot)))
                using (var path = Theme.Rounded(r, Theme.S(8)))
                    g.FillPath(b, path);
            var iconSize = Theme.S(18);
            var iconColor = selected ? Theme.NavGlow : Theme.Blend(Theme.Hex("#8FA6AA"), Color.White, hot);
            Icons.Draw(g, Icons.ForScreen(item.Key), new RectangleF(r.Left + Theme.S(12), r.Top + (h - iconSize) / 2f, iconSize, iconSize), iconColor, 2f);
            var textRect = new Rectangle(r.Left + Theme.S(40) + (int)(hot * Theme.S(2)), r.Top, r.Width - Theme.S(48), r.Height);
            if (item.Badge > 0)
            {
                var text = item.Badge > 99 ? "99+" : item.Badge.ToString(CultureInfo.InvariantCulture);
                var size = TextRenderer.MeasureText(text, Theme.Xs);
                var br = new Rectangle(r.Right - size.Width - Theme.S(16), r.Top + (h - Theme.S(18)) / 2, size.Width + Theme.S(10), Theme.S(18));
                using (var b = new SolidBrush(Theme.Hex("#E8B04A")))
                using (var path = Theme.Rounded(br, Theme.S(9)))
                    g.FillPath(b, path);
                TextRenderer.DrawText(g, text, Theme.Xs, br, Theme.Ink, TextFormatFlags.NoPrefix | TextFormatFlags.HorizontalCenter | TextFormatFlags.VerticalCenter);
                textRect.Width = br.Left - textRect.Left - Theme.S(4);
            }
            TextRenderer.DrawText(g, item.Title, selected ? Theme.UiBold : Theme.Ui, textRect, selected ? Color.White : Theme.Blend(Theme.NavInk, Color.White, hot), TextFormatFlags.NoPrefix | TextFormatFlags.Left | TextFormatFlags.VerticalCenter | TextFormatFlags.EndEllipsis);
            _hits.Add((r, item));
            y += h + Theme.S(2);
        }
        _contentHeight = y + _offset - BrandHeight + Theme.S(12);
        g.ResetClip();
    }

    /// <summary>Sliding selection pill with a glowing edge, drawn under the items.</summary>
    private void DrawPill(Graphics g, int top, int h)
    {
        int y = top;
        string? group = null;
        foreach (var item in _items)
        {
            if (item.Group != group)
            {
                group = item.Group;
                if (group.Length > 0) y += Theme.S(30);
            }
            if (item.Key == _selected)
            {
                var target = (float)(y + _offset);
                if (float.IsNaN(_pillFrom)) _pillFrom = target;
                _pillY = Motion.Lerp(_pillFrom, target, _pillT);
                var pr = new Rectangle(Theme.S(8), (int)Math.Round(_pillY) - _offset, Width - Theme.S(16), h);
                using (var b = new SolidBrush(Theme.NavActive))
                using (var path = Theme.Rounded(pr, Theme.S(8)))
                    g.FillPath(b, path);
                using (var b = new SolidBrush(Theme.NavGlow))
                using (var path = Theme.Rounded(new Rectangle(pr.Left, pr.Top + Theme.S(7), Theme.S(4), pr.Height - Theme.S(14)), Theme.S(2)))
                    g.FillPath(b, path);
                return;
            }
            y += h + Theme.S(2);
        }
    }

    // ---- keyboard: Up/Down move, Enter opens ----
    protected override bool IsInputKey(Keys keyData) =>
        keyData == Keys.Up || keyData == Keys.Down || keyData == Keys.Enter || base.IsInputKey(keyData);

    protected override void OnKeyDown(KeyEventArgs e)
    {
        if (_items.Count > 0 && (e.KeyCode == Keys.Up || e.KeyCode == Keys.Down))
        {
            var current = _hover ?? _items.FirstOrDefault(i => i.Key == _selected) ?? _items[0];
            var i = Compat.Clamp(_items.IndexOf(current) + (e.KeyCode == Keys.Down ? 1 : -1), 0, _items.Count - 1);
            SetHover(_items[i]);
            e.Handled = true;
        }
        else if (e.KeyCode == Keys.Enter && _hover != null)
        {
            Navigate?.Invoke(_hover);
            e.Handled = true;
        }
        base.OnKeyDown(e);
    }

    protected override void OnGotFocus(EventArgs e) { Invalidate(); base.OnGotFocus(e); }
    protected override void OnLostFocus(EventArgs e) { SetHover(null); base.OnLostFocus(e); }

    protected override void OnMouseWheel(MouseEventArgs e)
    {
        var max = Math.Max(0, _contentHeight - (Height - BrandHeight));
        _offset = Compat.Clamp(_offset - e.Delta / 2, 0, max);
        Invalidate();
        base.OnMouseWheel(e);
    }

    protected override void OnMouseMove(MouseEventArgs e)
    {
        var hit = e.Y > BrandHeight ? _hits.FirstOrDefault(h => h.r.Contains(e.Location)).item : null;
        SetHover(hit);
        base.OnMouseMove(e);
    }

    protected override void OnMouseLeave(EventArgs e) { SetHover(null); base.OnMouseLeave(e); }

    protected override void OnMouseClick(MouseEventArgs e)
    {
        if (e.Y > BrandHeight)
        {
            var hit = _hits.FirstOrDefault(h => h.r.Contains(e.Location)).item;
            if (hit != null) Navigate?.Invoke(hit);
        }
        base.OnMouseClick(e);
    }
}
