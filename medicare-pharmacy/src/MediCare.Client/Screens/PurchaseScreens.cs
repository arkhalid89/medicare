namespace MediCare.Client.Screens;

/// <summary>SCR-15 list: supplier bills (drafts and posted); new goods-in, return, reverse, pay.</summary>
public sealed class PurchasesScreen : AppScreen
{
    private readonly UiCombo _status = new UiCombo();
    private readonly UiDate _from = new UiDate();
    private readonly UiDate _to = new UiDate();
    private readonly UiText _q = new UiText("Bill no., GRN or supplier");
    private readonly Grid _grid = new Grid();
    private readonly Label _summary = new UiLabel("", Theme.Small, Theme.InkMuted);

    public override string Title => "Goods-in (purchases)";
    public override string Subtitle => "Supplier bills. Drafts change nothing until posted.";

    public override IEnumerable<FKey> FKeys => new[]
    {
        new FKey(Keys.Control | Keys.N, "New goods-in", () => Fire(New), primary: true) { Enabled = () => Session.Can(Perm.PurchasesPost) },
        new FKey(Keys.Enter, "Open", () => Fire(Open)),
        new FKey(Keys.F5, "Refresh", () => Fire(Fill)),
        new FKey(Keys.F9, "Return to supplier", () => Fire(Return)) { Enabled = () => Session.Can(Perm.PurchasesReturn) },
        new FKey(Keys.F10, "Pay supplier", () => Fire(Pay)) { Enabled = () => Session.Can(Perm.SupplierPaymentsPost) },
        new FKey(Keys.Control | Keys.R, "Reverse", () => Fire(Reverse)) { Enabled = () => Session.Can(Perm.PurchasesPost) },
        new FKey(Keys.Control | Keys.P, "Print list", () => TablePrinter.Print(FindForm()!, PrintTable.FromGrid(_grid, "Purchases", $"{Dates.Display(_from.Value)} to {Dates.Display(_to.Value)}"))),
    };

    public PurchasesScreen()
    {
        _status.SetItems(new[] { "All", "DRAFT", "POSTED", "REVERSED" });
        _from.Value = DateTime.Today.AddDays(-30);
        _to.Value = DateTime.Today;
        _grid.Col("GRN", 150, ColKind.Mono);
        _grid.Col("Status", 90);
        _grid.Col("Supplier", 0, ColKind.Text, 200);
        _grid.Col("Bill no.", 120, ColKind.Mono);
        _grid.Col("Bill date", 110, ColKind.Date);
        _grid.Col("Due", 110, ColKind.Date);
        _grid.Col("Lines", 60, ColKind.Int);
        _grid.Col("Net", 120, ColKind.Money);
        _grid.Col("Outstanding", 120, ColKind.Money);
        _grid.RowTone = o => o is PurchaseSummary p ? (p.Status == "DRAFT" ? Tone.Info : p.Status == "REVERSED" ? Tone.Muted : p.Outstanding > 0 && p.DueDate < DateTime.Today ? Tone.Warn : Tone.None) : Tone.None;
        _grid.RowActivated += _ => Fire(Open);
        _q.KeyDown += (_, e) => { if (e.KeyCode == Keys.Enter) { e.SuppressKeyPress = true; Fire(Fill); } };
        var show = new UiButton("Show");
        show.Click += (_, _) => Fire(Fill);
        var add = new UiButton("New goods-in", ButtonKind.Accent, "Ctrl+N") { Enabled = Session.Can(Perm.PurchasesPost) };
        add.Click += (_, _) => Fire(New);
        var card = new Card { Dock = DockStyle.Fill, Padding = new Padding(1) };
        card.Controls.Add(_grid);
        Compose(Box.Toolbar(new Control[] { _status.Sized(120), Box.Caption("From"), _from.Sized(140), Box.Caption("To"), _to.Sized(140), _q.Sized(220), show }, new Control[] { add }), _summary.Docked(DockStyle.Top), Box.Gap(6), card);
    }

    protected override Task OnFirstShow() => Fill();

