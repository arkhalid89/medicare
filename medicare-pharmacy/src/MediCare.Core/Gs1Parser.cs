using System;
using System.Collections.Generic;
using System.Linq;
using System.Text;

namespace MediCare.Core
{
    public sealed class Gs1Result
    {
        public bool Ok { get; set; }
        public string? Error { get; set; }
        public string? ErrorMessage { get; set; }
        public string Gtin { get; set; } = "";
        public string? Batch { get; set; }
        public DateTime? Expiry { get; set; }
        public DateTime? Manufactured { get; set; }
        public string? Serial { get; set; }
        public Dictionary<string, string> Ais { get; } = new Dictionary<string, string>();
    }

    /// <summary>
    /// Parses GS1 element strings from keyboard-wedge 2D scanners (ADR-014, FR-038):
    /// raw form with GS (ASCII 29) separators, optional symbology prefix (]d2, ]C1, ]Q3, ]e0),
    /// or the human-readable bracketed form "(01)...(17)...(10)...".
    /// </summary>
    public static class Gs1Parser
    {
        public const char GS = (char)29;

        private static readonly string[] Prefixes = { "]d2", "]C1", "]Q3", "]e0", "]d1" };

        /// <summary>True when the scan looks like a GS1 element string rather than a plain 1D barcode.</summary>
        public static bool IsCandidate(string? raw)
        {
            if (string.IsNullOrWhiteSpace(raw)) return false;
            var s = StripPrefix(raw!.Trim());
            if (s.StartsWith("(01)", StringComparison.Ordinal) || s.StartsWith("(02)", StringComparison.Ordinal)) return true;
            if (s.Length >= 16 && (s.StartsWith("01", StringComparison.Ordinal) || s.StartsWith("02", StringComparison.Ordinal)) && s.Take(16).All(char.IsDigit)) return true;
            return false;
        }

        public static Gs1Result Parse(string raw)
        {
            var r = new Gs1Result();
            if (!IsCandidate(raw)) return Fail(r, "NOT_GS1", "Not a GS1 code. Scan the 2D code on the pack, or type the medicine name.");
            var s = StripPrefix(raw.Trim());
            try
            {
                if (s[0] == '(') ParseBracketed(s, r.Ais);
                else ParseRaw(s, r.Ais);
            }
            catch (FormatException ex)
            {
                return Fail(r, "NOT_GS1", ex.Message);
            }

            string? gtin;
            if (!r.Ais.TryGetValue("01", out gtin) && !r.Ais.TryGetValue("02", out gtin))
                return Fail(r, "NOT_GS1", "The code has no GTIN (01).");
            if (gtin!.Length != 14 || !gtin.All(char.IsDigit) || !Gtin.IsValid(gtin))
                return Fail(r, "INVALID_GTIN", $"GTIN {gtin} has a wrong check digit. Rescan the pack.");
            r.Gtin = gtin;

            if (r.Ais.TryGetValue("17", out var exp))
            {
                if (!TryDate(exp, out var d)) return Fail(r, "INVALID_EXPIRY", $"Expiry {exp} in the code is not a valid date.");
                r.Expiry = d;
            }
            if (r.Ais.TryGetValue("11", out var mfg))
            {
                if (!TryDate(mfg, out var d)) return Fail(r, "INVALID_EXPIRY", $"Manufacturing date {mfg} in the code is not a valid date.");
                r.Manufactured = d;
            }
            if (r.Ais.TryGetValue("10", out var batch)) r.Batch = batch;
            if (r.Ais.TryGetValue("21", out var serial)) r.Serial = serial;
            r.Ok = true;
            return r;
        }

        private static Gs1Result Fail(Gs1Result r, string code, string msg)
        {
            r.Ok = false; r.Error = code; r.ErrorMessage = msg; return r;
        }

        private static string StripPrefix(string s)
        {
            foreach (var p in Prefixes)
                if (s.StartsWith(p, StringComparison.Ordinal)) return s.Substring(p.Length);
            return s;
        }

        private static void ParseBracketed(string s, Dictionary<string, string> ais)
        {
            int i = 0;
            while (i < s.Length)
            {
                if (s[i] != '(') throw new FormatException("Bracketed GS1 code is malformed.");
                var close = s.IndexOf(')', i);
                if (close < 0) throw new FormatException("Bracketed GS1 code is malformed.");
                var ai = s.Substring(i + 1, close - i - 1);
                var next = s.IndexOf('(', close);
                var value = next < 0 ? s.Substring(close + 1) : s.Substring(close + 1, next - close - 1);
                ais[ai] = value.Trim();
                if (next < 0) break;
                i = next;
            }
        }

