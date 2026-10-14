using System.IO;
using System.Media;
using System.Text.Json;

namespace MediCare.Client.Screens;

/// <summary>A cart line on this PC. Prices shown come from the host's quote, never from local arithmetic.</summary>
public sealed class CartLine
{
    public MedicineDto Med { get; set; } = new MedicineDto();
    public PackDto Pack { get; set; } = new PackDto();
    public int Qty { get; set; } = 1;
    public long? BatchId { get; set; }
    public string Pick { get; set; } = "FEFO";
    public string BatchLabel { get; set; } = "";
    public decimal Discount { get; set; }
    public SaleQuoteLine? Quote { get; set; }
    public string? Error { get; set; }
    public PrescriptionInput? Rx { get; set; }
    public bool Controlled => Med.Schedule == "B" || Med.Schedule == "D";
}

/// <summary>Serialized cart (held sales and the unconfirmed-sale draft on this PC).</summary>
public sealed class CartDraft
{
    public string Key { get; set; } = "";
    public long? CustomerId { get; set; }
    public string CustomerName { get; set; } = "";
    public decimal InvoiceDiscount { get; set; }
    public DateTime SavedAt { get; set; }
    public List<DraftLine> Lines { get; set; } = new List<DraftLine>();
}

public sealed class DraftLine
{
    public long MedicineId { get; set; }
    public long PackId { get; set; }
    public int Qty { get; set; }
    public long? BatchId { get; set; }
    public string Pick { get; set; } = "FEFO";
    public string BatchLabel { get; set; } = "";
    public decimal Discount { get; set; }
    public PrescriptionInput? Rx { get; set; }
}

/// <summary>SCR-04 POS sale. Keyboard first: scan, F2 search, F3 customer, F4 pay, F5 hold, F6 discount, F7 batch, F8 reprint, F9 return.</summary>
public sealed class PosScreen : AppScreen, IHasUnsavedWork
{
    private readonly List<CartLine> _cart = new List<CartLine>();
    private readonly Dictionary<long, (MedicineDto med, DateTime at)> _medCache = new Dictionary<long, (MedicineDto, DateTime)>();
    private CustomerDto? _customer;
    private decimal _invoiceDiscount;
    private string _key = Compat.NewKey();
    private long? _holdId;
    private SaleQuote? _quote;
    private int _quoteSeq;
    private Task? _quoteTask;
    private SaleResult? _lastSale;
    /// <summary>A sale sent while the host was unreachable. Resent unchanged (same key) so it can never post twice.</summary>
    private SaleRequest? _pending;

    private readonly UiText _scan = new UiText("Scan barcode or type a medicine name (F2)");
    private readonly UiButton _customerButton = new UiButton("Walk-in customer", ButtonKind.Secondary, "F3");
    private readonly Grid _grid = new Grid();
    private readonly Grid _results = new Grid();
    private readonly Panel _resultsPanel = new Panel();
    private readonly Label _lineName = new UiLabel("", Theme.LgBold);
    private readonly Label _lineInfo = new UiLabel("", Theme.Small, Theme.InkMuted);
    private readonly Badge _lineBadge = new Badge();
    private readonly Label _items = new UiLabel("", Theme.Small, Theme.InkMuted);
    private readonly Label _gross = new UiLabel("", Theme.Num);
    private readonly Label _discount = new UiLabel("", Theme.Num);
    private readonly Label _tax = new UiLabel("", Theme.Num);
    private readonly Label _net = new UiLabel("0.00", Theme.Total, Theme.Ink);
    private readonly Label _last = new UiLabel("", Theme.Small, Theme.InkMuted);
    private readonly UiButton _pay = new UiButton("Take payment", ButtonKind.Primary, "F4");
    private readonly Timer _quoteTimer = new Timer { Interval = 150 };
    private readonly Timer _searchTimer = new Timer { Interval = 250 };

    public override string Title => "New sale";
    public override string Subtitle => $"Counter {Session.Counter} · {(_customer?.Name ?? "Walk-in")}" + (_holdId.HasValue ? " · recalled from hold" : "");
    public bool HasUnsavedWork => _cart.Count > 0;

    private Keys K(string name, Keys fallback) => Session.ShortcutKey(name, fallback);

    public override IEnumerable<FKey> FKeys => new[]
    {
        new FKey(K("find", Keys.F2), "Search", () => { _scan.Focus(); _scan.SelectAll(); }),
        new FKey(K("customer", Keys.F3), "Customer", () => Fire(PickCustomer)),
        new FKey(K("pay", Keys.F4), "Take payment", () => Fire(Pay), primary: true) { Enabled = () => _cart.Count > 0 },
        new FKey(K("hold", Keys.F5), _cart.Count > 0 ? "Hold" : "Recall", () => Fire(HoldOrRecall)),
        new FKey(K("discount", Keys.F6), "Discount", () => Fire(Discount)) { Enabled = () => _cart.Count > 0 },
        new FKey(K("batch", Keys.F7), "Batch", () => Fire(PickBatch)) { Enabled = () => Selected != null },
        new FKey(K("reprint", Keys.F8), "Reprint", () => Fire(Reprint)),
        new FKey(K("return", Keys.F9), "Return", () => Fire(Return)),
        new FKey(Keys.Control | Keys.Delete, "Clear sale", ClearWithConfirm) { Enabled = () => _cart.Count > 0 },
    };

