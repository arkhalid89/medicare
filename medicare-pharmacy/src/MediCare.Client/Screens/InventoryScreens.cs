namespace MediCare.Client.Screens;

/// <summary>SCR-11 Medicines: search the catalogue with stock and status.</summary>
public sealed class MedicinesScreen : AppScreen
{
    private readonly UiText _q = new UiText("Name, generic, barcode or code");
    private readonly UiCheck _inactive = new UiCheck("Show inactive");
    private readonly Grid _grid = new Grid();
    private readonly Label _count = new UiLabel("", Theme.Small, Theme.InkMuted);
    private readonly Timer _timer = new Timer { Interval = 300 };

    public override string Title => "Medicines";
    public override string Subtitle => "The medicine list, with prices and saleable stock.";

    public override IEnumerable<FKey> FKeys => new[]
    {
        new FKey(Keys.F2, "Search", () => _q.Focus()),
        new FKey(Keys.Control | Keys.N, "New medicine", () => Fire(New)) { Enabled = () => Session.Can(Perm.MedicinesEdit) },
        new FKey(Keys.Enter, "Edit", () => Fire(Edit)),
        new FKey(Keys.F7, "Batches", () => Fire(Batches)),
        new FKey(Keys.Control | Keys.I, "Import list", () => Fire(Import)) { Enabled = () => Session.Can(Perm.MedicinesImport) },
        new FKey(Keys.Control | Keys.P, "Print", () => TablePrinter.Print(FindForm()!, PrintTable.FromGrid(_grid, "Medicines", _q.Text))),
    };

    public MedicinesScreen()
    {
        _grid.Col("Code", 90, ColKind.Mono);
        _grid.Col("Medicine", 0, ColKind.Text, 220);
        _grid.Col("Generic", 0, ColKind.Text, 150);
        _grid.Col("Manufacturer", 140);
        _grid.Col("Form", 90);
        _grid.Col("Pack", 90);
        _grid.Col("Price", 90, ColKind.Money);
        _grid.Col("Saleable stock", 160);
        _grid.Col("Sch.", 50);
        _grid.RowTone = o => o is MedicineListItem m ? (!m.IsActive ? Tone.Muted : m.StockQty <= 0 ? Tone.Danger : m.MinStockQty > 0 && m.StockQty < m.MinStockQty ? Tone.Warn : Tone.None) : Tone.None;
        _grid.RowActivated += _ => Fire(Edit);
        _q.TextChanged += (_, _) => { _timer.Stop(); _timer.Start(); };
        _q.KeyDown += (_, e) => { if (e.KeyCode == Keys.Down) { _grid.Focus(); e.Handled = true; } };
        _timer.Tick += (_, _) => { _timer.Stop(); Fire(Fill); };
        _inactive.CheckedChanged += (_, _) => Fire(Fill);
        var add = new UiButton("New medicine", ButtonKind.Accent, "Ctrl+N") { Enabled = Session.Can(Perm.MedicinesEdit) };
        add.Click += (_, _) => Fire(New);
        var import = new UiButton("Import list…", ButtonKind.Secondary) { Enabled = Session.Can(Perm.MedicinesImport) };
        import.Click += (_, _) => Fire(Import);
        var card = new Card { Dock = DockStyle.Fill, Padding = new Padding(1) };
        card.Controls.Add(_grid);
        Compose(Box.Toolbar(new Control[] { _q.Sized(360), _inactive, _count }, new Control[] { import, add }), Box.Gap(6), card);
    }

    public override void FocusFirst() => _q.Focus();
    protected override Task OnFirstShow() => Fill();

    private async Task Fill()
    {
        var list = await Session.Api.Get<List<MedicineListItem>>("medicines", new { q = _q.Text.Trim(), includeInactive = _inactive.Checked, limit = 500 });
        _grid.SetRows(list, m => new object?[] { m.Code, m.Name + (m.Strength.Length > 0 && !m.Name.Has(m.Strength) ? " " + m.Strength : ""), m.Generic, m.Manufacturer, m.DosageForm, m.DefaultPack, m.SalePrice, m.StockQty > 0 ? m.StockText : "Out of stock", m.Schedule });
        _count.Text = list.Count >= 500 ? "Showing the first 500. Type to narrow down." : $"{list.Count} medicines";
    }

