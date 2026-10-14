using System.Drawing.Printing;
using System.Runtime.InteropServices;

namespace MediCare.Client.Printing;

/// <summary>Printer helpers: list, receipt printing, cash drawer.</summary>
public static class Printers
{
    public static List<string> List()
    {
        try { return PrinterSettings.InstalledPrinters.Cast<string>().ToList(); }
        catch { return new List<string>(); }
    }

    /// <summary>Prints a receipt on this PC's receipt printer. Returns false (and logs) when printing failed.</summary>
    public static bool Receipt(ReceiptDto r, bool openDrawer = false)
    {
        try
        {
            new ReceiptPrinter(r, Session.Config.ReceiptWidthMm).Print(Session.Config.ReceiptPrinter);
            if (openDrawer && Session.Config.OpenCashDrawer) OpenDrawer(Session.Config.ReceiptPrinter);
            return true;
        }
        catch (Exception ex)
        {
            Log.Error("Receipt printing failed", ex);
            return false;
        }
    }

    public static void TestReceipt(IWin32Window owner)
    {
        var s = Session.Settings;
        var r = new ReceiptDto
        {
            Title = "Test receipt", PharmacyName = s.PharmacyName, Address = s.Address, Phone = s.Phone, LicenseNo = s.LicenseNo, DocNo = "TEST-0001", At = DateTime.Now,
            Cashier = Session.User.FullName, CounterNo = Session.Counter, Customer = "Walk-in",
            Lines = new List<ReceiptLine> { new ReceiptLine { Name = "Panadol 500mg Tablet", UrduName = "پیناڈول", QtyText = "2 Strip", Rate = 34.50m, Amount = 69.00m, BatchNo = "PX2231", Expiry = "Mar-27" } },
            Gross = 69, Net = 69, Tendered = 100, Change = 31, Payments = new List<KeyValuePair<string, decimal>> { new KeyValuePair<string, decimal>("Cash", 69) },
            FooterUrdu = s.ReceiptFooterUrdu, FooterEnglish = s.ReceiptFooterEnglish, ShowBatch = true,
        };
        if (!Receipt(r)) Msg.Error(owner, "The test receipt could not be printed. Check the printer is switched on, has paper, and is the one chosen here.");
    }

    // ---- cash drawer (ESC/POS pulse through the receipt printer) ----

    public static void OpenDrawer(string printer)
    {
        var name = printer.Length > 0 ? printer : new PrinterSettings().PrinterName;
        RawPrinter.Send(name, new byte[] { 0x1B, 0x70, 0x00, 0x19, 0xFA });
    }

    private static class RawPrinter
    {
        [StructLayout(LayoutKind.Sequential, CharSet = CharSet.Unicode)]
        private sealed class DocInfo
        {
            public string pDocName = "MediCare drawer";
            public string? pOutputFile;
            public string pDataType = "RAW";
        }

        [DllImport("winspool.drv", CharSet = CharSet.Unicode, SetLastError = true)]
        private static extern bool OpenPrinter(string name, out IntPtr handle, IntPtr defaults);
        [DllImport("winspool.drv", SetLastError = true)]
        private static extern bool ClosePrinter(IntPtr handle);
        [DllImport("winspool.drv", CharSet = CharSet.Unicode, SetLastError = true)]
        private static extern bool StartDocPrinter(IntPtr handle, int level, [In] DocInfo di);
        [DllImport("winspool.drv", SetLastError = true)]
        private static extern bool EndDocPrinter(IntPtr handle);
        [DllImport("winspool.drv", SetLastError = true)]
        private static extern bool StartPagePrinter(IntPtr handle);
        [DllImport("winspool.drv", SetLastError = true)]
        private static extern bool EndPagePrinter(IntPtr handle);
        [DllImport("winspool.drv", SetLastError = true)]
        private static extern bool WritePrinter(IntPtr handle, byte[] bytes, int count, out int written);

