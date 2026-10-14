using MediCare.Client.Screens;

namespace MediCare.Client.Dialogs;

/// <summary>SCR-05 Payment: cash (tendered and change), card, wallet, bank and credit; split allowed.</summary>
public sealed class PaymentDialog : DialogForm
{
    private readonly decimal _net;
    private readonly CustomerDto? _customer;
    private readonly Dictionary<string, MoneyBox> _amounts = new Dictionary<string, MoneyBox>();
    private readonly Dictionary<string, UiText> _refs = new Dictionary<string, UiText>();
    private readonly MoneyBox _tendered = new MoneyBox();
    private readonly Label _cashDue = new UiLabel("", Theme.NumLg);
    private readonly Label _change = new UiLabel("", Theme.NumLg, Theme.Ok);
    private readonly Label _remaining = new UiLabel("", Theme.Ui, Theme.InkMuted);
    private readonly bool _cashAllowed;

    public List<PaymentInput> Payments { get; } = new List<PaymentInput>();

    private static string Label(string m) => m switch
    {
        PaymentMethods.Cash => "Cash",
        PaymentMethods.Card => "Card",
        PaymentMethods.Wallet => "Mobile wallet (JazzCash / Easypaisa)",
        PaymentMethods.Bank => "Bank transfer",
        PaymentMethods.Credit => "On account (credit)",
        _ => m,
    };

    public PaymentDialog(decimal net, CustomerDto? customer) : base("Take payment", 620)
    {
        _net = net;
        _customer = customer;
        var methods = Session.Settings.PaymentMethods.Count > 0 ? Session.Settings.PaymentMethods : PaymentMethods.All.ToList();
        _cashAllowed = methods.Contains(PaymentMethods.Cash);

        var netLabel = new UiLabel("NET PAYABLE", Theme.Xs, Theme.InkMuted);
        var netValue = new UiLabel("Rs " + Money.Format(net), Theme.Total, Theme.Accent);
        var f = new FieldGrid(2);
        if (_cashAllowed)
        {
            f.Add("Cash to receive", _cashDue);
            f.Add("Cash tendered by customer", _tendered, hint: "Type what the customer hands over");
            var quick = new ButtonRow();
            quick.Add("Exact", ButtonKind.Secondary, () => { _tendered.Value = CashDue; Recalc(); _tendered.Focus(); });
            foreach (var v in new[] { 500, 1000, 5000 })
                quick.Add(v.ToString("#,##0", CultureInfo.InvariantCulture), ButtonKind.Secondary, () => { _tendered.Value = v; Recalc(); _tendered.Focus(); });
            f.AddFull(quick);
            f.Add("Change to give", _change, 2);
        }
        foreach (var m in methods.Where(m => m != PaymentMethods.Cash))
        {
            var box = new MoneyBox();
            _amounts[m] = box;
            box.TextChanged += (_, _) => Recalc();
            if (m == PaymentMethods.Credit)
            {
                var info = customer == null ? "Pick a named customer (F3) to sell on credit." : $"{customer.Name}: balance {Money.FormatRs(customer.Balance)}, limit {Money.FormatRs(customer.CreditLimit)}";
                f.Add(Label(m), box, hint: info);
                box.Enabled = customer != null;
                f.Break();
            }
            else
            {
                f.Add(Label(m), box);
                var r = new UiText("Reference / last 4 digits");
                _refs[m] = r;
                f.Add("Reference", r);
            }
        }
        f.AddFull(_remaining);
        _tendered.TextChanged += (_, _) => Recalc();
        Body.Stack(netLabel, netValue, Box.Gap(12), f);
        AddOk("Complete sale", Complete);
        AddCancel("Back to sale");
        FitToContent();
        if (_cashAllowed) _tendered.Value = net;
        Recalc();
        Shown += (_, _) => { if (_cashAllowed) { _tendered.Focus(); _tendered.SelectAll(); } };
    }

    private decimal Others => _amounts.Values.Sum(b => b.Value);
    private decimal CashDue => _cashAllowed ? Math.Max(0, _net - Others) : 0;