    private async Task Fill()
    {
        var list = await Session.Api.Get<List<PurchaseSummary>>("purchases", new { status = _status.SelectedIndex > 0 ? _status.SelectedItem as string : null, from = _from.Value.Date, to = _to.Value.Date, q = _q.Text.Trim() });
        _grid.SetRows(list, p => new object?[] { p.No ?? "(draft)", p.Status, p.Supplier, p.SupplierInvNo, p.InvDate, p.DueDate, p.Lines, p.Net, p.Outstanding == 0 ? null : p.Outstanding });
        _summary.Text = $"{list.Count} bills · posted net {Money.FormatRs(list.Where(p => p.Status == "POSTED").Sum(p => p.Net))} · outstanding {Money.FormatRs(list.Sum(p => p.Outstanding))}";
    }

    private PurchaseSummary? Current => _grid.Current<PurchaseSummary>();

    private async Task New()
    {
        using var d = new PurchaseEditorDialog(null);
        d.ShowDialog(FindForm());
        await Fill();
    }

    private async Task Open()
    {
        var p = Current;
        if (p == null) return;
        using var d = new PurchaseEditorDialog(p.Id);
        d.ShowDialog(FindForm());
        await Fill();
    }

    private async Task Return()
    {
        var p = Current;
        if (p == null || p.Status != "POSTED") { Bar.Show("Choose a posted bill to return goods against.", Tone.Warn); return; }
        var dto = await Session.Api.Get<PurchaseDto>($"purchases/{p.Id}");
        using var d = new PurchaseReturnDialog(dto);
        if (d.ShowDialog(FindForm()) == DialogResult.OK) await Fill();
    }

    private async Task Pay()
    {
        var p = Current;
        if (p == null) { Bar.Show("Choose a bill of the supplier you want to pay.", Tone.Warn); return; }
        var dto = await Session.Api.Get<PurchaseDto>($"purchases/{p.Id}");
        var s = await Session.Api.Get<SupplierDto>($"suppliers/{dto.SupplierId}");
        using var d = new SettlementDialog(false, s.Id, s.Name, s.Balance);
        if (d.ShowDialog(FindForm()) == DialogResult.OK) await Fill();
    }

    private async Task Reverse()
    {
        var p = Current;
        if (p == null || p.Status != "POSTED") return;
        var reason = Msg.Ask(FindForm(), "Reverse " + p.No, "Reason (for a wrongly entered bill; use a purchase return when goods go back)");
        if (reason == null) return;
        if (!Msg.Confirm(FindForm(), $"Reverse {p.No}? The stock it added is removed (only if still in stock) and the supplier balance is reversed.")) return;
        var req = new ReverseRequest { Reason = reason, IdempotencyKey = Compat.NewKey() };
        var r = await Approvals.Run(FindForm()!, t => { req.ApprovalToken = t.LastOrDefault() ?? ""; return Session.Api.Post<OkResult>($"purchases/{p.Id}/reverse", req, t); });
        Bar.Show(r.Message ?? "Reversed.", Tone.Ok);
        await Fill();
    }
}

/// <summary>SCR-15 Goods-in: supplier bill entry with 1D/2D scan capture; draft, post, pay now.</summary>
public sealed class PurchaseEditorDialog : DialogForm
{
    private sealed class Row
    {
        public MedicineDto Med = new MedicineDto();
        public PurchaseLineDto Line = new PurchaseLineDto();
    }

    private long? _id;
    private PurchaseDto _p = new PurchaseDto();
    private readonly UiCombo _supplier = new UiCombo();
    private readonly UiText _billNo = new UiText();
    private readonly UiDate _billDate = new UiDate();
    private readonly UiDate _due = new UiDate().Optional();
    private readonly UiCheck _opening = new UiCheck("Opening stock (no supplier balance)");
    private readonly UiText _notes = new UiText();
    private readonly MoneyBox _charges = new MoneyBox();
    private readonly UiText _scan = new UiText("Scan the box (2D or barcode) or type a medicine name, then Enter");
    private readonly Grid _grid = new Grid();
    private readonly Label _totals = new UiLabel("", Theme.NumBold);
    private readonly MoneyBox _paid = new MoneyBox();
    private readonly UiCombo _account = new UiCombo();
    private readonly UiText _payRef = new UiText();
    private readonly string _key = Compat.NewKey();
    private bool _readOnly;
    private UiButton _post = null!, _save = null!;

