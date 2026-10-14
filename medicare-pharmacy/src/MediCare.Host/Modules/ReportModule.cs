using System.Diagnostics;
using System.Globalization;
using System.Text;
using Dapper;
using MediCare.Contracts;
using MediCare.Core;
using MediCare.Host.Domain;
using MediCare.Host.Infrastructure;
using MediCare.Host.Security;
using Microsoft.Data.Sqlite;
using MiniExcelLibs;

namespace MediCare.Host.Modules;

public static class ReportModule
{
    public static void Map(WebApplication app)
    {
        var g = app.MapGroup("/api/v1");
        g.MapGet("/reports", (HttpContext http, ReportService s) => s.Catalog(http.Ctx())).Authenticated();
        g.MapGet("/reports/{key}", (string key, HttpContext http, ReportService s) => s.Run(http.Ctx(), key, http.Request.Query.ToDictionary(q => q.Key, q => q.Value.ToString()))).Authenticated();
        g.MapGet("/reports/{key}/export", (string key, string? format, HttpContext http, ReportService s) =>
        {
            var ctx = http.Ctx();
            ctx.Require(Perm.ReportsExport);
            var result = s.Run(ctx, key, http.Request.Query.ToDictionary(q => q.Key, q => q.Value.ToString()));
            var name = $"{key}-{Clock.NowPkt:yyyyMMdd-HHmm}";
            if (string.Equals(format, "xlsx", StringComparison.OrdinalIgnoreCase))
                return Results.File(ReportService.ToXlsx(result), "application/vnd.openxmlformats-officedocument.spreadsheetml.sheet", name + ".xlsx");
            return Results.File(Encoding.UTF8.GetBytes(ReportService.ToCsv(result)), "text/csv", name + ".csv");
        }).Authenticated();
        g.MapGet("/dashboard", (HttpContext http, ReportService s) => s.Dashboard(http.Ctx())).Perm(Perm.DashboardView);
        g.MapPost("/selfcheck", (SelfCheckService s) => s.Run()).Perm(Perm.HostStatus);
        g.MapGet("/selfcheck/last", (SelfCheckService s) => s.Last()).Authenticated();
    }
}

public sealed class ReportService(Db db, SettingsService settings, AccountService accounts, PartyService parties)
{
    private static readonly CultureInfo Inv = CultureInfo.InvariantCulture;
    private static string M(long paisa) => Money.Format(Money.FromPaisa(paisa));
    private static string M(decimal v) => Money.Format(v);
    private static string D(string iso) => Dates.Display(Clock.ToPkt(iso));
    private static string DT(string iso) => Dates.DisplayTime(Clock.ToPkt(iso));
    private static string Day(string yyyyMMdd) => Dates.Display(Clock.ParseDate(yyyyMMdd));

    private static ReportParam P(string key, string label, string type, params string[] choices) => new() { Key = key, Label = label, Type = type, Choices = choices.ToList() };
    private static readonly ReportParam From = P("from", "From", "date"), To = P("to", "To", "date");

    private sealed record Def(string Key, string Title, string Group, string Perm, string Description, List<ReportParam> Params);

    private static readonly List<Def> Defs = new()
    {
        new("daily_sales", "Daily sales", "Sales", Perm.ReportsSales, "Invoice, cashier, customer, payment method and total", new() { From, To, P("cashier", "Cashier", "user") }),
        new("sales_summary", "Sales summary", "Sales", Perm.ReportsSales, "Daily, weekly, monthly or custom totals", new() { From, To, P("group", "Group by", "choice", "Day", "Week", "Month") }),
        new("item_sales", "Item-wise sales", "Sales", Perm.ReportsSales, "Medicine, quantity, revenue and returns", new() { From, To, P("q", "Medicine contains", "text") }),
        new("sales_returns", "Sales returns", "Sales", Perm.ReportsSales, "Invoice, medicine, refund and reason", new() { From, To }),
        new("discounts", "Discount report", "Sales", Perm.ReportsSales, "Discounts by invoice, cashier and approver", new() { From, To }),
        new("cashier_closing", "Cashier closing", "Sales", Perm.ReportsSales, "Opening cash, receipts, payments, expected and actual cash", new() { From, To }),
        new("profit", "Profit report", "Accounts", Perm.ReportsFinance, "Net sales, COGS, gross profit, expenses, operating profit", new() { From, To }),
        new("purchases", "Purchase report", "Purchases", Perm.ReportsFinance, "Supplier, invoice, quantities, amounts", new() { From, To, P("supplier", "Supplier", "supplier") }),
        new("purchase_returns", "Purchase returns", "Purchases", Perm.ReportsFinance, "Supplier, batch, quantity, credit", new() { From, To }),
        new("customer_ledger", "Customer ledger", "Accounts", Perm.ReportsFinance, "Invoices, receipts, returns, balance", new() { P("customer", "Customer", "customer"), From, To }),
        new("customer_outstanding", "Customer outstanding", "Accounts", Perm.ReportsFinance, "Customer-wise dues with oldest invoice", new()),
        new("supplier_ledger", "Supplier ledger", "Accounts", Perm.ReportsFinance, "Purchases, payments, credits, balance", new() { P("supplier", "Supplier", "supplier"), From, To }),
        new("supplier_outstanding", "Supplier outstanding", "Accounts", Perm.ReportsFinance, "Supplier-wise and invoice-wise dues", new()),
        new("cashbook", "Cashbook", "Accounts", Perm.ReportsFinance, "Cash receipts, payments and running balance", new() { P("account", "Account", "cash_account"), From, To }),
        new("bankbook", "Bankbook", "Accounts", Perm.ReportsFinance, "Bank, card and wallet movements with running balance", new() { P("account", "Account", "bank_account"), From, To }),
        new("expenses", "Expense report", "Accounts", Perm.ReportsFinance, "Category, date, amount and approver", new() { From, To }),
        new("current_stock", "Current stock", "Stock", Perm.ReportsStock, "Medicine, batch, quantity, cost and sale price", new() { P("q", "Medicine contains", "text") }),
        new("stock_valuation", "Stock valuation", "Stock", Perm.ReportsStock, "Inventory value at moving-average cost", new()),
        new("batch_expiry", "Batch / expiry", "Stock", Perm.ReportsStock, "Batches expiring in a date range", new() { P("from", "Expiry from", "date"), P("to", "Expiry to", "date") }),
        new("near_expiry", "Near-expiry", "Stock", Perm.ReportsStock, "Saleable batches inside the alert window", new() { P("window", "Days", "int") }),
        new("expired_quarantine", "Expired / quarantine", "Stock", Perm.ReportsStock, "Non-saleable quantities and value", new()),
        new("low_stock", "Low stock", "Stock", Perm.ReportsStock, "Stock below minimum", new()),
        new("stock_movement", "Stock movement", "Stock", Perm.ReportsStock, "Purchases, sales, returns, adjustments", new() { From, To, P("q", "Medicine contains", "text") }),
        new("audit", "Audit report", "Admin", Perm.ReportsAdmin, "Sensitive actions, users and timestamps", new() { From, To }),
        new("backups", "Backup report", "Admin", Perm.ReportsAdmin, "Last backups, destination, result and verification", new()),
        new("controlled_register", "Controlled register", "Admin", Perm.ReportsAdmin, "Schedule B and D sales register", new() { From, To, P("schedule", "Schedule", "choice", "B and D", "B", "D") }),
    };

    public List<ReportInfo> Catalog(Ctx ctx) => Defs.Where(d => ctx.Has(d.Perm)).Select(d => new ReportInfo { Key = d.Key, Title = d.Title, Group = d.Group, Description = d.Description, Params = d.Params }).ToList();

    private static DateTime Date(Dictionary<string, string> p, string key, DateTime fallback) =>
        p.TryGetValue(key, out var v) && DateTime.TryParse(v, Inv, DateTimeStyles.None, out var d) ? d.Date : fallback;
    private static long? Long(Dictionary<string, string> p, string key) => p.TryGetValue(key, out var v) && long.TryParse(v, out var l) && l > 0 ? l : null;
    private static string Str(Dictionary<string, string> p, string key) => p.TryGetValue(key, out var v) ? v.Trim() : "";

