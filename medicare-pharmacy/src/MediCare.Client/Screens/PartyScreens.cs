namespace MediCare.Client.Screens;

/// <summary>SCR-17 Suppliers: list, editor, ledger, payment, refund.</summary>
public sealed class SuppliersScreen : AppScreen
{
    private readonly UiText _q = new UiText("Name, contact or mobile");
    private readonly UiCheck _inactive = new UiCheck("Show inactive");
    private readonly Grid _grid = new Grid();
    private readonly Label _summary = new UiLabel("", Theme.Small, Theme.InkMuted);

    public override string Title => "Suppliers";
    public override string Subtitle => "Distributors and companies you buy from, with what you owe them.";

    public override IEnumerable<FKey> FKeys => new[]
    {
        new FKey(Keys.Control | Keys.N, "New supplier", () => Fire(New)) { Enabled = () => Session.Can(Perm.SuppliersEdit) },
        new FKey(Keys.Enter, "Edit", () => Fire(Edit)),
        new FKey(Keys.F6, "Ledger", () => Fire(Ledger)) { Enabled = () => Session.Can(Perm.SuppliersView) },
        new FKey(Keys.F10, "Pay", () => Fire(Pay), primary: true) { Enabled = () => Session.Can(Perm.SupplierPaymentsPost) },
        new FKey(Keys.F11, "Refund received", () => Fire(Refund)) { Enabled = () => Session.Can(Perm.SupplierPaymentsPost) },
        new FKey(Keys.Control | Keys.P, "Print", () => TablePrinter.Print(FindForm()!, PrintTable.FromGrid(_grid, "Suppliers", "Balances as of " + Dates.Display(DateTime.Today)))),
    };

    public SuppliersScreen()
    {
        _grid.Col("Code", 90, ColKind.Mono);
        _grid.Col("Supplier", 0, ColKind.Text, 220);
        _grid.Col("Contact", 140);
        _grid.Col("Mobile", 130, ColKind.Mono);
        _grid.Col("Terms", 70, ColKind.Int);
        _grid.Col("We owe", 130, ColKind.Money);
        _grid.RowTone = o => o is SupplierDto s && !s.IsActive ? Tone.Muted : Tone.None;
        _grid.RowActivated += _ => Fire(Edit);
        _q.KeyDown += (_, e) => { if (e.KeyCode == Keys.Enter) { e.SuppressKeyPress = true; Fire(Fill); } };
        _inactive.CheckedChanged += (_, _) => Fire(Fill);
        var add = new UiButton("New supplier", ButtonKind.Accent, "Ctrl+N") { Enabled = Session.Can(Perm.SuppliersEdit) };
        add.Click += (_, _) => Fire(New);
        var card = new Card { Dock = DockStyle.Fill, Padding = new Padding(1) };
        card.Controls.Add(_grid);
        Compose(Box.Toolbar(new Control[] { _q.Sized(300), _inactive }, new Control[] { add }), _summary.Docked(DockStyle.Top), Box.Gap(6), card);
    }

    public override void FocusFirst() => _q.Focus();
    protected override Task OnFirstShow() => Fill();

    private async Task Fill()
    {
        var list = await Session.Api.Get<List<SupplierDto>>("suppliers", new { q = _q.Text.Trim(), includeInactive = _inactive.Checked });
        _grid.SetRows(list, s => new object?[] { s.Code, s.Name, s.Contact, s.Mobile, s.TermsDays, s.Balance });
        _summary.Text = $"{list.Count} suppliers · total payable {Money.FormatRs(list.Sum(s => s.Balance))}";
    }

    private SupplierDto? Current => _grid.Current<SupplierDto>();

    private async Task New()
    {
        using var d = new SupplierEditDialog(null);
        if (d.ShowDialog(FindForm()) == DialogResult.OK) await Fill();
    }

    private async Task Edit()
    {
        var s = Current;
        if (s == null || !Session.Can(Perm.SuppliersEdit)) { await Ledger(); return; }
        using var d = new SupplierEditDialog(await Session.Api.Get<SupplierDto>($"suppliers/{s.Id}"));
        if (d.ShowDialog(FindForm()) == DialogResult.OK) await Fill();
    }

    private Task Ledger()
    {
        var s = Current;
        if (s == null) return Task.CompletedTask;
        using var d = new LedgerDialog("Supplier ledger: " + s.Name, $"suppliers/{s.Id}/ledger");
        d.ShowDialog(FindForm());
        return Task.CompletedTask;
    }

    private async Task Pay()
    {
        var s = Current;
        if (s == null) return;
        using var d = new SettlementDialog(false, s.Id, s.Name, s.Balance);
        if (d.ShowDialog(FindForm()) == DialogResult.OK) await Fill();
    }

    private async Task Refund()
    {
        var s = Current;
        if (s == null) return;
        using var d = new SupplierRefundDialog(s);
        if (d.ShowDialog(FindForm()) == DialogResult.OK) await Fill();
    }
}

/// <summary>Money received back from a supplier (after a return or an overpayment).</summary>
public sealed class SupplierRefundDialog : DialogForm
{
    private readonly SupplierDto _s;
    private readonly MoneyBox _amount = new MoneyBox();
    private readonly UiCombo _account = new UiCombo();
    private readonly UiText _ref = new UiText();
    private readonly string _key = Compat.NewKey();

