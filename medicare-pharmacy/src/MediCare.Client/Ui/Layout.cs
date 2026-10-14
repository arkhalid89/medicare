namespace MediCare.Client.Ui;

public static class Box
{
    /// <summary>
    /// Adds children so they appear in the given order. WinForms docks from the last control to the first,
    /// so each child is brought to the front as it is added. Put a Dock=Fill control last.
    /// </summary>
    public static void Stack(this Control parent, params Control[] children)
    {
        parent.SuspendLayout();
        foreach (var c in children)
        {
            // an undocked child would sit at (0,0) on top of the others
            if (c.Dock == DockStyle.None) c.Dock = DockStyle.Top;
            parent.Controls.Add(c);
            c.BringToFront();
        }
        parent.ResumeLayout(true);
    }

    public static T Docked<T>(this T c, DockStyle dock) where T : Control
    {
        c.Dock = dock;
        return c;
    }

    public static T Sized<T>(this T c, int width = -1, int height = -1) where T : Control
    {
        if (width >= 0) c.Width = Theme.S(width);
        if (height >= 0) c.Height = Theme.S(height);
        return c;
    }

    /// <summary>A fixed-height spacer for stacks.</summary>
    public static Panel Gap(int px = 12) => new Panel { Dock = DockStyle.Top, Height = Theme.S(px), BackColor = Color.Transparent };

    /// <summary>A strip with a left part and right-aligned part (toolbars).</summary>
    public static Panel Toolbar(Control[] left, Control[]? right = null, int height = 48)
    {
        var bar = new Panel { Dock = DockStyle.Top, Height = Theme.S(height), BackColor = Color.Transparent, Padding = new Padding(0, Theme.S(4), 0, Theme.S(4)) };
        var l = new FlowLayoutPanel { Dock = DockStyle.Fill, WrapContents = false, BackColor = Color.Transparent, Padding = new Padding(0), Margin = new Padding(0) };
        foreach (var c in left) { c.Margin = new Padding(0, 0, Theme.S(8), 0); l.Controls.Add(c); }
        if (right != null && right.Length > 0)
        {
            var r = new FlowLayoutPanel { Dock = DockStyle.Right, WrapContents = false, AutoSize = true, AutoSizeMode = AutoSizeMode.GrowAndShrink, FlowDirection = FlowDirection.RightToLeft, BackColor = Color.Transparent, Padding = new Padding(0), Margin = new Padding(0) };
            foreach (var c in Enumerable.Reverse(right)) { c.Margin = new Padding(Theme.S(8), 0, 0, 0); r.Controls.Add(c); }
            bar.Controls.Add(l);
            bar.Controls.Add(r);
        }
        else bar.Controls.Add(l);
        foreach (Control c in l.Controls) if (c is TextBox || c is ComboBox || c is DateTimePicker) c.Margin = new Padding(0, Theme.S(4), Theme.S(8), 0);
        return bar;
    }

    public static Label Caption(string text) => new UiLabel(text, Theme.Small, Theme.InkMuted) { Margin = new Padding(0, Theme.S(10), Theme.S(4), 0) };
}
