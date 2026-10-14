using System.Drawing.Drawing2D;
using System.Drawing.Text;
using System.Runtime.InteropServices;

namespace MediCare.Client.Ui;

public enum Tone { None, Ok, Warn, Danger, Info, Accent, Muted }

/// <summary>
/// Design tokens from docs/02-design/tokens.css (direction A "Clear Dispensary" + C keycaps).
/// Forms never use raw colours or sizes; they ask the theme.
/// </summary>
public static class Theme
{
    // colour tokens
    public static readonly Color Canvas = Hex("#F5F8F8");
    public static readonly Color Surface = Hex("#FFFFFF");
    public static readonly Color SurfaceAlt = Hex("#EDF2F2");
    public static readonly Color Ink = Hex("#10262B");
    public static readonly Color InkMuted = Hex("#4E6469");
    public static readonly Color Line = Hex("#D5E0E1");
    public static readonly Color LineStrong = Hex("#A9BBBE");
    public static readonly Color Select = Hex("#E3F1F1");
    public static readonly Color Accent = Hex("#0B6E74");
    public static readonly Color AccentHover = Hex("#095B60");
    public static readonly Color OnAccent = Color.White;
    public static readonly Color Primary = Hex("#10262B");
    public static readonly Color PrimaryHover = Hex("#203F46");
    public static readonly Color Ok = Hex("#1F7A3A"), OkBg = Hex("#E7F4EA");
    public static readonly Color Warn = Hex("#8A5A00"), WarnBg = Hex("#FFF3D6");
    public static readonly Color Danger = Hex("#B42318"), DangerBg = Hex("#FDECEA");
    public static readonly Color Info = Hex("#0B5C8A"), InfoBg = Hex("#E2F0F8");
    public static readonly Color Nav = Hex("#10262B");
    public static readonly Color NavInk = Hex("#C9D6D8");
    public static readonly Color NavActive = Hex("#1D3A40");
    public static readonly Color NavHover = Hex("#18323A");
    public static readonly Color NavGlow = Hex("#4FB8C0");
    public static readonly Color AccentSoft = Hex("#CFE8E9");
    public static readonly Color AccentDeep = Hex("#07474C");
    public static readonly Color Gold = Hex("#E8A33D");

    /// <summary>Chart series colours, in order.</summary>
    public static readonly Color[] Palette = { Accent, Gold, NavGlow, Hex("#6D5BD0"), Ok, Hex("#C2410C"), InkMuted };

    public static bool Compact { get; set; }

    // type: tokens are px at 96 dpi; 1px = 0.75pt
    public static string UiFamily { get; private set; } = "Segoe UI";
    public static string NumFamily { get; private set; } = "Consolas";
    public static string UrduFamily { get; private set; } = "Arial";
    public static bool PreferredFontsMissing { get; private set; }

    public static Font Xs = null!, Small = null!, Ui = null!, UiBold = null!, Lg = null!, LgBold = null!, Title = null!, Kpi = null!, Total = null!;
    public static Font Num = null!, NumBold = null!, NumLg = null!, NumSmall = null!, Keycap = null!;

    public static float DpiScale { get; private set; } = 1f;

    public static void Init(bool compact)
    {
        Compact = compact;
        using (var g = Graphics.FromHwnd(IntPtr.Zero)) DpiScale = g.DpiX / 96f;
        var installed = new InstalledFontCollection().Families.Select(f => f.Name).ToList();
        string Pick(string[] names, out bool first)
        {
            first = installed.Contains(names[0], StringComparer.OrdinalIgnoreCase);
            return names.FirstOrDefault(n => installed.Contains(n, StringComparer.OrdinalIgnoreCase)) ?? "Microsoft Sans Serif";
        }
        UiFamily = Pick(new[] { "Atkinson Hyperlegible", "Segoe UI", "Tahoma" }, out var uiOk);
        NumFamily = Pick(new[] { "IBM Plex Mono", "Consolas", "Courier New" }, out var numOk);
        UrduFamily = Pick(new[] { "Noto Naskh Arabic", "Noto Nastaliq Urdu", "Segoe UI", "Arial", "Tahoma" }, out var urduOk);
        PreferredFontsMissing = !(uiOk && numOk && urduOk);

        Xs = F(UiFamily, 11);
        Small = F(UiFamily, 12);
        Ui = F(UiFamily, compact ? 13 : 14);
        UiBold = F(UiFamily, compact ? 13 : 14, FontStyle.Bold);
        Lg = F(UiFamily, compact ? 14 : 15);
        LgBold = F(UiFamily, compact ? 14 : 15, FontStyle.Bold);
        Title = F(UiFamily, 18, FontStyle.Bold);
        Kpi = F(UiFamily, 24, FontStyle.Bold);
        Total = F(NumFamily, 40, FontStyle.Bold);
        Num = F(NumFamily, compact ? 13 : 14);
        NumBold = F(NumFamily, compact ? 13 : 14, FontStyle.Bold);
        NumLg = F(NumFamily, 18, FontStyle.Bold);
        NumSmall = F(NumFamily, 12);
        Keycap = F(NumFamily, 12, FontStyle.Bold);
    }