    private async Task New()
    {
        using var d = new MedicineEditorDialog(null);
        if (d.ShowDialog(FindForm()) == DialogResult.OK) await Fill();
    }

    private async Task Edit()
    {
        var m = _grid.Current<MedicineListItem>();
        if (m == null) return;
        var med = await Session.Api.Get<MedicineDto>($"medicines/{m.Id}");
        if (!Session.Can(Perm.MedicinesEdit)) { Msg.Info(FindForm(), $"{med.Name}\n{med.Generic} {med.Strength}\nPacks: {string.Join(", ", med.Packs.Select(p => $"{p} Rs {Money.Format(p.SalePrice)}"))}\nRack {med.Rack}"); return; }
        using var d = new MedicineEditorDialog(med);
        if (d.ShowDialog(FindForm()) == DialogResult.OK) await Fill();
    }

    private async Task Batches()
    {
        var m = _grid.Current<MedicineListItem>();
        if (m == null) return;
        var med = await Session.Api.Get<MedicineDto>($"medicines/{m.Id}");
        using var d = new BatchPickDialog(med, null);
        d.ShowDialog(FindForm());
    }

    private async Task Import()
    {
        using var d = new ImportDialog();
        d.ShowDialog(FindForm());
        await Fill();
    }
}

/// <summary>SCR-14 Batches and stock: by state, expiry band and low stock; quarantine, write-off, movements.</summary>
public sealed class BatchesScreen : AppScreen
{
    private readonly UiCombo _view = new UiCombo();
    private readonly UiText _q = new UiText("Medicine or batch");
    private readonly IntBox _window = new IntBox();
    private readonly Grid _grid = new Grid();
    private readonly Label _summary = new UiLabel("", Theme.Small, Theme.InkMuted);

    private static readonly (string key, string label)[] Views =
    {
        ("ALL", "All stock"), ("SALEABLE", "Saleable"), ("NEAR", "Near expiry"), ("EXPIRED", "Expired (blocked)"), ("QUARANTINE", "Quarantine"), ("LOW", "Below minimum"),
    };

    public override string Title => "Batches and stock";
    public override string Subtitle => "Every batch with expiry, quantity and value. Expired stock cannot be sold.";

    public override IEnumerable<FKey> FKeys => new[]
    {
        new FKey(Keys.F5, "Refresh", () => Fire(Fill)),
        new FKey(Keys.F6, "Movements", () => Fire(Movements)),
        new FKey(Keys.F7, "To quarantine", () => Fire(() => Quarantine("MOVE"))) { Enabled = () => Session.Can(Perm.StockAdjust) },
        new FKey(Keys.F8, "Write off", () => Fire(() => Quarantine("WRITE_OFF"))) { Enabled = () => Session.Can(Perm.StockAdjust) },
        new FKey(Keys.F9, "Adjust qty", () => Fire(Adjust)) { Enabled = () => Session.Can(Perm.StockAdjust) },
        new FKey(Keys.Control | Keys.P, "Print", () => TablePrinter.Print(FindForm()!, PrintTable.FromGrid(_grid, "Stock: " + _view.Text, Dates.Display(DateTime.Today)))),
    };

    public BatchesScreen()
    {
        _view.SetItems(Views.Select(v => v.label));
        _view.SelectedIndexChanged += (_, _) => Fire(Fill);
        _window.Value = Session.Settings.NearExpiryDays;
        _grid.Col("Medicine", 0, ColKind.Text, 220);
        _grid.Col("Batch", 120, ColKind.Mono);
        _grid.Col("Expiry", 110, ColKind.Date);
        _grid.Col("Days", 70, ColKind.Int);
        _grid.Col("State", 120);
        _grid.Col("Quantity", 0, ColKind.Text, 140);
        _grid.Col("Rack", 70);
        _grid.Col("Cost value", 110, ColKind.Money);
        _grid.Col("Supplier", 140);
        _grid.RowTone = o => o is BatchRow b ? b.State switch { "EXPIRED" => Tone.Danger, "QUARANTINE" => Tone.Muted, "NEAR EXPIRY" => Tone.Warn, "LOW" => Tone.Warn, _ => Tone.None } : Tone.None;
        _grid.RowActivated += _ => Fire(Movements);
        _q.KeyDown += (_, e) => { if (e.KeyCode == Keys.Enter) { e.SuppressKeyPress = true; Fire(Fill); } };
        var show = new UiButton("Show", ButtonKind.Accent);
        show.Click += (_, _) => Fire(Fill);
        var card = new Card { Dock = DockStyle.Fill, Padding = new Padding(1) };
        card.Controls.Add(_grid);
        Compose(Box.Toolbar(new Control[] { _view.Sized(200), _q.Sized(260), Box.Caption("Near expiry within"), _window.Sized(70), Box.Caption("days"), show }), _summary.Docked(DockStyle.Top), Box.Gap(6), card);
    }