    private void Recalc()
    {
        var due = CashDue;
        _cashDue.Text = "Rs " + Money.Format(due);
        var change = _tendered.HasValue ? _tendered.Value - due : 0;
        _change.Text = change > 0 ? "Rs " + Money.Format(change) : "Rs 0.00";
        _change.ForeColor = change >= 0 ? Theme.Ok : Theme.Danger;
        var over = Others - _net;
        _remaining.Text = over > 0 ? $"Card, wallet, bank and credit add up to more than the net payable by {Money.FormatRs(over)}." :
            !_cashAllowed && Others < _net ? $"Remaining to allocate: {Money.FormatRs(_net - Others)}" : "";
        _remaining.ForeColor = over > 0 || (!_cashAllowed && Others < _net) ? Theme.Danger : Theme.InkMuted;
    }

    private Task<bool> Complete()
    {
        Payments.Clear();
        if (Others > _net) { Fail("The other payments are more than the net payable. Reduce them."); return Task.FromResult(false); }
        foreach (var kv in _amounts.Where(kv => kv.Value.Value > 0))
            Payments.Add(new PaymentInput { Method = kv.Key, Amount = kv.Value.Value, Reference = _refs.TryGetValue(kv.Key, out var r) ? r.Text.Trim() : null });
        var cash = CashDue;
        if (cash > 0)
        {
            var tendered = _tendered.HasValue ? _tendered.Value : cash;
            if (tendered < cash) { Fail($"Cash tendered is less than the cash due ({Money.FormatRs(cash)}). Enter more, or put the rest on card or credit."); _tendered.Focus(); return Task.FromResult(false); }
            Payments.Insert(0, new PaymentInput { Method = PaymentMethods.Cash, Amount = cash, Tendered = tendered });
        }
        if (Payments.Sum(p => p.Amount) != _net) { Fail($"Payments must add up to exactly {Money.FormatRs(_net)}."); return Task.FromResult(false); }
        return Task.FromResult(true);
    }
}

/// <summary>F6 discount: invoice discount (amount or %) and the selected line's discount.</summary>
public sealed class DiscountDialog : DialogForm
{
    private readonly MoneyBox _amount = new MoneyBox();
    private readonly MoneyBox _percent = new MoneyBox();
    private readonly MoneyBox _line = new MoneyBox();
    private readonly decimal _gross;
    private bool _sync;

    public decimal InvoiceDiscount => _amount.Value;
    public decimal LineDiscount => _line.Value;

    public DiscountDialog(decimal gross, decimal current, CartLine? line, decimal maxPercent) : base("Discount", 480)
    {
        _gross = gross;
        var f = new FieldGrid(2);
        f.Add("Invoice discount (Rs)", _amount);
        f.Add("or percent of Rs " + Money.Format(gross), _percent);
        if (line != null)
        {
            f.Add($"Discount on {line.Med.Name} (Rs)", _line, 2, hint: line.Quote != null ? $"Line amount before discount: {Money.FormatRs(line.Quote.Gross)}" : "");
            _line.Value = line.Discount;
        }
        f.AddFull(new UiLabel(maxPercent > 0 ? $"Your discount limit is {maxPercent:0.##}%. Above it, a manager approves with their PIN at payment." : "Any discount needs a manager's approval at payment.", Theme.Small, Theme.InkMuted).Wrap(Theme.S(420)));
        _amount.Value = current;
        _percent.Value = gross > 0 ? Money.Round2(current / gross * 100) : 0;
        _amount.TextChanged += (_, _) => { if (_sync) return; _sync = true; _percent.Value = _gross > 0 ? Money.Round2(_amount.Value / _gross * 100) : 0; _sync = false; };
        _percent.TextChanged += (_, _) => { if (_sync || !_percent.Focused) return; _sync = true; _amount.Value = Money.Round2(_gross * _percent.Value / 100); _sync = false; };
        Body.Stack(f);
        AddOk("Apply", () =>
        {
            if (_amount.Value > _gross) { Fail("The discount is more than the sale."); return Task.FromResult(false); }
            if (_percent.Value > 100) { Fail("Percent cannot be more than 100."); return Task.FromResult(false); }
            return Task.FromResult(true);
        });
        AddCancel();
        FitToContent();
    }
}

