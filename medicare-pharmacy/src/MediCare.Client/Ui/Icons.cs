using System.Drawing.Drawing2D;

namespace MediCare.Client.Ui;

/// <summary>
/// Line icons drawn with GDI+ on a 24-unit grid (no image files, crisp at any DPI).
/// Names: dashboard, cart, receipt, pill, layers, clipboard, truck, building, users, user, cash, expense,
/// lock, chart, shield, eye, database, gear, server, trend, clock, alert, down, box, check, inbox, info, x, plus, arrow.
/// </summary>
public static class Icons
{
    /// <summary>Icon for each navigation key.</summary>
    public static string ForScreen(string key) => key switch
    {
        "dashboard" => "dashboard",
        "pos" => "cart",
        "invoices" => "receipt",
        "medicines" => "pill",
        "batches" => "layers",
        "counts" => "clipboard",
        "purchases" => "truck",
        "suppliers" => "building",
        "customers" => "users",
        "cash" => "cash",
        "expenses" => "expense",
        "closing" => "lock",
        "reports" => "chart",
        "approvals" => "shield",
        "users" => "user",
        "audit" => "eye",
        "backup" => "database",
        "settings" => "gear",
        "host" => "server",
        _ => "box",
    };

    public static void Draw(Graphics g, string name, RectangleF r, Color color, float stroke = 2f)
    {
        if (string.IsNullOrEmpty(name) || r.Width <= 0) return;
        var state = g.Save();
        try
        {
            g.SmoothingMode = SmoothingMode.AntiAlias;
            g.TranslateTransform(r.X, r.Y);
            g.ScaleTransform(r.Width / 24f, r.Height / 24f);
            using var p = new Pen(color, stroke) { StartCap = LineCap.Round, EndCap = LineCap.Round, LineJoin = LineJoin.Round };
            using var b = new SolidBrush(color);
            Paint(g, name, p, b);
        }
        finally { g.Restore(state); }
    }

    private static void Circle(Graphics g, Pen p, float cx, float cy, float rad) => g.DrawEllipse(p, cx - rad, cy - rad, rad * 2, rad * 2);
    private static void Dot(Graphics g, Brush b, float cx, float cy, float rad) => g.FillEllipse(b, cx - rad, cy - rad, rad * 2, rad * 2);
    private static void Lines(Graphics g, Pen p, params float[] xy)
    {
        var pts = new PointF[xy.Length / 2];
        for (int i = 0; i < pts.Length; i++) pts[i] = new PointF(xy[i * 2], xy[i * 2 + 1]);
        g.DrawLines(p, pts);
    }
    private static void Poly(Graphics g, Pen p, params float[] xy)
    {
        var pts = new PointF[xy.Length / 2];
        for (int i = 0; i < pts.Length; i++) pts[i] = new PointF(xy[i * 2], xy[i * 2 + 1]);
        g.DrawPolygon(p, pts);
    }
    private static void RoundRect(Graphics g, Pen p, float x, float y, float w, float h, float rad)
    {
        using var path = new GraphicsPath();
        var d = rad * 2;
        path.AddArc(x, y, d, d, 180, 90);
        path.AddArc(x + w - d, y, d, d, 270, 90);
        path.AddArc(x + w - d, y + h - d, d, d, 0, 90);
        path.AddArc(x, y + h - d, d, d, 90, 90);
        path.CloseFigure();
        g.DrawPath(p, path);
    }