    // column indexes
    private const int CMed = 0, CPack = 1, CBatch = 2, CExp = 3, CQty = 4, CFree = 5, CRate = 6, CDisc = 7, CTax = 8, CMrp = 9, CSale = 10, CRack = 11, CNet = 12;

    public PurchaseEditorDialog(long? id) : base(id == null ? "New goods-in" : "Goods-in", 1260)
    {
        _id = id;
        var head = new FieldGrid(4);
        head.Add("Supplier", _supplier, 2);
        head.Add("Supplier bill no.", _billNo);
        head.Add("Bill date", _billDate);
        head.Add("Due date", _due);
        head.Add("Freight / other charges (Rs)", _charges, hint: "Spread over the lines' cost");
        head.Add("Notes", _notes);
        head.AddFull(_opening);

        _scan.Dock = DockStyle.Top;
        _scan.Height = Theme.ControlHeightLg;
        _scan.Font = Theme.Lg;
        _scan.KeyDown += (_, e) => { if (e.KeyCode == Keys.Enter) { e.SuppressKeyPress = true; UiRun.Fire(this, CaptureScan, Bar); } };
        KeepsEnter(_scan);
        _scan.KeyPress += (_, e) => { if (e.KeyChar == (char)29) { _scan.SelectedText = "\u001d"; e.Handled = true; } };

        _grid.ReadOnly = false;
        _grid.AllowUserToDeleteRows = true;
        _grid.EditMode = DataGridViewEditMode.EditOnKeystrokeOrF2;
        _grid.Col("Medicine", 0, ColKind.Text, 220).ReadOnly = true;
        var pack = new DataGridViewComboBoxColumn { HeaderText = "PACK", Width = Theme.S(100), FlatStyle = FlatStyle.Flat };
        _grid.Columns.Add(pack);
        _grid.Col("Batch", 100, ColKind.Mono);
        _grid.Col("Expiry MM/YY", 90, ColKind.Mono);
        _grid.Col("Qty", 60, ColKind.Int);
        _grid.Col("Free", 55, ColKind.Int);
        _grid.Col("Rate", 85, ColKind.Money);
        _grid.Col("Disc %", 60, ColKind.Percent);
        _grid.Col("Tax %", 55, ColKind.Percent);
        _grid.Col("MRP", 80, ColKind.Money);
        _grid.Col("Sale price", 85, ColKind.Money);
        _grid.Col("Rack", 60);
        _grid.Col("Net", 95, ColKind.Money).ReadOnly = true;
        foreach (DataGridViewColumn c in _grid.Columns) c.SortMode = DataGridViewColumnSortMode.NotSortable;
        _grid.CellEndEdit += (_, e) => { ReadRow(e.RowIndex); Totals(); };
        _grid.UserDeletedRow += (_, _) => Totals();
        var host = new Panel { Dock = DockStyle.Top, Height = Theme.S(300) };
        host.Controls.Add(_grid);

        var pay = new FieldGrid(4);
        pay.Add("Paid now (Rs)", _paid);
        pay.Add("From account", _account);
        pay.Add("Payment reference", _payRef);
        pay.Add("Bill total", _totals);

        Body.Stack(head, Box.Gap(4), _scan, Box.Gap(6), host, Box.Gap(8), pay);
        _post = AddOk("Post invoice", Post);
        _post.Hint = "Ctrl+Enter";
        AddCancel("Close");
        _save = AddButton("Save draft", () => UiRun.Fire(this, async () => { await Save(); Fail("Draft saved. It does not change stock until posted.", Tone.Ok); }, Bar), ButtonKind.Secondary, "Ctrl+S");
        AddButton("Print", PrintVoucher, ButtonKind.Ghost);
        AddButton("Delete draft", () => UiRun.Fire(this, DeleteDraft, Bar), ButtonKind.Ghost);
        FitToContent(640);
        Load += (_, _) => UiRun.Fire(this, LoadData, Bar);
    }