/// <summary>Edit a cart line: pack, quantity, line discount; or remove it.</summary>
public sealed class LineDialog : DialogForm
{
    private readonly CartLine _line;
    private readonly UiCombo _pack = new UiCombo();
    private readonly IntBox _qty = new IntBox();
    private readonly MoneyBox _disc = new MoneyBox();
    public bool Remove { get; private set; }

    public LineDialog(CartLine line) : base(line.Med.Name, 460)
    {
        _line = line;
        _pack.SetItems(line.Med.Packs.Where(p => p.IsSellable), line.Pack);
        _pack.SelectWhere<PackDto>(p => p.Id == line.Pack.Id);
        _qty.Value = line.Qty;
        _disc.Value = line.Discount;
        var f = new FieldGrid(2);
        f.Add("Pack", _pack);
        f.Add("Quantity", _qty);
        f.Add("Line discount (Rs)", _disc);
        f.AddFull(new UiLabel($"Price per {line.Pack.Name}: Rs {Money.Format(line.Pack.SalePrice)} (the batch price applies if it differs)", Theme.Small, Theme.InkMuted));
        Body.Stack(f);
        AddOk("Apply", Apply);
        AddCancel();
        AddButton("Remove line", () => { Remove = true; DialogResult = DialogResult.OK; Close(); }, ButtonKind.Danger, "Del");
        FitToContent();
        Shown += (_, _) => { _qty.Focus(); _qty.SelectAll(); };
    }

    private Task<bool> Apply()
    {
        if (_qty.Value <= 0) { Fail("Quantity must be at least 1."); return Task.FromResult(false); }
        if (_pack.Selected<PackDto>() is PackDto p) _line.Pack = p;
        _line.Qty = _qty.Value;
        _line.Discount = _disc.Value;
        return Task.FromResult(true);
    }
}

/// <summary>SCR-06 Batch picker: every batch with expiry, stock and rack. Expired and quarantined batches cannot be chosen.</summary>
public sealed class BatchPickDialog : DialogForm
{
    private readonly Grid _grid = new Grid();
    private readonly MedicineDto _med;
    public long? ChosenBatchId { get; private set; }
    public string ChosenBatchNo { get; private set; } = "";

    public BatchPickDialog(MedicineDto med, long? current) : base("Choose batch: " + med.Name, 760)
    {
        _med = med;
        _grid.Col("Batch", 140, ColKind.Mono);
        _grid.Col("Expiry", 110, ColKind.Date);
        _grid.Col("Days left", 90, ColKind.Int);
        _grid.Col("Available", 0, ColKind.Text, 140);
        _grid.Col("Rack", 80);
        _grid.Col("State", 110);
        _grid.Col("Price", 100, ColKind.Money);
        _grid.RowTone = o => o is BatchAvailDto b ? (!b.Saleable ? Tone.Danger : b.DaysLeft <= Session.Settings.NearExpiryDays ? Tone.Warn : Tone.None) : Tone.None;
        _grid.RowActivated += _ => UiRun.Fire(this, () => RunOk(Choose));
        var host = new Panel { Dock = DockStyle.Top, Height = Theme.S(300) };
        host.Controls.Add(_grid);
        Body.Stack(new UiLabel("FEFO sells the earliest-expiry saleable batch first. Choose a batch only when the customer needs a specific one.", Theme.Small, Theme.InkMuted).Wrap(Theme.S(700)), Box.Gap(8), host);
        AddOk("Use this batch", Choose);
        AddCancel();
        AddButton("Use FEFO (automatic)", () => { ChosenBatchId = null; ChosenBatchNo = ""; DialogResult = DialogResult.OK; Close(); }, ButtonKind.Ghost);
        FitToContent();
        Load += async (_, _) => await UiRun.Run(this, async () =>
        {
            var list = await Session.Api.Get<List<BatchAvailDto>>($"medicines/{med.Id}/batches");
            var packs = med.Packs.Select(p => new PackSize(p.Name, p.BaseQty)).ToList();
            _grid.SetRows(list, b => new object?[] { b.BatchNo, b.Expiry, b.DaysLeft, QtyFormat.Format(b.AvailableQty, packs, med.BaseUnit), b.Rack, b.State, b.SalePrice });
            var cur = list.FindIndex(b => b.BatchId == current);
            if (cur >= 0) _grid.SelectRow(cur);
        }, Bar);
    }