    public PosScreen()
    {
        Padding = new Padding(Theme.S(16), Theme.S(10), Theme.S(16), Theme.S(8));
        // ---- top: scan + customer ----
        _scan.Font = Theme.Lg;
        _scan.Height = Theme.ControlHeightLg;
        _scan.Dock = DockStyle.Fill;
        _customerButton.Dock = DockStyle.Right;
        _customerButton.Height = Theme.ControlHeightLg;
        _customerButton.Width = Theme.S(300);
        _customerButton.Click += (_, _) => Fire(PickCustomer);
        var top = new Panel { Dock = DockStyle.Top, Height = Theme.ControlHeightLg + Theme.S(8), Padding = new Padding(0, 0, 0, Theme.S(8)) };
        var gap = new Panel { Dock = DockStyle.Right, Width = Theme.S(10) };
        top.Controls.AddRange(new Control[] { _scan, gap, _customerButton });

        // ---- cart ----
        _grid.Col("#", 40, ColKind.Int);
        _grid.Col("Medicine", 0, ColKind.Text, 300);
        _grid.Col("Pack", 110);
        _grid.Col("Qty", 70, ColKind.Int);
        _grid.Col("Rate", 100, ColKind.Money);
        _grid.Col("Disc.", 90, ColKind.Money);
        _grid.Col("Amount", 120, ColKind.Money);
        _grid.Col("Batch (expiry)", 0, ColKind.Mono, 160);
        foreach (DataGridViewColumn c in _grid.Columns) c.SortMode = DataGridViewColumnSortMode.NotSortable;
        _grid.EmptyText = "Scan a barcode or type a name to start the sale.";
        _grid.RowTone = o => o is CartLine l ? (l.Error != null ? Tone.Danger : l.Quote?.NearestExpiry is DateTime e && (e - DateTime.Today).TotalDays <= Session.Settings.NearExpiryDays ? Tone.Warn : Tone.None) : Tone.None;
        _grid.SelectionChanged += (_, _) => ShowLine();
        _grid.RowActivated += _ => Fire(EditLine);
        _grid.KeyDown += GridKeyDown;
        var cartCard = new Card { Dock = DockStyle.Fill, Padding = new Padding(1) };
        cartCard.Controls.Add(_grid);

        // ---- search results overlay ----
        _results.Col("Medicine", 0, ColKind.Text, 260);
        _results.Col("Pack", 110);
        _results.Col("Price", 100, ColKind.Money);
        _results.Col("In stock", 0, ColKind.Text, 140);
        _results.EmptyText = "No medicine matches. Check the spelling or search by generic name.";
        _results.RowTone = o => o is MedicineListItem m && m.StockQty <= 0 ? Tone.Muted : Tone.None;
        _results.RowActivated += o => Fire(() => AddFromSearch((MedicineListItem)o));
        _resultsPanel.Visible = false;
        _resultsPanel.BackColor = Theme.LineStrong;
        _resultsPanel.Padding = new Padding(1);
        _resultsPanel.Controls.Add(_results);

        // ---- right column ----
        var right = BuildRight();
        var split = new Panel { Dock = DockStyle.Fill };
        var spacer = new Panel { Dock = DockStyle.Right, Width = Theme.S(12) };
        split.Controls.AddRange(new Control[] { cartCard, spacer, right });

        Compose(top, split);
        Controls.Add(_resultsPanel);
        _resultsPanel.BringToFront();

        _scan.KeyDown += ScanKeyDown;
        _scan.KeyPress += (_, e) =>
        {
            // GS1 group separator from 2D scanners (FNC1) arrives as Ctrl+]; keep it in the text.
            if (e.KeyChar == (char)29) { _scan.SelectedText = "\u001d"; e.Handled = true; }
        };
        _scan.TextChanged += (_, _) => { _searchTimer.Stop(); if (!LooksLikeCode(_scan.Text) && _scan.Text.Trim().Length >= 2) _searchTimer.Start(); else HideResults(); };
        _searchTimer.Tick += (_, _) => { _searchTimer.Stop(); Fire(Search); };
        _quoteTimer.Tick += (_, _) => { _quoteTimer.Stop(); _quoteTask = Quote(); };
        _pay.Click += (_, _) => Fire(Pay);
    }

