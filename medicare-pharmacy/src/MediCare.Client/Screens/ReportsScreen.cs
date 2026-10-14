using System.IO;

namespace MediCare.Client.Screens;

/// <summary>SCR-28 Reports: catalogue (filtered by role), parameters, viewer, print and export.</summary>
public sealed class ReportsScreen : AppScreen
{
    private readonly Grid _catalog = new Grid();
    private readonly FlowLayoutPanel _params = new FlowLayoutPanel();
    private readonly Grid _grid = new Grid();
    private readonly Label _title = new UiLabel("", Theme.LgBold);
    private readonly Label _desc = new UiLabel("", Theme.Small, Theme.InkMuted);
    private readonly Label _totals = new UiLabel("", Theme.NumBold);
    private readonly Dictionary<string, Func<string>> _values = new Dictionary<string, Func<string>>();
    private ReportInfo? _report;
    private ReportResult? _result;
    private List<IdName>? _users, _suppliers, _customers, _accounts;

    public override string Title => "Reports";
    public override string Subtitle => "Choose a report, set the filters and press Run (F5).";

    public override IEnumerable<FKey> FKeys => new[]
    {
        new FKey(Keys.F5, "Run", () => Fire(Run), primary: true),
        new FKey(Keys.Control | Keys.P, "Print", PrintIt),
        new FKey(Keys.Control | Keys.E, "Excel", () => Fire(() => Export("xlsx"))) { Enabled = () => Session.Can(Perm.ReportsExport) },
        new FKey(Keys.Control | Keys.Shift | Keys.E, "CSV", () => Fire(() => Export("csv"))) { Enabled = () => Session.Can(Perm.ReportsExport) },
    };

    public ReportsScreen()
    {
        _catalog.Col("Report", 0, ColKind.Text, 200);
        _catalog.Col("Group", 90);
        _catalog.SelectionChanged += (_, _) => Fire(SelectReport);
        _catalog.RowActivated += _ => Fire(Run);
        var left = new Card { Dock = DockStyle.Left, Width = Theme.S(300), Padding = new Padding(1) };
        left.Controls.Add(_catalog);

        _params.Dock = DockStyle.Top;
        _params.AutoSize = true;
        _params.AutoSizeMode = AutoSizeMode.GrowAndShrink;
        _params.BackColor = Color.Transparent;
        _params.WrapContents = true;
        _title.Dock = DockStyle.Top;
        _desc.Dock = DockStyle.Top;
        _totals.Dock = DockStyle.Bottom;
        _totals.AutoSize = false;
        _totals.Height = Theme.S(28);
        _totals.TextAlign = ContentAlignment.MiddleRight;
        _grid.EmptyText = "Press Run to see the report.";
        var gridCard = new Card { Dock = DockStyle.Fill, Padding = new Padding(1) };
        gridCard.Controls.Add(_grid);
        var run = new UiButton("Run", ButtonKind.Accent, "F5");
        run.Click += (_, _) => Fire(Run);
        var print = new UiButton("Print", ButtonKind.Secondary, "Ctrl+P");
        print.Click += (_, _) => PrintIt();
        var xlsx = new UiButton("Excel", ButtonKind.Secondary) { Enabled = Session.Can(Perm.ReportsExport) };
        xlsx.Click += (_, _) => Fire(() => Export("xlsx"));
        var csv = new UiButton("CSV", ButtonKind.Ghost) { Enabled = Session.Can(Perm.ReportsExport) };
        csv.Click += (_, _) => Fire(() => Export("csv"));
        var right = new Panel { Dock = DockStyle.Fill, Padding = new Padding(Theme.S(12), 0, 0, 0) };
        right.Stack(_title, _desc, Box.Gap(6), _params, Box.Toolbar(new Control[] { run, print, xlsx, csv }), _totals, gridCard);
        _totals.SendToBack();
        right.Controls.SetChildIndex(gridCard, 0);
        var body = new Panel { Dock = DockStyle.Fill };
        body.Controls.AddRange(new Control[] { right, left });
        Compose(body);
    }

