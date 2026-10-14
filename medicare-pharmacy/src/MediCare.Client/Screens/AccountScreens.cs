namespace MediCare.Client.Screens;

/// <summary>SCR-25 Cashbook and bankbook: running balances, cash in/out entries, transfers.</summary>
public sealed class CashScreen : AppScreen
{
    private readonly Grid _accounts = new Grid();
    private readonly Grid _book = new Grid();
    private readonly UiDate _from = new UiDate();
    private readonly UiDate _to = new UiDate();
    private readonly Label _summary = new UiLabel("", Theme.UiBold);
    private LedgerResult? _ledger;

    public override string Title => "Cash and bank";
    public override string Subtitle => "Every rupee in and out, by account.";

    public override IEnumerable<FKey> FKeys => new[]
    {
        new FKey(Keys.F5, "Refresh", () => Fire(Fill)),
        new FKey(Keys.Control | Keys.N, "Cash in / out", () => Fire(Entry)) { Enabled = () => Session.Can(Perm.CashPost) },
        new FKey(Keys.Control | Keys.T, "Transfer", () => Fire(Transfer)) { Enabled = () => Session.Can(Perm.CashPost) },
        new FKey(Keys.Control | Keys.P, "Print book", PrintBook),
    };

    public CashScreen()
    {
        _from.Value = DateTime.Today.AddDays(-7);
        _to.Value = DateTime.Today;
        _accounts.Col("Account", 0, ColKind.Text, 160);
        _accounts.Col("Kind", 70);
        _accounts.Col("Balance", 120, ColKind.Money);
        _accounts.SelectionChanged += (_, _) => Fire(Book);
        _book.Col("Date", 140, ColKind.DateTime);
        _book.Col("Document", 150, ColKind.Mono);
        _book.Col("Details", 0, ColKind.Text, 220);
        _book.Col("In", 110, ColKind.Money);
        _book.Col("Out", 110, ColKind.Money);
        _book.Col("Balance", 120, ColKind.Money);
        var left = new Card("Accounts") { Dock = DockStyle.Left, Width = Theme.S(380), Padding = new Padding(1, Theme.S(36), 1, 1) };
        left.Controls.Add(_accounts);
        var right = new Card { Dock = DockStyle.Fill, Padding = new Padding(1) };
        right.Controls.Add(_book);
        var show = new UiButton("Show");
        show.Click += (_, _) => Fire(Book);
        var entry = new UiButton("Cash in / out", ButtonKind.Accent, "Ctrl+N") { Enabled = Session.Can(Perm.CashPost) };
        entry.Click += (_, _) => Fire(Entry);
        var transfer = new UiButton("Transfer", ButtonKind.Secondary, "Ctrl+T") { Enabled = Session.Can(Perm.CashPost) };
        transfer.Click += (_, _) => Fire(Transfer);
        var body = new Panel { Dock = DockStyle.Fill };
        body.Controls.AddRange(new Control[] { right, new Panel { Dock = DockStyle.Left, Width = Theme.S(12) }, left });
        Compose(Box.Toolbar(new Control[] { Box.Caption("From"), _from.Sized(140), Box.Caption("To"), _to.Sized(140), show }, new Control[] { transfer, entry }), _summary.Docked(DockStyle.Top), Box.Gap(6), body);
    }

    protected override Task OnFirstShow() => Fill();

    private async Task Fill()
    {
        var list = await Session.Api.Get<List<CashAccountDto>>("cash-accounts");
        _accounts.SetRows(list.Where(a => a.IsActive), a => new object?[] { a.Name, a.Kind, a.Balance });
        await Book();
    }

    private async Task Book()
    {
        var a = _accounts.Current<CashAccountDto>();
        if (a == null) return;
        _ledger = await Session.Api.Get<LedgerResult>($"cash/{a.Id}/book", new { from = _from.Value.Date, to = _to.Value.Date });
        _summary.Text = $"{a.Name}: opening {Money.FormatRs(_ledger.Opening)} · closing {Money.FormatRs(_ledger.Closing)}";
        _book.SetRows(_ledger.Lines, l => new object?[] { l.At, l.DocNo, l.Details, l.Debit == 0 ? null : l.Debit, l.Credit == 0 ? null : l.Credit, l.Balance });
    }