    private async Task LoadData()
    {
        var suppliers = await Session.Api.Get<List<SupplierDto>>("suppliers");
        _supplier.SetItems(suppliers);
        var accounts = await Session.Api.Get<List<CashAccountDto>>("cash-accounts");
        _account.SetItems(accounts.Where(a => a.IsActive));
        _billDate.Value = DateTime.Today;
        if (_id == null) { _supplier.SelectedIndex = -1; Totals(); return; }
        _p = await Session.Api.Get<PurchaseDto>($"purchases/{_id}");
        Text = _p.No != null ? $"Goods-in {_p.No} ({_p.Status})" : "Goods-in draft";
        _supplier.SelectWhere<SupplierDto>(s => s.Id == _p.SupplierId);
        _billNo.Text = _p.SupplierInvNo;
        _billDate.Value = _p.InvDate == default ? DateTime.Today : _p.InvDate;
        if (_p.DueDate.HasValue) { _due.Checked = true; _due.Value = _p.DueDate.Value; }
        _opening.Checked = _p.IsOpeningStock;
        _notes.Text = _p.Notes;
        _charges.Value = _p.Charges;
        _paid.Value = _p.PaidNow;
        _payRef.Text = _p.PaidReference;
        if (_p.PaidFromAccountId.HasValue) _account.SelectWhere<CashAccountDto>(a => a.Id == _p.PaidFromAccountId);
        _grid.Rows.Clear();
        foreach (var l in _p.Lines)
        {
            var med = await Session.Api.Get<MedicineDto>($"medicines/{l.MedicineId}");
            AddRow(med, l);
        }
        _readOnly = _p.Status != "DRAFT";
        if (_readOnly)
        {
            _grid.ReadOnly = true;
            foreach (var c in new Control[] { _supplier, _billNo, _billDate, _due, _opening, _notes, _charges, _scan, _paid, _account, _payRef }) c.Enabled = false;
            _post.Visible = _save.Visible = false;
            Fail($"Posted {(_p.PostedAt.HasValue ? Dates.DisplayTime(_p.PostedAt.Value) : "")} by {_p.PostedBy}. Posted bills cannot be edited; use a purchase return or reversal. Outstanding {Money.FormatRs(_p.Outstanding)}.", Tone.Info);
        }
        Totals();
    }

    private void AddRow(MedicineDto med, PurchaseLineDto l)
    {
        var i = _grid.AddRow(med.Name + (med.Strength.Length > 0 && !med.Name.Has(med.Strength) ? " " + med.Strength : ""), null, l.BatchNo, l.Expiry.HasValue ? l.Expiry.Value.ToString("MM/yy", CultureInfo.InvariantCulture) : "",
            l.Qty, l.FreeQty, l.Rate, l.DiscountPercent, l.TaxPercent, l.Mrp, l.SalePrice, l.Rack, l.Net);
        var cell = (DataGridViewComboBoxCell)_grid.Rows[i].Cells[CPack];
        foreach (var p in med.Packs) cell.Items.Add(p.Name);
        cell.Value = med.Packs.FirstOrDefault(p => p.Id == l.PackId)?.Name ?? med.PurchasePack;
        _grid.Rows[i].Tag = new Row { Med = med, Line = l };
        _grid.SelectRow(i);
    }