    protected override async Task OnFirstShow()
    {
        var list = await Session.Api.Get<List<ReportInfo>>("reports");
        _catalog.SetRows(list.OrderBy(r => r.Group).ThenBy(r => r.Title), r => new object?[] { r.Title, r.Group });
        if (list.Count > 0) _catalog.SelectRow(0);
    }

    protected override Task OnReshow() => Task.CompletedTask;

    /// <summary>Opens a report directly (e.g. the controlled register from the navigation).</summary>
    public void Select(string key)
    {
        foreach (DataGridViewRow r in _catalog.Rows)
            if (r.Tag is ReportInfo i && i.Key == key) { _catalog.SelectRow(r.Index); break; }
    }

    private async Task SelectReport()
    {
        var r = _catalog.Current<ReportInfo>();
        if (r == null || r == _report) return;
        _report = r;
        _title.Text = r.Title;
        _desc.Text = r.Description;
        _params.SuspendLayout();
        _params.Controls.Clear();
        _values.Clear();
        foreach (var p in r.Params)
        {
            Control? input = null;
            switch (p.Type)
            {
                case "date":
                    var d = new UiDate { Width = Theme.S(140) };
                    d.Value = p.Key == "from" && !r.Key.Contains("expiry") ? DateTime.Today.AddDays(r.Key == "daily_sales" || r.Key == "cashier_closing" ? 0 : -30) : p.Key == "to" && r.Key == "batch_expiry" ? DateTime.Today.AddDays(90) : DateTime.Today;
                    _values[p.Key] = () => d.Value.ToString("yyyy-MM-dd", CultureInfo.InvariantCulture);
                    input = d;
                    break;
                case "int":
                    var n = new IntBox { Width = Theme.S(90) };
                    if (p.Key == "window") n.Value = Session.Settings.NearExpiryDays;
                    _values[p.Key] = () => n.Text;
                    input = n;
                    break;
                case "choice":
                    var c = new UiCombo { Width = Theme.S(140) };
                    c.SetItems(p.Choices);
                    _values[p.Key] = () => c.SelectedItem as string ?? "";
                    input = c;
                    break;
                case "user":
                case "supplier":
                case "customer":
                case "cash_account":
                case "bank_account":
                    var list = await Lookup(p.Type);
                    if (list == null) continue;
                    var combo = new UiCombo { Width = Theme.S(220) };
                    var items = new List<IdName>();
                    if (p.Type != "customer" && p.Type != "supplier" || r.Key.EndsWith("_outstanding")) items.Add(new IdName { Id = 0, Name = p.Type == "user" ? "(everyone)" : "(default)" });
                    items.AddRange(list);
                    combo.SetItems(items);
                    if (p.Type == "customer" || p.Type == "supplier") combo.Editable();
                    _values[p.Key] = () =>
                    {
                        var x = combo.Selected<IdName>() ?? items.FirstOrDefault(i => string.Equals(i.Name, combo.Text.Trim(), StringComparison.OrdinalIgnoreCase));
                        return x != null && x.Id > 0 ? x.Id.ToString(CultureInfo.InvariantCulture) : "";
                    };
                    input = combo;
                    break;
                default:
                    var t = new UiText { Width = Theme.S(200) };
                    _values[p.Key] = () => t.Text.Trim();
                    input = t;
                    break;
            }
            var cell = new Panel { Width = input.Width + Theme.S(12), Height = Theme.S(56), BackColor = Color.Transparent, Margin = new Padding(0, 0, Theme.S(8), Theme.S(4)) };
            var label = new UiLabel(p.Label, Theme.Small, Theme.InkMuted) { Location = new Point(0, 0) };
            input.Location = new Point(0, Theme.S(20));
            cell.Controls.Add(label);
            cell.Controls.Add(input);
            _params.Controls.Add(cell);
        }
        _params.ResumeLayout(true);
        _result = null;
        _grid.DataSource = null;
        _grid.Columns.Clear();
        _totals.Text = "";
    }

