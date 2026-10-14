using System.Data;

namespace MediCare.Client.Ui;

public enum ColKind { Text, Mono, Money, Int, Date, DateTime, Expiry, Percent, Check }

/// <summary>
/// Themed DataGridView. Rows carry their item in Tag, so screens work with DTOs, not cells.
/// Enter or double-click raises <see cref="RowActivated"/>.
/// </summary>
public class Grid : DataGridView
{
    public event Action<object>? RowActivated;
    /// <summary>Optional per-row tone (expired = danger, near expiry = warn...).</summary>
    public Func<object, Tone>? RowTone { get; set; }
    public string EmptyText { get; set; } = "Nothing to show.";
    public string EmptyIcon { get; set; } = "inbox";
    private int _hoverRow = -1;

    public Grid()
    {
        DoubleBuffered = true;
        ReadOnly = true;
        AllowUserToAddRows = false;
        AllowUserToDeleteRows = false;
        AllowUserToResizeRows = false;
        AllowUserToOrderColumns = false;
        SelectionMode = DataGridViewSelectionMode.FullRowSelect;
        MultiSelect = false;
        RowHeadersVisible = false;
        AutoGenerateColumns = false;
        BackgroundColor = Theme.Surface;
        BorderStyle = BorderStyle.None;
        CellBorderStyle = DataGridViewCellBorderStyle.SingleHorizontal;
        GridColor = Theme.Line;
        EnableHeadersVisualStyles = false;
        ColumnHeadersBorderStyle = DataGridViewHeaderBorderStyle.None;
        ColumnHeadersHeightSizeMode = DataGridViewColumnHeadersHeightSizeMode.DisableResizing;
        ColumnHeadersHeight = Theme.S(32);
        ColumnHeadersDefaultCellStyle = new DataGridViewCellStyle
        {
            BackColor = Theme.SurfaceAlt,
            ForeColor = Theme.InkMuted,
            Font = Theme.Xs,
            SelectionBackColor = Theme.SurfaceAlt,
            SelectionForeColor = Theme.InkMuted,
            Padding = new Padding(Theme.S(6), 0, Theme.S(6), 0),
            WrapMode = DataGridViewTriState.False,
        };
        DefaultCellStyle = new DataGridViewCellStyle
        {
            BackColor = Theme.Surface,
            ForeColor = Theme.Ink,
            Font = Theme.Ui,
            SelectionBackColor = Theme.Select,
            SelectionForeColor = Theme.Ink,
            Padding = new Padding(Theme.S(6), 0, Theme.S(6), 0),
            WrapMode = DataGridViewTriState.False,
        };
        AlternatingRowsDefaultCellStyle = new DataGridViewCellStyle { BackColor = Theme.Hex("#FAFCFC") };
        RowTemplate.Height = Theme.RowHeight;
        StandardTab = true;
        ShowCellToolTips = true;
        AutoSizeColumnsMode = DataGridViewAutoSizeColumnsMode.None;
        ScrollBars = ScrollBars.Both;
        Dock = DockStyle.Fill;
    }

    public DataGridViewColumn Col(string header, int width = 0, ColKind kind = ColKind.Text, int fillWeight = 100)
    {
        DataGridViewColumn col = kind == ColKind.Check ? new DataGridViewCheckBoxColumn() : new DataGridViewTextBoxColumn();
        col.HeaderText = header.ToUpperInvariant();
        col.SortMode = DataGridViewColumnSortMode.Automatic;
        col.Name = "c" + Columns.Count;
        if (width > 0) { col.Width = Theme.S(width); col.AutoSizeMode = DataGridViewAutoSizeColumnMode.None; }
        else { col.AutoSizeMode = DataGridViewAutoSizeColumnMode.Fill; col.FillWeight = fillWeight; col.MinimumWidth = Theme.S(80); }
        var style = new DataGridViewCellStyle();
        switch (kind)
        {
            case ColKind.Money:
                style.Alignment = DataGridViewContentAlignment.MiddleRight; style.Font = Theme.Num; style.Format = "#,##0.00";
                col.HeaderCell.Style.Alignment = DataGridViewContentAlignment.MiddleRight; break;
            case ColKind.Int:
            case ColKind.Percent:
                style.Alignment = DataGridViewContentAlignment.MiddleRight; style.Font = Theme.Num;
                col.HeaderCell.Style.Alignment = DataGridViewContentAlignment.MiddleRight; break;
            case ColKind.Mono: style.Font = Theme.Num; break;
            case ColKind.Date: style.Format = "dd-MMM-yyyy"; style.Font = Theme.Num; break;
            case ColKind.DateTime: style.Format = "dd-MMM-yyyy HH:mm"; style.Font = Theme.Num; break;
            case ColKind.Expiry: style.Format = "MMM-yy"; style.Font = Theme.Num; break;
            case ColKind.Check: style.Alignment = DataGridViewContentAlignment.MiddleCenter; break;
        }
        col.DefaultCellStyle = style;
        Columns.Add(col);
        return col;
    }