    private void PrintBook()
    {
        if (_ledger == null) return;
        var t = PrintTable.FromGrid(_book, _ledger.Title.Length > 0 ? _ledger.Title : "Cash book", $"{Dates.Display(_from.Value)} to {Dates.Display(_to.Value)}");
        t.Info.Add(new KeyValuePair<string, string>("Opening", Money.FormatRs(_ledger.Opening)));
        t.Info.Add(new KeyValuePair<string, string>("Closing", Money.FormatRs(_ledger.Closing)));
        t.Signatures = true;
        TablePrinter.Print(FindForm()!, t);
    }

    private async Task Entry()
    {
        using var d = new CashEntryDialog(_accounts.Current<CashAccountDto>());
        if (d.ShowDialog(FindForm()) == DialogResult.OK) await Fill();
    }

    private async Task Transfer()
    {
        using var d = new TransferDialog();
        if (d.ShowDialog(FindForm()) == DialogResult.OK) await Fill();
    }
}

public sealed class CashEntryDialog : DialogForm
{
    private static readonly (string key, string label)[] Kinds =
    {
        ("PAID_OUT", "Paid out of the drawer (small expense)"), ("OTHER_INCOME", "Other income received"), ("OWNER_CAPITAL", "Owner put money in"),
        ("OWNER_DRAWINGS", "Owner took money out"), ("OPENING_BALANCE", "Opening balance (first day only)"),
    };

    private readonly UiCombo _kind = new UiCombo();
    private readonly UiCombo _account = new UiCombo();
    private readonly MoneyBox _amount = new MoneyBox();
    private readonly UiText _memo = new UiText();
    private readonly string _key = Compat.NewKey();
    private readonly CashAccountDto? _preferred;

    public CashEntryDialog(CashAccountDto? preferred) : base("Cash in / out", 520)
    {
        _preferred = preferred;
        _kind.SetItems(Kinds.Select(k => k.label));
        var f = new FieldGrid(2);
        f.Add("What is it?", _kind, 2);
        f.Add("Account", _account);
        f.Add("Amount (Rs)", _amount);
        f.Add("Details", _memo, 2);
        Body.Stack(f);
        AddOk("Post entry", Post);
        AddCancel();
        FitToContent();
        Load += (_, _) => UiRun.Fire(this, async () =>
        {
            _account.SetItems((await Session.Api.Get<List<CashAccountDto>>("cash-accounts")).Where(a => a.IsActive));
            if (_preferred != null) _account.SelectWhere<CashAccountDto>(a => a.Id == _preferred.Id);
        }, Bar);
    }

    private async Task<bool> Post()
    {
        var a = _account.Selected<CashAccountDto>();
        if (a == null || _amount.Value <= 0) { Fail("Choose the account and enter the amount."); return false; }
        if (_memo.Text.Trim().Length == 0) { Fail("Enter the details (what the money was for)."); return false; }
        var req = new CashEntryRequest { IdempotencyKey = _key, CashAccountId = a.Id, Category = Kinds[_kind.SelectedIndex].key, Amount = _amount.Value, Memo = _memo.Text.Trim() };
        var r = await Approvals.Run(this, t => Session.Api.Post<OkResult>("cash/entries", req, t));
        Msg.Info(this, r.Message ?? $"Entry {r.No} posted.");
        return true;
    }
}

public sealed class TransferDialog : DialogForm
{
    private readonly UiCombo _from = new UiCombo();
    private readonly UiCombo _to = new UiCombo();
    private readonly MoneyBox _amount = new MoneyBox();
    private readonly UiText _ref = new UiText("e.g. deposit slip no.");
    private readonly string _key = Compat.NewKey();

