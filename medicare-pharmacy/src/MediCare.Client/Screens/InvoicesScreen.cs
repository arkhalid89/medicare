namespace MediCare.Client.Screens;

/// <summary>SCR-10 Invoices: search posted sales; view, reprint, return, reverse.</summary>
public sealed class InvoicesScreen : AppScreen
{
    private readonly UiDate _from = new UiDate();
    private readonly UiDate _to = new UiDate();
    private readonly UiText _q = new UiText("Invoice no., customer or mobile");
    private readonly UiCheck _mine = new UiCheck("This counter only");
    private readonly Grid _grid = new Grid();
    private readonly Label _summary = new UiLabel("", Theme.Small, Theme.InkMuted);

    public override string Title => "Invoices";
    public override string Subtitle => "Posted sales. Enter opens an invoice.";

    public override IEnumerable<FKey> FKeys => new[]
    {
        new FKey(Keys.F5, "Refresh", () => Fire(Fill)),
        new FKey(Keys.Enter, "Open", () => Fire(Open)),
        new FKey(Keys.F8, "Reprint", () => Fire(Reprint)),
        new FKey(Keys.F9, "Return", () => Fire(Return)) { Enabled = () => Session.Can(Perm.ReturnPost) },
        new FKey(Keys.Control | Keys.R, "Reverse", () => Fire(Reverse)) { Enabled = () => Session.Can(Perm.SalesPos) },
        new FKey(Keys.Control | Keys.P, "Print list", () => TablePrinter.Print(FindForm()!, PrintTable.FromGrid(_grid, "Invoices", $"{Dates.Display(_from.Value)} to {Dates.Display(_to.Value)}"))),
    };

    public InvoicesScreen()
    {
        _from.Value = DateTime.Today;
        _to.Value = DateTime.Today;
        _grid.Col("Invoice", 170, ColKind.Mono);
        _grid.Col("Date", 140, ColKind.DateTime);
        _grid.Col("Counter", 70, ColKind.Int);
        _grid.Col("Cashier", 130);
        _grid.Col("Customer", 0, ColKind.Text, 180);
        _grid.Col("Payment", 120);
        _grid.Col("Net", 110, ColKind.Money);
        _grid.Col("Unpaid", 100, ColKind.Money);
        _grid.Col("Status", 120);
        _grid.RowTone = o => o is SaleSummary s ? (s.Status != "POSTED" ? Tone.Muted : s.HasReturns ? Tone.Warn : Tone.None) : Tone.None;
        _grid.RowActivated += _ => Fire(Open);
        var show = new UiButton("Show", ButtonKind.Accent);
        show.Click += (_, _) => Fire(Fill);
        _q.KeyDown += (_, e) => { if (e.KeyCode == Keys.Enter) { e.SuppressKeyPress = true; Fire(Fill); } };
        var bar = Box.Toolbar(new Control[] { Box.Caption("From"), _from.Sized(140), Box.Caption("To"), _to.Sized(140), _q.Sized(280), _mine, show });
        var card = new Card { Dock = DockStyle.Fill, Padding = new Padding(1) };
        card.Controls.Add(_grid);
        Compose(bar, _summary.Docked(DockStyle.Top), Box.Gap(6), card);
    }

    public override void FocusFirst() => _q.Focus();

    protected override Task OnFirstShow() => Fill();

    private async Task Fill()
    {
        var list = await Session.Api.Get<List<SaleSummary>>("sales", new { from = _from.Value.Date, to = _to.Value.Date, q = _q.Text.Trim(), counter = _mine.Checked ? (int?)Session.Counter : null });
        _grid.SetRows(list, s => new object?[] { s.No, s.At, s.CounterNo, s.Cashier, s.Customer, s.Payment, s.Net, s.Outstanding == 0 ? null : s.Outstanding, s.Status + (s.HasReturns ? " · returns" : "") });
        var posted = list.Where(s => s.Status == "POSTED").ToList();
        _summary.Text = $"{list.Count} invoices · net {Money.FormatRs(posted.Sum(s => s.Net))} · unpaid {Money.FormatRs(posted.Sum(s => s.Outstanding))}";
    }

    private SaleSummary? Current => _grid.Current<SaleSummary>();

    private async Task Open()
    {
        var s = Current;
        if (s == null) return;
        using var d = new SaleDetailDialog(s.Id);
        d.ShowDialog(FindForm());
        if (d.Changed) await Fill();
    }

    private async Task Reprint()
    {
        var s = Current;
        if (s == null) return;
        var r = await Session.Api.Get<ReceiptDto>($"sales/{s.Id}/receipt", new { duplicate = true });
        if (!Printers.Receipt(r)) Bar.Show("The receipt could not be printed. Check the receipt printer.", Tone.Warn);
    }

    private async Task Return()
    {
        var s = Current;
        using var d = new SalesReturnDialog(s?.Id);
        if (d.ShowDialog(FindForm()) == DialogResult.OK) await Fill();
    }

