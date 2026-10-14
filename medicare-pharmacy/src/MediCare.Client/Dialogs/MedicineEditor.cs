namespace MediCare.Client.Dialogs;

/// <summary>SCR-12 Medicine editor: master data, pack levels and prices, barcodes, flags, Urdu name.</summary>
public sealed class MedicineEditorDialog : DialogForm
{
    private readonly MedicineDto _m;
    private readonly bool _isNew;
    private readonly UiText _name = new UiText();
    private readonly UiText _generic = new UiText("e.g. Omeprazole");
    private readonly UiText _brand = new UiText();
    private readonly UiCombo _mfr = new UiCombo().Editable();
    private readonly UiCombo _category = new UiCombo().Editable();
    private readonly UiCombo _form = new UiCombo().Editable();
    private readonly UiText _strength = new UiText("e.g. 20mg, 120mg/5ml");
    private readonly UiCombo _unit = new UiCombo().Editable();
    private readonly UiText _urdu = new UiText();
    private readonly UiText _rack = new UiText("e.g. A-12");
    private readonly UiCombo _schedule = new UiCombo();
    private readonly UiCheck _rx = new UiCheck("Prescription required");
    private readonly UiCheck _active = new UiCheck("Active (can be sold and purchased)", true);
    private readonly MoneyBox _tax = new MoneyBox();
    private readonly IntBox _min = new IntBox();
    private readonly MoneyBox _cost = new MoneyBox { Decimals = 4 };
    private readonly UiCombo _defaultPack = new UiCombo();
    private readonly UiCombo _purchasePack = new UiCombo();
    private readonly Grid _packs = new Grid();
    private readonly Grid _barcodes = new Grid();
    private readonly UiText _scan = new UiText("Scan a barcode here to add it");
    private readonly Dictionary<long, decimal> _oldPrices;
    private DataGridViewComboBoxColumn _barcodePack = null!;
    public long? SavedId { get; private set; }