    /// <summary>Scan/typing capture: 2D fills medicine, batch and expiry; scanning the same batch again adds one more.</summary>
    private async Task CaptureScan()
    {
        var text = _scan.Text.Trim();
        _scan.Clear();
        if (text.Length == 0) return;
        MedicineDto? med = null;
        Gs1Info? gs1 = null;
        long? scannedPack = null;
        var code = Gs1Parser.IsCandidate(text) || (text.Length >= 6 && text.All(char.IsDigit));
        if (code)
        {
            var r = await Session.Api.Get<ScanResult>("medicines/scan", new { code = text });
            med = r.Medicine; gs1 = r.Gs1; scannedPack = r.PackId;
        }
        else
        {
            using var pick = new MedicinePickDialog(text);
            if (pick.ShowDialog(this) != DialogResult.OK || pick.Chosen == null) return;
            med = await Session.Api.Get<MedicineDto>($"medicines/{pick.Chosen.Id}");
        }
        foreach (DataGridViewRow r in _grid.Rows)
        {
            if (r.Tag is Row row && row.Med.Id == med.Id && gs1?.Batch != null && string.Equals(row.Line.BatchNo, gs1.Batch, StringComparison.OrdinalIgnoreCase))
            {
                row.Line.Qty++;
                r.Cells[CQty].Value = row.Line.Qty;
                _grid.SelectRow(r.Index);
                Totals();
                return;
            }
        }
        var previous = _grid.Rows.Cast<DataGridViewRow>().Select(r => r.Tag).OfType<Row>().LastOrDefault(r => r.Med.Id == med.Id);
        var pack = med.Packs.FirstOrDefault(p => p.Id == scannedPack) ?? med.Packs.FirstOrDefault(p => p.Name == med.PurchasePack) ?? med.Packs.OrderByDescending(p => p.BaseQty).First();
        var line = new PurchaseLineDto
        {
            MedicineId = med.Id, MedicineName = med.Name, PackId = pack.Id, PackName = pack.Name, PackBaseQty = pack.BaseQty,
            BatchNo = gs1?.Batch ?? "", Expiry = gs1?.Expiry, Mfg = gs1?.Manufactured, Qty = previous?.Line.Qty ?? 1,
            Rate = previous?.Line.Rate ?? Money.Round2(med.DefaultPurchaseCost * pack.BaseQty), TaxPercent = med.TaxRatePercent,
            SalePrice = med.Packs.FirstOrDefault(p => p.Name == med.DefaultSalePack)?.SalePrice ?? 0, Rack = med.Rack,
            CaptureMethod = gs1 != null ? "2D" : code ? "1D" : "SEARCH",
        };
        AddRow(med, line);
        Totals();
        // jump to the first thing still needed
        var row2 = _grid.CurrentRow!;
        _grid.CurrentCell = row2.Cells[line.BatchNo.Length == 0 ? CBatch : line.Expiry == null ? CExp : CQty];
        _grid.Focus();
    }

    private void ReadRow(int index)
    {
        if (index < 0 || index >= _grid.RowCount || _grid.Rows[index].Tag is not Row row) return;
        var r = _grid.Rows[index];
        var l = row.Line;
        string S(int c) => Convert.ToString(r.Cells[c].Value, CultureInfo.InvariantCulture) ?? "";
        var packName = S(CPack);
        var pack = row.Med.Packs.FirstOrDefault(p => p.Name == packName);
        if (pack != null) { l.PackId = pack.Id; l.PackName = pack.Name; l.PackBaseQty = pack.BaseQty; }
        l.BatchNo = S(CBatch).Trim();
        l.Expiry = Dates.TryParseExpiry(S(CExp), out var exp) ? exp : (DateTime?)null;
        if (l.Expiry.HasValue) r.Cells[CExp].Value = l.Expiry.Value.ToString("MM/yy", CultureInfo.InvariantCulture);
        l.Qty = int.TryParse(S(CQty), out var q) ? q : 0;
        l.FreeQty = int.TryParse(S(CFree), out var fq) ? fq : 0;
        l.Rate = Money.Parse(S(CRate));
        l.DiscountPercent = Money.Parse(S(CDisc));
        l.TaxPercent = Money.Parse(S(CTax));
        l.Mrp = Money.Parse(S(CMrp));
        l.SalePrice = Money.Parse(S(CSale));
        l.Rack = S(CRack).Trim();
    }

