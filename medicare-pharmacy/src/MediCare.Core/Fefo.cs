using System;
using System.Collections.Generic;
using System.Linq;

namespace MediCare.Core
{
    public sealed class BatchStock
    {
        public long BatchId { get; set; }
        public DateTime Expiry { get; set; }
        public int Available { get; set; }
    }

    public sealed class BatchAllocation
    {
        public long BatchId { get; set; }
        public int Qty { get; set; }
    }

    public sealed class FefoResult
    {
        public List<BatchAllocation> Allocations { get; } = new List<BatchAllocation>();
        public int Shortfall { get; set; }
        public bool Complete => Shortfall == 0;
    }

    /// <summary>
    /// First-expiry-first-out allocation (FR-061). A batch is saleable only while its expiry date is after the business date.
    /// A preferred batch (2D scan or manual pick) is used first when it is saleable; the rest follows FEFO.
    /// </summary>
    public static class Fefo
    {
        public static bool IsSaleable(DateTime expiry, DateTime businessDate) => expiry.Date > businessDate.Date;

        public static int DaysLeft(DateTime expiry, DateTime businessDate) => (int)(expiry.Date - businessDate.Date).TotalDays;

        public static FefoResult Allocate(IEnumerable<BatchStock> batches, int requiredQty, DateTime businessDate, long? preferredBatchId = null, bool preferredOnly = false)
        {
            if (requiredQty <= 0) throw new ArgumentException("Quantity must be positive.");
            var result = new FefoResult();
            var eligible = batches.Where(b => b.Available > 0 && IsSaleable(b.Expiry, businessDate))
                                  .OrderBy(b => b.Expiry).ThenBy(b => b.BatchId).ToList();
            if (preferredBatchId.HasValue)
            {
                var pref = eligible.FirstOrDefault(b => b.BatchId == preferredBatchId.Value);
                if (pref != null)
                {
                    eligible.Remove(pref);
                    eligible.Insert(0, pref);
                }
                if (preferredOnly) eligible = pref == null ? new List<BatchStock>() : new List<BatchStock> { pref };
            }
            var remaining = requiredQty;
            foreach (var b in eligible)
            {
                if (remaining == 0) break;
                var take = Math.Min(b.Available, remaining);
                result.Allocations.Add(new BatchAllocation { BatchId = b.BatchId, Qty = take });
                remaining -= take;
            }
            result.Shortfall = remaining;
            return result;
        }
    }
}