    private Control BuildRight()
    {
        var right = new Panel { Dock = DockStyle.Right, Width = Theme.S(360) };
        var lineCard = new Card("Selected line") { Dock = DockStyle.Top, Height = Theme.S(150) };
        _lineName.AutoSize = false; _lineName.Dock = DockStyle.Top; _lineName.Height = Theme.S(26);
        _lineInfo.AutoSize = false; _lineInfo.Dock = DockStyle.Fill;
        var badgeRow = new FlowLayoutPanel { Dock = DockStyle.Top, Height = Theme.S(26), BackColor = Color.Transparent };
        badgeRow.Controls.Add(_lineBadge);
        lineCard.Stack(_lineName, badgeRow, _lineInfo);

        var totals = new Card("Totals") { Dock = DockStyle.Top, Height = Theme.S(170) };
        var t = new TableLayoutPanel { Dock = DockStyle.Fill, ColumnCount = 2, RowCount = 4, BackColor = Color.Transparent };
        t.ColumnStyles.Add(new ColumnStyle(SizeType.Percent, 50));
        t.ColumnStyles.Add(new ColumnStyle(SizeType.Percent, 50));
        void Row(int r, string label, Label value)
        {
            t.Controls.Add(new UiLabel(label, Theme.Ui, Theme.InkMuted), 0, r);
            value.Anchor = AnchorStyles.Right;
            t.Controls.Add(value, 1, r);
        }
        t.Controls.Add(_items, 0, 0);
        t.SetColumnSpan(_items, 2);
        Row(1, "Gross", _gross);
        Row(2, "Discount", _discount);
        Row(3, "Sales tax", _tax);
        totals.Controls.Add(t);

        var netCard = new Card("Net payable") { Dock = DockStyle.Top, Height = Theme.S(118) };
        _net.AutoSize = false;
        _net.Dock = DockStyle.Fill;
        _net.TextAlign = ContentAlignment.MiddleRight;
        _net.ForeColor = Theme.Accent;
        netCard.Controls.Add(_net);

        _pay.Large();
        _pay.Dock = DockStyle.Top;
        _pay.Height = Theme.S(52);
        var actions = new TableLayoutPanel { Dock = DockStyle.Top, ColumnCount = 2, RowCount = 2, Height = Theme.S(92), BackColor = Color.Transparent };
        actions.ColumnStyles.Add(new ColumnStyle(SizeType.Percent, 50));
        actions.ColumnStyles.Add(new ColumnStyle(SizeType.Percent, 50));
        UiButton A(string text, string hint, Func<Task> run)
        {
            var b = new UiButton(text, ButtonKind.Secondary, hint) { Dock = DockStyle.Fill, Margin = new Padding(0, Theme.S(6), Theme.S(6), 0) };
            b.Click += (_, _) => Fire(run);
            return b;
        }
        actions.Controls.Add(A("Discount", "F6", Discount), 0, 0);
        actions.Controls.Add(A("Hold / recall", "F5", HoldOrRecall), 1, 0);
        actions.Controls.Add(A("Batch", "F7", PickBatch), 0, 1);
        actions.Controls.Add(A("Return", "F9", Return), 1, 1);
        _last.AutoSize = false;
        _last.Dock = DockStyle.Top;
        _last.Height = Theme.S(40);
        right.Stack(lineCard, Box.Gap(8), totals, Box.Gap(8), netCard, Box.Gap(10), _pay, actions, Box.Gap(6), _last);
        return right;
    }

    public override void FocusFirst()
    {
        if (!_scan.Focused) _scan.Focus();
    }

    protected override async Task OnFirstShow()
    {
        Render();
        await RecoverDraft();
    }

    protected override Task OnReshow()
    {
        Render();
        return Task.CompletedTask;
    }

    // ---------------- scanning and searching ----------------

    private static bool LooksLikeCode(string text)
    {
        var t = text.Trim();
        if (t.Length == 0) return false;
        if (Gs1Parser.IsCandidate(t)) return true;
        return t.Length >= 6 && t.All(char.IsDigit);
    }

    private void ScanKeyDown(object? sender, KeyEventArgs e)
    {
        if (e.KeyCode == Keys.Enter)
        {
            e.Handled = e.SuppressKeyPress = true;
            var text = _scan.Text.Trim();
            if (text.Length == 0) { if (_cart.Count > 0) Fire(Pay); return; }
            if ((text.StartsWith("*") || text.StartsWith("+")) && int.TryParse(text.Substring(1), out var q)) { SetQty(q); _scan.Clear(); return; }
            if (_resultsPanel.Visible && _results.Current<MedicineListItem>() is MedicineListItem m && !LooksLikeCode(text)) { Fire(() => AddFromSearch(m)); return; }
            Fire(() => LooksLikeCode(text) ? ScanCode(text) : SearchAndAdd(text));
            return;
        }
        if (e.KeyCode == Keys.Escape)
        {
            if (_resultsPanel.Visible) HideResults();
            else _scan.Clear();
            e.Handled = e.SuppressKeyPress = true;
            return;
        }
        if (e.KeyCode == Keys.Down || e.KeyCode == Keys.Up)
        {
            var grid = _resultsPanel.Visible ? _results : _grid;
            if (grid.RowCount > 0)
            {
                var i = grid.CurrentRow?.Index ?? -1;
                grid.SelectRow(Compat.Clamp(i + (e.KeyCode == Keys.Down ? 1 : -1), 0, grid.RowCount - 1));
            }
            e.Handled = true;
            return;
        }
        if (_scan.TextLength == 0 && Selected != null)
        {
            if (e.KeyCode == Keys.Delete) { RemoveSelected(); e.Handled = true; }
            else if (e.KeyCode == Keys.Add || e.KeyCode == Keys.Oemplus) { SetQty(Selected.Qty + 1); e.Handled = e.SuppressKeyPress = true; }
            else if (e.KeyCode == Keys.Subtract || e.KeyCode == Keys.OemMinus) { if (Selected.Qty > 1) SetQty(Selected.Qty - 1); e.Handled = e.SuppressKeyPress = true; }
        }
    }