    private void Totals()
    {
        decimal gross = 0, disc = 0, tax = 0;
        foreach (DataGridViewRow r in _grid.Rows)
        {
            if (r.Tag is not Row row) continue;
            var l = row.Line;
            var g = Money.Round2(l.Qty * l.Rate);
            var d = Money.Round2(g * l.DiscountPercent / 100m);
            var t = Money.Round2((g - d) * l.TaxPercent / 100m);
            r.Cells[CNet].Value = g - d + t;
            gross += g; disc += d; tax += t;
        }
        var net = gross - disc + tax + _charges.Value;
        _totals.Text = $"Rs {Money.Format(net)}";
        _totals.Tag = net;
    }

    private PurchaseDto Build()
    {
        _grid.EndEdit();
        for (int i = 0; i < _grid.RowCount; i++) ReadRow(i);
        var s = _supplier.Selected<SupplierDto>();
        _p.SupplierId = s?.Id ?? 0;
        _p.SupplierInvNo = _billNo.Text.Trim();
        _p.InvDate = _billDate.Value.Date;
        _p.DueDate = _due.Date;
        _p.IsOpeningStock = _opening.Checked;
        _p.Notes = _notes.Text.Trim();
        _p.Charges = _charges.Value;
        _p.PaidNow = _paid.Value;
        _p.PaidFromAccountId = _paid.Value > 0 ? _account.Selected<CashAccountDto>()?.Id : null;
        _p.PaidReference = _payRef.Text.Trim();
        _p.Lines = _grid.Rows.Cast<DataGridViewRow>().Select(r => r.Tag).OfType<Row>().Select(r => r.Line).ToList();
        return _p;
    }

    private async Task Save()
    {
        var p = Build();
        if (p.SupplierId == 0) throw new ApiException(400, ErrorCodes.Validation, "Choose the supplier.");
        var r = _id == null ? await Session.Api.Post<OkResult>("purchases", p) : await Session.Api.Put<OkResult>($"purchases/{_id}", p);
        _id ??= r.Id;
        Totals();
    }

    private async Task<bool> Post()
    {
        if (_readOnly) return false;
        var p = Build();
        var missing = p.Lines.FirstOrDefault(l => l.BatchNo.Length == 0 || l.Expiry == null);
        if (missing != null) { Fail($"{missing.MedicineName}: enter the batch number and expiry (MM/YY) from the pack."); return false; }
        if (p.Lines.Count == 0) { Fail("Scan or add at least one medicine."); return false; }
        if (p.PaidNow > 0 && p.PaidFromAccountId == null) { Fail("Choose the account the payment is made from."); return false; }
        await Save();
        if (!Msg.Confirm(this, $"Post this bill for {Money.FormatRs(_totals.Tag is decimal d ? d : 0)}? Stock and the supplier balance are updated and the bill cannot be edited afterwards.")) return false;
        var req = new PostRequest { IdempotencyKey = _key };
        _p = await Approvals.Run(this, t => { req.ApprovalTokens = t.ToList(); return Session.Api.Post<PurchaseDto>($"purchases/{_id}/post", req, t); });
        if (Msg.Confirm(this, $"Bill posted as {_p.No}. Print the goods-received voucher?", "Posted")) PrintVoucher();
        return true;
    }

    private async Task DeleteDraft()
    {
        if (_id == null || _readOnly) { Close(); return; }
        if (!Msg.Confirm(this, "Delete this draft? It has not changed any stock.")) return;
        await Session.Api.Delete<OkResult>($"purchases/{_id}");
        Close();
    }