    private async Task<List<IdName>?> Lookup(string type)
    {
        switch (type)
        {
            case "user":
                if (!Session.Can(Perm.UsersManage)) return null;
                return _users ??= (await Session.Api.Get<List<UserDto>>("users")).Select(u => new IdName { Id = u.Id, Name = u.FullName }).ToList();
            case "supplier":
                return _suppliers ??= (await Session.Api.Get<List<SupplierDto>>("suppliers", new { includeInactive = true })).Select(s => new IdName { Id = s.Id, Name = s.Name }).ToList();
            case "customer":
                return _customers ??= (await Session.Api.Get<List<CustomerDto>>("customers", new { includeInactive = true })).Where(c => !c.IsWalkIn).Select(c => new IdName { Id = c.Id, Name = c.ToString() }).ToList();
            default:
                _accounts ??= (await Session.Api.Get<List<CashAccountDto>>("cash-accounts")).Select(a => new IdName { Id = a.Id, Name = a.Name + "|" + a.Kind }).ToList();
                return _accounts.Where(a => type == "cash_account" ? a.Name.EndsWith("|CASH") : !a.Name.EndsWith("|CASH"))
                    .Select(a => new IdName { Id = a.Id, Name = a.Name.Substring(0, a.Name.LastIndexOf('|')) }).ToList();
        }
    }

    private Dictionary<string, string> Values() => _values.ToDictionary(kv => kv.Key, kv => kv.Value());

    private async Task Run()
    {
        if (_report == null) return;
        _result = await Session.Api.Get<ReportResult>($"reports/{_report.Key}{ApiClient.Query(Values())}", slow: true);
        _grid.SetTable(_result);
        _desc.Text = _result.Subtitle.Length > 0 ? _result.Subtitle : _report.Description;
        _totals.Text = _result.Totals == null ? $"{_result.Rows.Count} rows" :
            string.Join("    ", _result.Columns.Select((c, i) => (c, i)).Where(x => x.i < _result.Totals.Count && _result.Totals[x.i].Length > 0).Select(x => (x.i == 0 ? "" : x.c.Name + ": ") + _result.Totals[x.i]));
        if (_result.Truncated) Bar.Show("Only the first rows are shown. Narrow the dates or export to Excel for everything.", Tone.Warn);
    }

    private void PrintIt()
    {
        if (_result == null || _report == null) { Bar.Show("Run the report first.", Tone.Info); return; }
        var t = new PrintTable { Title = _result.Title, Subtitle = _result.Subtitle, Landscape = _result.Columns.Count > 6 };
        for (int i = 0; i < _result.Columns.Count; i++)
        {
            var c = _result.Columns[i];
            var numeric = c.Type == "money" || c.Type == "int" || c.Type == "percent";
            t.Col(c.Name, c.Type == "text" ? 2.2f : 1f, numeric);
        }
        foreach (var r in _result.Rows) t.Rows.Add(r.ToArray());
        if (_result.Totals != null) t.Totals = _result.Totals.ToArray();
        TablePrinter.Print(FindForm()!, t);
    }

    private async Task Export(string format)
    {
        if (_report == null) return;
        var q = Values();
        q["format"] = format;
        var (data, name) = await Session.Api.Download($"reports/{_report.Key}/export{ApiClient.Query(q)}");
        using var sfd = new SaveFileDialog { FileName = name, Filter = format == "xlsx" ? "Excel workbook|*.xlsx" : "CSV|*.csv" };
        if (sfd.ShowDialog(FindForm()) != DialogResult.OK) return;
        File.WriteAllBytes(sfd.FileName, data);
        Bar.Show($"Saved {Path.GetFileName(sfd.FileName)}.", Tone.Ok);
    }
}