    private void GridKeyDown(object? sender, KeyEventArgs e)
    {
        if (Selected == null) return;
        if (e.KeyCode == Keys.Delete) { RemoveSelected(); e.Handled = true; }
        else if (e.KeyCode == Keys.Add || e.KeyCode == Keys.Oemplus) { SetQty(Selected.Qty + 1); e.Handled = true; }
        else if (e.KeyCode == Keys.Subtract || e.KeyCode == Keys.OemMinus) { if (Selected.Qty > 1) SetQty(Selected.Qty - 1); e.Handled = true; }
        else if (e.KeyCode == Keys.Escape) { _scan.Focus(); e.Handled = true; }
    }

    private async Task Search()
    {
        var q = _scan.Text.Trim();
        if (q.Length < 2 || LooksLikeCode(q)) { HideResults(); return; }
        var list = await Session.Api.Get<List<MedicineListItem>>("medicines", new { q, limit = 30 });
        if (_scan.Text.Trim() != q) return; // typed on meanwhile
        _results.SetRows(list, m => new object?[] { m.Name + (m.Strength.Length > 0 && !m.Name.Has(m.Strength) ? " " + m.Strength : "") + (m.Generic.Length > 0 ? "  ·  " + m.Generic : ""), m.DefaultPack, m.SalePrice, m.StockQty > 0 ? m.StockText : "Out of stock" });
        if (list.Count > 0) _results.SelectRow(0);
        ShowResults();
    }

    private void ShowResults()
    {
        var p = PointToClient(_scan.Parent!.PointToScreen(new Point(_scan.Left, _scan.Bottom)));
        _resultsPanel.SetBounds(p.X, p.Y + Theme.S(2), Math.Max(_scan.Width, Theme.S(600)), Theme.S(Math.Min(10, Math.Max(_results.RowCount, 3)) * (Theme.Compact ? 28 : 34) + 40));
        _resultsPanel.Visible = true;
        _resultsPanel.BringToFront();
    }

    private void HideResults() => _resultsPanel.Visible = false;

    private async Task SearchAndAdd(string text)
    {
        var list = await Session.Api.Get<List<MedicineListItem>>("medicines", new { q = text, limit = 30 });
        if (list.Count == 1) { await AddFromSearch(list[0]); return; }
        _results.SetRows(list, m => new object?[] { m.Name + (m.Generic.Length > 0 ? "  ·  " + m.Generic : ""), m.DefaultPack, m.SalePrice, m.StockQty > 0 ? m.StockText : "Out of stock" });
        if (list.Count > 0) _results.SelectRow(0);
        ShowResults();
    }

    private async Task<MedicineDto> Medicine(long id)
    {
        if (_medCache.TryGetValue(id, out var c) && (DateTime.UtcNow - c.at).TotalMinutes < 2) return c.med;
        var med = await Session.Api.Get<MedicineDto>($"medicines/{id}");
        _medCache[id] = (med, DateTime.UtcNow);
        return med;
    }

    private async Task AddFromSearch(MedicineListItem item)
    {
        HideResults();
        _scan.Clear();
        var med = await Medicine(item.Id);
        if (med.StockQty <= 0) { Fail($"{med.Name} is out of saleable stock.", Tone.Warn); return; }
        AddLine(med, null, null, "FEFO", "");
    }

    private async Task ScanCode(string code)
    {
        HideResults();
        _scan.Clear();
        try
        {
            var r = await Session.Api.Get<ScanResult>("medicines/scan", new { code });
            _medCache[r.Medicine.Id] = (r.Medicine, DateTime.UtcNow);
            var pack = r.PackId.HasValue ? r.Medicine.Packs.FirstOrDefault(p => p.Id == r.PackId && p.IsSellable) : null;
            if (r.Gs1?.Batch != null)
            {
                var b = r.Batches.FirstOrDefault(x => x.BatchNo.Equals(r.Gs1.Batch, StringComparison.OrdinalIgnoreCase));
                if (b != null && (b.State == "EXPIRED" || b.State == "QUARANTINE"))
                {
                    Beep();
                    Fail(r.Warning ?? $"Batch {b.BatchNo} cannot be sold.");
                    return;
                }
            }
            if (r.Medicine.StockQty <= 0) { Beep(); Fail($"{r.Medicine.Name} is out of saleable stock.", Tone.Warn); return; }
            var label = r.ScannedBatchId.HasValue ? r.Batches.First(b => b.BatchId == r.ScannedBatchId).BatchNo : "";
            AddLine(r.Medicine, pack, r.ScannedBatchId, r.ScannedBatchId.HasValue ? "2D" : "FEFO", label);
            if (r.Warning != null) Fail(r.Warning, Tone.Warn);
        }
        catch (ApiException ex) when (!ex.IsOffline && !ex.IsAuth)
        {
            Beep();
            Fail(ex.Message);
        }
    }

    private void Beep()
    {
        if (Session.Config.SoundOnScanError) SystemSounds.Hand.Play();
    }

    private void Fail(string text, Tone tone = Tone.Danger) => Bar.Show(text, tone);

    // ---------------- cart operations ----------------

    private CartLine? Selected => _grid.Current<CartLine>();