    private void PrintVoucher()
    {
        var p = _p.Lines.Count > 0 ? _p : Build();
        var t = new PrintTable { Title = p.No != null ? $"Goods received note {p.No}" : "Goods-in draft", Subtitle = p.SupplierName.Length > 0 ? p.SupplierName : _supplier.Text, Landscape = true, Signatures = true };
        t.Info.Add(new KeyValuePair<string, string>("Supplier bill", $"{p.SupplierInvNo} of {Dates.Display(p.InvDate)}"));
        t.Info.Add(new KeyValuePair<string, string>("Status", p.Status));
        t.Col("Medicine", 3).Col("Pack", 1).Col("Batch", 1.2f).Col("Expiry", 0.8f).Col("Qty", 0.6f, true).Col("Free", 0.6f, true).Col("Rate", 1, true).Col("Disc %", 0.7f, true).Col("Tax %", 0.7f, true).Col("Net", 1.2f, true);
        foreach (var l in p.Lines)
            t.Rows.Add(new[] { l.MedicineName, l.PackName, l.BatchNo, l.Expiry.HasValue ? Dates.Expiry(l.Expiry.Value) : "", l.Qty.ToString(CultureInfo.InvariantCulture), l.FreeQty.ToString(CultureInfo.InvariantCulture), Money.Format(l.Rate), l.DiscountPercent.ToString("0.##", CultureInfo.InvariantCulture), l.TaxPercent.ToString("0.##", CultureInfo.InvariantCulture), Money.Format(l.Net) });
        t.Totals = new[] { "Total", "", "", "", "", "", "", "", "", Money.Format(p.Net > 0 ? p.Net : _totals.Tag is decimal d ? d : 0) };
        if (p.Charges > 0) t.Notes.Add($"Includes freight / charges {Money.FormatRs(p.Charges)}.");
        if (p.PaidNow > 0) t.Notes.Add($"Paid at receipt {Money.FormatRs(p.PaidNow)} ({p.PaidReference}).");
        TablePrinter.Print(this, t);
    }

    protected override bool ProcessCmdKey(ref Message msg, Keys keyData)
    {
        if (keyData == (Keys.Control | Keys.S) && !_readOnly) { UiRun.Fire(this, Save, Bar); return true; }
        if (keyData == (Keys.Control | Keys.Enter) && !_readOnly) { UiRun.Fire(this, () => RunOk(Post)); return true; }
        return base.ProcessCmdKey(ref msg, keyData);
    }
}

/// <summary>Search a medicine by name (used by goods-in and other back-office screens).</summary>
public sealed class MedicinePickDialog : DialogForm
{
    private readonly UiText _q = new UiText("Name, generic or code");
    private readonly Grid _grid = new Grid();
    private readonly Timer _timer = new Timer { Interval = 250 };
    public MedicineListItem? Chosen { get; private set; }

    public MedicinePickDialog(string initial) : base("Find medicine", 760)
    {
        _grid.Col("Medicine", 0, ColKind.Text, 240);
        _grid.Col("Generic", 0, ColKind.Text, 160);
        _grid.Col("Pack", 90);
        _grid.Col("Stock", 140);
        _grid.RowActivated += _ => UiRun.Fire(this, () => RunOk(Pick));
        _q.Text = initial;
        _q.Dock = DockStyle.Top;
        _q.Height = Theme.ControlHeight;
        _q.TextChanged += (_, _) => { _timer.Stop(); _timer.Start(); };
        _q.KeyDown += (_, e) => { if (e.KeyCode == Keys.Down) { _grid.Focus(); e.Handled = true; } };
        _timer.Tick += (_, _) => { _timer.Stop(); UiRun.Fire(this, Fill, Bar); };
        var host = new Panel { Dock = DockStyle.Top, Height = Theme.S(320) };
        host.Controls.Add(_grid);
        Body.Stack(_q, Box.Gap(6), host);
        AddOk("Choose", Pick);
        AddCancel();
        FitToContent();
        Load += (_, _) => UiRun.Fire(this, Fill, Bar);
    }

    private async Task Fill()
    {
        var list = await Session.Api.Get<List<MedicineListItem>>("medicines", new { q = _q.Text.Trim(), limit = 50 });
        _grid.SetRows(list, m => new object?[] { m.Name, m.Generic, m.DefaultPack, m.StockText });
        if (list.Count > 0) _grid.SelectRow(0);
    }

    private Task<bool> Pick()
    {
        Chosen = _grid.Current<MedicineListItem>();
        return Task.FromResult(Chosen != null);
    }

