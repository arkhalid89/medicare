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
        _selected = key;
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
        using (var b = new SolidBrush(Theme.Accent))
        using (var path = Theme.Rounded(new Rectangle(pad, Theme.S(16), Theme.S(30), Theme.S(30)), Theme.S(6)))
            g.FillPath(b, path);
        using (var p = new Pen(Color.White, Theme.S(3)))
        {
            var cx = pad + Theme.S(15); var cy = Theme.S(31);
            g.DrawLine(p, cx - Theme.S(8), cy, cx + Theme.S(8), cy);
            g.DrawLine(p, cx, cy - Theme.S(8), cx, cy + Theme.S(8));
        }
        TextRenderer.DrawText(g, BrandTitle, Theme.UiBold, new Rectangle(pad + Theme.S(40), Theme.S(14), Width - pad * 2 - Theme.S(40), Theme.S(20)), Color.White, TextFormatFlags.NoPrefix | TextFormatFlags.Left | TextFormatFlags.EndEllipsis);
        TextRenderer.DrawText(g, BrandSub, Theme.Xs, new Rectangle(pad + Theme.S(40), Theme.S(34), Width - pad * 2 - Theme.S(40), Theme.S(18)), Theme.NavInk, TextFormatFlags.NoPrefix | TextFormatFlags.Left | TextFormatFlags.EndEllipsis);

        g.SetClip(new Rectangle(0, BrandHeight, Width, Height - BrandHeight));
        int y = BrandHeight + Theme.S(4) - _offset;
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
            var h = Theme.S(Theme.Compact ? 28 : 32);
            var r = new Rectangle(Theme.S(8), y, Width - Theme.S(16), h);
            var selected = item.Key == _selected;
            if (selected || item == _hover)
                using (var b = new SolidBrush(selected ? Theme.NavActive : Theme.Hex("#18323A")))
                using (var path = Theme.Rounded(r, Theme.S(6)))
                    g.FillPath(b, path);
            if (selected)
                using (var b = new SolidBrush(Theme.Hex("#4FB8C0")))
                    g.FillRectangle(b, r.Left, r.Top + Theme.S(6), Theme.S(3), r.Height - Theme.S(12));
            var textRect = new Rectangle(r.Left + Theme.S(12), r.Top, r.Width - Theme.S(20), r.Height);
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
            TextRenderer.DrawText(g, item.Title, selected ? Theme.UiBold : Theme.Ui, textRect, selected ? Color.White : Theme.NavInk, TextFormatFlags.NoPrefix | TextFormatFlags.Left | TextFormatFlags.VerticalCenter | TextFormatFlags.EndEllipsis);
            _hits.Add((r, item));
            y += h + Theme.S(2);
        }
        _contentHeight = y + _offset - BrandHeight + Theme.S(12);
        g.ResetClip();
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
            _hover = _items[i];
            Invalidate();
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
    protected override void OnLostFocus(EventArgs e) { _hover = null; Invalidate(); base.OnLostFocus(e); }

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
        if (hit != _hover) { _hover = hit; Invalidate(); }
        base.OnMouseMove(e);
    }

    protected override void OnMouseLeave(EventArgs e) { _hover = null; Invalidate(); base.OnMouseLeave(e); }

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