    private void AddLine(MedicineDto med, PackDto? pack, long? batchId, string pick, string batchLabel)
    {
        Bar.Clear();
        pack ??= med.Packs.FirstOrDefault(p => p.Name == med.DefaultSalePack && p.IsSellable) ?? med.Packs.Where(p => p.IsSellable).OrderBy(p => p.BaseQty).FirstOrDefault();
        if (pack == null) { Fail($"{med.Name} has no sellable pack. A manager can fix this in the medicine editor."); return; }
        var existing = _cart.FirstOrDefault(l => l.Med.Id == med.Id && l.Pack.Id == pack.Id && l.BatchId == batchId);
        if (existing != null)
        {
            existing.Qty++;
            existing.Error = null;
            Changed(existing);
            return;
        }
        var line = new CartLine { Med = med, Pack = pack, BatchId = batchId, Pick = pick, BatchLabel = batchLabel };
        _cart.Add(line);
        Changed(line);
        if (line.Controlled) Fail($"{med.Name} is a Schedule {med.Schedule} medicine. The prescription details will be asked for at payment.", Tone.Info);
        else if (med.RxRequired) Fail($"{med.Name} needs a prescription. Check it before selling.", Tone.Info);
    }

    private void SetQty(int qty)
    {
        var l = Selected;
        if (l == null || qty <= 0) return;
        if (qty > 9999) { Fail("That quantity looks wrong. Enter up to 9,999."); return; }
        l.Qty = qty;
        l.Error = null;
        Changed(l);
    }

    private void RemoveSelected()
    {
        var l = Selected;
        if (l == null) return;
        var i = _cart.IndexOf(l);
        _cart.Remove(l);
        Changed(i < _cart.Count ? _cart[i] : _cart.LastOrDefault());
    }

    private void ClearWithConfirm()
    {
        if (_cart.Count == 0) return;
        if (!Msg.Confirm(FindForm(), "Remove all items from this sale?")) return;
        Reset();
    }

    private void Reset()
    {
        _cart.Clear();
        _customer = null;
        _invoiceDiscount = 0;
        _holdId = null;
        _key = Compat.NewKey();
        _quote = null;
        Render();
        Shell?.RefreshKeys();
    }

    private void Changed(CartLine? select)
    {
        _pending = null;
        _quote = null;
        Render(select);
        _quoteTimer.Stop();
        _quoteTimer.Start();
        Shell?.RefreshKeys();
    }

    private SaleRequest BuildRequest() => new SaleRequest
    {
        IdempotencyKey = _key,
        CustomerId = _customer?.Id,
        CounterNo = Session.Counter,
        InvoiceDiscount = _invoiceDiscount,
        HoldId = _holdId,
        Lines = _cart.Select(l => new SaleLineInput { MedicineId = l.Med.Id, PackId = l.Pack.Id, Qty = l.Qty, BatchId = l.BatchId, Pick = l.Pick, DiscountAmount = l.Discount }).ToList(),
        Prescriptions = _cart.Select((l, i) => (l, i)).Where(x => x.l.Rx != null).Select(x => { x.l.Rx!.LineIndex = x.i; return x.l.Rx; }).ToList(),
    };

    /// <summary>Asks the host to price the cart (FEFO split, batch prices, discounts, tax).</summary>
    private async Task Quote()
    {
        var seq = ++_quoteSeq;
        if (_cart.Count == 0) { _quote = null; Render(); return; }
        try
        {
            var q = await Session.Api.Post<SaleQuote>("sales/quote", BuildRequest());
            if (seq != _quoteSeq) return;
            _quote = q;
            foreach (var l in _cart) l.Error = null;
            for (int i = 0; i < _cart.Count && i < q.Lines.Count; i++) _cart[i].Quote = q.Lines[i];
            if (Bar.Visible && Bar.BackColor == Theme.DangerBg && Bar.Tag as string == "quote") Bar.Clear();
            Render(Selected);
        }
        catch (ApiException ex) when (!ex.IsAuth)
        {
            if (seq != _quoteSeq) return;
            _quote = null;
            if (int.TryParse(ex.Details.Get("lineIndex"), out var idx) && idx >= 0 && idx < _cart.Count) _cart[idx].Error = ex.Message;
            else if (ex.Code == ErrorCodes.BatchNotSaleable) foreach (var l in _cart.Where(l => l.BatchId.HasValue)) l.Error = ex.Message;
            Fail(ex.Message, ex.IsOffline ? Tone.Warn : Tone.Danger);
            Bar.Tag = "quote";
            Render(Selected);
        }
    }

    private async Task<SaleQuote?> EnsureQuote()
    {
        if (_quoteTimer.Enabled) { _quoteTimer.Stop(); await Quote(); }
        else if (_quoteTask != null && !_quoteTask.IsCompleted) await _quoteTask;
        if (_quote == null && _cart.Count > 0) await Quote();
        return _quote;
    }