    private Task<bool> Choose()
    {
        var b = _grid.Current<BatchAvailDto>();
        if (b == null) return Task.FromResult(false);
        if (!b.Saleable) { Fail(b.State == "EXPIRED" ? $"Batch {b.BatchNo} expired on {Dates.Display(b.Expiry)}. Expired stock cannot be sold." : $"Batch {b.BatchNo} cannot be sold ({b.State.ToLowerInvariant()})."); return Task.FromResult(false); }
        ChosenBatchId = b.BatchId;
        ChosenBatchNo = b.BatchNo;
        return Task.FromResult(true);
    }
}

/// <summary>Find a customer by name, mobile or code; quick-add a new one.</summary>
public sealed class CustomerPickDialog : DialogForm
{
    private readonly UiText _search = new UiText("Name, mobile or code");
    private readonly Grid _grid = new Grid();
    private readonly Timer _timer = new Timer { Interval = 250 };
    public CustomerDto? Chosen { get; private set; }

    public CustomerPickDialog(bool allowWalkIn) : base("Choose customer", 760)
    {
        _grid.Col("Code", 90, ColKind.Mono);
        _grid.Col("Name", 0, ColKind.Text, 200);
        _grid.Col("Mobile", 130, ColKind.Mono);
        _grid.Col("Type", 90);
        _grid.Col("Balance", 110, ColKind.Money);
        _grid.Col("Credit limit", 110, ColKind.Money);
        _grid.RowActivated += _ => UiRun.Fire(this, () => RunOk(Choose));
        _search.Height = Theme.ControlHeight;
        _search.Dock = DockStyle.Top;
        _search.TextChanged += (_, _) => { _timer.Stop(); _timer.Start(); };
        _search.KeyDown += (_, e) =>
        {
            if (e.KeyCode == Keys.Down && _grid.RowCount > 0) { _grid.Focus(); e.Handled = true; }
        };
        _timer.Tick += (_, _) => { _timer.Stop(); UiRun.Fire(this, Load_, Bar); };
        var host = new Panel { Dock = DockStyle.Top, Height = Theme.S(320) };
        host.Controls.Add(_grid);
        Body.Stack(_search, Box.Gap(8), host);
        AddOk("Use customer", Choose);
        AddCancel();
        if (allowWalkIn) AddButton("Walk-in", () => { Chosen = null; DialogResult = DialogResult.OK; Close(); }, ButtonKind.Ghost);
        if (Session.Can(Perm.CustomersEdit)) AddButton("New customer", () => UiRun.Fire(this, AddNew, Bar), ButtonKind.Ghost, "Ctrl+N");
        FitToContent();
        Load += (_, _) => UiRun.Fire(this, Load_, Bar);
    }

    private async Task Load_()
    {
        var list = await Session.Api.Get<List<CustomerDto>>("customers", new { q = _search.Text.Trim() });
        _grid.SetRows(list.Where(c => !c.IsWalkIn), c => new object?[] { c.Code, c.Name, c.Mobile, c.Type, c.Balance, c.CreditLimit });
        if (_grid.RowCount > 0) _grid.SelectRow(0);
    }

    private async Task AddNew()
    {
        using var d = new CustomerEditDialog(null, _search.Text.Trim());
        if (d.ShowDialog(this) != DialogResult.OK || d.SavedId == null) return;
        Chosen = await Session.Api.Get<CustomerDto>($"customers/{d.SavedId}");
        DialogResult = DialogResult.OK;
        Close();
    }

    protected override bool ProcessCmdKey(ref Message msg, Keys keyData)
    {
        if (keyData == (Keys.Control | Keys.N) && Session.Can(Perm.CustomersEdit)) { UiRun.Fire(this, AddNew, Bar); return true; }
        return base.ProcessCmdKey(ref msg, keyData);
    }

    private Task<bool> Choose()
    {
        Chosen = _grid.Current<CustomerDto>();
        if (Chosen == null) { Fail("Choose a customer from the list, or press Walk-in."); return Task.FromResult(false); }
        return Task.FromResult(true);
    }

    protected override void OnFormClosed(FormClosedEventArgs e)
    {
        _timer.Dispose();
        base.OnFormClosed(e);
    }
}