    public TransferDialog() : base("Transfer between accounts", 520)
    {
        var f = new FieldGrid(2);
        f.Add("From", _from);
        f.Add("To", _to);
        f.Add("Amount (Rs)", _amount);
        f.Add("Reference", _ref);
        f.AddFull(new UiLabel("Example: cash deposited into the bank at the end of the day.", Theme.Small, Theme.InkMuted));
        Body.Stack(f);
        AddOk("Post transfer", async () =>
        {
            var a = _from.Selected<CashAccountDto>();
            var b = _to.Selected<CashAccountDto>();
            if (a == null || b == null || a.Id == b.Id) { Fail("Choose two different accounts."); return false; }
            if (_amount.Value <= 0) { Fail("Enter the amount."); return false; }
            var req = new TransferRequest { IdempotencyKey = _key, FromAccountId = a.Id, ToAccountId = b.Id, Amount = _amount.Value, Reference = _ref.Text.Trim() };
            var r = await Approvals.Run(this, t => Session.Api.Post<OkResult>("cash/transfers", req, t));
            Msg.Info(this, r.Message ?? "Transfer posted.");
            return true;
        });
        AddCancel();
        FitToContent();
        Load += (_, _) => UiRun.Fire(this, async () =>
        {
            var list = (await Session.Api.Get<List<CashAccountDto>>("cash-accounts")).Where(x => x.IsActive).ToList();
            _from.SetItems(list);
            _to.SetItems(list);
            if (list.Count > 1) _to.SelectedIndex = 1;
        }, Bar);
    }
}

/// <summary>SCR-26 Expenses: vouchers by category; larger ones need approval.</summary>
public sealed class ExpensesScreen : AppScreen
{
    private readonly UiDate _from = new UiDate();
    private readonly UiDate _to = new UiDate();
    private readonly Grid _grid = new Grid();
    private readonly Label _summary = new UiLabel("", Theme.Small, Theme.InkMuted);

    public override string Title => "Expenses";
    public override string Subtitle => "Rent, salaries, utilities and other running costs.";

    public override IEnumerable<FKey> FKeys => new[]
    {
        new FKey(Keys.Control | Keys.N, "New expense", () => Fire(New), primary: true) { Enabled = () => Session.Can(Perm.ExpensesPost) },
        new FKey(Keys.F5, "Refresh", () => Fire(Fill)),
        new FKey(Keys.Control | Keys.P, "Print", () => TablePrinter.Print(FindForm()!, PrintTable.FromGrid(_grid, "Expenses", $"{Dates.Display(_from.Value)} to {Dates.Display(_to.Value)}"))),
    };

    public ExpensesScreen()
    {
        _from.Value = new DateTime(DateTime.Today.Year, DateTime.Today.Month, 1);
        _to.Value = DateTime.Today;
        _grid.Col("Voucher", 140, ColKind.Mono);
        _grid.Col("Date", 110, ColKind.Date);
        _grid.Col("Category", 150);
        _grid.Col("Description", 0, ColKind.Text, 220);
        _grid.Col("Amount", 110, ColKind.Money);
        _grid.Col("Paid from", 130);
        _grid.Col("Entered by", 130);
        _grid.Col("Approved by", 130);
        var show = new UiButton("Show");
        show.Click += (_, _) => Fire(Fill);
        var add = new UiButton("New expense", ButtonKind.Accent, "Ctrl+N") { Enabled = Session.Can(Perm.ExpensesPost) };
        add.Click += (_, _) => Fire(New);
        var card = new Card { Dock = DockStyle.Fill, Padding = new Padding(1) };
        card.Controls.Add(_grid);
        Compose(Box.Toolbar(new Control[] { Box.Caption("From"), _from.Sized(140), Box.Caption("To"), _to.Sized(140), show }, new Control[] { add }), _summary.Docked(DockStyle.Top), Box.Gap(6), card);
    }

    protected override Task OnFirstShow() => Fill();

    private async Task Fill()
    {
        var list = await Session.Api.Get<List<ExpenseDto>>("expenses", new { from = _from.Value.Date, to = _to.Value.Date });
        _grid.SetRows(list, e => new object?[] { e.No, e.Date, e.Category, e.Description, e.Amount, e.Account, e.EnteredBy, e.ApprovedBy });
        _summary.Text = $"{list.Count} vouchers · total {Money.FormatRs(list.Sum(e => e.Amount))} · " + string.Join(" · ", list.GroupBy(e => e.Category).OrderByDescending(g => g.Sum(e => e.Amount)).Take(4).Select(g => $"{g.Key} {Money.Format(g.Sum(e => e.Amount))}"));
    }

