using MediCare.Client.Screens;

namespace MediCare.Client.Shell;

/// <summary>Navigation model (flows.md): groups, screens and the permissions that show them.</summary>
public static class Navigation
{
    public static IEnumerable<NavItem> Items(MainForm shell)
    {
        bool P(string p) => Session.Can(p);
        yield return new NavItem("dashboard", "", "Dashboard", () => P(Perm.DashboardView), () => new DashboardScreen());
        yield return new NavItem("pos", "Sales", "New sale", () => P(Perm.SalesPos), () => new PosScreen());
    }
}