    public MedicineEditorDialog(MedicineDto? existing) : base(existing == null ? "New medicine" : $"{existing.Name} ({existing.Code})", 900)
    {
        _isNew = existing == null;
        _m = existing ?? new MedicineDto { Packs = new List<PackDto> { new PackDto { Name = "Strip", BaseQty = 10, IsSellable = true }, new PackDto { Name = "Tablet", BaseQty = 1, IsSellable = true } }, BaseUnit = "Tablet" };
        _oldPrices = _m.Packs.Where(p => p.Id > 0).ToDictionary(p => p.Id, p => p.SalePrice);
        var tabs = new TabControl { Dock = DockStyle.Top, Height = Theme.S(470), Font = Theme.Ui, Padding = new Point(Theme.S(14), Theme.S(6)) };

        // ---- details ----
        var details = new TabPage("Details") { BackColor = Theme.Canvas, Padding = new Padding(Theme.S(12)) };
        var f = new FieldGrid(3);
        f.Add("Medicine name (as on the pack)", _name, 2);
        f.Add("Strength", _strength);
        f.Add("Generic name", _generic, 2);
        f.Add("Dosage form", _form);
        f.Add("Brand", _brand);
        f.Add("Manufacturer", _mfr);
        f.Add("Category", _category);
        f.Add("Base unit (smallest unit sold)", _unit, hint: "Tablet, capsule, bottle, tube…");
        f.Add("Rack / shelf", _rack);
        f.Add("Urdu name (receipts)", _urdu);
        f.Add("Drug schedule", _schedule, hint: "B and D are recorded in the controlled register");
        f.Add("Sales tax %", _tax);
        f.Add("Minimum stock (base units)", _min, hint: "Low-stock alert below this");
        f.AddFull(_rx);
        f.AddFull(_active);
        details.Controls.Add(f);
        _urdu.RightToLeft = RightToLeft.Yes;
        _urdu.Font = Theme.Urdu(16);

        // ---- packs and prices ----
        var packsPage = new TabPage("Packs and prices") { BackColor = Theme.Canvas, Padding = new Padding(Theme.S(12)) };
        _packs.ReadOnly = false;
        _packs.AllowUserToAddRows = true;
        _packs.AllowUserToDeleteRows = true;
        _packs.EditMode = DataGridViewEditMode.EditOnKeystrokeOrF2;
        _packs.Col("Pack name", 0, ColKind.Text, 160);
        _packs.Col("Contains (base units)", 170, ColKind.Int);
        _packs.Col("Sellable", 90, ColKind.Check);
        _packs.Col("Sale price (Rs)", 150, ColKind.Money);
        foreach (DataGridViewColumn c in _packs.Columns) c.SortMode = DataGridViewColumnSortMode.NotSortable;
        _packs.CellEndEdit += (_, _) => RefreshPackCombos();
        _packs.UserDeletedRow += (_, _) => RefreshPackCombos();
        var packHost = new Panel { Dock = DockStyle.Top, Height = Theme.S(220) };
        packHost.Controls.Add(_packs);
        var pf = new FieldGrid(3);
        pf.Add("Default pack at the counter", _defaultPack);
        pf.Add("Purchase pack (supplier bills)", _purchasePack);
        pf.Add("Default purchase cost per base unit", _cost);
        packsPage.Stack(new UiLabel("Example: Box = 30, Strip = 10, Tablet = 1. Prices are per pack. A pack of 1 base unit is added automatically if missing.", Theme.Small, Theme.InkMuted), Box.Gap(6), packHost, Box.Gap(10), pf);

        // ---- barcodes ----
        var codesPage = new TabPage("Barcodes") { BackColor = Theme.Canvas, Padding = new Padding(Theme.S(12)) };
        _barcodes.ReadOnly = false;
        _barcodes.AllowUserToAddRows = true;
        _barcodes.AllowUserToDeleteRows = true;
        _barcodes.Col("Barcode / GTIN", 0, ColKind.Mono, 200);
        _barcodePack = new DataGridViewComboBoxColumn { HeaderText = "PACK IT IDENTIFIES", Width = Theme.S(220), FlatStyle = FlatStyle.Flat };
        _barcodes.Columns.Add(_barcodePack);
        var codeHost = new Panel { Dock = DockStyle.Top, Height = Theme.S(260) };
        codeHost.Controls.Add(_barcodes);
        _scan.Dock = DockStyle.Top;
        _scan.Height = Theme.ControlHeight;
        _scan.KeyDown += (_, e) =>
        {
            if (e.KeyCode != Keys.Enter) return;
            e.SuppressKeyPress = true;
            var code = _scan.Text.Trim();
            if (Gs1Parser.IsCandidate(code)) { var g = Gs1Parser.Parse(code); if (g.Ok) code = g.Gtin; }
            if (code.Length > 0 && !_barcodes.Rows.Cast<DataGridViewRow>().Any(r => Convert.ToString(r.Cells[0].Value) == code)) _barcodes.Rows.Add(code, "");
            _scan.Clear();
        };
        KeepsEnter(_scan);
        codesPage.Stack(new UiLabel("Each barcode belongs to one medicine. For 2D (GS1 DataMatrix) packs, scan the box: the product code (GTIN) is stored.", Theme.Small, Theme.InkMuted), Box.Gap(6), _scan, Box.Gap(6), codeHost);

        tabs.TabPages.AddRange(new[] { details, packsPage, codesPage });
        Body.Stack(tabs);
        AddOk("Save medicine", Save);
        AddCancel();
        FitToContent(560);
        Load += (_, _) => UiRun.Fire(this, LoadData, Bar);
    }