    private async Task New()
    {
        using var d = new ExpenseDialog();
        if (d.ShowDialog(FindForm()) == DialogResult.OK) await Fill();
    }
}

public sealed class ExpenseDialog : DialogForm
{
    private readonly UiCombo _category = new UiCombo().Editable();
    private readonly UiText _desc = new UiText();
    private readonly MoneyBox _amount = new MoneyBox();
    private readonly UiCombo _account = new UiCombo();
    private readonly UiText _ref = new UiText("Bill / receipt no.");
    private readonly string _key = Compat.NewKey();

    public ExpenseDialog() : base("New expense voucher", 540)
    {
        var f = new FieldGrid(2);
        f.Add("Category", _category);
        f.Add("Amount (Rs)", _amount);
        f.Add("Description", _desc, 2);
        f.Add("Paid from", _account);
        f.Add("Reference", _ref);
        Body.Stack(f);
        AddOk("Post voucher", async () =>
        {
            var a = _account.Selected<CashAccountDto>();
            if (_category.Text.Trim().Length == 0 || _desc.Text.Trim().Length == 0) { Fail("Enter the category and description."); return false; }
            if (a == null || _amount.Value <= 0) { Fail("Enter the amount and choose the account."); return false; }
            var req = new ExpenseRequest { IdempotencyKey = _key, Category = _category.Text.Trim(), Description = _desc.Text.Trim(), Amount = _amount.Value, CashAccountId = a.Id, Reference = _ref.Text.Trim() };
            var r = await Approvals.Run(this, t => { req.ApprovalToken = t.LastOrDefault(); return Session.Api.Post<OkResult>("expenses", req, t); });
            Msg.Info(this, r.Message ?? $"Voucher {r.No} posted.");
            return true;
        });
        AddCancel();
        FitToContent();
        Load += (_, _) => UiRun.Fire(this, async () =>
        {
            _category.SetItems((await Session.Api.Get<LookupListDto>($"lists/{ListKinds.ExpenseCategory}")).Values);
            _account.SetItems((await Session.Api.Get<List<CashAccountDto>>("cash-accounts")).Where(x => x.IsActive));
        }, Bar);
    }
}

/// <summary>SCR-27 Cashier and day closing: my shift, everyone's shifts today, day lock.</summary>
public sealed class ClosingScreen : AppScreen
{
    private readonly Label _mine = new UiLabel("", Theme.Lg);
    private readonly UiButton _open = new UiButton("Open my shift", ButtonKind.Accent);
    private readonly UiButton _close = new UiButton("Count cash and close my shift", ButtonKind.Primary);
    private readonly Grid _shifts = new Grid();
    private readonly Label _day = new UiLabel("", Theme.UiBold);
    private readonly UiButton _closeDay = new UiButton("Close the day", ButtonKind.Danger);
    private ShiftDto? _current;

    public override string Title => "Closing";
    public override string Subtitle => "Shift close with cash count; day close locks the business date.";

    public override IEnumerable<FKey> FKeys => new[]
    {
        new FKey(Keys.F5, "Refresh", () => Fire(Fill)),
        new FKey(Keys.F10, "Close my shift", () => Fire(CloseShift)) { Enabled = () => _current != null },
        new FKey(Keys.Control | Keys.P, "Print shifts", () => TablePrinter.Print(FindForm()!, PrintTable.FromGrid(_shifts, "Shifts", Dates.Display(DateTime.Today)))),
    };

