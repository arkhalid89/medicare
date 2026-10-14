namespace MediCare.Client.Screens;

/// <summary>SCR-03 Dashboard: today's money, stock risk, dues, approvals, backup and self-check (filtered by role).</summary>
public sealed class DashboardScreen : AppScreen
{
    private readonly FlowLayoutPanel _tiles = new FlowLayoutPanel();
    private readonly KpiTile _sales = new KpiTile { Label = "Net sales today" };
    private readonly KpiTile _invoices = new KpiTile { Label = "Invoices today" };
    private readonly KpiTile _profit = new KpiTile { Label = "Estimated gross profit" };
    private readonly KpiTile _cash = new KpiTile { Label = "Cash in / out today" };
    private readonly KpiTile _mine = new KpiTile { Label = "My sales today" };
    private readonly KpiTile _near = new KpiTile { Label = "Near expiry" };
    private readonly KpiTile _expired = new KpiTile { Label = "Expired in stock" };
    private readonly KpiTile _low = new KpiTile { Label = "Below minimum stock" };
    private readonly KpiTile _custDues = new KpiTile { Label = "Customers owe" };
    private readonly KpiTile _suppDues = new KpiTile { Label = "We owe suppliers" };
    private readonly KpiTile _approvals = new KpiTile { Label = "Approvals waiting" };
    private readonly KpiTile _backup = new KpiTile { Label = "Last backup" };
    private readonly KpiTile _check = new KpiTile { Label = "Nightly self-check" };
    private readonly KpiTile _inventory = new KpiTile { Label = "Stock value (cost)" };
    private readonly BarChart _daily = new BarChart();
    private readonly BarChart _split = new BarChart { Horizontal = true };
    private readonly Grid _top = new Grid();
    private readonly Grid _slow = new Grid();
    private readonly Timer _refresh = new Timer { Interval = 60000 };

    public override string Title => "Dashboard";
    public override string Subtitle => "Today at a glance. Click a figure to open the detail.";

    public override IEnumerable<FKey> FKeys => new[]
    {
        new FKey(Keys.F5, "Refresh", () => Fire(Fill)),
        new FKey(Keys.F2, "New sale", () => Shell.Open("pos")) { Enabled = () => Session.Can(Perm.SalesPos) },
    };

    public DashboardScreen()
    {
        AutoScroll = true;
        _tiles.Dock = DockStyle.Top;
        _tiles.AutoSize = true;
        _tiles.AutoSizeMode = AutoSizeMode.GrowAndShrink;
        _tiles.BackColor = Color.Transparent;
        _tiles.Padding = new Padding(0);
        _near.Activated += () => OpenBatches("NEAR");
        _expired.Activated += () => OpenBatches("EXPIRED");
        _low.Activated += () => OpenBatches("LOW");
        _approvals.Activated += () => Shell.Open("approvals");
        _custDues.Activated += () => Shell.Open("customers");
        _suppDues.Activated += () => Shell.Open("suppliers");
        _backup.Activated += () => Shell.Open("backup");
        _sales.Activated += () => Shell.Open("invoices");
        _invoices.Activated += () => Shell.Open("invoices");

        var charts = new TableLayoutPanel { Dock = DockStyle.Top, Height = Theme.S(260), ColumnCount = 2, BackColor = Color.Transparent };
        charts.ColumnStyles.Add(new ColumnStyle(SizeType.Percent, 60));
        charts.ColumnStyles.Add(new ColumnStyle(SizeType.Percent, 40));
        var dailyCard = new Card("Net sales, last 14 days") { Dock = DockStyle.Fill };
        _daily.Dock = DockStyle.Fill;
        dailyCard.Controls.Add(_daily);
        var splitCard = new Card("Payments today") { Dock = DockStyle.Fill };
        _split.Dock = DockStyle.Fill;
        splitCard.Controls.Add(_split);
        charts.Controls.Add(dailyCard, 0, 0);
        charts.Controls.Add(splitCard, 1, 0);

        var lists = new TableLayoutPanel { Dock = DockStyle.Top, Height = Theme.S(300), ColumnCount = 2, BackColor = Color.Transparent };
        lists.ColumnStyles.Add(new ColumnStyle(SizeType.Percent, 50));
        lists.ColumnStyles.Add(new ColumnStyle(SizeType.Percent, 50));
        var topCard = new Card("Top sellers, last 30 days") { Dock = DockStyle.Fill, Padding = new Padding(1, Theme.S(36), 1, 1) };
        _top.Col("Medicine", 0, ColKind.Text, 200);
        _top.Col("Qty", 100);
        _top.Col("Sales", 110, ColKind.Money);
        topCard.Controls.Add(_top);
        var slowCard = new Card("Slow movers (no sale in 60 days, in stock)") { Dock = DockStyle.Fill, Padding = new Padding(1, Theme.S(36), 1, 1) };
        _slow.Col("Medicine", 0, ColKind.Text, 200);
        _slow.Col("Stock", 120);
        _slow.Col("Value", 110, ColKind.Money);
        slowCard.Controls.Add(_slow);
        lists.Controls.Add(topCard, 0, 0);
        lists.Controls.Add(slowCard, 1, 0);
        lists.Tag = "finance";
        charts.Tag = "finance";

        Compose(_tiles, Box.Gap(8), charts, Box.Gap(8), lists);
        _refresh.Tick += (_, _) => { if (Visible) Fire(Fill); };
    }

    private void OpenBatches(string view) => Fire(async () =>
    {
        var s = await Shell.OpenScreen<BatchesScreen>("batches");
        s?.ShowView(view);
    });