/// <summary>SCR-08 Held sales on this counter (or all counters).</summary>
public sealed class HoldsDialog : DialogForm
{
    private readonly Grid _grid = new Grid();
    private readonly UiCheck _all = new UiCheck("Show all counters");
    public HoldDto? Recalled { get; private set; }

    public HoldsDialog() : base("Held sales", 760)
    {
        _grid.Col("Held at", 140, ColKind.DateTime);
        _grid.Col("Counter", 80, ColKind.Int);
        _grid.Col("By", 140);
        _grid.Col("Customer / note", 0, ColKind.Text, 200);
        _grid.Col("Lines", 70, ColKind.Int);
        _grid.Col("Amount", 110, ColKind.Money);
        _grid.EmptyText = "No held sales.";
        _grid.RowActivated += _ => UiRun.Fire(this, () => RunOk(Recall));
        _all.CheckedChanged += (_, _) => UiRun.Fire(this, Fill, Bar);
        var host = new Panel { Dock = DockStyle.Top, Height = Theme.S(300) };
        host.Controls.Add(_grid);
        Body.Stack(_all, host);
        AddOk("Recall", Recall);
        AddCancel();
        FitToContent();
        Load += (_, _) => UiRun.Fire(this, Fill, Bar);
    }

    private async Task Fill()
    {
        var list = await Session.Api.Get<List<HoldDto>>("holds", new { counter = _all.Checked ? (int?)null : Session.Counter });
        _grid.SetRows(list, h => new object?[] { h.CreatedAt, h.CounterNo, h.User, h.Customer + (h.Note.Length > 0 && h.Note != h.Customer ? " · " + h.Note : ""), h.Lines, h.Amount });
    }

    private async Task<bool> Recall()
    {
        var h = _grid.Current<HoldDto>();
        if (h == null) return false;
        Recalled = await Session.Api.Delete<HoldDto>($"holds/{h.Id}");
        return true;
    }
}

/// <summary>Prescription details for Schedule B / D medicines (controlled register, FR-097).</summary>
public sealed class PrescriptionDialog : DialogForm
{
    private readonly CartLine _line;
    private readonly UiText _prescriber = new UiText("Dr. name");
    private readonly UiText _reg = new UiText("PMDC registration no.");
    private readonly UiText _patient = new UiText();
    private readonly UiText _rx = new UiText();

    public PrescriptionDialog(CartLine line) : base("Prescription: " + line.Med.Name, 520)
    {
        _line = line;
        var f = new FieldGrid(2);
        f.AddFull(new UiLabel($"{line.Med.Name} is a Schedule {line.Med.Schedule} medicine. These details go into the controlled-drug register.", Theme.Ui, Theme.Warn).Wrap(Theme.S(460)));
        f.Add("Prescriber", _prescriber);
        f.Add("Registration no.", _reg);
        f.Add("Patient", _patient);
        f.Add("Prescription no. / date", _rx);
        if (line.Rx != null) { _prescriber.Text = line.Rx.Prescriber; _reg.Text = line.Rx.PrescriberReg; _patient.Text = line.Rx.Patient; _rx.Text = line.Rx.RxNo; }
        Body.Stack(f);
        AddOk("Save", () =>
        {
            if (_prescriber.Text.Trim().Length == 0 || _patient.Text.Trim().Length == 0) { Fail("Prescriber and patient are required."); return Task.FromResult(false); }
            _line.Rx = new PrescriptionInput { Prescriber = _prescriber.Text.Trim(), PrescriberReg = _reg.Text.Trim(), Patient = _patient.Text.Trim(), RxNo = _rx.Text.Trim() };
            return Task.FromResult(true);
        });
        AddCancel();
        FitToContent();
    }
}

/// <summary>Open the cashier's shift with the opening float (SCR-27).</summary>
public sealed class OpenShiftDialog : DialogForm
{
    private readonly MoneyBox _float = new MoneyBox();
    public ShiftDto? Shift { get; private set; }

