using System.Drawing.Printing;

namespace MediCare.Client.Printing;

public sealed class PrintCol
{
    public string Name { get; set; } = "";
    public float Weight { get; set; } = 1;
    public bool Right { get; set; }
}

/// <summary>A document made of a heading block and a table: reports, ledgers, statements, vouchers, A4 invoices.</summary>
public sealed class PrintTable
{
    public string Title { get; set; } = "";
    public string Subtitle { get; set; } = "";
    public List<KeyValuePair<string, string>> Info { get; } = new List<KeyValuePair<string, string>>();
    public List<PrintCol> Columns { get; } = new List<PrintCol>();
    public List<string[]> Rows { get; } = new List<string[]>();
    public string[]? Totals { get; set; }
    public List<string> Notes { get; } = new List<string>();
    public bool Landscape { get; set; }
    public bool Signatures { get; set; }

    public PrintTable Col(string name, float weight = 1, bool right = false)
    {
        Columns.Add(new PrintCol { Name = name, Weight = weight, Right = right });
        return this;
    }

    /// <summary>Prints what a grid shows (visible columns, formatted values).</summary>
    public static PrintTable FromGrid(DataGridView grid, string title, string subtitle = "")
    {
        var t = new PrintTable { Title = title, Subtitle = subtitle };
        var cols = grid.Columns.Cast<DataGridViewColumn>().Where(c => c.Visible && !(c is DataGridViewCheckBoxColumn)).OrderBy(c => c.DisplayIndex).ToList();
        foreach (var c in cols)
            t.Col(c.HeaderText, Math.Max(c.Width, 40), c.DefaultCellStyle.Alignment == DataGridViewContentAlignment.MiddleRight);
        foreach (DataGridViewRow r in grid.Rows)
            t.Rows.Add(cols.Select(c => r.Cells[c.Index].FormattedValue?.ToString() ?? "").ToArray());
        t.Landscape = cols.Count > 7;
        return t;
    }
}

