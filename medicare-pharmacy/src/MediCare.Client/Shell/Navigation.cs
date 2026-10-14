using MediCare.Client.Screens;

namespace MediCare.Client.Shell;

/// <summary>Navigation model (flows.md): groups, screens and the permissions that show them.</summary>
public static class Navigation
{
    public static IEnumerable<NavItem> Items(MainForm shell)
    {
        bool P(params string[] perms) => Session.CanAny(perms);

        yield return new NavItem("dashboard", "", "Dashboard", () => P(Perm.DashboardView), () => new DashboardScreen());

        yield return new NavItem("pos", "Sales", "New sale", () => P(Perm.SalesPos), () => new PosScreen());
        yield return new NavItem("invoices", "Sales", "Invoices and returns", () => P(Perm.SalesPos, Perm.SalesViewAll, Perm.ReturnPost), () => new InvoicesScreen());

        yield return new NavItem("medicines", "Inventory", "Medicines", () => P(Perm.MedicinesView), () => new MedicinesScreen());
        yield return new NavItem("batches", "Inventory", "Batches and stock", () => P(Perm.StockView), () => new BatchesScreen());
        yield return new NavItem("counts", "Inventory", "Stock counts", () => P(Perm.StockAdjust), () => new StockCountsScreen());

        yield return new NavItem("purchases", "Purchases", "Goods-in", () => P(Perm.PurchasesView), () => new PurchasesScreen());
        yield return new NavItem("suppliers", "Purchases", "Suppliers", () => P(Perm.SuppliersView, Perm.SupplierPaymentsPost), () => new SuppliersScreen());

        yield return new NavItem("customers", "Customers", "Customers", () => P(Perm.CustomersView, Perm.CustomersEdit, Perm.ReceiptsPost), () => new CustomersScreen());

        yield return new NavItem("cash", "Accounts", "Cash and bank", () => P(Perm.CashView), () => new CashScreen());
        yield return new NavItem("expenses", "Accounts", "Expenses", () => P(Perm.CashView, Perm.ExpensesPost), () => new ExpensesScreen());
        yield return new NavItem("closing", "Accounts", "Shift and day closing", () => P(Perm.ShiftClose, Perm.DayClose, Perm.CashView), () => new ClosingScreen());

        yield return new NavItem("reports", "Reports", "Reports", () => P(Perm.ReportsSales, Perm.ReportsStock, Perm.ReportsFinance, Perm.ReportsAdmin), () => new ReportsScreen());

        yield return new NavItem("approvals", "Approvals", "Approvals", () => P(Perm.ApprovalsView), () => new ApprovalsScreen());

        yield return new NavItem("users", "Admin", "Users and roles", () => P(Perm.UsersManage), () => new UsersScreen());
        yield return new NavItem("audit", "Admin", "Audit log", () => P(Perm.AuditView), () => new AuditScreen());
        yield return new NavItem("backup", "Admin", "Backup and restore", () => P(Perm.BackupRun, Perm.BackupRestore), () => new BackupScreen());
        yield return new NavItem("settings", "Admin", "Settings", () => P(Perm.SettingsManage), () => new SettingsScreen());
        yield return new NavItem("host", "Admin", "Host and workstations", () => P(Perm.HostStatus), () => new HostScreen());
    }
}