    private async Task LoadData()
    {
        async Task<List<string>> L(string kind) => (await Session.Api.Get<LookupListDto>($"lists/{kind}")).Values;
        _mfr.SetItems(await L(ListKinds.Manufacturer));
        _category.SetItems(await L(ListKinds.Category));
        _form.SetItems(await L(ListKinds.DosageForm));
        _unit.SetItems(await L(ListKinds.Unit));
        _schedule.SetItems(new[] { "(none)", "B", "D", "G" });

        _name.Text = _m.Name; _generic.Text = _m.Generic; _brand.Text = _m.Brand; _mfr.Text = _m.Manufacturer; _category.Text = _m.Category;
        _form.Text = _m.DosageForm; _strength.Text = _m.Strength; _unit.Text = _m.BaseUnit; _urdu.Text = _m.UrduName; _rack.Text = _m.Rack;
        _schedule.SelectedItem = _m.Schedule.Length == 0 ? "(none)" : _m.Schedule;
        _rx.Checked = _m.RxRequired; _active.Checked = _m.IsActive; _tax.Value = _m.TaxRatePercent; _min.Value = _m.MinStockQty; _cost.Value = _m.DefaultPurchaseCost;
        foreach (var p in _m.Packs.OrderByDescending(p => p.BaseQty))
        {
            var i = _packs.Rows.Add(p.Name, p.BaseQty, p.IsSellable, p.SalePrice);
            _packs.Rows[i].Tag = p;
        }
        RefreshPackCombos();
        _defaultPack.SelectedItem = _m.DefaultSalePack;
        _purchasePack.SelectedItem = _m.PurchasePack;
        foreach (var b in _m.Barcodes) _barcodes.Rows.Add(b.Code, b.PackName ?? "");
    }

    private List<PackDto> ReadPacks()
    {
        var list = new List<PackDto>();
        foreach (DataGridViewRow r in _packs.Rows)
        {
            if (r.IsNewRow) continue;
            var name = Convert.ToString(r.Cells[0].Value)?.Trim() ?? "";
            if (name.Length == 0) continue;
            var old = r.Tag as PackDto;
            list.Add(new PackDto
            {
                Id = old?.Id ?? 0,
                Name = name,
                BaseQty = int.TryParse(Convert.ToString(r.Cells[1].Value, CultureInfo.InvariantCulture), out var q) ? q : 0,
                IsSellable = r.Cells[2].Value is bool b && b,
                SalePrice = Money.Parse(Convert.ToString(r.Cells[3].Value, CultureInfo.InvariantCulture)),
            });
        }
        return list;
    }

    private void RefreshPackCombos()
    {
        var names = ReadPacks().Select(p => p.Name).ToList();
        var d = _defaultPack.SelectedItem as string;
        var p = _purchasePack.SelectedItem as string;
        _defaultPack.SetItems(names);
        _purchasePack.SetItems(names);
        if (d != null && names.Contains(d)) _defaultPack.SelectedItem = d;
        if (p != null && names.Contains(p)) _purchasePack.SelectedItem = p;
        _barcodePack.Items.Clear();
        _barcodePack.Items.Add("");
        foreach (var n in names) _barcodePack.Items.Add(n);
    }