    /// <summary>Replaces all rows. <paramref name="map"/> returns the cell values in column order.</summary>
    public void SetRows<T>(IEnumerable<T> items, Func<T, object?[]> map)
    {
        var keep = CurrentRow?.Tag;
        var firstVisible = FirstDisplayedScrollingRowIndex;
        SuspendLayout();
        Rows.Clear();
        var list = items.ToList();
        if (list.Count > 0)
        {
            var rows = new DataGridViewRow[list.Count];
            for (int i = 0; i < list.Count; i++)
            {
                var r = new DataGridViewRow();
                r.CreateCells(this, map(list[i]).Select(v => v ?? DBNull.Value).ToArray());
                r.Tag = list[i];
                r.Height = Theme.RowHeight;
                if (RowTone != null)
                {
                    var tone = RowTone(list[i]!);
                    if (tone != Tone.None)
                    {
                        r.DefaultCellStyle.BackColor = Theme.Back(tone);
                        r.DefaultCellStyle.ForeColor = Theme.Fore(tone);
                    }
                }
                rows[i] = r;
            }
            Rows.AddRange(rows);
        }
        ResumeLayout();
        if (keep != null)
        {
            foreach (DataGridViewRow r in Rows)
                if (Equals(r.Tag, keep)) { SelectRow(r.Index); break; }
        }
        if (firstVisible >= 0 && firstVisible < RowCount) { try { FirstDisplayedScrollingRowIndex = firstVisible; } catch { } }
        Invalidate();
    }

    /// <summary>Adds an unbound row; null values show as empty cells.</summary>
    public int AddRow(params object?[] values) => Rows.Add(values.Select(v => v ?? DBNull.Value).ToArray());

    public void SelectRow(int index)
    {
        if (index < 0 || index >= RowCount) return;
        var col = Columns.Cast<DataGridViewColumn>().FirstOrDefault(c => c.Visible);
        if (col == null) return;
        CurrentCell = Rows[index].Cells[col.Index];
        Rows[index].Selected = true;
    }

    public T? Current<T>() where T : class => CurrentRow?.Tag as T;

    public IEnumerable<T> Items<T>() => Rows.Cast<DataGridViewRow>().Select(r => r.Tag).OfType<T>();

    protected override bool ProcessDialogKey(Keys keyData)
    {
        if (keyData == Keys.Enter && ReadOnly && CurrentRow?.Tag != null && RowActivated != null)
        {
            RowActivated(CurrentRow.Tag);
            return true;
        }
        return base.ProcessDialogKey(keyData);
    }

    protected override void OnCellDoubleClick(DataGridViewCellEventArgs e)
    {
        base.OnCellDoubleClick(e);
        if (e.RowIndex >= 0 && Rows[e.RowIndex].Tag != null) RowActivated?.Invoke(Rows[e.RowIndex].Tag!);
    }

    /// <summary>Briefly highlights a row (a line just added or changed). Follows the row's item if the rows are rebuilt meanwhile.</summary>
    public void Flash(int index)
    {
        if (index < 0 || index >= RowCount) return;
        var tag = Rows[index].Tag;
        DataGridViewRow? row = null;
        Color back = Color.Empty, sel = Color.Empty;
        Motion.Run(this, "flash", 700, t =>
        {
            var current = tag == null ? (index < RowCount ? Rows[index] : null) : Rows.Cast<DataGridViewRow>().FirstOrDefault(r => ReferenceEquals(r.Tag, tag));
            if (current == null) return;
            if (current != row)
            {
                // first frame on this row object: remember its own colours to fade back to
                row = current;
                back = row.DefaultCellStyle.BackColor;
                sel = row.DefaultCellStyle.SelectionBackColor;
            }
            var style = row.DefaultCellStyle;
            var done = t >= 1f;
            style.BackColor = done ? back : Theme.Blend(Theme.AccentSoft, back.IsEmpty ? Theme.Surface : back, t);
            style.SelectionBackColor = done ? sel : Theme.Blend(Theme.Hex("#9FD3D6"), sel.IsEmpty ? Theme.Select : sel, t);
        }, ease: Motion.EaseInOut);
    }