    public OpenShiftDialog() : base("Open shift", 420)
    {
        var f = new FieldGrid(1);
        f.AddFull(new UiLabel("Count the cash in the drawer before the first sale.", Theme.Ui, Theme.InkMuted));
        f.Add("Opening float (Rs)", _float);
        Body.Stack(f);
        AddOk("Open shift", async () =>
        {
            Shift = await Session.Api.Post<ShiftDto>("shifts/open", new OpenShiftRequest { Float = _float.Value, CounterNo = Session.Counter });
            return true;
        });
        AddCancel();
        FitToContent();
    }
}

/// <summary>SCR-09 Sales return against an invoice: quantities, inspection outcome, refund method.</summary>
public sealed class SalesReturnDialog : DialogForm
{
    private readonly UiText _no = new UiText("Scan or type the invoice number");
    private readonly Grid _grid = new Grid();
    private readonly UiCombo _method = new UiCombo();
    private readonly Label _info = new UiLabel("", Theme.Ui, Theme.InkMuted);
    private readonly Label _total = new UiLabel("", Theme.NumLg);
    private readonly string _key = Compat.NewKey();
    private SaleDetail? _sale;
    private DataGridViewColumn _qtyCol = null!, _outcomeCol = null!, _reasonCol = null!;

    public SalesReturnDialog(long? saleId) : base("Sales return", 980)
    {
        _no.Dock = DockStyle.Fill;
        var find = new UiButton("Find invoice") { Dock = DockStyle.Right };
        find.Click += (_, _) => UiRun.Fire(this, () => LoadSale(_no.Text.Trim(), null), Bar);
        _no.KeyDown += (_, e) => { if (e.KeyCode == Keys.Enter) { e.SuppressKeyPress = true; UiRun.Fire(this, () => LoadSale(_no.Text.Trim(), null), Bar); } };
        KeepsEnter(_no);
        var top = new Panel { Dock = DockStyle.Top, Height = Theme.ControlHeight };
        top.Controls.AddRange(new Control[] { _no, find });

        _grid.ReadOnly = false;
        _grid.EditMode = DataGridViewEditMode.EditOnEnter;
        _grid.Col("Medicine", 0, ColKind.Text, 220).ReadOnly = true;
        _grid.Col("Batch", 110, ColKind.Mono).ReadOnly = true;
        _grid.Col("Sold", 130).ReadOnly = true;
        _grid.Col("Can return", 100, ColKind.Int).ReadOnly = true;
        _qtyCol = _grid.Col("Return qty", 100, ColKind.Int);
        var outcome = new DataGridViewComboBoxColumn { HeaderText = "OUTCOME", Width = Theme.S(130), FlatStyle = FlatStyle.Flat, DisplayStyleForCurrentCellOnly = true };
        outcome.Items.AddRange("Restock", "Quarantine");
        _grid.Columns.Add(outcome);
        _outcomeCol = outcome;
        _reasonCol = _grid.Col("Reason", 0, ColKind.Text, 140);
        _grid.Col("Rate", 90, ColKind.Money).ReadOnly = true;
        foreach (DataGridViewColumn c in _grid.Columns) c.SortMode = DataGridViewColumnSortMode.NotSortable;
        _grid.CellEndEdit += (_, _) => UpdateTotal();
        var host = new Panel { Dock = DockStyle.Top, Height = Theme.S(280) };
        host.Controls.Add(_grid);

        _method.SetItems(new[] { "Cash refund", "Bank refund", "Credit note (customer account)" });
        var f = new FieldGrid(3);
        f.Add("Refund method", _method);
        f.Add("Estimated refund", _total);
        Body.Stack(top, Box.Gap(8), _info, Box.Gap(6), host, Box.Gap(8), new UiLabel("Return quantities are in base units (tablets, capsules, bottles). Restock puts sealed, undamaged packs back on sale; quarantine keeps them out of sale.", Theme.Small, Theme.InkMuted).Wrap(Theme.S(920)), Box.Gap(8), f);
        AddOk("Post return", Post, ButtonKind.Danger);
        AddCancel();
        FitToContent();
        if (saleId.HasValue) Load += (_, _) => UiRun.Fire(this, () => LoadSale(null, saleId), Bar);
    }