    private async Task<bool> Save()
    {
        _packs.EndEdit();
        _barcodes.EndEdit();
        if (_name.Text.Trim().Length == 0) { Fail("Enter the medicine name."); _name.Focus(); return false; }
        var packs = ReadPacks();
        if (packs.Any(p => p.BaseQty < 1)) { Fail("Every pack must contain at least 1 base unit."); return false; }
        _m.Name = _name.Text.Trim(); _m.Generic = _generic.Text.Trim(); _m.Brand = _brand.Text.Trim(); _m.Manufacturer = _mfr.Text.Trim();
        _m.Category = _category.Text.Trim(); _m.DosageForm = _form.Text.Trim(); _m.Strength = _strength.Text.Trim(); _m.BaseUnit = _unit.Text.Trim();
        _m.UrduName = _urdu.Text.Trim(); _m.Rack = _rack.Text.Trim(); _m.Schedule = _schedule.SelectedItem as string == "(none)" ? "" : _schedule.SelectedItem as string ?? "";
        _m.RxRequired = _rx.Checked || _m.Schedule.Length > 0; _m.IsActive = _active.Checked; _m.TaxRatePercent = _tax.Value; _m.MinStockQty = _min.Value; _m.DefaultPurchaseCost = _cost.Value;
        _m.Packs = packs;
        _m.DefaultSalePack = _defaultPack.SelectedItem as string ?? "";
        _m.PurchasePack = _purchasePack.SelectedItem as string ?? "";
        _m.Barcodes = _barcodes.Rows.Cast<DataGridViewRow>().Where(r => !r.IsNewRow)
            .Select(r => new BarcodeDto { Code = Convert.ToString(r.Cells[0].Value)?.Trim() ?? "", PackName = Convert.ToString(r.Cells[1].Value) is string s && s.Length > 0 ? s : null })
            .Where(b => b.Code.Length > 0).ToList();
        var changed = packs.Where(p => p.Id > 0 && _oldPrices.TryGetValue(p.Id, out var old) && old != p.SalePrice).ToList();
        if (changed.Count > 0)
        {
            var reason = Msg.Ask(this, "Price change", $"Reason for the new price of {string.Join(", ", changed.Select(c => c.Name))} (kept in price history)");
            if (reason == null) return false;
            _m.PriceChangeReason = reason;
        }
        var r = _isNew ? await Session.Api.Post<OkResult>("medicines", _m) : await Session.Api.Put<OkResult>($"medicines/{_m.Id}", _m);
        SavedId = r.Id ?? _m.Id;
        return true;
    }
}

/// <summary>SCR-13 Medicine import from Excel/CSV: upload, map columns, validate, dry run, commit, undo.</summary>
public sealed class ImportDialog : DialogForm
{
    private readonly Label _file = new UiLabel("No file chosen.", Theme.Ui, Theme.InkMuted);
    private readonly Grid _map = new Grid();
    private readonly Grid _issues = new Grid();
    private readonly Label _summary = new UiLabel("", Theme.UiBold);
    private ImportPreview? _preview;
    private DataGridViewComboBoxColumn _fieldCol = null!;

    public ImportDialog() : base("Import medicine list", 980)
    {
        var choose = new UiButton("Choose Excel or CSV file…", ButtonKind.Accent);
        choose.Click += (_, _) => UiRun.Fire(this, Upload, Bar);
        _map.ReadOnly = false;
        _map.Col("Column in your file", 0, ColKind.Text, 200).ReadOnly = true;
        _fieldCol = new DataGridViewComboBoxColumn { HeaderText = "MEDICARE FIELD", Width = Theme.S(260), FlatStyle = FlatStyle.Flat };
        _fieldCol.Items.Add(ImportFields.Ignore);
        foreach (var fld in ImportFields.All) _fieldCol.Items.Add(fld);
        _map.Columns.Add(_fieldCol);
        var mapHost = new Panel { Dock = DockStyle.Top, Height = Theme.S(200) };
        mapHost.Controls.Add(_map);
        _issues.Col("Row", 70, ColKind.Int);
        _issues.Col("Medicine", 220);
        _issues.Col("Problem", 0, ColKind.Text, 300);
        _issues.Col("Severity", 90);
        _issues.RowTone = o => o is ImportIssue i ? (i.Severity == "ERROR" ? Tone.Danger : Tone.Warn) : Tone.None;
        _issues.EmptyText = "Validate to see problems before importing.";
        var issueHost = new Panel { Dock = DockStyle.Top, Height = Theme.S(180) };
        issueHost.Controls.Add(_issues);
        var validate = new UiButton("Validate mapping");
        validate.Click += (_, _) => UiRun.Fire(this, ValidateMapping, Bar);
        var dry = new UiButton("Dry run");
        dry.Click += (_, _) => UiRun.Fire(this, () => Commit(true), Bar);
        var errors = new UiButton("Save problem rows (CSV)", ButtonKind.Ghost);
        errors.Click += (_, _) => UiRun.Fire(this, SaveErrors, Bar);
        Body.Stack(new UiLabel("Columns are matched automatically; check the mapping. Rows with errors are skipped. Existing medicines with the same barcode or name are updated, not duplicated.", Theme.Small, Theme.InkMuted).Wrap(Theme.S(920)),
            Box.Gap(8), Box.Toolbar(new Control[] { choose, _file }), mapHost, Box.Gap(6), Box.Toolbar(new Control[] { validate, dry, errors, _summary }), issueHost);
        AddOk("Import now", () => Commit(false));
        AddCancel("Close");
        AddButton("Undo last import", () => UiRun.Fire(this, Undo, Bar), ButtonKind.Ghost);
        FitToContent();
    }