    protected override void OnFormClosed(FormClosedEventArgs e) { _timer.Dispose(); base.OnFormClosed(e); }
}

/// <summary>SCR-16 Purchase return: send batches back to the supplier against a posted bill.</summary>
public sealed class PurchaseReturnDialog : DialogForm
{
    private readonly PurchaseDto _p;
    private readonly Grid _grid = new Grid();
    private readonly UiText _creditNote = new UiText("Supplier's credit note number");
    private readonly string _key = Compat.NewKey();

    public PurchaseReturnDialog(PurchaseDto p) : base($"Return to {p.SupplierName} against {p.No}", 980)
    {
        _p = p;
        _grid.ReadOnly = false;
        _grid.EditMode = DataGridViewEditMode.EditOnEnter;
        _grid.Col("Medicine", 0, ColKind.Text, 220).ReadOnly = true;
        _grid.Col("Batch", 110, ColKind.Mono).ReadOnly = true;
        _grid.Col("Received (base units)", 150, ColKind.Int).ReadOnly = true;
        _grid.Col("Already returned", 130, ColKind.Int).ReadOnly = true;
        _grid.Col("Return qty", 100, ColKind.Int);
        var from = new DataGridViewComboBoxColumn { HeaderText = "FROM STOCK", Width = Theme.S(130), FlatStyle = FlatStyle.Flat };
        from.Items.AddRange("Saleable", "Quarantine");
        _grid.Columns.Add(from);
        _grid.Col("Reason", 0, ColKind.Text, 160);
        foreach (DataGridViewColumn c in _grid.Columns) c.SortMode = DataGridViewColumnSortMode.NotSortable;
        foreach (var l in p.Lines)
        {
            var received = (l.Qty + l.FreeQty) * Math.Max(1, l.PackBaseQty);
            var i = _grid.Rows.Add(l.MedicineName, l.BatchNo, received, l.ReturnedBaseQty, 0, "Quarantine", "");
            _grid.Rows[i].Tag = l;
        }
        var host = new Panel { Dock = DockStyle.Top, Height = Theme.S(300) };
        host.Controls.Add(_grid);
        var f = new FieldGrid(2);
        f.Add("Credit note reference", _creditNote);
        Body.Stack(new UiLabel("Quantities are in base units. Returned stock leaves at its cost; the supplier balance is reduced.", Theme.Small, Theme.InkMuted), Box.Gap(6), host, Box.Gap(8), f);
        AddOk("Post return", Post, ButtonKind.Danger);
        AddCancel();
        FitToContent();
    }

    private async Task<bool> Post()
    {
        _grid.EndEdit();
        var lines = new List<PurchaseReturnLineInput>();
        foreach (DataGridViewRow r in _grid.Rows)
        {
            if (r.Tag is not PurchaseLineDto l) continue;
            var q = int.TryParse(Convert.ToString(r.Cells[4].Value, CultureInfo.InvariantCulture), out var v) ? v : 0;
            if (q <= 0) continue;
            var reason = Convert.ToString(r.Cells[6].Value)?.Trim() ?? "";
            if (reason.Length == 0) { Fail($"{l.MedicineName}: enter the reason."); return false; }
            lines.Add(new PurchaseReturnLineInput { PurchaseItemId = l.Id, BaseQty = q, FromState = Convert.ToString(r.Cells[5].Value) == "Saleable" ? StockStates.Saleable : StockStates.Quarantine, Reason = reason });
        }
        if (lines.Count == 0) { Fail("Enter the quantity to return on at least one line."); return false; }
        if (!Msg.Confirm(this, $"Post the return of {lines.Count} line(s) to {_p.SupplierName}? It cannot be edited later.")) return false;
        var req = new PurchaseReturnRequest { IdempotencyKey = _key, PurchaseId = _p.Id, CreditNoteRef = _creditNote.Text.Trim(), Lines = lines };
        var r2 = await Approvals.Run(this, t => Session.Api.Post<OkResult>("purchase-returns", req, t));
        Msg.Info(this, r2.Message ?? $"Return {r2.No} posted.");
        return true;
    }
}
