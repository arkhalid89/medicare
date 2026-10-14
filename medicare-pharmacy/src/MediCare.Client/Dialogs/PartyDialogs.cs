namespace MediCare.Client.Dialogs;

/// <summary>Customer editor (SCR-20): contact, type, credit limit, opening balance.</summary>
public sealed class CustomerEditDialog : DialogForm
{
    private readonly CustomerDto _c;
    private readonly UiText _name = new UiText();
    private readonly UiText _mobile = new UiText("03xx-xxxxxxx");
    private readonly UiText _address = new UiText();
    private readonly UiCombo _type = new UiCombo();
    private readonly MoneyBox _limit = new MoneyBox();
    private readonly MoneyBox _opening = new MoneyBox();
    private readonly UiCheck _active = new UiCheck("Active", true);
    public long? SavedId { get; private set; }

    public CustomerEditDialog(CustomerDto? existing, string initialName = "") : base(existing == null ? "New customer" : "Customer " + existing.Code, 560)
    {
        _c = existing ?? new CustomerDto { Name = initialName };
        _name.Text = _c.Name;
        _mobile.Text = _c.Mobile;
        _address.Text = _c.Address;
        _type.SetItems(new[] { "REGULAR", "CREDIT" });
        _type.SelectedItem = _c.Type == "CREDIT" ? "CREDIT" : "REGULAR";
        _limit.Value = _c.CreditLimit;
        _opening.Value = _c.OpeningBalance;
        _active.Checked = _c.IsActive;
        var f = new FieldGrid(2);
        f.Add("Name", _name, 2);
        f.Add("Mobile", _mobile);
        f.Add("Type", _type, hint: "CREDIT customers can buy on account");
        f.Add("Address", _address, 2);
        f.Add("Credit limit (Rs)", _limit, hint: "Credit above this needs a manager's approval");
        if (!_c.OpeningPosted) f.Add("Opening balance owed (Rs)", _opening, hint: "From the old register. Posted once.");
        else f.Add("Opening balance", new UiLabel(Money.FormatRs(_c.OpeningBalance) + " (posted)", Theme.Num));
        if (existing != null) f.AddFull(_active);
        Body.Stack(f);
        AddOk("Save customer", Save);
        AddCancel();
        FitToContent();
    }

    private async Task<bool> Save()
    {
        if (_name.Text.Trim().Length == 0) { Fail("Enter the customer's name."); return false; }
        _c.Name = _name.Text.Trim();
        _c.Mobile = _mobile.Text.Trim();
        _c.Address = _address.Text.Trim();
        _c.Type = _type.SelectedItem as string ?? "REGULAR";
        _c.CreditLimit = _limit.Value;
        _c.IsActive = _active.Checked;
        var r = _c.Id == 0 ? await Session.Api.Post<OkResult>("customers", _c) : await Session.Api.Put<OkResult>($"customers/{_c.Id}", _c);
        SavedId = r.Id ?? _c.Id;
        if (!_c.OpeningPosted && _opening.Value != 0 && SavedId > 0)
            await Session.Api.Post<OkResult>($"customers/{SavedId}/opening", new OpeningBalanceRequest { Amount = _opening.Value });
        return true;
    }
}

/// <summary>Supplier editor (SCR-17): contact, terms, opening balance.</summary>
public sealed class SupplierEditDialog : DialogForm
{
    private readonly SupplierDto _s;
    private readonly UiText _name = new UiText();
    private readonly UiText _contact = new UiText();
    private readonly UiText _mobile = new UiText();
    private readonly UiText _address = new UiText();
    private readonly UiText _tax = new UiText("NTN / STRN");
    private readonly IntBox _terms = new IntBox();
    private readonly UiText _notes = new UiText();
    private readonly MoneyBox _opening = new MoneyBox();
    private readonly UiCheck _active = new UiCheck("Active", true);
    public long? SavedId { get; private set; }