    private void Render(CartLine? select = null)
    {
        _grid.SetRows(_cart, l => new object?[]
        {
            _cart.IndexOf(l) + 1,
            l.Med.Name + (l.Med.Strength.Length > 0 && !l.Med.Name.Has(l.Med.Strength) ? " " + l.Med.Strength : "") + (l.Controlled ? $"  [Sch {l.Med.Schedule}]" : ""),
            l.Pack.Name,
            l.Qty,
            l.Quote?.Rate ?? l.Pack.SalePrice,
            l.Quote?.Discount ?? l.Discount,
            l.Quote?.Net,
            l.Error != null ? "⚠ " + l.Error : l.Quote != null ? (l.Pick == "FEFO" ? "" : l.Pick + ": ") + l.Quote.Batches : (l.BatchLabel.Length > 0 ? l.Pick + ": " + l.BatchLabel : "FEFO"),
        });
        if (select != null)
        {
            var idx = _cart.IndexOf(select);
            if (idx >= 0) _grid.SelectRow(idx);
        }
        else if (_cart.Count > 0 && _grid.CurrentRow == null) _grid.SelectRow(_cart.Count - 1);

        var q = _quote;
        var units = _cart.Sum(l => l.Qty);
        _items.Text = _cart.Count == 0 ? "No items" : $"{_cart.Count} line{(_cart.Count == 1 ? "" : "s")} · {units} pack{(units == 1 ? "" : "s")}";
        _gross.Text = q == null ? "…" : Money.Format(q.Gross);
        _discount.Text = q == null ? "…" : q.Discount == 0 ? "0.00" : $"-{Money.Format(q.Discount)} ({q.DiscountPercent:0.##}%)";
        _discount.ForeColor = q != null && q.DiscountPercent > (Session.Login?.MaxDiscountPercent ?? 0) ? Theme.Warn : Theme.Ink;
        _tax.Text = q == null ? "…" : Money.Format(q.Tax);
        _net.Text = _cart.Count == 0 ? "0.00" : q == null ? "…" : Money.Format(q.Net);
        _pay.Enabled = _cart.Count > 0;
        _customerButton.Text = _customer == null ? "Walk-in customer" : _customer.Name + (_customer.Type == "CREDIT" || _customer.Balance != 0 ? $"  ·  due {Money.Format(_customer.Balance)}" : "");
        _customerButton.Kind = _customer == null ? ButtonKind.Secondary : ButtonKind.Accent;
        ShowLine();
        Shell?.RefreshKeys();
    }

    private void ShowLine()
    {
        var l = Selected;
        if (l == null)
        {
            _lineName.Text = "";
            _lineInfo.Text = "Scan or search to add medicines.\n+ / − change quantity, Del removes the line, Enter edits it.";
            _lineBadge.Set("", Tone.None);
            return;
        }
        _lineName.Text = l.Med.Name;
        var parts = new List<string>();
        if (l.Med.Generic.Length > 0) parts.Add(l.Med.Generic);
        parts.Add(string.Join(" · ", new[] { l.Med.Strength, l.Med.DosageForm, l.Med.Manufacturer }.Where(x => x.Length > 0)));
        parts.Add($"Stock: {QtyFormat.Format(l.Med.StockQty, l.Med.Packs.Select(p => new PackSize(p.Name, p.BaseQty)), l.Med.BaseUnit)}" + (l.Med.Rack.Length > 0 ? $" · Rack {l.Med.Rack}" : ""));
        if (l.Quote != null) parts.Add($"Batch: {l.Quote.Batches}");
        if (l.Rx != null) parts.Add($"Rx: {l.Rx.Prescriber} for {l.Rx.Patient}");
        _lineInfo.Text = string.Join("\n", parts.Where(p => p.Length > 0));
        if (l.Error != null) _lineBadge.Set("Problem", Tone.Danger);
        else if (l.Controlled) _lineBadge.Set($"Schedule {l.Med.Schedule}", Tone.Warn);
        else if (l.Pick == "2D") _lineBadge.Set("2D scanned batch", Tone.Info);
        else if (l.Pick == "MANUAL") _lineBadge.Set("Batch chosen", Tone.Info);
        else _lineBadge.Set("FEFO", Tone.Ok);
    }

    // ---------------- actions ----------------

    private async Task EditLine()
    {
        var l = Selected;
        if (l == null) return;
        using var d = new LineDialog(l);
        if (d.ShowDialog(FindForm()) != DialogResult.OK) return;
        if (d.Remove) { RemoveSelected(); return; }
        l.Error = null;
        Changed(l);
        await Task.CompletedTask;
    }

    private async Task PickCustomer()
    {
        using var d = new CustomerPickDialog(allowWalkIn: true);
        if (d.ShowDialog(FindForm()) != DialogResult.OK) return;
        _customer = d.Chosen == null || d.Chosen.IsWalkIn ? null : d.Chosen;
        Render(Selected);
        Shell?.RefreshKeys();
        await Task.CompletedTask;
    }

    private async Task Discount()
    {
        if (_cart.Count == 0) return;
        var q = await EnsureQuote();
        using var d = new DiscountDialog(q?.Gross ?? 0, _invoiceDiscount, Selected, Session.Login?.MaxDiscountPercent ?? 0);
        if (d.ShowDialog(FindForm()) != DialogResult.OK) return;
        _invoiceDiscount = d.InvoiceDiscount;
        if (Selected != null) Selected.Discount = d.LineDiscount;
        Changed(Selected);
    }

