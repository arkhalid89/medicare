namespace MediCare.Client.Screens;

/// <summary>SCR-03 Dashboard: today's money, stock risk, dues, approvals, backup and self-check (filtered by role).</summary>
public sealed class DashboardScreen : AppScreen
{
    private readonly HeroBanner _hero = new HeroBanner();
    private readonly FlowLayoutPanel _tiles = new FlowLayoutPanel();
    private readonly KpiTile _sales = new KpiTile { Label = "Net sales today", Icon = "trend" };
    private readonly KpiTile _invoices = new KpiTile { Label = "Invoices today", Icon = "receipt" };
    private readonly KpiTile _profit = new KpiTile { Label = "Estimated gross profit", Icon = "chart" };
    private readonly KpiTile _cash = new KpiTile { Label = "Cash in / out today", Icon = "cash" };
    private readonly KpiTile _mine = new KpiTile { Label = "My sales today", Icon = "user" };
    private readonly KpiTile _near = new KpiTile { Label = "Near expiry", Icon = "clock" };
    private readonly KpiTile _expired = new KpiTile { Label = "Expired in stock", Icon = "alert" };
    private readonly KpiTile _low = new KpiTile { Label = "Below minimum stock", Icon = "down" };
    private readonly KpiTile _custDues = new KpiTile { Label = "Customers owe", Icon = "users" };
    private readonly KpiTile _suppDues = new KpiTile { Label = "We owe suppliers", Icon = "truck" };
    private readonly KpiTile _approvals = new KpiTile { Label = "Approvals waiting", Icon = "shield" };
    private readonly KpiTile _backup = new KpiTile { Label = "Last backup", Icon = "database" };
    private readonly KpiTile _check = new KpiTile { Label = "Nightly self-check", Icon = "check" };
    private readonly KpiTile _inventory = new KpiTile { Label = "Stock value (cost)", Icon = "box" };
    private readonly TrendChart _daily = new TrendChart();
    private readonly DonutChart _split = new DonutChart { CenterLabel = "Today" };
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
        _tiles.AutoSize = false;
        _tiles.BackColor = Color.Transparent;
        _tiles.Padding = new Padding(0);
        _tiles.SizeChanged += (_, _) => FitTiles();
        _near.Activated += () => OpenBatches("NEAR");
        _expired.Activated += () => OpenBatches("EXPIRED");
        _low.Activated += () => OpenBatches("LOW");
        _approvals.Activated += () => Shell.Open("approvals");
        _custDues.Activated += () => Shell.Open("customers");
        _suppDues.Activated += () => Shell.Open("suppliers");
        _backup.Activated += () => Shell.Open("backup");
        _sales.Activated += () => Shell.Open("invoices");
        _invoices.Activated += () => Shell.Open("invoices");

        if (Session.Can(Perm.SalesPos)) _hero.AddAction("New sale", "cart", "F2", () => Shell.Open("pos"));
        if (Session.Can(Perm.PurchasesView)) _hero.AddAction("Goods-in", "truck", "", () => Shell.Open("purchases"));
        if (Session.CanAny(Perm.ReportsSales, Perm.ReportsStock, Perm.ReportsFinance, Perm.ReportsAdmin)) _hero.AddAction("Reports", "chart", "", () => Shell.Open("reports"));
        _hero.Dock = DockStyle.Top;
        SetGreeting();

        var charts = new TableLayoutPanel { Dock = DockStyle.Top, Height = Theme.S(280), ColumnCount = 2, BackColor = Color.Transparent };
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