    public override void FocusFirst() => _q.Focus();
    protected override Task OnFirstShow() { _view.SelectedIndex = 0; return Task.CompletedTask; }
    protected override Task OnReshow() => Fill();

    /// <summary>Opens the screen on a view (e.g. from the dashboard's near-expiry tile).</summary>
    public void ShowView(string key)
    {
        var i = Array.FindIndex(Views, v => v.key == key);
        if (i >= 0) _view.SelectedIndex = i;
    }

    private async Task Fill()
    {
        if (_view.SelectedIndex < 0) return;
        var key = Views[_view.SelectedIndex].key;
        var list = await Session.Api.Get<List<BatchRow>>("batches", new { view = key, q = _q.Text.Trim(), window = _window.Value > 0 ? _window.Value : (int?)null });
        _grid.SetRows(list, b => new object?[] { b.Medicine, b.BatchNo, b.BatchNo.Length == 0 ? null : b.Expiry, b.BatchNo.Length == 0 ? null : b.DaysLeft, b.State, b.QtyText, b.Rack, b.CostValue, b.Supplier });
        _summary.Text = $"{list.Count} rows · cost value {Money.FormatRs(list.Sum(b => b.CostValue))}" + (list.Count >= 5000 ? " · first 5,000 shown" : "");
    }

    private BatchRow? Current => _grid.Current<BatchRow>();

    private async Task Movements()
    {
        var b = Current;
        if (b == null || b.BatchId == 0) return;
        var list = await Session.Api.Get<List<MovementRow>>($"batches/{b.BatchId}/movements");
        using var d = new GridDialog($"Movements: {b.Medicine} batch {b.BatchNo}", 860);
        d.Grid.Col("Date", 150, ColKind.DateTime);
        d.Grid.Col("Type", 120);
        d.Grid.Col("Document", 170, ColKind.Mono);
        d.Grid.Col("State", 110);
        d.Grid.Col("Qty", 90, ColKind.Int);
        d.Grid.Col("By", 0, ColKind.Text, 140);
        d.Grid.SetRows(list, m => new object?[] { m.At, m.Type, m.DocNo, m.State, m.QtyDelta, m.User });
        d.ShowDialog(FindForm());
    }

    private async Task Quarantine(string action)
    {
        var b = Current;
        if (b == null || b.BatchId == 0) { Bar.Show("Choose a batch first.", Tone.Warn); return; }
        if (action == "WRITE_OFF" && b.State != "QUARANTINE") { Bar.Show("Write-off is done from quarantine. Move the stock to quarantine first (F7), then write it off.", Tone.Warn); return; }
        if (action == "MOVE" && b.State == "QUARANTINE") { Bar.Show("This stock is already in quarantine.", Tone.Warn); return; }
        using var d = new QuarantineDialog(b, action);
        if (d.ShowDialog(FindForm()) == DialogResult.OK) await Fill();
    }

    private async Task Adjust()
    {
        var b = Current;
        if (b == null || b.BatchId == 0) { Bar.Show("Choose a batch first.", Tone.Warn); return; }
        using var d = new AdjustDialog(b);
        if (d.ShowDialog(FindForm()) == DialogResult.OK) await Fill();
    }
}

/// <summary>Read-only grid in a dialog (movements, history).</summary>
public sealed class GridDialog : DialogForm
{
    public Grid Grid { get; } = new Grid();

    public GridDialog(string title, int width) : base(title, width)
    {
        var host = new Panel { Dock = DockStyle.Top, Height = Theme.S(380) };
        host.Controls.Add(Grid);
        Body.Stack(host);
        AddButton("Print", () => TablePrinter.Print(this, PrintTable.FromGrid(Grid, title)), ButtonKind.Secondary);
        var close = AddButton("Close", Close, ButtonKind.Primary, "Esc");
        CancelButton = close;
        FitToContent();
    }
}