    protected override async Task OnFirstShow()
    {
        await Fill();
        _refresh.Start();
    }

    private async Task Fill()
    {
        var d = await Session.Api.Get<DashboardDto>("dashboard");
        var tiles = new List<KpiTile>();
        if (d.ShowFinance)
        {
            _sales.Set(Money.FormatRs(d.NetSalesToday), $"gross {Money.Format(d.GrossSalesToday)} · returns {Money.Format(d.ReturnsToday)}");
            _invoices.Set(d.InvoicesToday.ToString("#,##0", CultureInfo.InvariantCulture), $"purchases {Money.FormatRs(d.PurchasesToday)}");
            _profit.Set(Money.FormatRs(d.EstGrossProfitToday), d.NetSalesToday > 0 ? $"{d.EstGrossProfitToday / d.NetSalesToday * 100:0.#}% margin at average cost" : "at average cost");
            _cash.Set(Money.FormatRs(d.CashReceivedToday - d.CashPaidToday), $"in {Money.Format(d.CashReceivedToday)} · out {Money.Format(d.CashPaidToday)}");
            _inventory.Set(Money.FormatRs(d.InventoryValue), "saleable and quarantine");
            _custDues.Set(Money.FormatRs(d.CustomerDues), $"{d.CustomersWithDues} customers", d.CustomerDues > 0 ? Tone.Warn : Tone.None);
            _suppDues.Set(Money.FormatRs(d.SupplierDues), $"{d.SuppliersWithDues} suppliers");
            tiles.AddRange(new[] { _sales, _invoices, _profit, _cash, _inventory });
        }
        _mine.Set(Money.FormatRs(d.MySalesToday), $"{d.MyInvoicesToday} invoices by you");
        tiles.Add(_mine);
        _near.Set(d.NearExpiryCount.ToString(CultureInfo.InvariantCulture), $"batches · {Money.FormatRs(d.NearExpiryValue)}", d.NearExpiryCount > 0 ? Tone.Warn : Tone.Ok);
        _expired.Set(d.ExpiredCount.ToString(CultureInfo.InvariantCulture), d.ExpiredCount > 0 ? $"{Money.FormatRs(d.ExpiredValue)} · move to quarantine" : "none", d.ExpiredCount > 0 ? Tone.Danger : Tone.Ok);
        _low.Set(d.LowStockCount.ToString(CultureInfo.InvariantCulture), "medicines to reorder", d.LowStockCount > 0 ? Tone.Warn : Tone.Ok);
        if (Session.Can(Perm.StockView)) tiles.AddRange(new[] { _near, _expired, _low });
        if (d.ShowFinance) tiles.AddRange(new[] { _custDues, _suppDues });
        if (Session.Can(Perm.ApprovalsView))
        {
            _approvals.Set(d.PendingApprovals.ToString(CultureInfo.InvariantCulture), d.PendingApprovals > 0 ? "open the Approvals screen" : "nothing waiting", d.PendingApprovals > 0 ? Tone.Warn : Tone.Ok);
            tiles.Add(_approvals);
        }
        var backupAge = d.LastBackupAt.HasValue ? (DateTime.Now - d.LastBackupAt.Value).TotalHours : 999;
        _backup.Set(d.LastBackupAt.HasValue ? Dates.DisplayTime(d.LastBackupAt.Value) : "Never",
            (d.LastBackupOk ? "OK" : "FAILED") + " · USB " + (d.LastUsbBackupAt.HasValue ? Dates.Display(d.LastUsbBackupAt.Value) : "never"),
            !d.LastBackupOk || backupAge > 26 ? Tone.Danger : d.LastUsbBackupAt == null || (DateTime.Now - d.LastUsbBackupAt.Value).TotalDays > 7 ? Tone.Warn : Tone.Ok);
        _check.Set(d.SelfCheckAt.HasValue ? (d.SelfCheckOk ? "Passed" : "Problems") : "Not run yet", d.SelfCheckSummary, d.SelfCheckAt == null ? Tone.None : d.SelfCheckOk ? Tone.Ok : Tone.Danger);
        tiles.Add(_backup);
        tiles.Add(_check);

        _tiles.SuspendLayout();
        _tiles.Controls.Clear();
        foreach (var t in tiles) _tiles.Controls.Add(t);
        _tiles.ResumeLayout(true);

        foreach (Control c in Controls) if (c.Tag as string == "finance") c.Visible = d.ShowFinance;
        _daily.Format = v => v >= 1000 ? (v / 1000m).ToString("0.#", CultureInfo.InvariantCulture) + "k" : v.ToString("0", CultureInfo.InvariantCulture);
        _daily.SetData(d.DailySales);
        _split.SetData(d.PaymentSplit);
        _top.SetRows(d.TopSellers, r => r.Cast<object?>().Select((v, i) => i == 2 && decimal.TryParse(Convert.ToString(v, CultureInfo.InvariantCulture), NumberStyles.Any, CultureInfo.InvariantCulture, out var m) ? m : v).ToArray());
        _slow.SetRows(d.SlowMovers, r => r.Cast<object?>().Select((v, i) => i == 2 && decimal.TryParse(Convert.ToString(v, CultureInfo.InvariantCulture), NumberStyles.Any, CultureInfo.InvariantCulture, out var m) ? m : v).ToArray());
    }

    protected override void Dispose(bool disposing)
    {
        if (disposing) _refresh.Dispose();
        base.Dispose(disposing);
    }
}