    private async Task Upload()
    {
        using var ofd = new OpenFileDialog { Filter = "Excel or CSV (*.xlsx;*.csv)|*.xlsx;*.csv|All files|*.*", Title = "Choose the medicine list" };
        if (ofd.ShowDialog(this) != DialogResult.OK) return;
        _preview = await Session.Api.Upload<ImportPreview>("imports/medicines", ofd.FileName);
        _file.Text = $"{_preview.FileName}: {_preview.RowsRead} rows";
        ShowPreview();
    }

    private void ShowPreview()
    {
        if (_preview == null) return;
        _map.Rows.Clear();
        foreach (var col in _preview.Columns)
        {
            var i = _map.Rows.Add(col, _preview.Mapping.TryGetValue(col, out var f) && _fieldCol.Items.Contains(f) ? f : ImportFields.Ignore);
            _map.Rows[i].Tag = col;
        }
        _issues.SetRows(_preview.Issues, i => new object?[] { i.Row, i.Name, i.Problem, i.Severity });
        _summary.Text = $"Ready {_preview.Ready} · warnings {_preview.Warnings} · errors {_preview.Errors} · {_preview.Status}";
    }

    private async Task ValidateMapping()
    {
        if (_preview == null) { Fail("Choose a file first."); return; }
        _map.EndEdit();
        var mapping = _map.Rows.Cast<DataGridViewRow>().ToDictionary(r => (string)r.Tag!, r => Convert.ToString(r.Cells[1].Value) ?? ImportFields.Ignore);
        _preview = await Session.Api.Put<ImportPreview>($"imports/{_preview.JobId}/mapping", new ImportMapRequest { Mapping = mapping });
        ShowPreview();
    }

    private async Task<bool> Commit(bool dryRun)
    {
        if (_preview == null) { Fail("Choose a file first."); return false; }
        await ValidateMapping();
        if (!dryRun && !Msg.Confirm(this, $"Import {_preview.Ready} medicines now? Rows with errors ({_preview.Errors}) are skipped. You can undo this import afterwards.")) return false;
        _preview = await Session.Api.Post<ImportPreview>($"imports/{_preview.JobId}/commit?dryRun={(dryRun ? "true" : "false")}", new { }, slow: true);
        ShowPreview();
        if (dryRun) { Fail($"Dry run: {_preview.Ready} would be imported, {_preview.Errors} skipped. Nothing was saved.", Tone.Info); return false; }
        Msg.Info(this, $"Import finished: {_preview.Status}.");
        return true;
    }

    private async Task Undo()
    {
        if (_preview == null) { Fail("Open the import to undo first (choose the same file again if needed)."); return; }
        if (!Msg.Confirm(this, "Undo this import? Medicines it created are removed if they were not used yet; updated medicines keep their new data.")) return;
        var r = await Session.Api.Post<OkResult>($"imports/{_preview.JobId}/undo", new { });
        Fail(r.Message ?? "Import undone.", Tone.Ok);
    }

    private async Task SaveErrors()
    {
        if (_preview == null) return;
        var csv = await Session.Api.Get<string>($"imports/{_preview.JobId}/errors.csv");
        using var sfd = new SaveFileDialog { Filter = "CSV|*.csv", FileName = "import-problems.csv" };
        if (sfd.ShowDialog(this) == DialogResult.OK) System.IO.File.WriteAllText(sfd.FileName, csv, System.Text.Encoding.UTF8);
    }
}