        Compose(_hero, Box.Gap(8), _tiles, Box.Gap(8), charts, Box.Gap(8), lists);
        _refresh.Tick += (_, _) => { if (Visible) Fire(Fill); };
    }

    /// <summary>Tiles stretch so each row is filled edge to edge.</summary>
    private void FitTiles()
    {
        var avail = _tiles.ClientSize.Width;
        if (avail <= 0) return;
        var margin = Theme.S(8);
        var cols = Math.Max(1, avail / (Theme.S(220) + margin));
        var w = avail / cols - margin;
        foreach (Control t in _tiles.Controls) if (t.Width != w) t.Width = w;
        // explicit height: an auto-sized flow panel does not re-measure when its children change width
        var rows = (_tiles.Controls.Count + cols - 1) / cols;
        var h = rows * (Theme.S(112) + margin);
        if (_tiles.Height != h) _tiles.Height = h;
    }

    private void SetGreeting()
    {
        var hour = DateTime.Now.Hour;
        var part = hour < 12 ? "Good morning" : hour < 17 ? "Good afternoon" : "Good evening";
        var name = Session.User.FullName.Split(new[] { ' ' }, StringSplitOptions.RemoveEmptyEntries).FirstOrDefault() ?? "";
        _hero.Heading = name.Length > 0 ? $"{part}, {name}" : part;
        var s = Session.Settings;
        var day = DateTime.TryParseExact(s.BusinessDate, "yyyy-MM-dd", CultureInfo.InvariantCulture, DateTimeStyles.None, out var d) ? d.ToString("dddd d MMMM yyyy", CultureInfo.InvariantCulture) : s.BusinessDate;
        _hero.Sub = $"{day}  ·  {(s.DayClosed ? "day closed" : "business day open")}  ·  counter {Session.Counter}";
        _hero.Invalidate();
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
        SetGreeting();
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
        _backup.Set(d.LastBackupAt.HasValue ? Relative(d.LastBackupAt.Value) : "Never",
            (d.LastBackupOk ? "OK" : "FAILED") + " · USB " + (d.LastUsbBackupAt.HasValue ? Dates.Display(d.LastUsbBackupAt.Value) : "never"),
            !d.LastBackupOk || backupAge > 26 ? Tone.Danger : d.LastUsbBackupAt == null || (DateTime.Now - d.LastUsbBackupAt.Value).TotalDays > 7 ? Tone.Warn : Tone.Ok);
        _check.Set(d.SelfCheckAt.HasValue ? (d.SelfCheckOk ? "Passed" : "Problems") : "Not run yet", d.SelfCheckSummary, d.SelfCheckAt == null ? Tone.None : d.SelfCheckOk ? Tone.Ok : Tone.Danger);
        tiles.Add(_backup);
        tiles.Add(_check);

        if (!tiles.SequenceEqual(_tiles.Controls.Cast<Control>().OfType<KpiTile>()))
        {
            _tiles.SuspendLayout();
            _tiles.Controls.Clear();
            foreach (var t in tiles) _tiles.Controls.Add(t);
            FitTiles();
            _tiles.ResumeLayout(true);
        }

        foreach (Control c in Controls) if (c.Tag as string == "finance") c.Visible = d.ShowFinance;
        _daily.SetData(d.DailySales);
        _split.SetData(d.PaymentSplit);
        _top.SetRows(d.TopSellers, r => r.Cast<object?>().Select((v, i) => i == 2 && decimal.TryParse(Convert.ToString(v, CultureInfo.InvariantCulture), NumberStyles.Any, CultureInfo.InvariantCulture, out var m) ? m : v).ToArray());
        _slow.SetRows(d.SlowMovers, r => r.Cast<object?>().Select((v, i) => i == 2 && decimal.TryParse(Convert.ToString(v, CultureInfo.InvariantCulture), NumberStyles.Any, CultureInfo.InvariantCulture, out var m) ? m : v).ToArray());
    }

    public override void OnHostEvent(EventDto e)
    {
        if (Visible && (e.Type == EventTypes.ApprovalRequested || e.Type == EventTypes.ApprovalDecided)) Fire(Fill);
    }

    private static string Relative(DateTime at)
    {
        var days = (DateTime.Today - at.Date).TotalDays;
        var time = at.ToString("HH:mm", CultureInfo.InvariantCulture);
        return days == 0 ? "Today " + time : days == 1 ? "Yesterday " + time : Dates.Display(at);
    }

    protected override void Dispose(bool disposing)
    {
        if (disposing) _refresh.Dispose();
        base.Dispose(disposing);
    }
}