    public ReportResult Run(Ctx ctx, string key, Dictionary<string, string> p)
    {
        var def = Defs.FirstOrDefault(d => d.Key == key) ?? throw AppException.NotFound("Report");
        ctx.Require(def.Perm);
        var sw = Stopwatch.StartNew();
        var from = Date(p, "from", Clock.Today);
        var to = Date(p, "to", Clock.Today);
        if (to < from) throw AppException.Validation("The To date is before the From date.");
        var mineOnly = !ctx.Has(Perm.SalesViewAll);
        var r = new ReportResult { Key = key, Title = def.Title, GeneratedAt = Clock.NowPkt, Subtitle = $"{Dates.Display(from)} to {Dates.Display(to)}" };
        var f = Clock.DateText(from);
        var t = Clock.DateText(to);
        db.Read(c =>
        {
            switch (key)
            {
                case "daily_sales":
                {
                    var cashier = mineOnly ? ctx.UserId : Long(p, "cashier");
                    Cols(r, ("Invoice", "text"), ("Date/time", "datetime"), ("Counter", "int"), ("Cashier", "text"), ("Customer", "text"), ("Payment", "text"), ("Gross", "money"), ("Discount", "money"), ("Tax", "money"), ("Net", "money"), ("Status", "text"));
                    long g = 0, d = 0, tx = 0, n = 0;
                    foreach (var s in c.Query(@"WITH pm AS (SELECT sp.header_id, GROUP_CONCAT(DISTINCT sp.method) AS methods FROM sales_headers h CROSS JOIN sales_payments sp ON sp.header_id=h.id
                            WHERE h.business_date BETWEEN @f AND @t GROUP BY sp.header_id)
                        SELECT s.no, s.sold_at, s.counter_no, s.status, s.gross_paisa, s.discount_paisa, s.tax_paisa, s.net_paisa, u.full_name AS cashier, cu.name AS customer, pm.methods
                        FROM sales_headers s JOIN users u ON u.id=s.cashier_id JOIN customers cu ON cu.id=s.customer_id LEFT JOIN pm ON pm.header_id=s.id
                        WHERE s.business_date BETWEEN @f AND @t AND (@cashier IS NULL OR s.cashier_id=@cashier) ORDER BY s.id", new { f, t, cashier }))
                    {
                        var posted = (string)s.status == "POSTED";
                        r.Rows.Add(Row(s.no, DT(s.sold_at), s.counter_no, s.cashier, s.customer, ((string?)s.methods ?? "").Contains(',') ? "Split" : s.methods, M((long)s.gross_paisa), M((long)s.discount_paisa), M((long)s.tax_paisa), M((long)s.net_paisa), s.status));
                        if (posted) { g += (long)s.gross_paisa; d += (long)s.discount_paisa; tx += (long)s.tax_paisa; n += (long)s.net_paisa; }
                    }
                    r.Totals = Row($"{r.Rows.Count} invoices", "", "", "", "", "Posted only", M(g), M(d), M(tx), M(n), "");
                    break;
                }
                case "sales_summary":
                {
                    var group = Str(p, "group");
                    var expr = group == "Month" ? "substr(business_date,1,7)" : group == "Week" ? "strftime('%Y-W%W', business_date)" : "business_date";
                    Cols(r, ("Period", "text"), ("Invoices", "int"), ("Gross", "money"), ("Discount", "money"), ("Tax", "money"), ("Returns", "money"), ("Net sales (ex tax)", "money"));
                    var returns = c.Query<(string period, long refund, long taxable)>($"SELECT {expr.Replace("business_date", "sr.business_date")}, SUM(sri.refund_paisa), SUM(sri.taxable_paisa) FROM sales_return_items sri JOIN sales_returns sr ON sr.id=sri.return_id WHERE sr.business_date BETWEEN @f AND @t GROUP BY 1", new { f, t })
                        .ToDictionary(x => x.period, x => x);
                    long ti = 0, tg = 0, td = 0, tt = 0, tr = 0, tn = 0;
                    foreach (var s in c.Query<(string period, long n, long gross, long disc, long tax, long taxable)>($@"SELECT {expr}, COUNT(*), SUM(gross_paisa), SUM(discount_paisa), SUM(tax_paisa), SUM(taxable_paisa)
                        FROM sales_headers WHERE status='POSTED' AND business_date BETWEEN @f AND @t AND (@mine=0 OR cashier_id=@u) GROUP BY 1 ORDER BY 1", new { f, t, mine = mineOnly ? 1 : 0, u = ctx.UserId }))
                    {
                        var ret = returns.TryGetValue(s.period, out var x) ? x : default;
                        r.Rows.Add(Row(group == "Day" || group == "" ? Day(s.period) : s.period, s.n, M(s.gross), M(s.disc), M(s.tax), M(ret.refund), M(s.taxable - ret.taxable)));
                        ti += s.n; tg += s.gross; td += s.disc; tt += s.tax; tr += ret.refund; tn += s.taxable - ret.taxable;
                    }
                    r.Totals = Row("Total", ti, M(tg), M(td), M(tt), M(tr), M(tn));
                    break;
                }
                case "item_sales":
                {
                    var q = Str(p, "q");
                    Cols(r, ("Medicine", "text"), ("Units sold", "int"), ("Revenue (net)", "money"), ("Units returned", "int"), ("Returns", "money"), ("Net revenue", "money"));
                    var rets = c.Query<(long mid, long qty, long refund)>(@"SELECT si.medicine_id, SUM(sri.base_qty), SUM(sri.refund_paisa) FROM sales_return_items sri JOIN sales_returns sr ON sr.id=sri.return_id
                        JOIN sales_items si ON si.id=sri.sales_item_id WHERE sr.business_date BETWEEN @f AND @t GROUP BY si.medicine_id", new { f, t }).ToDictionary(x => x.mid, x => x);
                    long tq = 0, tn = 0, trq = 0, trr = 0;
                    foreach (var s in c.Query<(long mid, string name, long qty, long net)>(@"SELECT si.medicine_id, si.medicine_name, SUM(si.base_qty), SUM(si.net_paisa) FROM sales_items si JOIN sales_headers s ON s.id=si.header_id
                        WHERE s.status='POSTED' AND s.business_date BETWEEN @f AND @t AND (@q='' OR si.medicine_name LIKE '%'||@q||'%') GROUP BY si.medicine_id ORDER BY 4 DESC", new { f, t, q }))
                    {
                        var ret = rets.TryGetValue(s.mid, out var x) ? x : default;
                        r.Rows.Add(Row(s.name, s.qty, M(s.net), ret.qty, M(ret.refund), M(s.net - ret.refund)));
                        tq += s.qty; tn += s.net; trq += ret.qty; trr += ret.refund;
                    }
                    r.Totals = Row("Total", tq, M(tn), trq, M(trr), M(tn - trr));
                    break;
                }
                case "sales_returns":
                {
                    Cols(r, ("Return", "text"), ("Date", "datetime"), ("Invoice", "text"), ("Medicine", "text"), ("Batch", "text"), ("Units", "int"), ("Outcome", "text"), ("Reason", "text"), ("Refund", "money"), ("Method", "text"), ("Approved by", "text"));
                    long tot = 0;
                    foreach (var s in c.Query(@"SELECT sr.no, sr.at, s.no AS inv, si.medicine_name, b.batch_no, sri.base_qty, sri.outcome, sri.reason, sri.refund_paisa, sr.refund_method, IFNULL(ap.full_name,'') AS approver
                        FROM sales_return_items sri JOIN sales_returns sr ON sr.id=sri.return_id JOIN sales_headers s ON s.id=sr.sale_id JOIN sales_items si ON si.id=sri.sales_item_id JOIN batches b ON b.id=sri.batch_id
                        LEFT JOIN approvals a ON a.id=sr.approval_id LEFT JOIN users ap ON ap.id=a.approver_id WHERE sr.business_date BETWEEN @f AND @t ORDER BY sr.id", new { f, t }))
                    {
                        r.Rows.Add(Row(s.no, DT(s.at), s.inv, s.medicine_name, s.batch_no, s.base_qty, s.outcome, s.reason, M((long)s.refund_paisa), s.refund_method, s.approver));
                        tot += (long)s.refund_paisa;
                    }
                    r.Totals = Row("Total", "", "", "", "", "", "", "", M(tot), "", "");
                    break;
                }
                case "discounts":
                {
                    Cols(r, ("Invoice", "text"), ("Date/time", "datetime"), ("Cashier", "text"), ("Gross", "money"), ("Discount", "money"), ("Discount %", "percent"), ("Approved by", "text"));
                    long tg = 0, td = 0;
                    foreach (var s in c.Query(@"SELECT s.no, s.sold_at, u.full_name AS cashier, s.gross_paisa, s.discount_paisa, IFNULL(ap.full_name,'') AS approver FROM sales_headers s JOIN users u ON u.id=s.cashier_id
                        LEFT JOIN approvals a ON a.id=s.discount_approval_id LEFT JOIN users ap ON ap.id=a.approver_id
                        WHERE s.status='POSTED' AND s.discount_paisa > 0 AND s.business_date BETWEEN @f AND @t AND (@mine=0 OR s.cashier_id=@u) ORDER BY s.id", new { f, t, mine = mineOnly ? 1 : 0, u = ctx.UserId }))
                    {
                        var pct = (long)s.gross_paisa == 0 ? 0 : Math.Round((decimal)(long)s.discount_paisa * 100m / (long)s.gross_paisa, 2);
                        r.Rows.Add(Row(s.no, DT(s.sold_at), s.cashier, M((long)s.gross_paisa), M((long)s.discount_paisa), pct.ToString("0.00", Inv), s.approver));
                        tg += (long)s.gross_paisa; td += (long)s.discount_paisa;
                    }
                    r.Totals = Row($"{r.Rows.Count} invoices", "", "", M(tg), M(td), tg == 0 ? "0.00" : Math.Round(td * 100m / tg, 2).ToString("0.00", Inv), "");
                    break;
                }
                case "cashier_closing":
                {
                    Cols(r, ("Shift", "int"), ("Cashier", "text"), ("Counter", "int"), ("Opened", "datetime"), ("Closed", "datetime"), ("Float", "money"), ("Cash sales", "money"), ("Receipts", "money"),
                        ("Refunds", "money"), ("Paid out / other", "money"), ("Expected", "money"), ("Counted", "money"), ("Difference", "money"), ("Reason", "text"));
                    foreach (var id in c.Query<long>("SELECT id FROM shifts WHERE business_date BETWEEN @f AND @t AND (@mine=0 OR user_id=@u) ORDER BY id", new { f, t, mine = mineOnly ? 1 : 0, u = ctx.UserId }).ToList())
                    {
                        var s = accounts.ShiftById(c, id);
                        r.Rows.Add(Row(s.Id, s.User, s.CounterNo, Dates.DisplayTime(s.OpenedAt), s.ClosedAt == null ? "open" : Dates.DisplayTime(s.ClosedAt.Value), M(s.Float), M(s.CashSales), M(s.Receipts),
                            M(s.Refunds), M(s.PaidOuts), M(s.Expected), s.Status == "CLOSED" ? M(s.Counted) : "", s.Status == "CLOSED" ? M(s.Difference) : "", s.Reason));
                    }
                    break;
                }
                case "profit":
                {
                    Cols(r, ("Line", "text"), ("Amount", "money"));
                    var bal = c.Query<(string code, long dr, long cr)>(@"SELECT a.code, SUM(jl.debit_paisa), SUM(jl.credit_paisa) FROM journal_lines jl JOIN journal_entries je ON je.id=jl.entry_id
                        JOIN accounts a ON a.id=jl.account_id WHERE je.entry_date BETWEEN @f AND @t GROUP BY a.code", new { f, t }).ToDictionary(x => x.code, x => x.cr - x.dr);
                    long B(string code) => bal.TryGetValue(code, out var v) ? v : 0;
                    var gross = B(Acct.Sales); var disc = -B(Acct.SalesDiscounts); var ret = -B(Acct.SalesReturns);
                    var netSales = gross - disc - ret; var cogs = -B(Acct.Cogs); var gp = netSales - cogs;
                    var shrink = -B(Acct.Shrinkage); var rounding = -B(Acct.Rounding); var opex = -B(Acct.OpEx); var overShort = -B(Acct.CashOverShort);
                    var op = gp - shrink - rounding - opex - overShort; var other = B(Acct.OtherIncome);
                    r.Rows.Add(Row("Gross sales (excluding sales tax)", M(gross)));
                    r.Rows.Add(Row("Less: sales discounts", M(-disc)));
                    r.Rows.Add(Row("Less: sales returns", M(-ret)));
                    r.Rows.Add(Row("Net sales", M(netSales)));
                    r.Rows.Add(Row("Cost of goods sold (moving average)", M(-cogs)));
                    r.Rows.Add(Row("Gross profit", M(gp)));
                    r.Rows.Add(Row("Gross margin %", netSales == 0 ? "0.00" : Math.Round(gp * 100m / netSales, 2).ToString("0.00", Inv)));
                    r.Rows.Add(Row("Less: write-offs and shrinkage", M(-shrink)));
                    r.Rows.Add(Row("Less: operating expenses", M(-opex)));
                    r.Rows.Add(Row("Less: cash short / (over)", M(-overShort)));
                    if (rounding != 0) r.Rows.Add(Row("Less: rounding differences", M(-rounding)));
                    r.Rows.Add(Row("Operating profit", M(op)));
                    r.Rows.Add(Row("Other income", M(other)));
                    r.Totals = Row("Net profit", M(op + other));
                    r.Subtitle += ". Supplier payments are not expenses; purchase cost reaches profit only through COGS.";
                    break;
                }
                case "purchases":
                {
                    var sid = Long(p, "supplier");
                    Cols(r, ("Purchase", "text"), ("Invoice date", "date"), ("Supplier", "text"), ("Supplier inv.", "text"), ("Lines", "int"), ("Gross", "money"), ("Discount", "money"), ("Tax", "money"),
                        ("Charges", "money"), ("Net", "money"), ("Paid", "money"), ("Returned", "money"), ("Outstanding", "money"), ("Status", "text"));
                    long tn = 0, to2 = 0;
                    foreach (var s in c.Query(@"SELECT pu.*, su.name AS supplier, (SELECT COUNT(*) FROM purchase_items WHERE purchase_id=pu.id) AS lines FROM purchases pu JOIN suppliers su ON su.id=pu.supplier_id
                        WHERE pu.status<>'DRAFT' AND pu.business_date BETWEEN @f AND @t AND (@sid IS NULL OR pu.supplier_id=@sid) ORDER BY pu.id", new { f, t, sid }))
                    {
                        var outst = (string)s.status == "POSTED" && s.is_opening == 0 ? (long)s.net_paisa - (long)s.paid_paisa - (long)s.returned_paisa : 0;
                        r.Rows.Add(Row(s.no, Day(s.inv_date), s.supplier, s.supplier_inv_no, s.lines, M((long)s.gross_paisa), M((long)s.discount_paisa), M((long)s.tax_paisa), M((long)s.charges_paisa),
                            M((long)s.net_paisa), M((long)s.paid_paisa), M((long)s.returned_paisa), M(outst), s.is_opening == 1 ? "OPENING STOCK" : s.status));
                        if ((string)s.status == "POSTED") { tn += (long)s.net_paisa; to2 += outst; }
                    }
                    r.Totals = Row($"{r.Rows.Count} purchases", "", "", "", "", "", "", "", "", M(tn), "", "", M(to2), "");
                    break;
                }
                case "purchase_returns":
                {
                    Cols(r, ("Return", "text"), ("Date", "datetime"), ("Supplier", "text"), ("Purchase", "text"), ("Medicine", "text"), ("Batch", "text"), ("Units", "int"), ("Amount", "money"), ("Credit note", "text"), ("Reason", "text"));
                    long tot = 0;
                    foreach (var s in c.Query(@"SELECT pr.no, pr.at, su.name AS supplier, pu.no AS pno, m.name AS med, b.batch_no, pri.base_qty, pri.amount_paisa, pr.credit_note_ref, pri.reason
                        FROM purchase_return_items pri JOIN purchase_returns pr ON pr.id=pri.return_id JOIN purchases pu ON pu.id=pr.purchase_id JOIN suppliers su ON su.id=pr.supplier_id
                        JOIN batches b ON b.id=pri.batch_id JOIN medicines m ON m.id=b.medicine_id WHERE pr.business_date BETWEEN @f AND @t ORDER BY pr.id", new { f, t }))
                    {
                        r.Rows.Add(Row(s.no, DT(s.at), s.supplier, s.pno, s.med, s.batch_no, s.base_qty, M((long)s.amount_paisa), s.credit_note_ref, s.reason));
                        tot += (long)s.amount_paisa;
                    }
                    r.Totals = Row("Total", "", "", "", "", "", "", M(tot), "", "");
                    break;
                }
                case "customer_ledger":
                case "supplier_ledger":
                {
                    var isCust = key == "customer_ledger";
                    var id = Long(p, isCust ? "customer" : "supplier") ?? throw AppException.Validation($"Choose a {(isCust ? "customer" : "supplier")}.");
                    var l = parties.Ledger(isCust ? "C" : "S", id, from, to);
                    r.Title = $"{def.Title}: {l.Title}";
                    Cols(r, ("Date/time", "datetime"), ("Document", "text"), ("Details", "text"), ("Debit", "money"), ("Credit", "money"), ("Balance", "money"));
                    r.Rows.Add(Row(Dates.Display(from), "", "Opening balance", "", "", M(l.Opening)));
                    foreach (var x in l.Lines) r.Rows.Add(Row(Dates.DisplayTime(x.At), x.DocNo, x.Details, x.Debit == 0 ? "" : M(x.Debit), x.Credit == 0 ? "" : M(x.Credit), M(x.Balance)));
                    r.Totals = Row("Closing balance", "", l.BalanceSide == "Cr" ? "Payable to supplier" : "Receivable from customer", M(l.Lines.Sum(x => x.Debit)), M(l.Lines.Sum(x => x.Credit)), M(l.Closing));
                    break;
                }
                case "customer_outstanding":
                {
                    Cols(r, ("Customer", "text"), ("Code", "text"), ("Mobile", "text"), ("Credit limit", "money"), ("Balance", "money"), ("Oldest unpaid invoice", "text"), ("Days", "int"));
                    long tot = 0;
                    foreach (var cu in c.Query("SELECT id, code, name, mobile, credit_limit_paisa FROM customers WHERE type<>'WALKIN' ORDER BY name").ToList())
                    {
                        var bal = PartyService.CustomerBalance(c, null, (long)cu.id);
                        if (bal == 0) continue;
                        var oldest = c.QueryOne<(string no, string date)>("SELECT no, business_date FROM sales_headers WHERE customer_id=@id AND status='POSTED' AND credit_paisa > settled_paisa ORDER BY id LIMIT 1", new { id = (long)cu.id });
                        r.Rows.Add(Row(cu.name, cu.code, cu.mobile, M((long)cu.credit_limit_paisa), M(bal), oldest?.no ?? "", oldest == null ? "" : ((int)(Clock.Today - Clock.ParseDate(oldest.Value.date)).TotalDays).ToString(Inv)));
                        tot += bal;
                    }
                    r.Subtitle = $"As of {Dates.Display(Clock.Today)}";
                    r.Totals = Row($"{r.Rows.Count} customers", "", "", "", M(tot), "", "");
                    break;
                }
                case "supplier_outstanding":
                {
                    Cols(r, ("Supplier", "text"), ("Code", "text"), ("Balance", "money"), ("Unpaid invoices", "int"), ("Oldest due date", "date"), ("Overdue", "money"));
                    long tot = 0, over = 0;
                    var today = Clock.DateText(Clock.Today);
                    foreach (var s in c.Query("SELECT id, code, name FROM suppliers ORDER BY name").ToList())
                    {
                        var bal = PartyService.SupplierBalance(c, null, (long)s.id);
                        if (bal == 0) continue;
                        var inv = c.QuerySingle<(long n, string? due, long overdue)>(@"SELECT COUNT(*), MIN(due_date), IFNULL(SUM(CASE WHEN due_date < @today THEN net_paisa - paid_paisa - returned_paisa ELSE 0 END),0)
                            FROM purchases WHERE supplier_id=@id AND status='POSTED' AND is_opening=0 AND net_paisa > paid_paisa + returned_paisa", new { id = (long)s.id, today });
                        r.Rows.Add(Row(s.name, s.code, M(bal), inv.n, inv.due == null ? "" : Day(inv.due), M(inv.overdue)));
                        tot += bal; over += inv.overdue;
                    }
                    r.Subtitle = $"As of {Dates.Display(Clock.Today)}";
                    r.Totals = Row($"{r.Rows.Count} suppliers", "", M(tot), "", "", M(over));
                    break;
                }
                case "cashbook":
                case "bankbook":
                {
                    var id = Long(p, "account") ?? c.ExecuteScalar<long?>(key == "cashbook" ? "SELECT id FROM cash_accounts WHERE kind='CASH' ORDER BY id LIMIT 1" : "SELECT id FROM cash_accounts WHERE kind<>'CASH' ORDER BY id LIMIT 1")
                             ?? throw AppException.Validation("No account of this kind exists.");
                    var book = accounts.Book(id, from, to);
                    r.Title = $"{def.Title}: {book.Title}";
                    Cols(r, ("Date/time", "datetime"), ("Document", "text"), ("Details", "text"), ("Receipt", "money"), ("Payment", "money"), ("Balance", "money"));
                    r.Rows.Add(Row(Dates.Display(from), "", "Opening balance", "", "", M(book.Opening)));
                    foreach (var x in book.Lines) r.Rows.Add(Row(Dates.DisplayTime(x.At), x.DocNo, x.Details, x.Debit == 0 ? "" : M(x.Debit), x.Credit == 0 ? "" : M(x.Credit), M(x.Balance)));
                    r.Totals = Row("Closing balance", "", "", M(book.Lines.Sum(x => x.Debit)), M(book.Lines.Sum(x => x.Credit)), M(book.Closing));
                    break;
                }
                case "expenses":
                {
                    Cols(r, ("Voucher", "text"), ("Date", "date"), ("Category", "text"), ("Description", "text"), ("Amount", "money"), ("Paid from", "text"), ("Entered by", "text"), ("Approved by", "text"));
                    var list = accounts.Expenses(from, to);
                    foreach (var e in list.OrderBy(e => e.Id)) r.Rows.Add(Row(e.No, Dates.Display(e.Date), e.Category, e.Description, M(e.Amount), e.Account, e.EnteredBy, e.ApprovedBy));
                    foreach (var grp in list.GroupBy(e => e.Category).OrderBy(g2 => g2.Key)) r.Rows.Add(Row("", "", grp.Key, "Category total", M(grp.Sum(e => e.Amount)), "", "", ""));
                    r.Totals = Row($"{list.Count} vouchers", "", "", "", M(list.Sum(e => e.Amount)), "", "", "");
                    break;
                }
                case "current_stock":
                case "batch_expiry":
                case "near_expiry":
                case "expired_quarantine":
                {
                    var showCost = ctx.Has(Perm.CostView);
                    var today = Clock.Today;
                    var window = Long(p, "window") ?? settings.GetInt("stock.nearExpiryDays", 90);
                    var q = Str(p, "q");
                    Cols(r, ("Medicine", "text"), ("Batch", "text"), ("Expiry", "date"), ("Days left", "int"), ("State", "text"), ("Units", "int"), ("Quantity", "text"), ("Rack", "text"), ("Sale price", "money"), ("Cost value", "money"));
                    var rows = c.Query(@"SELECT m.id AS mid, m.name, m.base_unit, b.batch_no, b.expiry_date, sb.state, sb.qty, CASE WHEN b.rack<>'' THEN b.rack ELSE m.rack END AS rack, IFNULL(mc.avg_cost_e4,0) AS avg,
                        IFNULL((SELECT sale_paisa FROM medicine_packs WHERE id=m.default_sale_pack_id),0) AS price, IFNULL(b.sale_paisa,0) AS bprice
                        FROM stock_balances sb JOIN batches b ON b.id=sb.batch_id JOIN medicines m ON m.id=b.medicine_id LEFT JOIN medicine_costs mc ON mc.medicine_id=m.id
                        WHERE sb.qty > 0 AND (@q='' OR m.name LIKE '%'||@q||'%')
                          AND (@key = 'current_stock'
                            OR (@key = 'batch_expiry' AND b.expiry_date BETWEEN @f AND @t)
                            OR (@key = 'near_expiry' AND sb.state='SALEABLE' AND b.expiry_date > @today AND b.expiry_date <= @horizon)
                            OR (@key = 'expired_quarantine' AND (sb.state='QUARANTINE' OR b.expiry_date <= @today)))
                        ORDER BY m.name, b.expiry_date", new { q, key, f, t, today = Clock.DateText(today), horizon = Clock.DateText(today.AddDays(window)) }).ToList();
                    long totValue = 0, totUnits = 0;
                    var packs = rows.Count > 50 ? CatalogueService.AllPacks(c, null) : new Dictionary<long, List<CatalogueService.PackRow>>();
                    foreach (var s in rows)
                    {
                        var exp = Clock.ParseDate((string)s.expiry_date);
                        var days = Fefo.DaysLeft(exp, today);
                        var expired = !Fefo.IsSaleable(exp, today);
                        var state = (string)s.state == "QUARANTINE" ? "QUARANTINE" : expired ? "EXPIRED" : days <= window ? "NEAR EXPIRY" : "SALEABLE";
                        var include = key switch
                        {
                            "current_stock" => true,
                            "batch_expiry" => exp >= from && exp <= to,
                            "near_expiry" => state == "NEAR EXPIRY",
                            _ => state is "EXPIRED" or "QUARANTINE",
                        };
                        if (!include) continue;
                        var mid = (long)s.mid;
                        if (!packs.ContainsKey(mid)) packs[mid] = CatalogueService.Packs(c, null, mid);
                        var value = Money.ToPaisa(Costing.Cogs((int)(long)s.qty, Money.FromE4((long)s.avg)));
                        r.Rows.Add(Row(s.name, s.batch_no, Dates.Display(exp), days, state, s.qty, CatalogueService.QtyText((int)(long)s.qty, packs[mid], (string)s.base_unit), s.rack,
                            M((long)s.bprice > 0 ? (long)s.bprice : (long)s.price), showCost ? M(value) : "-"));
                        totValue += value; totUnits += (long)s.qty;
                    }
                    if (key != "batch_expiry") r.Subtitle = key == "near_expiry" ? $"Saleable batches expiring within {window} days of {Dates.Display(today)}" : $"As of {Dates.Display(today)}";
                    else r.Subtitle = $"Expiry between {Dates.Display(from)} and {Dates.Display(to)}";
                    r.Totals = Row($"{r.Rows.Count} batches", "", "", "", "", totUnits, "", "", "", showCost ? M(totValue) : "-");
                    break;
                }
                case "stock_valuation":
                {
                    ctx.Require(Perm.CostView);
                    var today = Clock.DateText(Clock.Today);
                    Cols(r, ("Medicine", "text"), ("Saleable units", "int"), ("Expired units", "int"), ("Quarantine units", "int"), ("Average cost", "money"), ("Saleable value", "money"), ("Expired value", "money"), ("Quarantine value", "money"));
                    long ts = 0, te = 0, tq = 0;
                    foreach (var s in c.Query<(string name, long avg, long sale, long exp, long quar)>(@"SELECT m.name, IFNULL(mc.avg_cost_e4,0),
                        IFNULL(SUM(CASE WHEN sb.state='SALEABLE' AND b.expiry_date > @today THEN sb.qty END),0),
                        IFNULL(SUM(CASE WHEN sb.state='SALEABLE' AND b.expiry_date <= @today THEN sb.qty END),0),
                        IFNULL(SUM(CASE WHEN sb.state='QUARANTINE' THEN sb.qty END),0)
                        FROM stock_balances sb JOIN batches b ON b.id=sb.batch_id JOIN medicines m ON m.id=b.medicine_id LEFT JOIN medicine_costs mc ON mc.medicine_id=m.id
                        WHERE sb.qty > 0 GROUP BY m.id ORDER BY m.name", new { today }))
                    {
                        var avg = Money.FromE4(s.avg);
                        long vs = Money.ToPaisa(Costing.Cogs((int)s.sale, avg)), ve = Money.ToPaisa(Costing.Cogs((int)s.exp, avg)), vq = Money.ToPaisa(Costing.Cogs((int)s.quar, avg));
                        r.Rows.Add(Row(s.name, s.sale, s.exp, s.quar, avg.ToString("#,##0.0000", Inv), M(vs), M(ve), M(vq)));
                        ts += vs; te += ve; tq += vq;
                    }
                    var ledger = c.ExecuteScalar<long>("SELECT IFNULL(SUM(jl.debit_paisa - jl.credit_paisa),0) FROM journal_lines jl JOIN accounts a ON a.id=jl.account_id WHERE a.code IN ('1200','1210')");
                    r.Subtitle = $"Moving-average cost as of {Dates.Display(Clock.Today)}. Ledger inventory balance {Money.FormatRs(Money.FromPaisa(ledger))}.";
                    r.Totals = Row("Total", "", "", "", "", M(ts), M(te), M(tq));
                    break;
                }
                case "low_stock":
                {
                    Cols(r, ("Medicine", "text"), ("Saleable units", "int"), ("Minimum", "int"), ("Shortfall", "int"), ("Last supplier", "text"), ("Rack", "text"));
                    var today = Clock.DateText(Clock.Today);
                    foreach (var s in c.Query<(string name, long qty, long min, string? supplier, string rack)>(@"WITH s AS (SELECT b.medicine_id AS mid, SUM(sb.qty) AS qty
                            FROM stock_balances sb JOIN batches b ON b.id=sb.batch_id WHERE sb.state='SALEABLE' AND b.expiry_date > @today GROUP BY b.medicine_id)
                        SELECT m.name, IFNULL(s.qty,0), m.min_stock_qty,
                            (SELECT su.name FROM batches b JOIN suppliers su ON su.id=b.supplier_id WHERE b.medicine_id=m.id ORDER BY b.id DESC LIMIT 1), m.rack
                        FROM medicines m LEFT JOIN s ON s.mid=m.id WHERE m.is_active=1 AND m.min_stock_qty > IFNULL(s.qty,0) ORDER BY m.name", new { today }))
                        r.Rows.Add(Row(s.name, s.qty, s.min, s.min - s.qty, s.supplier ?? "", s.rack));
                    r.Subtitle = $"As of {Dates.Display(Clock.Today)}";
                    r.Totals = Row($"{r.Rows.Count} medicines below minimum", "", "", "", "", "");
                    break;
                }
                case "stock_movement":
                {
                    var q = Str(p, "q");
                    Cols(r, ("Date/time", "datetime"), ("Medicine", "text"), ("Batch", "text"), ("Type", "text"), ("Document", "text"), ("State", "text"), ("In", "int"), ("Out", "int"), ("User", "text"));
                    long tin = 0, tout = 0;
                    foreach (var s in c.Query(@"SELECT sm.at, m.name, b.batch_no, sm.source_type, sm.doc_no, sm.state, sm.qty_delta, IFNULL(u.full_name,'system') AS user FROM stock_movements sm
                        JOIN batches b ON b.id=sm.batch_id JOIN medicines m ON m.id=b.medicine_id LEFT JOIN users u ON u.id=sm.user_id
                        WHERE sm.business_date BETWEEN @f AND @t AND (@q='' OR m.name LIKE '%'||@q||'%') ORDER BY sm.id LIMIT 20001", new { f, t, q }))
                    {
                        var d = (long)s.qty_delta;
                        r.Rows.Add(Row(DT(s.at), s.name, s.batch_no, StockService.TypeName(s.source_type), s.doc_no, s.state, d > 0 ? d.ToString(Inv) : "", d < 0 ? (-d).ToString(Inv) : "", s.user));
                        if (d > 0) tin += d; else tout -= d;
                    }
                    r.Totals = Row("Total", "", "", "", "", "", tin, tout, "");
                    break;
                }
                case "audit":
                {
                    var (fi, ti) = Clock.UtcRange(from, to);
                    Cols(r, ("Time", "datetime"), ("User", "text"), ("Workstation", "text"), ("Action", "text"), ("Module", "text"), ("Record", "text"), ("Before", "text"), ("After", "text"), ("Reason", "text"), ("Approver", "text"));
                    foreach (var s in c.Query("SELECT * FROM audit_logs WHERE at >= @fi AND at < @ti ORDER BY id LIMIT 20001", new { fi, ti }))
                        r.Rows.Add(Row(DT(s.at), s.username, s.workstation, s.action, s.module, s.record_ref, Trim((string)s.before_json), Trim((string)s.after_json), s.reason, s.approver));
                    break;
                }
                case "backups":
                {
                    Cols(r, ("Started", "datetime"), ("Kind", "text"), ("File", "text"), ("Size MB", "text"), ("Result", "text"), ("Verification", "text"), ("Copied to", "text"), ("Message", "text"));
                    foreach (var s in c.Query("SELECT * FROM backup_history ORDER BY id DESC LIMIT 500"))
                        r.Rows.Add(Row(DT(s.started_at), s.kind, s.file_name, ((long)s.size_bytes / 1048576.0).ToString("0.0", Inv), s.result, s.check_result, s.copied_to ?? "", s.message));
                    r.Subtitle = "Most recent first";
                    break;
                }
                case "controlled_register":
                {
                    var sched = Str(p, "schedule");
                    var list = sched == "B" ? new[] { "B" } : sched == "D" ? new[] { "D" } : new[] { "B", "D" };
                    Cols(r, ("Serial", "text"), ("Date", "datetime"), ("Prescriber", "text"), ("Reg. no.", "text"), ("Patient", "text"), ("Drug", "text"), ("Manufacturer", "text"), ("Batch", "text"),
                        ("Units sold", "int"), ("Rx no.", "text"), ("Invoice", "text"), ("Dispensed by", "text"));
                    foreach (var s in c.Query(@"SELECT pr.register_serial, sh.sold_at, pr.prescriber, pr.prescriber_reg, pr.patient, m.name, m.manufacturer, b.batch_no,
                        (SELECT SUM(base_qty) FROM sales_items WHERE header_id=sh.id AND line_no=si.line_no) AS qty, pr.rx_no, sh.no, u.full_name AS who
                        FROM prescriptions pr JOIN sales_items si ON si.id=pr.sales_item_id JOIN sales_headers sh ON sh.id=pr.header_id JOIN medicines m ON m.id=si.medicine_id
                        JOIN batches b ON b.id=si.batch_id JOIN users u ON u.id=sh.cashier_id WHERE m.schedule IN @list AND sh.business_date BETWEEN @f AND @t ORDER BY pr.id", new { list, f, t }))
                        r.Rows.Add(Row(s.register_serial ?? "", DT(s.sold_at), s.prescriber, s.prescriber_reg, s.patient, s.name, s.manufacturer, s.batch_no, s.qty, s.rx_no, s.no, s.who));
                    r.Subtitle += ". Write the serial number on the prescription.";
                    break;
                }
            }
            return 0;
        });
        if (r.Rows.Count > 20000) { r.Rows = r.Rows.Take(20000).ToList(); r.Truncated = true; }
        r.ElapsedMs = sw.ElapsedMilliseconds;
        return r;
    }

    private static string Trim(string s) => s.Length > 300 ? s.Substring(0, 300) + "..." : s;

    private static void Cols(ReportResult r, params (string name, string type)[] cols) => r.Columns = cols.Select(c => new ReportColumn { Name = c.name, Type = c.type }).ToList();

    private static List<string> Row(params object?[] cells) => cells.Select(x => x switch
    {
        null => "",
        string s => s,
        long l => l.ToString(Inv),
        int i => i.ToString(Inv),
        decimal d => d.ToString(Inv),
        _ => Convert.ToString(x, Inv) ?? "",
    }).ToList();

    public static string ToCsv(ReportResult r)
    {
        var sb = new StringBuilder("﻿");
        sb.AppendLine(string.Join(",", r.Columns.Select(c => ImportService.Csv(c.Name))));
        foreach (var row in r.Rows) sb.AppendLine(string.Join(",", row.Select(ImportService.Csv)));
        if (r.Totals != null) sb.AppendLine(string.Join(",", r.Totals.Select(ImportService.Csv)));
        return sb.ToString();
    }

    public static byte[] ToXlsx(ReportResult r)
    {
        var rows = new List<Dictionary<string, object?>>();
        var names = r.Columns.Select((c, i) => r.Columns.Take(i).Any(x => x.Name == c.Name) ? $"{c.Name} {i}" : c.Name).ToList();
        void Add(List<string> cells)
        {
            var d = new Dictionary<string, object?>();
            for (int i = 0; i < names.Count; i++)
            {
                var v = i < cells.Count ? cells[i] : "";
                d[names[i]] = (r.Columns[i].Type is "money" or "int" or "percent") && decimal.TryParse(v.Replace(",", ""), NumberStyles.Number, Inv, out var n) ? n : v;
            }
            rows.Add(d);
        }
        foreach (var row in r.Rows) Add(row);
        if (r.Totals != null) Add(r.Totals);
        using var ms = new MemoryStream();
        ms.SaveAs(rows, sheetName: r.Title.Length > 31 ? r.Title.Substring(0, 31) : r.Title);
        return ms.ToArray();
    }

    // ---------- dashboard (FR-095) ----------
    private readonly object _dashLock = new();
    private DashboardDto? _dash;
    private DateTime _dashAt;
    private long _dashVersion = -1;
    private string _dashDay = "";

    /// <summary>
    /// Dashboard for one person. The shared figures are cached: reused while nothing was written,
    /// and recomputed at most every 10 seconds while counters are busy (every PC polls every minute).
    /// </summary>
    public DashboardDto Dashboard(Ctx ctx)
    {
        DashboardDto shared;
        lock (_dashLock)
        {
            var day = Clock.DateText(Clock.Today);
            var fresh = _dash != null && _dashDay == day && (_dashVersion == db.WriteVersion || (Clock.UtcNow - _dashAt).TotalSeconds < 10);
            if (!fresh)
            {
                var version = db.WriteVersion;
                _dash = BuildDashboard();
                _dashAt = Clock.UtcNow;
                _dashVersion = version;
                _dashDay = day;
            }
            shared = _dash!;
        }
        var d = Json.Deserialize<DashboardDto>(Json.Serialize(shared))!;
        d.ShowFinance = ctx.Has(Perm.ReportsFinance) || ctx.Has(Perm.CashView);
        var my = db.Read(c => c.QuerySingle<(long n, long net)>("SELECT COUNT(*), IFNULL(SUM(net_paisa),0) FROM sales_headers WHERE status='POSTED' AND business_date=@today AND cashier_id=@u",
            new { today = d.BusinessDate, u = ctx.UserId }));
        d.MyInvoicesToday = (int)my.n;
        d.MySalesToday = Money.FromPaisa(my.net);
        if (!d.ShowFinance)
        {
            d.CashReceivedToday = d.CashPaidToday = d.EstGrossProfitToday = d.InventoryValue = d.CustomerDues = d.SupplierDues = 0;
            d.GrossSalesToday = d.NetSalesToday = d.PurchasesToday = d.ReturnsToday = 0;
            d.TopSellers.Clear();
            d.SlowMovers.Clear();
            d.PaymentSplit.Clear();
            d.DailySales.Clear();
        }
        return d;
    }

    private DashboardDto BuildDashboard() => db.Read(c =>
    {
        var today = Clock.DateText(Clock.Today);
        var d = new DashboardDto { BusinessDate = today };
        var s = c.QuerySingle<(long n, long gross, long taxable)>("SELECT COUNT(*), IFNULL(SUM(gross_paisa),0), IFNULL(SUM(taxable_paisa),0) FROM sales_headers WHERE status='POSTED' AND business_date=@today", new { today });
        var ret = c.QuerySingle<(long refund, long taxable)>("SELECT IFNULL(SUM(sri.refund_paisa),0), IFNULL(SUM(sri.taxable_paisa),0) FROM sales_return_items sri JOIN sales_returns sr ON sr.id=sri.return_id WHERE sr.business_date=@today", new { today });
        d.InvoicesToday = (int)s.n; d.GrossSalesToday = Money.FromPaisa(s.gross); d.NetSalesToday = Money.FromPaisa(s.taxable - ret.taxable); d.ReturnsToday = Money.FromPaisa(ret.refund);
        d.PurchasesToday = Money.FromPaisa(c.ExecuteScalar<long>("SELECT IFNULL(SUM(net_paisa),0) FROM purchases WHERE status='POSTED' AND is_opening=0 AND business_date=@today", new { today }));
        var cash = c.QuerySingle<(long dr, long cr)>(@"SELECT IFNULL(SUM(jl.debit_paisa),0), IFNULL(SUM(jl.credit_paisa),0) FROM journal_lines jl JOIN journal_entries je ON je.id=jl.entry_id
            WHERE je.entry_date=@today AND jl.account_id IN (SELECT account_id FROM cash_accounts WHERE kind='CASH')", new { today });
        d.CashReceivedToday = Money.FromPaisa(cash.dr); d.CashPaidToday = Money.FromPaisa(cash.cr);
        var cogs = c.ExecuteScalar<long>(@"SELECT IFNULL(SUM(jl.debit_paisa - jl.credit_paisa),0) FROM journal_lines jl JOIN journal_entries je ON je.id=jl.entry_id JOIN accounts a ON a.id=jl.account_id
            WHERE je.entry_date=@today AND a.code='5000'", new { today });
        d.EstGrossProfitToday = Money.FromPaisa(s.taxable - ret.taxable - cogs);
        var inv = c.QuerySingle<(long sale, long near, long nearCount, long exp, long expCount)>(@"SELECT
            IFNULL(SUM(CASE WHEN sb.state='SALEABLE' AND b.expiry_date > @today THEN sb.qty * IFNULL(mc.avg_cost_e4,0) END),0) / 100,
            IFNULL(SUM(CASE WHEN sb.state='SALEABLE' AND b.expiry_date > @today AND b.expiry_date <= @horizon THEN sb.qty * IFNULL(mc.avg_cost_e4,0) END),0) / 100,
            COUNT(DISTINCT CASE WHEN sb.state='SALEABLE' AND b.expiry_date > @today AND b.expiry_date <= @horizon AND sb.qty > 0 THEN b.id END),
            IFNULL(SUM(CASE WHEN sb.state='QUARANTINE' OR b.expiry_date <= @today THEN sb.qty * IFNULL(mc.avg_cost_e4,0) END),0) / 100,
            COUNT(DISTINCT CASE WHEN (sb.state='QUARANTINE' OR b.expiry_date <= @today) AND sb.qty > 0 THEN b.id END)
            FROM stock_balances sb JOIN batches b ON b.id=sb.batch_id LEFT JOIN medicine_costs mc ON mc.medicine_id=b.medicine_id",
            new { today, horizon = Clock.DateText(Clock.Today.AddDays(settings.GetInt("stock.nearExpiryDays", 90))) });
        d.InventoryValue = Money.FromPaisa(inv.sale); d.NearExpiryValue = Money.FromPaisa(inv.near); d.NearExpiryCount = (int)inv.nearCount; d.ExpiredValue = Money.FromPaisa(inv.exp); d.ExpiredCount = (int)inv.expCount;
        d.LowStockCount = c.ExecuteScalar<int>(@"WITH s AS (SELECT b.medicine_id AS mid, SUM(sb.qty) AS qty FROM stock_balances sb JOIN batches b ON b.id=sb.batch_id
                WHERE sb.state='SALEABLE' AND b.expiry_date > @today GROUP BY b.medicine_id)
            SELECT COUNT(*) FROM medicines m LEFT JOIN s ON s.mid=m.id WHERE m.is_active=1 AND m.min_stock_qty > IFNULL(s.qty,0)", new { today });
        var cust = c.QuerySingle<(long total, long n)>(@"SELECT IFNULL(SUM(bal),0), COUNT(*) FROM (SELECT SUM(jl.debit_paisa - jl.credit_paisa) AS bal FROM journal_lines jl JOIN accounts a ON a.id=jl.account_id
            WHERE a.code='1100' AND jl.party_type='C' GROUP BY jl.party_id HAVING bal > 0)");
        var sup = c.QuerySingle<(long total, long n)>(@"SELECT IFNULL(SUM(bal),0), COUNT(*) FROM (SELECT SUM(jl.credit_paisa - jl.debit_paisa) AS bal FROM journal_lines jl JOIN accounts a ON a.id=jl.account_id
            WHERE a.code='2000' AND jl.party_type='S' GROUP BY jl.party_id HAVING bal > 0)");
        d.CustomerDues = Money.FromPaisa(cust.total); d.CustomersWithDues = (int)cust.n; d.SupplierDues = Money.FromPaisa(sup.total); d.SuppliersWithDues = (int)sup.n;
        d.PendingApprovals = c.ExecuteScalar<int>("SELECT COUNT(*) FROM approvals WHERE status='PENDING'");
        var last = c.QueryOne<(string started_at, string result)>("SELECT started_at, result FROM backup_history WHERE kind IN ('DAILY','HOURLY','MANUAL') ORDER BY id DESC LIMIT 1");
        if (last != null) { d.LastBackupAt = Clock.ToPkt(last.Value.started_at); d.LastBackupOk = last.Value.result == "OK"; }
        d.LastUsbBackupAt = Clock.ToPktOrNull(c.ExecuteScalar<string?>("SELECT MAX(started_at) FROM backup_history WHERE copied_to IS NOT NULL"));
        var sc = c.QueryOne<(string at, long ok, string details)>("SELECT at, ok, details_json FROM selfcheck_runs ORDER BY id DESC LIMIT 1");
        if (sc != null)
        {
            d.SelfCheckAt = Clock.ToPkt(sc.Value.at); d.SelfCheckOk = sc.Value.ok == 1;
            var items = Json.Deserialize<List<SelfCheckItem>>(sc.Value.details) ?? new();
            d.SelfCheckSummary = d.SelfCheckOk ? "Stock = movements, books balanced, audit chain intact" : string.Join("; ", items.Where(i => !i.Ok).Select(i => i.Check));
        }
        var first = Clock.DateText(Clock.Today.AddDays(-13));
        var salesByDay = c.Query<(string day, long v)>("SELECT business_date, SUM(taxable_paisa) FROM sales_headers WHERE status='POSTED' AND business_date BETWEEN @first AND @today GROUP BY business_date", new { first, today })
            .ToDictionary(x => x.day, x => x.v);
        var returnsByDay = c.Query<(string day, long v)>("SELECT sr.business_date, SUM(sri.taxable_paisa) FROM sales_returns sr JOIN sales_return_items sri ON sri.return_id=sr.id WHERE sr.business_date BETWEEN @first AND @today GROUP BY sr.business_date", new { first, today })
            .ToDictionary(x => x.day, x => x.v);
        for (int i = 13; i >= 0; i--)
        {
            var day = Clock.DateText(Clock.Today.AddDays(-i));
            var v = (salesByDay.TryGetValue(day, out var sv) ? sv : 0) - (returnsByDay.TryGetValue(day, out var rv) ? rv : 0);
            d.DailySales.Add(new KeyValuePair<string, decimal>(Clock.Today.AddDays(-i).ToString("dd-MMM", Inv), Money.FromPaisa(v)));
        }
        foreach (var x in c.Query<(string method, long amount)>(@"SELECT sp.method, SUM(sp.amount_paisa) FROM sales_payments sp JOIN sales_headers s ON s.id=sp.header_id
            WHERE s.status='POSTED' AND s.business_date=@today GROUP BY sp.method ORDER BY 2 DESC", new { today }))
            d.PaymentSplit.Add(new KeyValuePair<string, decimal>(x.method, Money.FromPaisa(x.amount)));
        var since = Clock.DateText(Clock.Today.AddDays(-6));
        foreach (var x in c.Query<(string name, long qty, long net)>(@"SELECT si.medicine_name, SUM(si.base_qty), SUM(si.net_paisa) FROM sales_headers s CROSS JOIN sales_items si ON si.header_id=s.id
            WHERE s.status='POSTED' AND s.business_date >= @since GROUP BY si.medicine_id ORDER BY 3 DESC LIMIT 8", new { since }))
            d.TopSellers.Add(Row(x.name, x.qty, M(x.net)));
        var slowSince = Clock.DateText(Clock.Today.AddDays(-90));
        foreach (var x in c.Query<(string name, long qty, long avg)>(@"SELECT m.name, SUM(sb.qty), IFNULL(mc.avg_cost_e4,0) FROM stock_balances sb JOIN batches b ON b.id=sb.batch_id JOIN medicines m ON m.id=b.medicine_id
            LEFT JOIN medicine_costs mc ON mc.medicine_id=m.id WHERE sb.state='SALEABLE' AND sb.qty > 0 AND b.created_at < @slowIso
              AND NOT EXISTS (SELECT 1 FROM sales_items si JOIN sales_headers s ON s.id=si.header_id WHERE si.medicine_id=m.id AND s.business_date >= @slowSince)
            GROUP BY m.id ORDER BY SUM(sb.qty) * IFNULL(mc.avg_cost_e4,0) DESC LIMIT 8", new { slowSince, slowIso = Clock.Iso(Clock.UtcNow.AddDays(-90)) }))
            d.SlowMovers.Add(Row(x.name, x.qty, M(Costing.Cogs((int)x.qty, Money.FromE4(x.avg)))));
        return d;
    });
}

public sealed class SelfCheckService(Db db)
{
    public SelfCheckResult Run()
    {
        var result = db.Read(c =>
        {
            var r = new SelfCheckResult { At = Clock.NowPkt };
            void Add(string check, bool ok, string detail) => r.Items.Add(new SelfCheckItem { Check = check, Ok = ok, Detail = detail });
            var quick = c.ExecuteScalar<string>("PRAGMA quick_check") ?? "no result";
            Add("Database file integrity", quick == "ok", quick);
            var badStock = c.Query<(long batch_id, string state, long qty, long sum)>(@"SELECT x.batch_id, x.state, IFNULL(sb.qty,0), x.s FROM
                (SELECT batch_id, state, SUM(qty_delta) AS s FROM stock_movements GROUP BY batch_id, state) x
                LEFT JOIN stock_balances sb ON sb.batch_id=x.batch_id AND sb.state=x.state WHERE IFNULL(sb.qty,0) <> x.s
                UNION ALL SELECT sb.batch_id, sb.state, sb.qty, 0 FROM stock_balances sb WHERE sb.qty <> 0 AND NOT EXISTS (SELECT 1 FROM stock_movements sm WHERE sm.batch_id=sb.batch_id AND sm.state=sb.state)").ToList();
            Add("Stock balances equal their movements", badStock.Count == 0, badStock.Count == 0 ? "All batch balances match" : string.Join(", ", badStock.Take(10).Select(b => $"batch {b.batch_id} {b.state}: balance {b.qty} vs movements {b.sum}")));
            var unbalanced = c.Query<long>("SELECT entry_id FROM journal_lines GROUP BY entry_id HAVING SUM(debit_paisa) <> SUM(credit_paisa)").ToList();
            Add("Every journal entry balances", unbalanced.Count == 0, unbalanced.Count == 0 ? "Debits equal credits" : "Unbalanced entries: " + string.Join(", ", unbalanced.Take(10)));
            var badSales = c.Query<string>("SELECT s.no FROM sales_headers s WHERE s.net_paisa <> (SELECT IFNULL(SUM(net_paisa),0) FROM sales_items WHERE header_id=s.id)").ToList();
            Add("Invoice totals equal their lines", badSales.Count == 0, badSales.Count == 0 ? "All invoices match" : string.Join(", ", badSales.Take(10)));
            var qoh = c.Query<(string name, long qoh, long bal)>(@"SELECT m.name, mc.qty_on_hand, IFNULL((SELECT SUM(sb.qty) FROM stock_balances sb JOIN batches b ON b.id=sb.batch_id WHERE b.medicine_id=m.id),0)
                FROM medicine_costs mc JOIN medicines m ON m.id=mc.medicine_id").Where(x => x.qoh != x.bal).ToList();
            Add("Costing quantities match stock", qoh.Count == 0, qoh.Count == 0 ? "OK" : string.Join(", ", qoh.Take(10).Select(x => $"{x.name}: {x.qoh} vs {x.bal}")));
            var broken = Audit.VerifyChain(c);
            Add("Audit log chain intact", broken == null, broken == null ? "No tampering detected" : $"Chain breaks at audit row {broken}");
            var valuation = c.ExecuteScalar<long>("SELECT IFNULL(SUM(sb.qty * IFNULL(mc.avg_cost_e4,0)),0) / 100 FROM stock_balances sb JOIN batches b ON b.id=sb.batch_id LEFT JOIN medicine_costs mc ON mc.medicine_id=b.medicine_id");
            var ledger = c.ExecuteScalar<long>("SELECT IFNULL(SUM(jl.debit_paisa - jl.credit_paisa),0) FROM journal_lines jl JOIN accounts a ON a.id=jl.account_id WHERE a.code IN ('1200','1210')");
            var diff = Math.Abs(valuation - ledger);
            Add("Inventory ledger close to valuation", diff <= Math.Max(50000, Math.Abs(ledger) / 100), $"Valuation {Money.FormatRs(Money.FromPaisa(valuation))}, ledger {Money.FormatRs(Money.FromPaisa(ledger))} (moving-average rounding)");
            var lastOk = c.ExecuteScalar<string?>("SELECT MAX(started_at) FROM backup_history WHERE result='OK' AND kind IN ('DAILY','HOURLY','MANUAL')");
            var recent = lastOk != null && (Clock.UtcNow - DateTime.Parse(lastOk).ToUniversalTime()).TotalHours <= 26;
            Add("Verified backup in the last 26 hours", recent, lastOk == null ? "No verified backup yet. Run Back up now." : $"Last verified backup {Dates.DisplayTime(Clock.ToPkt(lastOk))}");
            r.Ok = r.Items.All(i => i.Ok);
            return r;
        });
        db.Write((c, tx) => c.Execute("INSERT INTO selfcheck_runs(at, ok, details_json) VALUES(@at,@ok,@d)", new { at = Clock.NowIso(), ok = result.Ok ? 1 : 0, d = Json.Serialize(result.Items) }, tx));
        return result;
    }

    public SelfCheckResult? Last() => db.Read(c =>
    {
        var r = c.QueryOne<(string at, long ok, string details)>("SELECT at, ok, details_json FROM selfcheck_runs ORDER BY id DESC LIMIT 1");
        return r == null ? null : new SelfCheckResult { At = Clock.ToPkt(r.Value.at), Ok = r.Value.ok == 1, Items = Json.Deserialize<List<SelfCheckItem>>(r.Value.details) ?? new() };
    });
}