    public SupplierEditDialog(SupplierDto? existing) : base(existing == null ? "New supplier" : "Supplier " + existing.Code, 600)
    {
        _s = existing ?? new SupplierDto();
        _name.Text = _s.Name;
        _contact.Text = _s.Contact;
        _mobile.Text = _s.Mobile;
        _address.Text = _s.Address;
        _tax.Text = _s.TaxNo;
        _terms.Value = _s.TermsDays;
        _notes.Text = _s.Notes;
        _opening.Value = _s.OpeningBalance;
        _active.Checked = _s.IsActive;
        var f = new FieldGrid(2);
        f.Add("Supplier / distributor name", _name, 2);
        f.Add("Contact person", _contact);
        f.Add("Mobile", _mobile);
        f.Add("Address", _address, 2);
        f.Add("Tax number", _tax);
        f.Add("Payment terms (days)", _terms);
        if (!_s.OpeningPosted) f.Add("Opening balance we owe (Rs)", _opening, hint: "From the old books. Posted once.");
        else f.Add("Opening balance", new UiLabel(Money.FormatRs(_s.OpeningBalance) + " (posted)", Theme.Num));
        f.Add("Notes", _notes);
        if (existing != null) f.AddFull(_active);
        Body.Stack(f);
        AddOk("Save supplier", Save);
        AddCancel();
        FitToContent();
    }

    private async Task<bool> Save()
    {
        if (_name.Text.Trim().Length == 0) { Fail("Enter the supplier's name."); return false; }
        _s.Name = _name.Text.Trim();
        _s.Contact = _contact.Text.Trim();
        _s.Mobile = _mobile.Text.Trim();
        _s.Address = _address.Text.Trim();
        _s.TaxNo = _tax.Text.Trim();
        _s.TermsDays = _terms.Value;
        _s.Notes = _notes.Text.Trim();
        _s.IsActive = _active.Checked;
        var r = _s.Id == 0 ? await Session.Api.Post<OkResult>("suppliers", _s) : await Session.Api.Put<OkResult>($"suppliers/{_s.Id}", _s);
        SavedId = r.Id ?? _s.Id;
        if (!_s.OpeningPosted && _opening.Value != 0 && SavedId > 0)
            await Session.Api.Post<OkResult>($"suppliers/{SavedId}/opening", new OpeningBalanceRequest { Amount = _opening.Value });
        return true;
    }
}

/// <summary>Customer receipt (SCR-22) or supplier payment (SCR-19), allocated to open documents (oldest first by default).</summary>
public sealed class SettlementDialog : DialogForm
{
    private readonly bool _customer;
    private readonly long _partyId;
    private readonly MoneyBox _amount = new MoneyBox();
    private readonly UiCombo _method = new UiCombo();
    private readonly UiCombo _account = new UiCombo();
    private readonly UiText _ref = new UiText();
    private readonly UiText _notes = new UiText();
    private readonly Grid _docs = new Grid();
    private readonly Label _unallocated = new UiLabel("", Theme.Small, Theme.InkMuted);
    private readonly string _key = Compat.NewKey();
    private DataGridViewColumn _allocCol = null!;
    public VoucherResult? Result { get; private set; }