        public static void Send(string printer, byte[] data)
        {
            if (!OpenPrinter(printer, out var h, IntPtr.Zero)) return;
            try
            {
                if (!StartDocPrinter(h, 1, new DocInfo())) return;
                StartPagePrinter(h);
                WritePrinter(h, data, data.Length, out _);
                EndPagePrinter(h);
                EndDocPrinter(h);
            }
            finally { ClosePrinter(h); }
        }
    }
}

/// <summary>
/// Thermal receipt (58 or 80 mm). English body, Urdu medicine names and Urdu footer drawn right-to-left
/// with the Urdu font (Noto Naskh Arabic when installed).
/// </summary>
public sealed class ReceiptPrinter
{
    private readonly ReceiptDto _r;
    private readonly int _widthMm;

    public ReceiptPrinter(ReceiptDto receipt, int widthMm)
    {
        _r = receipt;
        _widthMm = widthMm;
    }

    // printable width in 1/100 inch (paper minus the printer's unprintable margins)
    private float Width => (_widthMm == 58 ? 48f : 72f) / 25.4f * 100f;

    public void Print(string printerName)
    {
        using var doc = new PrintDocument { DocumentName = "Receipt " + _r.DocNo };
        if (printerName.Length > 0) doc.PrinterSettings.PrinterName = printerName;
        if (!doc.PrinterSettings.IsValid) throw new InvalidOperationException($"Printer \"{printerName}\" was not found.");
        doc.PrintController = new StandardPrintController(); // no "Printing..." pop-up
        float height;
        using (var g = doc.PrinterSettings.CreateMeasurementGraphics()) height = Render(g, measureOnly: true);
        var paperWidth = (int)Math.Ceiling(_widthMm / 25.4 * 100);
        doc.DefaultPageSettings.PaperSize = new PaperSize("Receipt", paperWidth, (int)Math.Ceiling(height) + 40);
        doc.DefaultPageSettings.Margins = new Margins(0, 0, 0, 0);
        doc.OriginAtMargins = false;
        doc.PrintPage += (_, e) =>
        {
            Render(e.Graphics!, measureOnly: false);
            e.HasMorePages = false;
        };
        doc.Print();
    }

