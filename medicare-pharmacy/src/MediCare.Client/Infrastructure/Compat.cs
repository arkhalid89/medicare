namespace MediCare.Client.Infrastructure;

/// <summary>Helpers that exist in newer .NET but not in .NET Framework 4.8 (Windows 7 build).</summary>
public static class Compat
{
    public static TValue? Get<TKey, TValue>(this IDictionary<TKey, TValue>? d, TKey key) where TKey : notnull =>
        d != null && d.TryGetValue(key, out var v) ? v : default;

    public static bool Has(this string? s, string part) =>
        s != null && s.IndexOf(part, StringComparison.OrdinalIgnoreCase) >= 0;

    public static string[] SplitTrim(this string? s, char sep) =>
        (s ?? "").Split(new[] { sep }, StringSplitOptions.RemoveEmptyEntries).Select(x => x.Trim()).Where(x => x.Length > 0).ToArray();

    public static int Clamp(int v, int min, int max) => v < min ? min : v > max ? max : v;

    public static string NewKey() => Guid.NewGuid().ToString("N");

    public static string Inv(this decimal d) => d.ToString(CultureInfo.InvariantCulture);
}