    public ClosingScreen()
    {
        var myCard = new Card("My shift") { Dock = DockStyle.Top, Height = Theme.S(140) };
        _mine.AutoSize = false;
        _mine.Dock = DockStyle.Top;
        _mine.Height = Theme.S(50);
        var buttons = new ButtonRow();
        buttons.AddControl(_open);
        buttons.AddControl(_close);
        myCard.Stack(_mine, buttons);
        _open.Click += (_, _) => Fire(async () => { using var d = new OpenShiftDialog(); if (d.ShowDialog(FindForm()) == DialogResult.OK) await Fill(); });
        _close.Click += (_, _) => Fire(CloseShift);
        _close.Width = _close.Measure();

        _shifts.Col("Cashier", 0, ColKind.Text, 140);
        _shifts.Col("Counter", 70, ColKind.Int);
        _shifts.Col("Opened", 140, ColKind.DateTime);
        _shifts.Col("Float", 90, ColKind.Money);
        _shifts.Col("Cash sales", 110, ColKind.Money);
        _shifts.Col("Receipts", 100, ColKind.Money);
        _shifts.Col("Refunds", 100, ColKind.Money);
        _shifts.Col("Paid out", 100, ColKind.Money);
        _shifts.Col("Expected", 110, ColKind.Money);
        _shifts.Col("Counted", 110, ColKind.Money);
        _shifts.Col("Difference", 110, ColKind.Money);
        _shifts.Col("Status", 90);
        _shifts.RowTone = o => o is ShiftDto s ? (s.Status == "OPEN" ? Tone.Info : s.Difference != 0 ? Tone.Warn : Tone.None) : Tone.None;
        var card = new Card("Shifts today") { Dock = DockStyle.Fill, Padding = new Padding(1, Theme.S(36), 1, 1) };
        card.Controls.Add(_shifts);

        var dayCard = new Card("Business day") { Dock = DockStyle.Bottom, Height = Theme.S(110) };
        var dayRow = new ButtonRow();
        dayRow.AddControl(_closeDay);
        dayCard.Stack(_day, Box.Gap(6), dayRow);
        _closeDay.Visible = Session.Can(Perm.DayClose);
        _closeDay.Click += (_, _) => Fire(CloseDay);

        Compose(myCard, Box.Gap(8), dayCard, card);
        dayCard.SendToBack();
    }

    protected override Task OnFirstShow() => Fill();

    private async Task Fill()
    {
        _current = await Session.Api.Get<ShiftDto?>("shifts/current");
        if (_current == null)
        {
            _mine.Text = "Your shift is not open. Open it with the cash in the drawer before selling.";
            _open.Visible = Session.Can(Perm.ShiftClose);
            _close.Visible = false;
        }
        else
        {
            _mine.Text = $"Open since {Dates.DisplayTime(_current.OpenedAt)} at counter {_current.CounterNo} · float {Money.FormatRs(_current.Float)} · cash expected now {Money.FormatRs(_current.Expected)}";
            _open.Visible = false;
            _close.Visible = Session.Can(Perm.ShiftClose);
        }
        var day = await Session.Api.Get<DayStatusDto>("day/status");
        _day.Text = day.Closed ? $"Business day {day.BusinessDate} is CLOSED (by {day.ClosedBy} at {(day.ClosedAt.HasValue ? Dates.DisplayTime(day.ClosedAt.Value) : "")}). Postings now need a manager's approval."
            : $"Business day {day.BusinessDate} is open. {(day.OpenShifts.Count == 0 ? "All shifts are closed." : $"Open shifts: {string.Join(", ", day.OpenShifts.Select(s => s.User))}.")}";
        _closeDay.Enabled = !day.Closed && day.OpenShifts.Count == 0;
        if (Session.Can(Perm.CashView))
        {
            var list = await Session.Api.Get<List<ShiftDto>>("shifts", new { date = DateTime.Today });
            _shifts.SetRows(list, s => new object?[] { s.User, s.CounterNo, s.OpenedAt, s.Float, s.CashSales, s.Receipts, s.Refunds, s.PaidOuts, s.Expected, s.ClosedAt.HasValue ? s.Counted : (decimal?)null, s.ClosedAt.HasValue ? s.Difference : (decimal?)null, s.Status });
        }
        else _shifts.EmptyText = "You can see your own shift above.";
        Shell?.RefreshKeys();
    }

    private async Task CloseShift()
    {
        if (_current == null) return;
        using var d = new CloseShiftDialog(_current);
        if (d.ShowDialog(FindForm()) == DialogResult.OK) await Fill();
    }

    private async Task CloseDay()
    {
        if (!Msg.Confirm(FindForm(), "Close the business day? After closing, any posting for today needs a manager's approval. Take the USB backup next.")) return;
        await Session.Api.Post<DayStatusDto>("day/close", new { });
        await Fill();
        if (Session.Can(Perm.BackupRun) && Msg.Confirm(FindForm(), "The day is closed. Take the USB backup now?"))
            await Shell.OpenAsync("backup");
    }
}

