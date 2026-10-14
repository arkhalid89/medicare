using System.Collections.Generic;

namespace MediCare.Contracts
{
    /// <summary>Permission keys. The host checks these on every endpoint; the client uses them to build menus.</summary>
    public static class Perm
    {
        public const string DashboardView = "dashboard.view";
        public const string SalesPos = "sales.pos";
        public const string SalesViewAll = "sales.view.all";
        public const string DiscountApprove = "sales.discount.approve";
        public const string CreditApprove = "sales.credit.approve";
        public const string ReturnPost = "sales.return.post";
        public const string ReturnApprove = "sales.return.approve";
        public const string DocsReverse = "docs.reverse";
        public const string MedicinesView = "medicines.view";
        public const string MedicinesEdit = "medicines.edit";
        public const string MedicinesImport = "medicines.import";
        public const string StockView = "stock.view";
        public const string StockAdjust = "stock.adjust";
        public const string StockAdjustApprove = "stock.adjust.approve";
        public const string PurchasesView = "purchases.view";
        public const string PurchasesPost = "purchases.post";
        public const string PurchasesReturn = "purchases.return";
        public const string SuppliersView = "suppliers.view";
        public const string SuppliersEdit = "suppliers.edit";
        public const string CustomersView = "customers.view";
        public const string CustomersEdit = "customers.edit";
        public const string ReceiptsPost = "receipts.post";
        public const string SupplierPaymentsPost = "supplier.payments.post";
        public const string CashView = "cash.view";
        public const string CashPost = "cash.post";
        public const string ExpensesPost = "expenses.post";
        public const string ExpensesApprove = "expenses.approve";
        public const string ShiftClose = "shift.close";
        public const string CashDiffApprove = "cash.diff.approve";
        public const string DayClose = "day.close";
        public const string AfterDayCloseApprove = "day.afterclose.approve";
        public const string ReportsSales = "reports.sales";
        public const string ReportsStock = "reports.stock";
        public const string ReportsFinance = "reports.finance";
        public const string ReportsAdmin = "reports.admin";
        public const string ReportsExport = "reports.export";
        public const string CostView = "cost.view";
        public const string ApprovalsView = "approvals.view";
        public const string UsersManage = "users.manage";
        public const string AuditView = "audit.view";
        public const string SettingsManage = "settings.manage";
        public const string BackupRun = "backup.run";
        public const string BackupRestore = "backup.restore";
        public const string HostStatus = "host.status";

        public static readonly IReadOnlyList<PermissionInfo> Catalog = new List<PermissionInfo>
        {
            new PermissionInfo(DashboardView, "General", "View dashboard"),
            new PermissionInfo(SalesPos, "Sales", "Sell at POS"),
            new PermissionInfo(SalesViewAll, "Sales", "See all counters' invoices"),
            new PermissionInfo(DiscountApprove, "Sales", "Approve discounts above limit"),
            new PermissionInfo(CreditApprove, "Sales", "Approve credit above limit"),
            new PermissionInfo(ReturnPost, "Sales", "Post sales returns"),
            new PermissionInfo(ReturnApprove, "Sales", "Approve refunds above threshold"),
            new PermissionInfo(DocsReverse, "Sales", "Approve reversals of posted documents"),
            new PermissionInfo(MedicinesView, "Inventory", "View medicines"),
            new PermissionInfo(MedicinesEdit, "Inventory", "Add and edit medicines and prices"),
            new PermissionInfo(MedicinesImport, "Inventory", "Import medicine list"),
            new PermissionInfo(StockView, "Inventory", "View batches and stock"),
            new PermissionInfo(StockAdjust, "Inventory", "Create stock adjustments and counts"),
            new PermissionInfo(StockAdjustApprove, "Inventory", "Approve adjustments and write-offs"),
            new PermissionInfo(PurchasesView, "Purchases", "View purchases"),
            new PermissionInfo(PurchasesPost, "Purchases", "Enter and post goods-in"),
            new PermissionInfo(PurchasesReturn, "Purchases", "Post purchase returns"),
            new PermissionInfo(SuppliersView, "Purchases", "View suppliers and ledgers"),
            new PermissionInfo(SuppliersEdit, "Purchases", "Add and edit suppliers"),
            new PermissionInfo(CustomersView, "Customers", "View customers and ledgers"),
            new PermissionInfo(CustomersEdit, "Customers", "Add and edit customers"),
            new PermissionInfo(ReceiptsPost, "Accounts", "Post customer receipts"),
            new PermissionInfo(SupplierPaymentsPost, "Accounts", "Post supplier payments"),
            new PermissionInfo(CashView, "Accounts", "View cashbook and bankbook"),
            new PermissionInfo(CashPost, "Accounts", "Post cash entries and transfers"),
            new PermissionInfo(ExpensesPost, "Accounts", "Post expense vouchers"),
            new PermissionInfo(ExpensesApprove, "Accounts", "Approve expenses above threshold"),
            new PermissionInfo(ShiftClose, "Accounts", "Open and close own shift"),
            new PermissionInfo(CashDiffApprove, "Accounts", "Approve cash differences"),
            new PermissionInfo(DayClose, "Accounts", "Close the business day"),
            new PermissionInfo(AfterDayCloseApprove, "Accounts", "Approve posting after day close"),
            new PermissionInfo(ReportsSales, "Reports", "Sales reports"),
            new PermissionInfo(ReportsStock, "Reports", "Stock reports"),
            new PermissionInfo(ReportsFinance, "Reports", "Financial reports"),
            new PermissionInfo(ReportsAdmin, "Reports", "Audit, backup and controlled register reports"),
            new PermissionInfo(ReportsExport, "Reports", "Export reports"),
            new PermissionInfo(CostView, "Reports", "See cost and margin"),
            new PermissionInfo(ApprovalsView, "Control", "See approval inbox"),
            new PermissionInfo(UsersManage, "Control", "Manage users and roles"),
            new PermissionInfo(AuditView, "Control", "View audit log"),
            new PermissionInfo(SettingsManage, "Control", "Change settings"),
            new PermissionInfo(BackupRun, "Control", "Run backups"),
            new PermissionInfo(BackupRestore, "Control", "Restore backups"),
            new PermissionInfo(HostStatus, "Control", "View host status and workstations"),
        };
    }

    public sealed class PermissionInfo
    {
        public PermissionInfo() { }
        public PermissionInfo(string key, string group, string description) { Key = key; Group = group; Description = description; }
        public string Key { get; set; } = "";
        public string Group { get; set; } = "";
        public string Description { get; set; } = "";
    }
}