    public SettlementDialog(bool customer, long partyId, string partyName, decimal balance) : base(customer ? "Receive payment from " + partyName : "Pay supplier " + partyName, 820)
    {
        _customer = customer;
        _partyId = partyId;
        _docs.ReadOnly = false;
        _docs.Col("Document", 160, ColKind.Mono).ReadOnly = true;
        _docs.Col("Date", 110, ColKind.Date).ReadOnly = true;
        _docs.Col("Due", 110, ColKind.Date).ReadOnly = true;
        _docs.Col("Total", 120, ColKind.Money).ReadOnly = true;
        _docs.Col("Outstanding", 120, ColKind.Money).ReadOnly = true;
        _allocCol = _docs.Col("Allocate", 0, ColKind.Money, 120);
        foreach (DataGridViewColumn c in _docs.Columns) c.SortMode = DataGridViewColumnSortMode.NotSortable;
        _docs.CellEndEdit += (_, _) => ShowUnallocated();
        _amount.TextChanged += (_, _) => AutoAllocate();
        var host = new Panel { Dock = DockStyle.Top, Height = Theme.S(220) };
        host.Controls.Add(_docs);
        var f = new FieldGrid(3);
        f.Add("Amount (Rs)", _amount);
        if (customer)
        {
            _method.SetItems(new[] { PaymentMethods.Cash, PaymentMethods.Card, PaymentMethods.Wallet, PaymentMethods.Bank });
            f.Add("Method", _method);
        }
        f.Add(customer ? "Into account" : "Pay from account", _account);
        f.Add("Reference (cheque / transfer no.)", _ref);
        f.Add("Notes", _notes, 2);
        Body.Stack(new UiLabel($"Current balance: {Money.FormatRs(balance)}", Theme.UiBold), Box.Gap(8), f, Box.Gap(4), new UiLabel("Allocation (oldest first; edit if needed). Any amount not allocated is kept as an advance.", Theme.Small, Theme.InkMuted), host, _unallocated);
        AddOk(customer ? "Post receipt" : "Post payment", Post);
        AddCancel();
        FitToContent();
        Load += (_, _) => UiRun.Fire(this, LoadData, Bar);
    }

    private async Task LoadData()
    {
        var accounts = await Session.Api.Get<List<CashAccountDto>>("cash-accounts");
        _account.SetItems(accounts.Where(a => a.IsActive));
        var docs = await Session.Api.Get<List<OutstandingDoc>>(_customer ? $"customers/{_partyId}/outstanding" : $"suppliers/{_partyId}/outstanding");
        _docs.Rows.Clear();
        foreach (var d in docs.OrderBy(d => d.Date))
        {
            var i = _docs.AddRow(d.No, d.Date, d.DueDate, d.Total, d.Outstanding, 0m);
            _docs.Rows[i].Tag = d;
        }
        if (!_customer) _amount.Value = docs.Sum(d => d.Outstanding);
        AutoAllocate();
    }

    private void AutoAllocate()
    {
        var left = _amount.Value;
        foreach (DataGridViewRow r in _docs.Rows)
        {
            if (r.Tag is not OutstandingDoc d) continue;
            var a = Math.Min(left, d.Outstanding);
            r.Cells[_allocCol.Index].Value = a;
            left -= a;
        }
        ShowUnallocated();
    }

    private decimal Allocated => _docs.Rows.Cast<DataGridViewRow>().Sum(r => Money.Parse(Convert.ToString(r.Cells[_allocCol.Index].Value, CultureInfo.InvariantCulture)));

    private void ShowUnallocated()
    {
        var left = _amount.Value - Allocated;
        _unallocated.Text = left > 0 ? $"Advance (not allocated): {Money.FormatRs(left)}" : left < 0 ? $"Allocated more than the amount by {Money.FormatRs(-left)}" : "";
        _unallocated.ForeColor = left < 0 ? Theme.Danger : Theme.InkMuted;
    }