    private static Font F(string family, float px, FontStyle style = FontStyle.Regular) => new Font(family, px * 0.75f, style, GraphicsUnit.Point);

    public static Font Urdu(float px) => new Font(UrduFamily, px * 0.75f, FontStyle.Regular, GraphicsUnit.Point);

    // spacing and sizes
    public static int S(int px) => (int)Math.Round(px * DpiScale);
    public static int RowHeight => S(Compact ? 28 : 34);
    public static int ControlHeight => S(Compact ? 30 : 34);
    public static int ControlHeightLg => S(44);
    public static int NavWidth => S(200);
    public static int HeaderHeight => S(56);
    public static int StatusHeight => S(28);
    public static int FKeyHeight => S(46);

    public static Color Hex(string hex) => ColorTranslator.FromHtml(hex);

    /// <summary>Mix two colours; t = 0 gives a, t = 1 gives b.</summary>
    public static Color Blend(Color a, Color b, float t)
    {
        t = t < 0 ? 0 : t > 1 ? 1 : t;
        int Mix(int x, int y) => (int)Math.Round(x + (y - x) * t);
        return Color.FromArgb(Mix(a.A, b.A), Mix(a.R, b.R), Mix(a.G, b.G), Mix(a.B, b.B));
    }

    public static Color Alpha(Color c, int alpha) => Color.FromArgb(Math.Max(0, Math.Min(255, alpha)), c.R, c.G, c.B);

    /// <summary>Soft drop shadow under a rounded rectangle (a few translucent layers, no blur needed).</summary>
    public static void Shadow(Graphics g, Rectangle r, int radius, float strength = 1f)
    {
        for (int i = 3; i >= 1; i--)
        {
            var layer = new Rectangle(r.X - i + 1, r.Y + i, r.Width + (i - 1) * 2, r.Height + i - 1);
            using var path = Rounded(layer, radius + i);
            using var b = new SolidBrush(Color.FromArgb((int)(10 * strength * (4 - i)), 16, 38, 43));
            g.FillPath(b, path);
        }
    }

    public static Color Fore(Tone t) => t switch
    {
        Tone.Ok => Ok,
        Tone.Warn => Warn,
        Tone.Danger => Danger,
        Tone.Info => Info,
        Tone.Accent => Accent,
        Tone.Muted => InkMuted,
        _ => Ink,
    };

    public static Color Back(Tone t) => t switch
    {
        Tone.Ok => OkBg,
        Tone.Warn => WarnBg,
        Tone.Danger => DangerBg,
        Tone.Info => InfoBg,
        Tone.Accent => Select,
        Tone.Muted => SurfaceAlt,
        _ => Surface,
    };

    /// <summary>The colour actually behind a control: the first ancestor whose background is not transparent.</summary>
    public static Color EffectiveBack(Control? c)
    {
        for (; c != null; c = c.Parent)
            if (c.BackColor.A == 255) return c.BackColor;
        return Canvas;
    }

    public static GraphicsPath Rounded(Rectangle r, int radius)
    {
        var p = new GraphicsPath();
        var d = radius * 2;
        if (d <= 0) { p.AddRectangle(r); return p; }
        p.AddArc(r.X, r.Y, d, d, 180, 90);
        p.AddArc(r.Right - d, r.Y, d, d, 270, 90);
        p.AddArc(r.Right - d, r.Bottom - d, d, d, 0, 90);
        p.AddArc(r.X, r.Bottom - d, d, d, 90, 90);
        p.CloseFigure();
        return p;
    }

    public static void Smooth(Graphics g)
    {
        g.SmoothingMode = SmoothingMode.AntiAlias;
        g.TextRenderingHint = TextRenderingHint.ClearTypeGridFit;
    }

    // ---- native helpers ----
    [DllImport("user32.dll", CharSet = CharSet.Unicode)]
    private static extern IntPtr SendMessage(IntPtr hWnd, int msg, IntPtr wp, string lp);

    /// <summary>Grey hint text inside an empty text box (works on Windows 7 and later).</summary>
    public static void Cue(TextBox box, string text)
    {
        void Apply()
        {
            // cosmetic only: never let a missing native call (other platforms, old comctl32) break a screen
            try { SendMessage(box.Handle, 0x1501 /* EM_SETCUEBANNER */, (IntPtr)1, text); }
            catch (Exception ex) when (ex is EntryPointNotFoundException || ex is DllNotFoundException) { }
        }
        if (box.IsHandleCreated) Apply(); else box.HandleCreated += (_, _) => Apply();
    }
}