    private async Task PickBatch()
    {
        var l = Selected;
        if (l == null) return;
        using var d = new BatchPickDialog(l.Med, l.BatchId);
        if (d.ShowDialog(FindForm()) != DialogResult.OK) return;
        l.BatchId = d.ChosenBatchId;
        l.Pick = d.ChosenBatchId.HasValue ? "MANUAL" : "FEFO";
        l.BatchLabel = d.ChosenBatchNo;
        l.Error = null;
        Changed(l);
        await Task.CompletedTask;
    }

    private async Task HoldOrRecall()
    {
        if (_cart.Count > 0)
        {
            var note = Msg.Ask(FindForm(), "Hold this sale", "Note (e.g. customer name or what they are waiting for)", _customer?.Name ?? "", required: false);
            if (note == null) return;
            var q = await EnsureQuote();
            await Session.Api.Post<OkResult>("holds", new HoldSaveRequest
            {
                CounterNo = Session.Counter, CustomerId = _customer?.Id, CustomerName = _customer?.Name ?? "Walk-in", Note = note,
                Lines = _cart.Count, Amount = q?.Net ?? 0, PayloadJson = JsonSerializer.Serialize(ToDraft(), ApiClient.Json),
            });
            Reset();
            Bar.Show("Sale held for 24 hours. Press F5 with an empty sale to recall it. Stock is not reserved while held.", Tone.Info);
            return;
        }
        using var d = new HoldsDialog();
        if (d.ShowDialog(FindForm()) != DialogResult.OK || d.Recalled == null) return;
        var draft = JsonSerializer.Deserialize<CartDraft>(d.Recalled.PayloadJson, ApiClient.Json);
        if (draft == null) return;
        await LoadDraft(draft);
        Bar.Show($"Recalled held sale ({d.Recalled.Lines} lines). Prices and batches are checked again now.", Tone.Info);
    }

    private CartDraft ToDraft() => new CartDraft
    {
        Key = _key,
        CustomerId = _customer?.Id,
        CustomerName = _customer?.Name ?? "",
        InvoiceDiscount = _invoiceDiscount,
        SavedAt = DateTime.Now,
        Lines = _cart.Select(l => new DraftLine { MedicineId = l.Med.Id, PackId = l.Pack.Id, Qty = l.Qty, BatchId = l.BatchId, Pick = l.Pick, BatchLabel = l.BatchLabel, Discount = l.Discount, Rx = l.Rx }).ToList(),
    };

    private async Task LoadDraft(CartDraft draft, bool keepKey = false)
    {
        _cart.Clear();
        foreach (var dl in draft.Lines)
        {
            var med = await Medicine(dl.MedicineId);
            var pack = med.Packs.FirstOrDefault(p => p.Id == dl.PackId);
            if (pack == null) continue;
            _cart.Add(new CartLine { Med = med, Pack = pack, Qty = dl.Qty, BatchId = dl.BatchId, Pick = dl.Pick, BatchLabel = dl.BatchLabel, Discount = dl.Discount, Rx = dl.Rx });
        }
        _customer = draft.CustomerId.HasValue ? await Session.Api.Get<CustomerDto>($"customers/{draft.CustomerId}") : null;
        if (_customer?.IsWalkIn == true) _customer = null;
        _invoiceDiscount = draft.InvoiceDiscount;
        _key = keepKey && draft.Key.Length > 0 ? draft.Key : Compat.NewKey();
        Changed(_cart.LastOrDefault());
    }

    private async Task Reprint()
    {
        long? id = _lastSale?.Id;
        if (id == null)
        {
            var no = Msg.Ask(FindForm(), "Reprint receipt", "Invoice number");
            if (no == null) return;
            id = (await Session.Api.Get<SaleDetail>($"sales/by-no/{Uri.EscapeDataString(no.Trim())}")).Id;
        }
        var receipt = await Session.Api.Get<ReceiptDto>($"sales/{id}/receipt", new { duplicate = true });
        if (!Printers.Receipt(receipt)) Fail("The receipt could not be printed. Check the printer and paper, then press F8 again.", Tone.Warn);
        else Bar.Show($"Duplicate receipt {receipt.DocNo} printed.", Tone.Ok);
    }

    private async Task Return()
    {
        using var d = new SalesReturnDialog(null);
        d.ShowDialog(FindForm());
        await Task.CompletedTask;
    }

    // ---------------- payment and posting ----------------

