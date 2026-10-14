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
                r.CreateCells(this, map(list[i]));
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
        if (e.RowIndex >= 0 && Rows[e.RowIndex].Tag != null) RowActivated?.Invoke(Rows[e.RowIndex].Tag);
    }

    protected override void OnPaint(PaintEventArgs e)
    {
        base.OnPaint(e);
        if (RowCount == 0 && EmptyText.Length > 0)
        {
            var r = new Rectangle(0, ColumnHeadersHeight, Width, Math.Max(Height - ColumnHeadersHeight, Theme.S(40)));
            TextRenderer.DrawText(e.Graphics, EmptyText, Theme.Ui, r, Theme.InkMuted, TextFormatFlags.HorizontalCenter | TextFormatFlags.VerticalCenter | TextFormatFlags.WordBreak);
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
