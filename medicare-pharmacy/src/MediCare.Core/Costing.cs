using System;
using System.Collections.Generic;
using System.Linq;
using System.Text;

namespace MediCare.Core
{
    /// <summary>Moving weighted-average cost per base unit (BRD 7.3, ADR-007).</summary>
    public static class Costing
    {
        /// <summary>Effective cost per base unit for a received line (free units, discounts and charges included).</summary>
        public static decimal UnitCost(decimal lineTotalCost, int baseUnitsReceived)
        {
            if (baseUnitsReceived <= 0) throw new ArgumentException("Received quantity must be positive.");
            return Money.Round4(lineTotalCost / baseUnitsReceived);
        }

        /// <summary>New average after adding stock worth addValue.</summary>
        public static decimal AddToAverage(int oldQty, decimal oldAvg, int addQty, decimal addValue)
        {
            if (addQty <= 0) return oldAvg;
            if (oldQty <= 0) return Money.Round4(addValue / addQty);
            return Money.Round4((oldQty * oldAvg + addValue) / (oldQty + addQty));
        }

        /// <summary>New average after removing stock at a specific value (purchase return at purchase cost).</summary>
        public static decimal RemoveFromAverage(int oldQty, decimal oldAvg, int removeQty, decimal removeValue)
        {
            var remaining = oldQty - removeQty;
            if (remaining <= 0) return oldAvg;
            var value = oldQty * oldAvg - removeValue;
            if (value < 0) return oldAvg;
            return Money.Round4(value / remaining);
        }

        public static decimal Cogs(int baseQty, decimal unitCost) => Money.Round2(baseQty * unitCost);

        /// <summary>Allocates a charge (freight) to lines pro-rata to their values; remainder to the largest line.</summary>
        public static decimal[] AllocatePro(decimal total, IList<decimal> weights)
        {
            var result = new decimal[weights.Count];
            var sum = weights.Sum();
            if (total == 0 || sum == 0 || weights.Count == 0) return result;
            decimal allocated = 0;
            for (int i = 0; i < weights.Count; i++) { result[i] = Money.Round2(total * weights[i] / sum); allocated += result[i]; }
            var rem = total - allocated;
            if (rem != 0)
            {
                int largest = 0;
                for (int i = 1; i < weights.Count; i++) if (weights[i] > weights[largest]) largest = i;
                result[largest] += rem;
            }
            return result;
        }
    }

    public sealed class PackSize
    {
        public PackSize(string name, int baseQty) { Name = name; BaseQty = baseQty; }
        public string Name { get; }
        public int BaseQty { get; }
    }

    /// <summary>Formats integer base-unit quantities in pack terms: 1526 caps with Box=70, Strip=14 → "21 Box 4 Strip".</summary>
    public static class QtyFormat
    {
        public static string Format(int baseQty, IEnumerable<PackSize> packs, string baseUnit)
        {
            if (baseQty == 0) return "0";
            var neg = baseQty < 0;
            var q = Math.Abs(baseQty);
            var parts = new List<string>();
            foreach (var p in packs.Where(p => p.BaseQty > 1).OrderByDescending(p => p.BaseQty))
            {
                if (q >= p.BaseQty)
                {
                    parts.Add($"{q / p.BaseQty} {p.Name}");
                    q %= p.BaseQty;
                }
            }
            if (q > 0) parts.Add($"{q} {baseUnit}");
            var s = string.Join(" ", parts);
            return neg ? "-" + s : s;
        }

        /// <summary>Quantity in one pack, with remainder in base units: 109 Strip, or 109 Strip + 3 Tablet.</summary>
        public static string InPack(int baseQty, PackSize pack, string baseUnit)
        {
            if (pack.BaseQty <= 1) return $"{baseQty} {baseUnit}";
            var whole = baseQty / pack.BaseQty;
            var rem = baseQty % pack.BaseQty;
            var sb = new StringBuilder($"{whole} {pack.Name}");
            if (rem != 0) sb.Append($" + {rem} {baseUnit}");
            return sb.ToString();
        }
    }
}