/// <summary>Move saleable stock to quarantine, or write quarantined stock off as a loss.</summary>
public sealed class QuarantineDialog : DialogForm
{
    private readonly BatchRow _b;
    private readonly string _action;
    private readonly IntBox _qty = new IntBox();
    private readonly UiCombo _reason = new UiCombo().Editable();
    private readonly string _key = Compat.NewKey();

    public QuarantineDialog(BatchRow b, string action) : base(action == "MOVE" ? "Move to quarantine" : "Write off from quarantine", 520)
    {
        _b = b;
        _action = action;
        _qty.Value = b.Qty;
        var f = new FieldGrid(2);
        f.AddFull(new UiLabel($"{b.Medicine} · batch {b.BatchNo} · expiry {Dates.Display(b.Expiry)} · {b.QtyText}", Theme.UiBold).Wrap(Theme.S(460)));
        f.Add("Quantity (base units)", _qty);
        f.Add("Reason", _reason);
        f.AddFull(new UiLabel(action == "MOVE" ? "Quarantined stock cannot be sold. It can be returned to the supplier or written off." : "Write-off removes the stock at its cost and records a loss. It may need a manager's approval.", Theme.Small, Theme.InkMuted).Wrap(Theme.S(460)));
        Body.Stack(f);
        AddOk(action == "MOVE" ? "Move to quarantine" : "Write off", Post, action == "MOVE" ? ButtonKind.Primary : ButtonKind.Danger);
        AddCancel();
        FitToContent();
        Load += (_, _) => UiRun.Fire(this, async () => _reason.SetItems((await Session.Api.Get<LookupListDto>($"lists/{ListKinds.AdjustmentReason}")).Values.Concat(new[] { "Expired", "Damaged", "Recalled by manufacturer" }).Distinct()), Bar);
    }

    private async Task<bool> Post()
    {
        if (_qty.Value <= 0 || _qty.Value > _b.Qty) { Fail($"Quantity must be between 1 and {_b.Qty}."); return false; }
        if (_reason.Text.Trim().Length == 0) { Fail("Enter the reason."); return false; }
        var req = new QuarantineRequest { IdempotencyKey = _key, BatchId = _b.BatchId, Qty = _qty.Value, Reason = _reason.Text.Trim(), Action = _action };
        var r = await Approvals.Run(this, t => { req.ApprovalToken = t.LastOrDefault(); return Session.Api.Post<OkResult>("stock/quarantine", req, t); });
        Msg.Info(this, r.Message ?? "Done.");
        return true;
    }
}

/// <summary>Set a batch's physical quantity; the difference is posted at average cost (FR-080).</summary>
public sealed class AdjustDialog : DialogForm
{
    private readonly BatchRow _b;
    private readonly IntBox _physical = new IntBox();
    private readonly UiCombo _reason = new UiCombo().Editable();
    private readonly UiText _notes = new UiText();
    private readonly Label _preview = new UiLabel("", Theme.Ui, Theme.InkMuted);
    private readonly string _key = Compat.NewKey();

    public AdjustDialog(BatchRow b) : base("Stock adjustment", 540)
    {
        _b = b;
        _physical.Value = b.Qty;
        var f = new FieldGrid(2);
        f.AddFull(new UiLabel($"{b.Medicine} · batch {b.BatchNo} · system quantity {b.Qty} ({b.QtyText}) · {(b.State == "QUARANTINE" ? "quarantine" : "saleable")}", Theme.UiBold).Wrap(Theme.S(480)));
        f.Add("Physical quantity counted (base units)", _physical);
        f.Add("Reason", _reason);
        f.Add("Notes", _notes, 2);
        f.AddFull(_preview);
        Body.Stack(f);
        _physical.TextChanged += (_, _) => UiRun.Fire(this, Preview);
        AddOk("Post adjustment", Post);
        AddCancel();
        FitToContent();
        Load += (_, _) => UiRun.Fire(this, async () => _reason.SetItems((await Session.Api.Get<LookupListDto>($"lists/{ListKinds.AdjustmentReason}")).Values), Bar);
    }