        private static void ParseRaw(string s, Dictionary<string, string> ais)
        {
            int i = 0;
            while (i < s.Length)
            {
                if (s[i] == GS) { i++; continue; }
                var aiLen = AiLength(s, i);
                if (i + aiLen > s.Length) throw new FormatException("GS1 code ends inside an application identifier.");
                var ai = s.Substring(i, aiLen);
                i += aiLen;
                var fixedLen = FixedDataLength(ai);
                string value;
                if (fixedLen > 0)
                {
                    if (i + fixedLen > s.Length) throw new FormatException($"Value for ({ai}) is too short.");
                    value = s.Substring(i, fixedLen);
                    i += fixedLen;
                }
                else
                {
                    var end = s.IndexOf(GS, i);
                    if (end < 0) end = s.Length;
                    value = s.Substring(i, end - i);
                    i = end;
                }
                ais[ai] = value;
            }
        }

        private static int AiLength(string s, int i)
        {
            if (i + 2 > s.Length || !char.IsDigit(s[i]) || !char.IsDigit(s[i + 1])) throw new FormatException("GS1 code contains an invalid application identifier.");
            var two = int.Parse(s.Substring(i, 2));
            if (two <= 22 || two == 30 || two == 37 || two >= 90) return 2;
            if (two >= 23 && two <= 29) return 3;
            if (two >= 31 && two <= 36) return 4;
            if (two == 39) return 4;
            if (two >= 40 && two <= 49) return 3;
            if (two == 71) return 3;
            if (two >= 70 && two <= 89) return 4;
            return 2;
        }

        private static int FixedDataLength(string ai)
        {
            switch (ai)
            {
                case "00": return 18;
                case "01": case "02": case "03": return 14;
                case "04": return 16;
                case "11": case "12": case "13": case "15": case "16": case "17": return 6;
                case "20": return 2;
            }
            if (ai.Length == 4 && ai[0] == '3' && ai[1] >= '1' && ai[1] <= '6') return 6;
            if (ai.Length == 3 && ai.StartsWith("41", StringComparison.Ordinal)) return 13;
            return 0;
        }

        private static bool TryDate(string yymmdd, out DateTime date)
        {
            date = default;
            if (yymmdd.Length != 6 || !yymmdd.All(char.IsDigit)) return false;
            int y = 2000 + int.Parse(yymmdd.Substring(0, 2));
            int m = int.Parse(yymmdd.Substring(2, 2));
            int d = int.Parse(yymmdd.Substring(4, 2));
            if (m < 1 || m > 12) return false;
            var dim = DateTime.DaysInMonth(y, m);
            if (d == 0) d = dim;
            if (d > dim) return false;
            date = new DateTime(y, m, d);
            return true;
        }

        /// <summary>Builds a raw element string (for tests and label printing).</summary>
        public static string Build(string gtin14, DateTime? expiry, string? batch, string? serial = null)
        {
            var sb = new StringBuilder("01").Append(gtin14);
            if (expiry.HasValue) sb.Append("17").Append(expiry.Value.ToString("yyMMdd"));
            if (!string.IsNullOrEmpty(batch)) { sb.Append("10").Append(batch); if (!string.IsNullOrEmpty(serial)) sb.Append(GS); }
            if (!string.IsNullOrEmpty(serial)) sb.Append("21").Append(serial);
            return sb.ToString();
        }
    }

    public static class Gtin
    {
        /// <summary>Validates the mod-10 check digit of GTIN-8/12/13/14.</summary>
        public static bool IsValid(string code)
        {
            if (string.IsNullOrEmpty(code) || !code.All(char.IsDigit)) return false;
            if (code.Length != 8 && code.Length != 12 && code.Length != 13 && code.Length != 14) return false;
            return CheckDigit(code.Substring(0, code.Length - 1)) == code[code.Length - 1] - '0';
        }

        public static int CheckDigit(string body)
        {
            int sum = 0;
            for (int i = 0; i < body.Length; i++)
            {
                int digit = body[body.Length - 1 - i] - '0';
                sum += digit * (i % 2 == 0 ? 3 : 1);
            }
            return (10 - sum % 10) % 10;
        }

        /// <summary>Normalises a GTIN-14 with leading zeros to the shorter barcode actually printed (e.g. EAN-13).</summary>
        public static IEnumerable<string> Variants(string gtin14)
        {
            yield return gtin14;
            var t = gtin14;
            while (t.Length > 8 && t[0] == '0')
            {
                t = t.Substring(1);
                if (t.Length == 13 || t.Length == 12 || t.Length == 8) yield return t;
            }
        }
    }
}