    private async Task Reverse()
    {
        var s = Current;
        if (s == null) return;
        if (await SaleDetailDialog.ReverseSale(FindForm()!, s.Id, s.No)) await Fill();
    }
}

/// <summary>One invoice with its lines and payments.</summary>
public sealed class SaleDetailDialog : DialogForm
{
    private readonly long _id;
    private readonly Grid _lines = new Grid();
    private readonly Label _head = new UiLabel("", Theme.UiBold);
    private readonly Label _totals = new UiLabel("", Theme.Num);
    private SaleDetail? _sale;
    public bool Changed { get; private set; }

    public SaleDetailDialog(long id) : base("Invoice", 980)
    {
        _id = id;
        _lines.Col("#", 40, ColKind.Int);
        _lines.Col("Medicine", 0, ColKind.Text, 220);
        _lines.Col("Batch", 110, ColKind.Mono);
        _lines.Col("Expiry", 80, ColKind.Expiry);
        _lines.Col("Qty", 110);
        _lines.Col("Rate", 90, ColKind.Money);
        _lines.Col("Disc.", 80, ColKind.Money);
        _lines.Col("Tax", 70, ColKind.Money);
        _lines.Col("Net", 100, ColKind.Money);
        _lines.Col("Returned", 80, ColKind.Int);
        _lines.Col("Pick", 70);
        var host = new Panel { Dock = DockStyle.Top, Height = Theme.S(300) };
        host.Controls.Add(_lines);
        Body.Stack(_head, Box.Gap(8), host, Box.Gap(8), _totals);
        AddButton("Close", Close, ButtonKind.Secondary, "Esc");
        CancelButton = (IButtonControl)Buttons.Controls[0];
        AddButton("Reprint", () => UiRun.Fire(this, async () =>
        {
            var r = await Session.Api.Get<ReceiptDto>($"sales/{_id}/receipt", new { duplicate = true });
            if (!Printers.Receipt(r)) Fail("The receipt could not be printed.", Tone.Warn);
        }, Bar), ButtonKind.Secondary, "F8");
        if (Session.Can(Perm.ReturnPost))
            AddButton("Return items", () => { using var d = new SalesReturnDialog(_id); if (d.ShowDialog(this) == DialogResult.OK) { Changed = true; UiRun.Fire(this, Fill, Bar); } });
        AddButton("Reverse invoice", () => UiRun.Fire(this, async () => { if (_sale != null && await ReverseSale(this, _id, _sale.No)) { Changed = true; await Fill(); } }, Bar), ButtonKind.Danger);
        FitToContent();
        Load += (_, _) => UiRun.Fire(this, Fill, Bar);
    }

    private async Task Fill()
    {
        _sale = await Session.Api.Get<SaleDetail>($"sales/{_id}");
        var s = _sale;
        Text = "Invoice " + s.No;
        _head.Text = $"{s.No} · {Dates.DisplayTime(s.At)} · counter {s.CounterNo} · {s.Cashier} · {s.Customer} · {s.Status}" + (s.ReversedBy != null ? $" (reversed by {s.ReversedBy})" : "");
        _lines.SetRows(s.Lines, l => new object?[] { l.LineNo, l.Medicine, l.BatchNo, l.Expiry, l.QtyText, l.Rate, l.Discount, l.Tax, l.Net, l.ReturnedBaseQty == 0 ? null : l.ReturnedBaseQty, l.Pick });
        _totals.Text = $"Gross {Money.Format(s.Gross)}   Discount {Money.Format(s.Discount)}   Tax {Money.Format(s.Tax)}   NET {Money.Format(s.Net)}" +
                       $"\nPaid: {string.Join(", ", s.Payments.Select(p => $"{p.Method} {Money.Format(p.Amount)}"))}" + (s.Outstanding > 0 ? $"   ·   unpaid {Money.Format(s.Outstanding)}" : "");
    }

    /// <summary>Reversal posts an exact opposite document (needs a reason and a manager's approval).</summary>
    public static async Task<bool> ReverseSale(IWin32Window owner, long id, string no)
    {
        var reason = Msg.Ask(owner, "Reverse invoice " + no, "Reason (kept in the audit log). Use reversal only for wrong entries; use a return when goods come back.");
        if (reason == null) return false;
        if (!Msg.Confirm(owner, $"Reverse {no}? Stock goes back, payments are reversed, and the invoice is marked REVERSED. This cannot be undone.")) return false;
        var req = new ReverseRequest { Reason = reason, IdempotencyKey = Compat.NewKey() };
        var r = await Approvals.Run(owner, t => { req.ApprovalToken = t.LastOrDefault() ?? ""; return Session.Api.Post<OkResult>($"sales/{id}/reverse", req, t); });
        Msg.Info(owner, r.Message ?? $"Invoice {no} reversed.");
        return true;
    }
}