/// <summary>Count the drawer by denomination; the difference needs a reason and, if not zero, a manager's approval.</summary>
public sealed class CloseShiftDialog : DialogForm
{
    private static readonly int[] Notes = { 5000, 1000, 500, 100, 50, 20, 10, 5, 2, 1 };
    private readonly ShiftDto _s;
    private readonly Dictionary<int, IntBox> _counts = new Dictionary<int, IntBox>();
    private readonly Label _counted = new UiLabel("", Theme.NumLg);
    private readonly Label _diff = new UiLabel("", Theme.NumLg);
    private readonly UiText _reason = new UiText("Needed when counted cash differs from expected");

    public CloseShiftDialog(ShiftDto s) : base("Close shift: cash count", 620)
    {
        _s = s;
        var f = new FieldGrid(4);
        foreach (var n in Notes)
        {
            var box = new IntBox();
            box.TextChanged += (_, _) => Recalc();
            _counts[n] = box;
            f.Add($"Rs {n:#,##0} × ", box);
        }
        f.Break();
        f.Add("Expected in drawer", new UiLabel(Money.FormatRs(s.Expected), Theme.NumLg), 4, hint: $"Float {Money.Format(s.Float)} + cash sales {Money.Format(s.CashSales)} + receipts {Money.Format(s.Receipts)} − refunds {Money.Format(s.Refunds)} − paid out {Money.Format(s.PaidOuts)}");
        f.Add("Counted", _counted, 2);
        f.Add("Difference", _diff, 2);
        f.Add("Reason for difference", _reason, 4);
        Body.Stack(f);
        AddOk("Close shift", Post);
        AddCancel();
        FitToContent();
        Recalc();
    }

    private decimal Counted => _counts.Sum(kv => (decimal)kv.Key * kv.Value.Value);

    private void Recalc()
    {
        var diff = Counted - _s.Expected;
        _counted.Text = Money.FormatRs(Counted);
        _diff.Text = (diff > 0 ? "+" : "") + Money.Format(diff) + (diff < 0 ? " short" : diff > 0 ? " over" : "");
        _diff.ForeColor = diff == 0 ? Theme.Ok : Theme.Danger;
    }

    private async Task<bool> Post()
    {
        var diff = Counted - _s.Expected;
        if (diff != 0 && _reason.Text.Trim().Length == 0) { Fail("Enter the reason for the difference."); _reason.Focus(); return false; }
        if (!Msg.Confirm(this, $"Close the shift with {Money.FormatRs(Counted)} counted ({(diff == 0 ? "no difference" : Money.FormatRs(diff))})?")) return false;
        var req = new CloseShiftRequest { Counts = _counts.Where(kv => kv.Value.Value > 0).Select(kv => new DenominationCount { Denomination = kv.Key, Count = kv.Value.Value }).ToList(), Reason = _reason.Text.Trim() };
        var r = await Approvals.Run(this, t => { req.ApprovalToken = t.LastOrDefault(); return Session.Api.Post<ShiftDto>($"shifts/{_s.Id}/close", req, t); });
        var t2 = new PrintTable { Title = "Shift close", Subtitle = $"{r.User} · counter {r.CounterNo} · {Dates.DisplayTime(r.OpenedAt)} to {(r.ClosedAt.HasValue ? Dates.DisplayTime(r.ClosedAt.Value) : "")}", Signatures = true };
        t2.Col("Item", 3).Col("Amount", 1, true);
        foreach (var (label, v) in new[] { ("Opening float", r.Float), ("Cash sales", r.CashSales), ("Receipts", r.Receipts), ("Refunds", -r.Refunds), ("Paid out", -r.PaidOuts), ("Expected", r.Expected), ("Counted", r.Counted), ("Difference", r.Difference) })
            t2.Rows.Add(new[] { label, Money.Format(v) });
        foreach (var kv in _counts.Where(kv => kv.Value.Value > 0)) t2.Notes.Add($"Rs {kv.Key:#,##0} × {kv.Value.Value} = {Money.Format(kv.Key * kv.Value.Value)}");
        if (Msg.Confirm(this, "Shift closed. Print the shift summary?", "Closed")) TablePrinter.Print(this, t2);
        return true;
    }
}