    private async Task LoadSale(string? no, long? id)
    {
        if (id == null && string.IsNullOrWhiteSpace(no)) { Fail("Scan or type the invoice number."); return; }
        _sale = id.HasValue ? await Session.Api.Get<SaleDetail>($"sales/{id}") : await Session.Api.Get<SaleDetail>($"sales/by-no/{Uri.EscapeDataString(no!)}");
        _no.Text = _sale.No;
        _info.Text = $"{_sale.No} · {Dates.DisplayTime(_sale.At)} · {_sale.Customer} · net {Money.FormatRs(_sale.Net)}" + (_sale.Outstanding > 0 ? $" · unpaid {Money.FormatRs(_sale.Outstanding)} (refund goes to the customer's account)" : "") + (_sale.Status != "POSTED" ? $" · {_sale.Status}" : "");
        if (_sale.Outstanding > 0) _method.SelectedIndex = 2;
        _grid.Rows.Clear();
        foreach (var l in _sale.Lines)
        {
            var can = l.BaseQty - l.ReturnedBaseQty;
            var i = _grid.Rows.Add(l.Medicine, l.BatchNo, l.QtyText, can, 0, "Restock", "", l.Rate);
            _grid.Rows[i].Tag = l;
            if (can <= 0) { _grid.Rows[i].ReadOnly = true; _grid.Rows[i].DefaultCellStyle.ForeColor = Theme.InkMuted; }
        }
        UpdateTotal();
    }

    private void UpdateTotal()
    {
        decimal total = 0;
        foreach (DataGridViewRow r in _grid.Rows)
        {
            if (r.Tag is not SaleLineDetail l) continue;
            var q = ParseInt(r.Cells[_qtyCol.Index].Value);
            if (q > 0 && l.BaseQty > 0) total += Money.Round2(l.Net * q / l.BaseQty);
        }
        _total.Text = Money.FormatRs(total);
    }

    private static int ParseInt(object? v) => int.TryParse(Convert.ToString(v, CultureInfo.InvariantCulture), NumberStyles.Integer, CultureInfo.InvariantCulture, out var i) ? i : 0;

    private async Task<bool> Post()
    {
        _grid.EndEdit();
        if (_sale == null) { Fail("Find the invoice first."); return false; }
        var lines = new List<SalesReturnLineInput>();
        foreach (DataGridViewRow r in _grid.Rows)
        {
            if (r.Tag is not SaleLineDetail l) continue;
            var q = ParseInt(r.Cells[_qtyCol.Index].Value);
            if (q <= 0) continue;
            if (q > l.BaseQty - l.ReturnedBaseQty) { Fail($"{l.Medicine}: only {l.BaseQty - l.ReturnedBaseQty} can still be returned."); return false; }
            var reason = Convert.ToString(r.Cells[_reasonCol.Index].Value) ?? "";
            if (reason.Trim().Length == 0) { Fail($"{l.Medicine}: enter the reason for the return."); return false; }
            lines.Add(new SalesReturnLineInput { SaleItemId = l.Id, BaseQty = q, Outcome = Convert.ToString(r.Cells[_outcomeCol.Index].Value) == "Quarantine" ? "QUARANTINE" : "RESTOCK", Reason = reason.Trim() });
        }
        if (lines.Count == 0) { Fail("Enter the quantity to return on at least one line."); return false; }
        var method = _method.SelectedIndex switch { 1 => "BANK", 2 => "CREDIT_NOTE", _ => "CASH" };
        if (!Msg.Confirm(this, $"Post this return? {lines.Count} line(s), refund about {_total.Text} by {_method.Text.ToLowerInvariant()}.\nA posted return cannot be edited.")) return false;
        var req = new SalesReturnRequest { IdempotencyKey = _key, SaleId = _sale.Id, Lines = lines, RefundMethod = method };
        var result = await Approvals.Run(this, tokens => { req.ApprovalToken = tokens.LastOrDefault(); return Session.Api.Post<SalesReturnResult>("sales-returns", req, tokens); });
        if (!Printers.Receipt(result.Receipt, openDrawer: method == "CASH"))
            Msg.Error(this, $"Return {result.No} is saved, but the slip did not print. Check the receipt printer; the return is in the sales returns report.");
        Msg.Info(this, $"Return {result.No} posted. Refund {Money.FormatRs(result.Refund)}" + (method == "CASH" ? " — give the cash to the customer." : "."));
        return true;
    }
}