    private AdjustmentRequest Request() => new AdjustmentRequest
    {
        IdempotencyKey = _key, Reason = _reason.Text.Trim(), Notes = _notes.Text.Trim(),
        Lines = new List<AdjustmentLineInput> { new AdjustmentLineInput { BatchId = _b.BatchId, State = _b.State == "QUARANTINE" ? StockStates.Quarantine : StockStates.Saleable, PhysicalQty = _physical.Value } },
    };

    private async Task Preview()
    {
        if (_physical.Value == _b.Qty) { _preview.Text = "No difference."; return; }
        var p = await Session.Api.Post<AdjustmentPreview>("stock/adjustments/preview", Request());
        _preview.Text = $"Difference {_physical.Value - _b.Qty:+#;-#;0} units · value {Money.FormatRs(p.Value)}" + (p.NeedsApproval ? " · needs a manager's approval" : "");
        _preview.ForeColor = p.NeedsApproval ? Theme.Warn : Theme.InkMuted;
    }

    private async Task<bool> Post()
    {
        if (_physical.Value == _b.Qty) { Fail("The physical quantity equals the system quantity. Nothing to adjust."); return false; }
        if (_reason.Text.Trim().Length == 0) { Fail("Choose the reason."); return false; }
        var req = Request();
        var r = await Approvals.Run(this, t => { req.ApprovalToken = t.LastOrDefault(); return Session.Api.Post<OkResult>("stock/adjustments", req, t); });
        Msg.Info(this, r.Message ?? $"Adjustment {r.No} posted.");
        return true;
    }
}

/// <summary>SCR-23 Stock counts: freeze, count, review and post differences.</summary>
public sealed class StockCountsScreen : AppScreen
{
    private readonly Grid _grid = new Grid();

    public override string Title => "Stock counts";
    public override string Subtitle => "Count a rack or category; differences are posted as one adjustment.";

    public override IEnumerable<FKey> FKeys => new[]
    {
        new FKey(Keys.Control | Keys.N, "New count", () => Fire(New)),
        new FKey(Keys.Enter, "Open", () => Fire(Open)),
        new FKey(Keys.F5, "Refresh", () => Fire(Fill)),
    };

    public StockCountsScreen()
    {
        _grid.Col("Count", 150, ColKind.Mono);
        _grid.Col("Scope", 0, ColKind.Text, 200);
        _grid.Col("Started", 150, ColKind.DateTime);
        _grid.Col("Status", 120);
        _grid.RowActivated += _ => Fire(Open);
        var add = new UiButton("New count", ButtonKind.Accent, "Ctrl+N");
        add.Click += (_, _) => Fire(New);
        var card = new Card { Dock = DockStyle.Fill, Padding = new Padding(1) };
        card.Controls.Add(_grid);
        Compose(Box.Toolbar(new Control[] { add }), Box.Gap(6), card);
    }

    protected override Task OnFirstShow() => Fill();

    private async Task Fill()
    {
        var list = await Session.Api.Get<List<StockCountDto>>("stock/counts");
        _grid.SetRows(list, c => new object?[] { c.No, c.Scope, c.FrozenAt, c.Status });
    }

    private async Task New()
    {
        var rack = Msg.Ask(FindForm(), "New stock count", "Rack prefix to count (e.g. A for racks A-01, A-02…). Leave empty for all racks.", required: false);
        if (rack == null) return;
        var count = await Session.Api.Post<StockCountDto>("stock/counts", new StockCountCreate { RackPrefix = rack });
        await Fill();
        using var d = new CountDialog(count.Id);
        d.ShowDialog(FindForm());
        await Fill();
    }

    private async Task Open()
    {
        var c = _grid.Current<StockCountDto>();
        if (c == null) return;
        using var d = new CountDialog(c.Id);
        d.ShowDialog(FindForm());
        await Fill();
    }
}

/// <summary>Enter counted quantities; save progress; post the differences.</summary>
public sealed class CountDialog : DialogForm
{
    private readonly long _id;
    private readonly Grid _grid = new Grid();
    private readonly Label _head = new UiLabel("", Theme.UiBold);
    private readonly string _key = Compat.NewKey();
    private StockCountDto? _count;

