using System.Drawing.Drawing2D;
using System.Drawing.Text;
using System.Runtime.InteropServices;

namespace MediCare.HostMonitor;

/// <summary>Colours mirror docs/02-design/tokens.css. Change them there first.</summary>
internal static class Theme
{
    public static readonly Color Canvas = ColorTranslator.FromHtml("#F5F8F8");
    public static readonly Color Surface = ColorTranslator.FromHtml("#FFFFFF");
    public static readonly Color SurfaceAlt = ColorTranslator.FromHtml("#EDF2F2");
    public static readonly Color Ink = ColorTranslator.FromHtml("#10262B");
    public static readonly Color InkMuted = ColorTranslator.FromHtml("#4E6469");
    public static readonly Color Line = ColorTranslator.FromHtml("#D5E0E1");
    public static readonly Color LineStrong = ColorTranslator.FromHtml("#A9BBBE");
    public static readonly Color Accent = ColorTranslator.FromHtml("#0B6E74");
    public static readonly Color AccentHover = ColorTranslator.FromHtml("#095B60");
    public static readonly Color Ok = ColorTranslator.FromHtml("#1F7A3A");
    public static readonly Color OkBg = ColorTranslator.FromHtml("#E7F4EA");
    public static readonly Color Warn = ColorTranslator.FromHtml("#8A5A00");
    public static readonly Color WarnBg = ColorTranslator.FromHtml("#FFF3D6");
    public static readonly Color Danger = ColorTranslator.FromHtml("#B42318");
    public static readonly Color DangerBg = ColorTranslator.FromHtml("#FDECEA");

    private const string UiFamily = "Segoe UI";
    private static readonly string MonoFamily = IsInstalled("IBM Plex Mono") ? "IBM Plex Mono" : "Consolas";

    public static readonly Font Body = new(UiFamily, 10f);
    public static readonly Font BodyBold = new(UiFamily, 10f, FontStyle.Bold);
    public static readonly Font Small = new(UiFamily, 9f);
    public static readonly Font Caption = new(UiFamily, 8.25f, FontStyle.Bold);
    public static readonly Font Title = new(UiFamily, 13.5f, FontStyle.Bold);
    public static readonly Font State = new(UiFamily, 16f, FontStyle.Bold);
    public static readonly Font Fingerprint = new(MonoFamily, 15f, FontStyle.Bold);
    public static readonly Font Mono = new(MonoFamily, 10f);

    private static bool IsInstalled(string family)
    {
        using var fonts = new InstalledFontCollection();
        return fonts.Families.Any(f => string.Equals(f.Name, family, StringComparison.OrdinalIgnoreCase));
    }

    public enum ButtonKind { Accent, Secondary, Danger }

    public static Button MakeButton(string text, ButtonKind kind = ButtonKind.Secondary)
    {
        var b = new Button
        {
            Text = text,
            AutoSize = true,
            AutoSizeMode = AutoSizeMode.GrowAndShrink,
            MinimumSize = new Size(0, 36),
            Padding = new Padding(12, 2, 12, 2),
            Margin = new Padding(0, 0, 8, 0),
            FlatStyle = FlatStyle.Flat,
            Font = BodyBold,
            Cursor = Cursors.Hand,
            UseVisualStyleBackColor = false,
        };
        b.FlatAppearance.BorderSize = 1;
        switch (kind)
        {
            case ButtonKind.Accent:
                b.BackColor = Accent;
                b.ForeColor = Color.White;
                b.FlatAppearance.BorderColor = Accent;
                b.FlatAppearance.MouseOverBackColor = AccentHover;
                b.FlatAppearance.MouseDownBackColor = AccentHover;
                break;
            case ButtonKind.Danger:
                b.BackColor = Surface;
                b.ForeColor = Danger;
                b.FlatAppearance.BorderColor = Danger;
                b.FlatAppearance.MouseOverBackColor = DangerBg;
                b.FlatAppearance.MouseDownBackColor = DangerBg;
                break;
            default:
                b.BackColor = Surface;
                b.ForeColor = Ink;
                b.FlatAppearance.BorderColor = LineStrong;
                b.FlatAppearance.MouseOverBackColor = SurfaceAlt;
                b.FlatAppearance.MouseDownBackColor = SurfaceAlt;
                break;
        }
        return b;
    }

    public static Label MakeLabel(string text, Font? font = null, Color? color = null) => new()
    {
        Text = text,
        AutoSize = true,
        Font = font ?? Body,
        ForeColor = color ?? Ink,
        Margin = new Padding(0, 0, 0, 4),
    };

    /// <summary>Tray and window icon: a filled status dot with a white rim, drawn at the requested size.</summary>
    public static Icon MakeDotIcon(Color fill, int size)
    {
        using var bmp = new Bitmap(size, size);
        using (var g = Graphics.FromImage(bmp))
        {
            g.SmoothingMode = SmoothingMode.AntiAlias;
            g.Clear(Color.Transparent);
            var inset = Math.Max(1f, size / 16f);
            var rect = new RectangleF(inset, inset, size - 2 * inset - 1, size - 2 * inset - 1);
            using var brush = new SolidBrush(fill);
            using var pen = new Pen(Color.White, Math.Max(1.5f, size / 12f));
            g.FillEllipse(brush, rect);
            g.DrawEllipse(pen, rect);
        }
        var handle = bmp.GetHicon();
        try
        {
            using var temp = Icon.FromHandle(handle);
            return (Icon)temp.Clone();
        }
        finally
        {
            DestroyIcon(handle);
        }
    }

    [DllImport("user32.dll", SetLastError = true)]
    [return: MarshalAs(UnmanagedType.Bool)]
    private static extern bool DestroyIcon(IntPtr hIcon);
}

/// <summary>White panel with a 1 px token border (design system: panels, radius kept square for GDI simplicity).</summary>
internal sealed class Card : Panel
{
    public Card()
    {
        BackColor = Theme.Surface;
        Padding = new Padding(16);
        Margin = new Padding(0, 0, 0, 12);
        Dock = DockStyle.Fill;
        DoubleBuffered = true;
        ResizeRedraw = true;
    }

    protected override void OnPaint(PaintEventArgs e)
    {
        base.OnPaint(e);
        using var pen = new Pen(Theme.Line);
        e.Graphics.DrawRectangle(pen, 0, 0, Width - 1, Height - 1);
    }
}