    private async Task<bool> Post()
    {
        _docs.EndEdit();
        if (_amount.Value <= 0) { Fail("Enter the amount."); return false; }
        if (Allocated > _amount.Value) { Fail("The allocations are more than the amount."); return false; }
        var account = _account.Selected<CashAccountDto>();
        if (account == null) { Fail("Choose the cash or bank account."); return false; }
        var allocs = _docs.Rows.Cast<DataGridViewRow>().Where(r => r.Tag is OutstandingDoc)
            .Select(r => new AllocationInput { DocId = ((OutstandingDoc)r.Tag!).Id, Amount = Money.Parse(Convert.ToString(r.Cells[_allocCol.Index].Value, CultureInfo.InvariantCulture)) })
            .Where(a => a.Amount > 0).ToList();
        if (_customer)
        {
            var req = new ReceiptRequest { IdempotencyKey = _key, CustomerId = _partyId, Amount = _amount.Value, Method = _method.SelectedItem as string ?? PaymentMethods.Cash, CashAccountId = account.Id, Reference = _ref.Text.Trim(), Notes = _notes.Text.Trim(), Allocations = allocs };
            Result = await Approvals.Run(this, t => Session.Api.Post<VoucherResult>("receipts", req, t));
        }
        else
        {
            var req = new SupplierPaymentRequest { IdempotencyKey = _key, SupplierId = _partyId, Amount = _amount.Value, CashAccountId = account.Id, Reference = _ref.Text.Trim(), Notes = _notes.Text.Trim(), Allocations = allocs };
            Result = await Approvals.Run(this, t => Session.Api.Post<VoucherResult>("supplier-payments", req, t));
        }
        if (Msg.Confirm(this, $"{(_customer ? "Receipt" : "Payment")} {Result.No} posted ({Money.FormatRs(Result.Amount)}). Print the voucher?", "Posted"))
            Printers.Receipt(Result.Print);
        return true;
    }
}

/// <summary>Ledger statement viewer (SCR-18 / SCR-21) with print.</summary>
public sealed class LedgerDialog : DialogForm
{
    private readonly string _path;
    private readonly UiDate _from = new UiDate();
    private readonly UiDate _to = new UiDate();
    private readonly Grid _grid = new Grid();
    private readonly Label _summary = new UiLabel("", Theme.UiBold);
    private LedgerResult? _ledger;

    public LedgerDialog(string title, string path) : base(title, 980)
    {
        _path = path;
        _from.Value = DateTime.Today.AddMonths(-3);
        _to.Value = DateTime.Today;
        _grid.Col("Date", 140, ColKind.DateTime);
        _grid.Col("Document", 160, ColKind.Mono);
        _grid.Col("Details", 0, ColKind.Text, 220);
        _grid.Col("Debit", 120, ColKind.Money);
        _grid.Col("Credit", 120, ColKind.Money);
        _grid.Col("Balance", 130, ColKind.Money);
        var show = new UiButton("Show");
        show.Click += (_, _) => UiRun.Fire(this, Fill, Bar);
        var host = new Panel { Dock = DockStyle.Top, Height = Theme.S(380) };
        host.Controls.Add(_grid);
        Body.Stack(Box.Toolbar(new Control[] { Box.Caption("From"), _from.Sized(140), Box.Caption("To"), _to.Sized(140), show }), Box.Gap(4), _summary, Box.Gap(6), host);
        AddButton("Close", () => Close(), ButtonKind.Secondary, "Esc");
        AddButton("Print statement", PrintIt, ButtonKind.Primary);
        CancelButton = Buttons.Controls[0] as IButtonControl;
        FitToContent();
        Load += (_, _) => UiRun.Fire(this, Fill, Bar);
    }

    private async Task Fill()
    {
        _ledger = await Session.Api.Get<LedgerResult>(_path, new { from = _from.Value.Date, to = _to.Value.Date });
        _summary.Text = $"Opening {Money.FormatRs(_ledger.Opening)}   ·   Closing {Money.FormatRs(_ledger.Closing)} {_ledger.BalanceSide}";
        _grid.SetRows(_ledger.Lines, l => new object?[] { l.At, l.DocNo, l.Details, l.Debit == 0 ? null : l.Debit, l.Credit == 0 ? null : l.Credit, l.Balance });
    }

    private void PrintIt()
    {
        if (_ledger == null) return;
        var t = PrintTable.FromGrid(_grid, _ledger.Title, $"{Dates.Display(_from.Value)} to {Dates.Display(_to.Value)}");
        t.Info.Add(new KeyValuePair<string, string>("Opening balance", Money.FormatRs(_ledger.Opening)));
        t.Info.Add(new KeyValuePair<string, string>("Closing balance", Money.FormatRs(_ledger.Closing) + " " + _ledger.BalanceSide));
        t.Landscape = false;
        t.Signatures = true;
        TablePrinter.Print(this, t);
    }
}
