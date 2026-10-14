namespace MediCare.Client.Infrastructure;

/// <summary>Process-wide state: this PC's configuration, the API client and the logged-in user.</summary>
public static class Session
{
    public static ClientConfig Config { get; set; } = new ClientConfig();
    public static ApiClient Api { get; private set; } = null!;
    public static LoginResponse? Login { get; private set; }
    public static ClientSettings Settings => Login?.Settings ?? new ClientSettings();
    public static UserInfo User => Login?.User ?? new UserInfo();
    public static int Counter => Config.CounterNo;

    /// <summary>Raised on the UI thread after settings were reloaded from the host.</summary>
    public static event Action? SettingsChanged;

    public static void Connect(string hostUrl, string fingerprint)
    {
        var old = Api;
        Api = new ApiClient(hostUrl, fingerprint);
        if (old != null) { Api.Token = old.Token; old.Dispose(); }
    }

    public static void SetLogin(LoginResponse? login)
    {
        Login = login;
        Api.Token = login?.Token;
    }

    public static void UpdateLogin(LoginResponse me)
    {
        if (Login == null) return;
        me.Token = Login.Token;
        Login = me;
        SettingsChanged?.Invoke();
    }

    public static void UpdateSettings(ClientSettings s)
    {
        if (Login == null) return;
        Login.Settings = s;
        SettingsChanged?.Invoke();
    }

    public static bool Can(string perm) => Login != null && Login.Permissions.Contains(perm);
    public static bool CanAny(params string[] perms) => perms.Any(Can);

    public static string Shortcut(string name, string fallback) =>
        Settings.Shortcuts.TryGetValue(name, out var v) && !string.IsNullOrWhiteSpace(v) ? v : fallback;

    public static Keys ShortcutKey(string name, Keys fallback)
    {
        var text = Shortcut(name, "");
        if (text.Length == 0) return fallback;
        try { return (Keys)new KeysConverter().ConvertFromInvariantString(text.Replace("Ctrl", "Control"))!; }
        catch { return fallback; }
    }
}