/// <summary>Prints a <see cref="PrintTable"/> on A4 with page breaks, repeated headers and page numbers.</summary>
public static class TablePrinter
{
    public static void Print(IWin32Window owner, PrintTable t, bool preview = true)
    {
        using var doc = new PrintDocument { DocumentName = t.Title };
        if (Session.Config.A4Printer.Length > 0) doc.PrinterSettings.PrinterName = Session.Config.A4Printer;
        doc.DefaultPageSettings.Landscape = t.Landscape;
        doc.DefaultPageSettings.Margins = new Margins(50, 50, 50, 50);
        int rowIndex = 0, page = 0;
        var printedAt = DateTime.Now;
        doc.BeginPrint += (_, _) => { rowIndex = 0; page = 0; };
        doc.PrintPage += (_, e) =>
        {
            page++;
            var g = e.Graphics!;
            var area = e.MarginBounds;
            using var fTitle = new Font(Theme.UiFamily, 14f, FontStyle.Bold);
            using var fSub = new Font(Theme.UiFamily, 9f);
            using var fHead = new Font(Theme.UiFamily, 8f, FontStyle.Bold);
            using var fBody = new Font(Theme.UiFamily, 8.5f);
            using var fNum = new Font(Theme.NumFamily, 8f);
            using var fNumBold = new Font(Theme.NumFamily, 8f, FontStyle.Bold);
            using var line = new Pen(Color.FromArgb(170, 170, 170), 0.5f);
            var y = (float)area.Top;
            var s = Session.Settings;
            g.DrawString(s.PharmacyName, fSub, Brushes.DimGray, area.Left, y);
            g.DrawString($"Page {page}", fSub, Brushes.DimGray, new RectangleF(area.Left, y, area.Width, 20), new StringFormat { Alignment = StringAlignment.Far });
            y += 18;
            g.DrawString(t.Title, fTitle, Brushes.Black, area.Left, y);
            y += fTitle.GetHeight(g) + 2;
            if (t.Subtitle.Length > 0) { g.DrawString(t.Subtitle, fSub, Brushes.Black, area.Left, y); y += fSub.GetHeight(g) + 2; }
            if (page == 1 && t.Info.Count > 0)
            {
                y += 4;
                var colW = area.Width / 2f;
                for (int i = 0; i < t.Info.Count; i++)
                {
                    var x = area.Left + (i % 2) * colW;
                    g.DrawString(t.Info[i].Key + ": ", fHead, Brushes.Black, x, y);
                    var kw = g.MeasureString(t.Info[i].Key + ": ", fHead).Width;
                    g.DrawString(t.Info[i].Value, fBody, Brushes.Black, new RectangleF(x + kw, y, colW - kw - 8, 16));
                    if (i % 2 == 1 || i == t.Info.Count - 1) y += 16;
                }
            }
            y += 8;

            // column geometry
            var totalWeight = t.Columns.Sum(c => c.Weight);
            var xs = new float[t.Columns.Count];
            var ws = new float[t.Columns.Count];
            float cx = area.Left;
            for (int i = 0; i < t.Columns.Count; i++)
            {
                xs[i] = cx;
                ws[i] = area.Width * t.Columns[i].Weight / totalWeight;
                cx += ws[i];
            }
            var rightFmt = new StringFormat { Alignment = StringAlignment.Far, Trimming = StringTrimming.EllipsisCharacter, FormatFlags = StringFormatFlags.NoWrap };
            var leftFmt = new StringFormat { Alignment = StringAlignment.Near, Trimming = StringTrimming.EllipsisCharacter, FormatFlags = StringFormatFlags.NoWrap };
            void Row(string[] cells, Font text, Font num, float height)
            {
                for (int i = 0; i < t.Columns.Count && i < cells.Length; i++)
                {
                    var right = t.Columns[i].Right;
                    g.DrawString(cells[i], right ? num : text, Brushes.Black, new RectangleF(xs[i] + 3, y + 2, ws[i] - 6, height), right ? rightFmt : leftFmt);
                }
                y += height;
            }
            var rowH = fBody.GetHeight(g) + 5;
            using (var headBack = new SolidBrush(Color.FromArgb(237, 242, 242))) g.FillRectangle(headBack, area.Left, y, area.Width, rowH);
            Row(t.Columns.Select(c => c.Name.ToUpperInvariant()).ToArray(), fHead, fHead, rowH);
            var reserve = rowH * 2 + (t.Signatures ? 60 : 0) + t.Notes.Count * 14 + 20;
            while (rowIndex < t.Rows.Count && y + rowH < area.Bottom - 20)
            {
                Row(t.Rows[rowIndex], fBody, fNum, rowH);
                g.DrawLine(line, area.Left, y, area.Right, y);
                rowIndex++;
            }
            var done = rowIndex >= t.Rows.Count;
            if (done && y + reserve > area.Bottom && t.Rows.Count > 0 && (t.Totals != null || t.Signatures || t.Notes.Count > 0))
            {
                // not enough room for totals: they go on the next page
                if (y + rowH * 2 > area.Bottom) { e.HasMorePages = true; return; }
            }
            if (done)
            {
                if (t.Rows.Count == 0) { g.DrawString("No rows for these filters.", fBody, Brushes.DimGray, area.Left + 3, y + 4); y += rowH; }
                if (t.Totals != null)
                {
                    using (var p = new Pen(Color.Black, 1f)) g.DrawLine(p, area.Left, y, area.Right, y);
                    Row(t.Totals, fHead, fNumBold, rowH);
                }
                y += 10;
                foreach (var n in t.Notes) { g.DrawString(n, fSub, Brushes.Black, new RectangleF(area.Left, y, area.Width, 30)); y += 14; }
                if (t.Signatures)
                {
                    y = Math.Max(y + 30, area.Bottom - 40);
                    var third = area.Width / 3f;
                    foreach (var (label, i) in new[] { "Prepared by", "Checked by", "Approved by" }.Select((l, i) => (l, i)))
                    {
                        g.DrawLine(Pens.Black, area.Left + i * third + 10, y, area.Left + (i + 1) * third - 20, y);
                        g.DrawString(label, fSub, Brushes.Black, area.Left + i * third + 10, y + 2);
                    }
                }
                g.DrawString($"Printed {printedAt:dd-MMM-yyyy HH:mm} by {Session.User.FullName}", fSub, Brushes.DimGray, area.Left, area.Bottom + 8);
            }
            e.HasMorePages = !done;
        };
        if (preview)
        {
            using var dlg = new PrintPreviewDialog { Document = doc, Width = Theme.S(1000), Height = Theme.S(760), UseAntiAlias = true, ShowIcon = false };
            dlg.ShowDialog(owner);
        }
        else
        {
            using var pd = new PrintDialog { Document = doc, UseEXDialog = true };
            if (pd.ShowDialog(owner) == DialogResult.OK) doc.Print();
        }
    }
}
