using System;
using System.Globalization;

namespace MediCare.Core
{
    /// <summary>
    /// Money and cost helpers (ADR-006). Amounts are decimal in code; stored as INTEGER paisa,
    /// unit costs as INTEGER x10,000. Rounding is always half away from zero.
    /// </summary>
    public static class Money
    {
        public static decimal Round2(decimal v) => Math.Round(v, 2, MidpointRounding.AwayFromZero);
        public static decimal Round4(decimal v) => Math.Round(v, 4, MidpointRounding.AwayFromZero);

        public static long ToPaisa(decimal v) => (long)(Round2(v) * 100m);
        public static decimal FromPaisa(long paisa) => paisa / 100m;

        public static long ToE4(decimal v) => (long)(Round4(v) * 10000m);
        public static decimal FromE4(long e4) => e4 / 10000m;

        public static long PercentToBp(decimal percent) => (long)Math.Round(percent * 100m, 0, MidpointRounding.AwayFromZero);
        public static decimal BpToPercent(long bp) => bp / 100m;

        private static readonly CultureInfo Inv = CultureInfo.InvariantCulture;

        /// <summary>"1,100.00"</summary>
        public static string Format(decimal v) => v.ToString("#,##0.00", Inv);

        /// <summary>"Rs 1,100.00"</summary>
        public static string FormatRs(decimal v) => (v < 0 ? "-Rs " : "Rs ") + Math.Abs(v).ToString("#,##0.00", Inv);

        public static bool TryParse(string? text, out decimal value)
        {
            value = 0;
            if (string.IsNullOrWhiteSpace(text)) return false;
            var t = text!.Replace(",", "").Replace("Rs", "").Trim();
            return decimal.TryParse(t, NumberStyles.Number, Inv, out value);
        }

        public static decimal Parse(string? text) => TryParse(text, out var v) ? v : 0m;
    }

    public static class Dates
    {
        public static string Display(DateTime d) => d.ToString("dd-MMM-yyyy", CultureInfo.InvariantCulture);
        public static string DisplayTime(DateTime d) => d.ToString("dd-MMM-yyyy HH:mm", CultureInfo.InvariantCulture);
        public static string Expiry(DateTime d) => d.ToString("MMM-yy", CultureInfo.InvariantCulture);
        public static string Iso(DateTime d) => d.ToString("yyyy-MM-dd", CultureInfo.InvariantCulture);

        public static DateTime ParseIso(string s) => DateTime.ParseExact(s, "yyyy-MM-dd", CultureInfo.InvariantCulture);

        public static DateTime EndOfMonth(int year, int month) => new DateTime(year, month, DateTime.DaysInMonth(year, month));

        /// <summary>Parses user-typed expiry: "08/27", "08/2027", "2027-08-31", "31-08-2027". MM/YY means end of month.</summary>
        public static bool TryParseExpiry(string? text, out DateTime date)
        {
            date = default;
            if (string.IsNullOrWhiteSpace(text)) return false;
            var t = text!.Trim();
            var inv = CultureInfo.InvariantCulture;
            string[] full = { "yyyy-MM-dd", "dd-MM-yyyy", "dd/MM/yyyy", "dd-MMM-yyyy", "d-MMM-yyyy", "dd.MM.yyyy" };
            if (DateTime.TryParseExact(t, full, inv, DateTimeStyles.None, out date)) return true;
            var parts = t.Split('/', '-', '.', ' ');
            if (parts.Length == 2 && int.TryParse(parts[0], out var m) && int.TryParse(parts[1], out var y) && m >= 1 && m <= 12)
            {
                if (y < 100) y += 2000;
                if (y < 2000 || y > 2100) return false;
                date = EndOfMonth(y, m);
                return true;
            }
            if (parts.Length == 2 && DateTime.TryParseExact(t, new[] { "MMM-yy", "MMM-yyyy", "MMM yy", "MMM yyyy" }, inv, DateTimeStyles.None, out var mm))
            {
                date = EndOfMonth(mm.Year, mm.Month);
                return true;
            }
            return false;
        }
    }
}