    // ---- hover highlight ----
    protected override void OnCellMouseEnter(DataGridViewCellEventArgs e)
    {
        base.OnCellMouseEnter(e);
        if (e.RowIndex == _hoverRow) return;
        var old = _hoverRow;
        _hoverRow = e.RowIndex;
        if (old >= 0 && old < RowCount) InvalidateRow(old);
        if (_hoverRow >= 0 && _hoverRow < RowCount) InvalidateRow(_hoverRow);
    }

    protected override void OnMouseLeave(EventArgs e)
    {
        base.OnMouseLeave(e);
        var old = _hoverRow;
        _hoverRow = -1;
        if (old >= 0 && old < RowCount) InvalidateRow(old);
    }

    protected override void OnCellFormatting(DataGridViewCellFormattingEventArgs e)
    {
        base.OnCellFormatting(e);
        if (e.RowIndex >= 0 && e.RowIndex == _hoverRow && e.CellStyle != null && !Rows[e.RowIndex].Selected)
            e.CellStyle.BackColor = Theme.Blend(e.CellStyle.BackColor.IsEmpty ? Theme.Surface : e.CellStyle.BackColor, Theme.Accent, 0.07f);
    }

    protected override void OnPaint(PaintEventArgs e)
    {
        base.OnPaint(e);
        if (RowCount == 0 && EmptyText.Length > 0)
        {
            var g = e.Graphics;
            var area = new Rectangle(0, ColumnHeadersHeight, Width, Math.Max(Height - ColumnHeadersHeight, Theme.S(40)));
            var icon = Theme.S(36);
            var showIcon = EmptyIcon.Length > 0 && area.Height >= Theme.S(120);
            var textSize = TextRenderer.MeasureText(g, EmptyText, Theme.Ui, new Size(Math.Max(Width - Theme.S(40), 100), 0), TextFormatFlags.WordBreak | TextFormatFlags.NoPrefix);
            var block = textSize.Height + (showIcon ? icon + Theme.S(12) : 0);
            var y = area.Top + (area.Height - block) / 2;
            if (showIcon)
            {
                Theme.Smooth(g);
                var chip = new Rectangle((Width - icon - Theme.S(20)) / 2, y - Theme.S(10), icon + Theme.S(20), icon + Theme.S(20));
                using (var b = new SolidBrush(Theme.SurfaceAlt)) g.FillEllipse(b, chip);
                var glyph = icon - Theme.S(8);
                Icons.Draw(g, EmptyIcon, new RectangleF(chip.X + (chip.Width - glyph) / 2f, chip.Y + (chip.Height - glyph) / 2f, glyph, glyph), Theme.LineStrong, 1.8f);
                y += icon + Theme.S(16);
            }
            TextRenderer.DrawText(g, EmptyText, Theme.Ui, new Rectangle(Theme.S(20), y, Width - Theme.S(40), textSize.Height + Theme.S(4)), Theme.InkMuted, TextFormatFlags.NoPrefix | TextFormatFlags.HorizontalCenter | TextFormatFlags.WordBreak);
        }
    }

    protected override void OnDataError(bool displayErrorDialogIfNoHandler, DataGridViewDataErrorEventArgs e)
    {
        e.ThrowException = false;
    }

    /// <summary>Fast fill from a report result (thousands of rows).</summary>
    public void SetTable(ReportResult r)
    {
        Columns.Clear();
        var table = new DataTable();
        foreach (var c in r.Columns)
        {
            var kind = c.Type switch
            {
                "money" => ColKind.Money,
                "int" => ColKind.Int,
                "percent" => ColKind.Percent,
                "date" => ColKind.Mono,
                "datetime" => ColKind.Mono,
                _ => ColKind.Text,
            };
            var col = Col(c.Name, c.Type == "text" ? 0 : (c.Type == "datetime" ? 150 : 120), kind);
            col.DataPropertyName = "c" + table.Columns.Count;
            if (kind == ColKind.Money) col.DefaultCellStyle.Format = "#,##0.00";
            table.Columns.Add("c" + table.Columns.Count, kind == ColKind.Money || kind == ColKind.Int || kind == ColKind.Percent ? typeof(decimal) : typeof(string));
        }
        foreach (var row in r.Rows)
        {
            var values = new object[r.Columns.Count];
            for (int i = 0; i < values.Length; i++)
            {
                var v = i < row.Count ? row[i] : "";
                if (table.Columns[i].DataType == typeof(decimal))
                    values[i] = decimal.TryParse(v, NumberStyles.Any, CultureInfo.InvariantCulture, out var d) ? d : (object)DBNull.Value;
                else values[i] = v;
            }
            table.Rows.Add(values);
        }
        DataSource = table;
    }
}