    private async Task Pay()
    {
        if (_cart.Count == 0) return;
        HideResults();
        if (_pending != null)
        {
            if (Msg.Confirm(FindForm(), "The last attempt to save this sale did not reach the host.\n\nSend exactly the same sale again now? It cannot be saved twice."))
            {
                await Complete(_pending, await PostWithShift(_pending));
                return;
            }
            _pending = null;
        }
        var q = await EnsureQuote();
        if (q == null) { Fail(Bar.Visible ? Bar.Text : "The sale could not be priced. Check the lines marked in red.", Tone.Danger); return; }

        // controlled medicines need the prescription register details (FR-097)
        foreach (var l in _cart.Where(l => l.Controlled && (l.Rx == null || l.Rx.Prescriber.Length == 0 || l.Rx.Patient.Length == 0)))
        {
            using var rx = new PrescriptionDialog(l);
            if (rx.ShowDialog(FindForm()) != DialogResult.OK) return;
        }

        using var pay = new PaymentDialog(q.Net, _customer);
        if (pay.ShowDialog(FindForm()) != DialogResult.OK) return;
        var req = BuildRequest();
        req.Payments = pay.Payments;
        SaleResult result;
        try
        {
            result = await PostWithShift(req);
        }
        catch (ApiException ex) when (ex.IsOffline)
        {
            _pending = req;
            SaveDraft(req);
            throw;
        }
        catch (ApiException ex) when (ex.Code == ErrorCodes.StockChanged || ex.Code == ErrorCodes.BatchNotSaleable)
        {
            if (int.TryParse(ex.Details.Get("lineIndex"), out var idx) && idx >= 0 && idx < _cart.Count) _cart[idx].Error = ex.Message;
            Render(Selected);
            throw;
        }
        await Complete(req, result);
    }

    private async Task Complete(SaleRequest req, SaleResult result)
    {
        _pending = null;
        DeleteDraft();
        _lastSale = result;
        var cash = req.Payments.Any(p => p.Method == PaymentMethods.Cash);
        var printed = !Session.Config.AutoPrintReceipt || Printers.Receipt(result.Receipt, openDrawer: cash);
        Reset();
        _last.Text = $"Last sale {result.No} · Rs {Money.Format(result.Net)}" + (result.Change > 0 ? $" · change Rs {Money.Format(result.Change)}" : "");
        if (!printed) Bar.Show($"Sale {result.No} is saved, but the receipt did not print. Check the printer, then press F8 to reprint.", Tone.Warn);
        else Bar.Show(result.Change > 0 ? $"Sale {result.No} saved.  GIVE CHANGE: Rs {Money.Format(result.Change)}" : $"Sale {result.No} saved.", Tone.Ok);
        _scan.Focus();
        await Task.CompletedTask;
    }

    private async Task<SaleResult> PostWithShift(SaleRequest req)
    {
        try
        {
            return await Approvals.Run(FindForm()!, tokens => { req.ApprovalTokens = tokens.ToList(); return Session.Api.Post<SaleResult>("sales", req, tokens); });
        }
        catch (ApiException ex) when (ex.Code == ErrorCodes.ShiftRequired)
        {
            using var open = new OpenShiftDialog();
            if (open.ShowDialog(FindForm()) != DialogResult.OK) throw new OperationCanceledException();
            return await Approvals.Run(FindForm()!, tokens => { req.ApprovalTokens = tokens.ToList(); return Session.Api.Post<SaleResult>("sales", req, tokens); });
        }
    }

    // ---------------- unconfirmed sale (host down at payment) ----------------

    private sealed class PendingSale
    {
        public DateTime SavedAt { get; set; }
        public string CustomerName { get; set; } = "";
        public decimal Net { get; set; }
        public SaleRequest Request { get; set; } = new SaleRequest();
        public CartDraft Cart { get; set; } = new CartDraft();
    }

    private static string DraftPath => Path.Combine(ClientConfig.DraftDir, $"pending-sale-counter{Session.Counter}.json");

    private void SaveDraft(SaleRequest req)
    {
        try
        {
            Directory.CreateDirectory(ClientConfig.DraftDir);
            var p = new PendingSale { SavedAt = DateTime.Now, CustomerName = _customer?.Name ?? "Walk-in", Net = _quote?.Net ?? 0, Request = req, Cart = ToDraft() };
            File.WriteAllText(DraftPath, JsonSerializer.Serialize(p, ApiClient.Json));
        }
        catch (Exception ex) { Log.Error("Saving sale draft", ex); }
    }

    private static void DeleteDraft()
    {
        try { if (File.Exists(DraftPath)) File.Delete(DraftPath); } catch (Exception ex) { Log.Error("Deleting sale draft", ex); }
    }

    /// <summary>On start-up: a sale that was sent while the host was unreachable is resent unchanged.</summary>
    private async Task RecoverDraft()
    {
        if (!File.Exists(DraftPath)) return;
        PendingSale? p = null;
        try { p = JsonSerializer.Deserialize<PendingSale>(File.ReadAllText(DraftPath), ApiClient.Json); } catch (Exception ex) { Log.Error("Reading sale draft", ex); }
        if (p == null || p.Request.Lines.Count == 0) { DeleteDraft(); return; }
        if (!Msg.Confirm(FindForm(), $"A sale from {p.SavedAt:dd-MMM HH:mm} ({p.CustomerName}, Rs {Money.Format(p.Net)}) was sent while the host was unreachable, so this PC does not know if it was saved.\n\nSend it again now? If it was saved, the host recognises it and nothing is saved twice."))
        {
            DeleteDraft();
            return;
        }
        try
        {
            var result = await Session.Api.Post<SaleResult>("sales", p.Request, p.Request.ApprovalTokens);
            await Complete(p.Request, result);
        }
        catch (ApiException ex) when (!ex.IsOffline && !ex.IsAuth)
        {
            DeleteDraft();
            await LoadDraft(p.Cart, keepKey: true);
            Fail("The sale was not saved: " + ex.Message + " It is back in the cart; check it and take payment again.");
        }
    }
}