    public SupplierRefundDialog(SupplierDto s) : base("Refund received from " + s.Name, 480)
    {
        _s = s;
        var f = new FieldGrid(2);
        f.Add("Amount (Rs)", _amount);
        f.Add("Into account", _account);
        f.Add("Reference", _ref, 2);
        Body.Stack(f);
        AddOk("Post refund", async () =>
        {
            var a = _account.Selected<CashAccountDto>();
            if (_amount.Value <= 0 || a == null) { Fail("Enter the amount and choose the account."); return false; }
            var req = new SupplierRefundRequest { IdempotencyKey = _key, SupplierId = _s.Id, Amount = _amount.Value, CashAccountId = a.Id, Reference = _ref.Text.Trim() };
            var r = await Approvals.Run(this, t => Session.Api.Post<VoucherResult>("supplier-refunds", req, t));
            Msg.Info(this, $"Refund {r.No} posted.");
            return true;
        });
        AddCancel();
        FitToContent();
        Load += (_, _) => UiRun.Fire(this, async () => _account.SetItems((await Session.Api.Get<List<CashAccountDto>>("cash-accounts")).Where(x => x.IsActive)), Bar);
    }
}

/// <summary>SCR-20 Customers: list, editor, credit limit, ledger, receipts.</summary>
public sealed class CustomersScreen : AppScreen
{
    private readonly UiText _q = new UiText("Name, mobile or code");
    private readonly UiCheck _inactive = new UiCheck("Show inactive");
    private readonly UiCheck _dues = new UiCheck("Only with dues");
    private readonly Grid _grid = new Grid();
    private readonly Label _summary = new UiLabel("", Theme.Small, Theme.InkMuted);

    public override string Title => "Customers";
    public override string Subtitle => "Regular and credit customers, with what they owe.";

    public override IEnumerable<FKey> FKeys => new[]
    {
        new FKey(Keys.Control | Keys.N, "New customer", () => Fire(New)) { Enabled = () => Session.Can(Perm.CustomersEdit) },
        new FKey(Keys.Enter, "Edit", () => Fire(Edit)),
        new FKey(Keys.F6, "Ledger", () => Fire(Ledger)) { Enabled = () => Session.Can(Perm.CustomersView) },
        new FKey(Keys.F10, "Receive payment", () => Fire(Receive), primary: true) { Enabled = () => Session.Can(Perm.ReceiptsPost) },
        new FKey(Keys.Control | Keys.P, "Print", () => TablePrinter.Print(FindForm()!, PrintTable.FromGrid(_grid, "Customers", "Balances as of " + Dates.Display(DateTime.Today)))),
    };

    public CustomersScreen()
    {
        _grid.Col("Code", 90, ColKind.Mono);
        _grid.Col("Customer", 0, ColKind.Text, 220);
        _grid.Col("Mobile", 130, ColKind.Mono);
        _grid.Col("Type", 90);
        _grid.Col("Credit limit", 120, ColKind.Money);
        _grid.Col("Owes us", 130, ColKind.Money);
        _grid.RowTone = o => o is CustomerDto c ? (!c.IsActive ? Tone.Muted : c.CreditLimit > 0 && c.Balance > c.CreditLimit ? Tone.Danger : Tone.None) : Tone.None;
        _grid.RowActivated += _ => Fire(Edit);
        _q.KeyDown += (_, e) => { if (e.KeyCode == Keys.Enter) { e.SuppressKeyPress = true; Fire(Fill); } };
        _inactive.CheckedChanged += (_, _) => Fire(Fill);
        _dues.CheckedChanged += (_, _) => Fire(Fill);
        var add = new UiButton("New customer", ButtonKind.Accent, "Ctrl+N") { Enabled = Session.Can(Perm.CustomersEdit) };
        add.Click += (_, _) => Fire(New);
        var card = new Card { Dock = DockStyle.Fill, Padding = new Padding(1) };
        card.Controls.Add(_grid);
        Compose(Box.Toolbar(new Control[] { _q.Sized(300), _inactive, _dues }, new Control[] { add }), _summary.Docked(DockStyle.Top), Box.Gap(6), card);
    }

    public override void FocusFirst() => _q.Focus();
    protected override Task OnFirstShow() => Fill();

    private async Task Fill()
    {
        var list = (await Session.Api.Get<List<CustomerDto>>("customers", new { q = _q.Text.Trim(), includeInactive = _inactive.Checked })).Where(c => !c.IsWalkIn && (!_dues.Checked || c.Balance > 0)).ToList();
        _grid.SetRows(list, c => new object?[] { c.Code, c.Name, c.Mobile, c.Type, c.CreditLimit == 0 ? null : c.CreditLimit, c.Balance });
        _summary.Text = $"{list.Count} customers · total receivable {Money.FormatRs(list.Sum(c => c.Balance))}";
    }

    private CustomerDto? Current => _grid.Current<CustomerDto>();

    private async Task New()
    {
        using var d = new CustomerEditDialog(null);
        if (d.ShowDialog(FindForm()) == DialogResult.OK) await Fill();
    }

    private async Task Edit()
    {
        var c = Current;
        if (c == null || !Session.Can(Perm.CustomersEdit)) { await Ledger(); return; }
        using var d = new CustomerEditDialog(await Session.Api.Get<CustomerDto>($"customers/{c.Id}"));
        if (d.ShowDialog(FindForm()) == DialogResult.OK) await Fill();
    }

    private Task Ledger()
    {
        var c = Current;
        if (c == null) return Task.CompletedTask;
        using var d = new LedgerDialog("Customer ledger: " + c.Name, $"customers/{c.Id}/ledger");
        d.ShowDialog(FindForm());
        return Task.CompletedTask;
    }

    private async Task Receive()
    {
        var c = Current;
        if (c == null) return;
        using var d = new SettlementDialog(true, c.Id, c.Name, c.Balance);
        if (d.ShowDialog(FindForm()) == DialogResult.OK) await Fill();
    }
}