    private float Render(Graphics g, bool measureOnly)
    {
        var small = _widthMm == 58;
        using var fBody = new Font(Theme.UiFamily, small ? 7f : 8f);
        using var fBold = new Font(Theme.UiFamily, small ? 7f : 8f, FontStyle.Bold);
        using var fHead = new Font(Theme.UiFamily, small ? 10f : 12f, FontStyle.Bold);
        using var fNet = new Font(Theme.UiFamily, small ? 10f : 12f, FontStyle.Bold);
        using var fMono = new Font(Theme.NumFamily, small ? 6.5f : 7.5f);
        using var fUrdu = new Font(Theme.UrduFamily, small ? 9f : 10f);
        var left = small ? 4f : 6f;
        var w = Width - left * 2;
        var y = 4f;
        var brush = Brushes.Black;
        var center = new StringFormat { Alignment = StringAlignment.Center };
        var right = new StringFormat { Alignment = StringAlignment.Far };
        var leftFmt = new StringFormat { Alignment = StringAlignment.Near };
        var rtl = new StringFormat(StringFormatFlags.DirectionRightToLeft) { Alignment = StringAlignment.Center };

        void Text(string s, Font f, StringFormat fmt, float x = 0, float width = 0)
        {
            if (string.IsNullOrWhiteSpace(s)) return;
            var wd = width > 0 ? width : w;
            var size = g.MeasureString(s, f, (int)wd, fmt);
            if (!measureOnly) g.DrawString(s, f, brush, new RectangleF(left + x, y, wd, size.Height + 2), fmt);
            y += size.Height;
        }

        void Pair(string a, string b, Font f)
        {
            var bw = g.MeasureString(b, f).Width + 2;
            var aSize = g.MeasureString(a, f, (int)(w - bw), leftFmt);
            if (!measureOnly)
            {
                g.DrawString(a, f, brush, new RectangleF(left, y, w - bw, aSize.Height + 2), leftFmt);
                g.DrawString(b, f, brush, new RectangleF(left, y, w, aSize.Height + 2), right);
            }
            y += Math.Max(aSize.Height, f.GetHeight(g));
        }

        void Rule()
        {
            y += 3;
            if (!measureOnly) using (var p = new Pen(Color.Black, 0.5f) { DashStyle = System.Drawing.Drawing2D.DashStyle.Dash }) g.DrawLine(p, left, y, left + w, y);
            y += 4;
        }

        Text(_r.PharmacyName, fHead, center);
        Text(_r.Address, fBody, center);
        Text(string.Join("  ", new[] { _r.Phone.Length > 0 ? "Ph " + _r.Phone : "", _r.LicenseNo.Length > 0 ? "Lic " + _r.LicenseNo : "" }.Where(x => x.Length > 0)), fBody, center);
        if (_r.Ntn.Length > 0) Text("NTN " + _r.Ntn, fBody, center);
        y += 3;
        Text((_r.IsDuplicate ? "DUPLICATE " : "") + _r.Title.ToUpperInvariant(), fBold, center);
        Rule();
        Pair(_r.DocNo, _r.At.ToString("dd-MMM-yyyy HH:mm", CultureInfo.InvariantCulture), fMono);
        Pair($"Counter {_r.CounterNo} · {_r.Cashier}", "", fBody);
        if (_r.Customer.Length > 0 && _r.Customer != "Walk-in") Text("Customer: " + _r.Customer, fBody, leftFmt);
        Rule();
        foreach (var l in _r.Lines)
        {
            Text(l.Name, fBold, leftFmt);
            if (l.UrduName.Length > 0)
            {
                var rtlRight = new StringFormat(StringFormatFlags.DirectionRightToLeft) { Alignment = StringAlignment.Near };
                Text(l.UrduName, fUrdu, rtlRight);
            }
            Pair($"{l.QtyText} x {Money.Format(l.Rate)}", Money.Format(l.Amount), fMono);
            if (l.Discount > 0) Pair("  discount", "-" + Money.Format(l.Discount), fMono);
            if (_r.ShowBatch && l.BatchNo.Length > 0) Text($"  Batch {l.BatchNo}  Exp {l.Expiry}", fMono, leftFmt);
            y += 2;
        }
        Rule();
        Pair("Gross", Money.Format(_r.Gross), fMono);
        if (_r.Discount != 0) Pair("Discount", "-" + Money.Format(_r.Discount), fMono);
        if (_r.Tax != 0) Pair("Sales tax", Money.Format(_r.Tax), fMono);
        y += 2;
        Pair(_r.Title.StartsWith("Return", StringComparison.OrdinalIgnoreCase) ? "REFUND" : "NET PAYABLE", "Rs " + Money.Format(_r.Net), fNet);
        y += 2;
        foreach (var p in _r.Payments) Pair(p.Key, Money.Format(p.Value), fMono);
        if (_r.Tendered > 0) Pair("Cash tendered", Money.Format(_r.Tendered), fMono);
        if (_r.Change > 0) Pair("Change", Money.Format(_r.Change), fBold);
        if (_r.CustomerBalance != 0) Pair("Your balance due", Money.Format(_r.CustomerBalance), fBold);
        if (!string.IsNullOrWhiteSpace(_r.Note)) { y += 2; Text(_r.Note!, fBody, leftFmt); }
        Rule();
        foreach (var line in _r.FooterUrdu.Where(x => !string.IsNullOrWhiteSpace(x))) Text(line, fUrdu, rtl);
        if (_r.FooterEnglish.Length > 0) { y += 2; Text(_r.FooterEnglish, fBody, center); }
        y += 2;
        Text("MediCare Pharmacy", fMono, center);
        return y + 8;
    }
}