    public CountDialog(long id) : base("Stock count", 980)
    {
        _id = id;
        _grid.ReadOnly = false;
        _grid.EditMode = DataGridViewEditMode.EditOnKeystrokeOrF2;
        _grid.Col("Rack", 80).ReadOnly = true;
        _grid.Col("Medicine", 0, ColKind.Text, 240).ReadOnly = true;
        _grid.Col("Batch", 120, ColKind.Mono).ReadOnly = true;
        _grid.Col("System (frozen)", 130, ColKind.Int).ReadOnly = true;
        _grid.Col("Counted", 110, ColKind.Int);
        _grid.Col("Difference", 110, ColKind.Int).ReadOnly = true;
        _grid.Col("Moved since freeze", 140, ColKind.Int).ReadOnly = true;
        foreach (DataGridViewColumn c in _grid.Columns) c.SortMode = DataGridViewColumnSortMode.NotSortable;
        _grid.CellEndEdit += (_, e) => Diff(e.RowIndex);
        var host = new Panel { Dock = DockStyle.Top, Height = Theme.S(400) };
        host.Controls.Add(_grid);
        Body.Stack(_head, Box.Gap(4), new UiLabel("Type the counted quantity in base units. Leave empty for batches not counted yet. Sales during the count are shown in the last column.", Theme.Small, Theme.InkMuted), Box.Gap(6), host);
        AddOk("Post differences", Post, ButtonKind.Danger);
        AddButton("Save progress", () => UiRun.Fire(this, Save, Bar), ButtonKind.Secondary, "Ctrl+S");
        AddCancel("Close");
        AddButton("Cancel count", () => UiRun.Fire(this, CancelCount, Bar), ButtonKind.Ghost);
        FitToContent();
        Load += (_, _) => UiRun.Fire(this, Fill, Bar);
    }

    private async Task Fill()
    {
        _count = await Session.Api.Get<StockCountDto>($"stock/counts/{_id}");
        Text = "Stock count " + _count.No;
        _head.Text = $"{_count.No} · {_count.Scope} · frozen {Dates.DisplayTime(_count.FrozenAt)} · {_count.Status}";
        _grid.Rows.Clear();
        foreach (var i in _count.Items.OrderBy(i => i.Rack).ThenBy(i => i.Medicine))
        {
            var r = _grid.AddRow(i.Rack, i.Medicine, i.BatchNo, i.FrozenQty, i.CountedQty, i.CountedQty.HasValue ? i.CountedQty - i.FrozenQty : null, i.MovementsSince == 0 ? null : i.MovementsSince);
            _grid.Rows[r].Tag = i;
        }
        var open = _count.Status == "OPEN";
        _grid.ReadOnly = !open;
    }

    private void Diff(int row)
    {
        if (row < 0 || _grid.Rows[row].Tag is not StockCountItem i) return;
        var v = Convert.ToString(_grid.Rows[row].Cells[4].Value, CultureInfo.InvariantCulture);
        i.CountedQty = int.TryParse(v, out var q) && q >= 0 ? q : (int?)null;
        _grid.Rows[row].Cells[5].Value = i.CountedQty.HasValue ? i.CountedQty - i.FrozenQty : null;
    }

    private async Task Save()
    {
        _grid.EndEdit();
        if (_count == null) return;
        await Session.Api.Put<OkResult>($"stock/counts/{_id}", new StockCountSave { Items = _count.Items });
        Fail("Progress saved.", Tone.Ok);
    }

    private async Task<bool> Post()
    {
        await Save();
        if (_count == null) return false;
        var diffs = _count.Items.Count(i => i.CountedQty.HasValue && i.CountedQty != i.FrozenQty);
        if (!Msg.Confirm(this, $"Post the count? {diffs} batch(es) differ and will be adjusted at average cost. Batches not counted are left unchanged.")) return false;
        var req = new PostRequest { IdempotencyKey = _key };
        var r = await Approvals.Run(this, t => { req.ApprovalTokens = t.ToList(); return Session.Api.Post<OkResult>($"stock/counts/{_id}/post", req, t); });
        Msg.Info(this, r.Message ?? "Count posted.");
        return true;
    }

    private async Task CancelCount()
    {
        if (!Msg.Confirm(this, "Cancel this count? Nothing is adjusted.")) return;
        await Session.Api.Post<OkResult>($"stock/counts/{_id}/cancel", new { });
        Close();
    }

    protected override bool ProcessCmdKey(ref Message msg, Keys keyData)
    {
        if (keyData == (Keys.Control | Keys.S)) { UiRun.Fire(this, Save, Bar); return true; }
        return base.ProcessCmdKey(ref msg, keyData);
    }
}