    private static void Paint(Graphics g, string name, Pen p, Brush b)
    {
        switch (name)
        {
            case "dashboard":
                RoundRect(g, p, 3, 3, 8, 8, 1.5f); RoundRect(g, p, 13, 3, 8, 5, 1.5f);
                RoundRect(g, p, 13, 10, 8, 11, 1.5f); RoundRect(g, p, 3, 13, 8, 8, 1.5f);
                break;
            case "cart":
                Lines(g, p, 2, 3, 5, 3, 7.5f, 15, 19, 15, 21, 7, 6, 7);
                Circle(g, p, 9, 19.5f, 1.5f); Circle(g, p, 17, 19.5f, 1.5f);
                break;
            case "receipt":
                Poly(g, p, 5, 2, 19, 2, 19, 21, 16.5f, 19.5f, 14, 21, 12, 19.5f, 10, 21, 7.5f, 19.5f, 5, 21);
                Lines(g, p, 8.5f, 7, 15.5f, 7); Lines(g, p, 8.5f, 11, 15.5f, 11); Lines(g, p, 8.5f, 15, 12.5f, 15);
                break;
            case "pill":
            {
                var s = g.Save();
                g.TranslateTransform(12, 12); g.RotateTransform(-45); g.TranslateTransform(-12, -12);
                RoundRect(g, p, 3, 8, 18, 8, 4); Lines(g, p, 12, 8, 12, 16);
                g.Restore(s);
                break;
            }
            case "layers":
                Poly(g, p, 12, 3, 21, 8, 12, 13, 3, 8);
                Lines(g, p, 3, 12, 12, 17, 21, 12); Lines(g, p, 3, 16, 12, 21, 21, 16);
                break;
            case "clipboard":
                RoundRect(g, p, 5, 4, 14, 18, 2); RoundRect(g, p, 9, 2, 6, 4, 1);
                Lines(g, p, 8.5f, 13, 11, 15.5f, 15.5f, 10.5f);
                break;
            case "truck":
                RoundRect(g, p, 2, 6, 12, 10, 1); Lines(g, p, 14, 9, 18, 9, 21, 12, 21, 16, 14, 16);
                Circle(g, p, 6.5f, 18, 2); Circle(g, p, 17, 18, 2);
                break;
            case "building":
                RoundRect(g, p, 4, 3, 9, 18, 1); Lines(g, p, 13, 9, 20, 9, 20, 21, 2.5f, 21);
                Lines(g, p, 7, 7, 10, 7); Lines(g, p, 7, 11, 10, 11); Lines(g, p, 7, 15, 10, 15); Lines(g, p, 16, 13, 17, 13); Lines(g, p, 16, 17, 17, 17);
                break;
            case "users":
                Circle(g, p, 9, 8, 3.5f); g.DrawArc(p, 2.5f, 14, 13, 12, 180, 180);
                Circle(g, p, 17, 9, 2.5f); g.DrawArc(p, 15, 14.5f, 7, 8, 200, 140);
                break;
            case "user":
                Circle(g, p, 12, 8, 4); g.DrawArc(p, 4, 14, 16, 14, 180, 180);
                break;
            case "cash":
                RoundRect(g, p, 2, 6, 20, 12, 2); Circle(g, p, 12, 12, 3);
                Dot(g, b, 6, 12, 1); Dot(g, b, 18, 12, 1);
                break;
            case "expense":
                Lines(g, p, 3, 13, 3, 20, 21, 20, 21, 13);
                Lines(g, p, 12, 15, 12, 3); Lines(g, p, 7.5f, 7.5f, 12, 3, 16.5f, 7.5f);
                break;
            case "lock":
                RoundRect(g, p, 5, 11, 14, 10, 2); g.DrawArc(p, 8, 4, 8, 12, 180, 180);
                Lines(g, p, 12, 15, 12, 17);
                break;
            case "chart":
                Lines(g, p, 3, 21, 21, 21);
                using (var thick = new Pen(p.Color, p.Width * 1.6f) { StartCap = LineCap.Round, EndCap = LineCap.Round })
                {
                    g.DrawLine(thick, 6, 17, 6, 13); g.DrawLine(thick, 10.5f, 17, 10.5f, 8);
                    g.DrawLine(thick, 15, 17, 15, 11); g.DrawLine(thick, 19.5f, 17, 19.5f, 5);
                }
                break;
            case "shield":
                Poly(g, p, 12, 2, 20, 5, 20, 11, 17.5f, 16.5f, 12, 21.5f, 6.5f, 16.5f, 4, 11, 4, 5);
                Lines(g, p, 8.5f, 12, 11, 14.5f, 15.5f, 9.5f);
                break;
            case "eye":
                using (var path = new GraphicsPath())
                {
                    path.AddBezier(2, 12, 6, 4.5f, 18, 4.5f, 22, 12);
                    path.AddBezier(22, 12, 18, 19.5f, 6, 19.5f, 2, 12);
                    g.DrawPath(p, path);
                }
                Circle(g, p, 12, 12, 3);
                break;
            case "database":
                g.DrawEllipse(p, 4, 3, 16, 5.5f);
                Lines(g, p, 4, 5.75f, 4, 18.25f); Lines(g, p, 20, 5.75f, 20, 18.25f);
                g.DrawArc(p, 4, 9, 16, 5.5f, 0, 180); g.DrawArc(p, 4, 15.5f, 16, 5.5f, 0, 180);
                break;
            case "gear":
                Circle(g, p, 12, 12, 3);
                Circle(g, p, 12, 12, 6.5f);
                using (var tooth = new Pen(p.Color, p.Width * 1.5f) { StartCap = LineCap.Round, EndCap = LineCap.Round })
                    for (int i = 0; i < 8; i++)
                    {
                        var a = i * Math.PI / 4;
                        g.DrawLine(tooth, 12 + (float)Math.Cos(a) * 7.5f, 12 + (float)Math.Sin(a) * 7.5f, 12 + (float)Math.Cos(a) * 9.5f, 12 + (float)Math.Sin(a) * 9.5f);
                    }
                break;
            case "server":
                RoundRect(g, p, 3, 3, 18, 7, 1.5f); RoundRect(g, p, 3, 14, 18, 7, 1.5f);
                Dot(g, b, 7, 6.5f, 1); Dot(g, b, 7, 17.5f, 1); Lines(g, p, 12, 10, 12, 14);
                break;
            case "trend":
                Lines(g, p, 3, 17, 9, 11, 13, 15, 21, 7); Lines(g, p, 15, 7, 21, 7, 21, 13);
                break;
            case "clock":
                Circle(g, p, 12, 12, 9); Lines(g, p, 12, 7, 12, 12, 15.5f, 14);
                break;
            case "alert":
                Poly(g, p, 12, 3, 22, 20, 2, 20); Lines(g, p, 12, 9, 12, 14); Dot(g, b, 12, 17, 1.2f);
                break;
            case "down":
                Lines(g, p, 12, 3, 12, 16); Lines(g, p, 7, 11, 12, 16, 17, 11); Lines(g, p, 4, 20.5f, 20, 20.5f);
                break;
            case "box":
                Poly(g, p, 12, 2, 21, 7, 21, 17, 12, 22, 3, 17, 3, 7);
                Lines(g, p, 3, 7, 12, 12, 21, 7); Lines(g, p, 12, 12, 12, 22);
                break;
            case "check":
                Circle(g, p, 12, 12, 9); Lines(g, p, 7.5f, 12.5f, 10.5f, 15.5f, 16.5f, 9);
                break;
            case "inbox":
                Poly(g, p, 3, 13, 6, 5, 18, 5, 21, 13, 21, 19, 3, 19);
                Lines(g, p, 3, 13, 8, 13, 10, 16, 14, 16, 16, 13, 21, 13);
                break;
            case "info":
                Circle(g, p, 12, 12, 9); Lines(g, p, 12, 11, 12, 16.5f); Dot(g, b, 12, 7.5f, 1.2f);
                break;
            case "x":
                Lines(g, p, 6, 6, 18, 18); Lines(g, p, 18, 6, 6, 18);
                break;
            case "plus":
                Lines(g, p, 12, 5, 12, 19); Lines(g, p, 5, 12, 19, 12);
                break;
            case "arrow":
                Lines(g, p, 5, 12, 19, 12); Lines(g, p, 13, 6, 19, 12, 13, 18);
                break;
            case "search":
                Circle(g, p, 10.5f, 10.5f, 6.5f); Lines(g, p, 15.5f, 15.5f, 21, 21);
                break;
        }
    }
}
