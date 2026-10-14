using System;
using System.Collections.Generic;
using System.Linq;

namespace MediCare.Core
{
    public sealed class PriceLine
    {
        public decimal UnitPrice { get; set; }
        public int Qty { get; set; }
        public decimal LineDiscount { get; set; }
        public decimal TaxRatePercent { get; set; }
    }

    public sealed class PricedLine
    {
        public decimal Gross { get; set; }
        public decimal LineDiscount { get; set; }
        public decimal InvoiceDiscount { get; set; }
        public decimal Taxable { get; set; }
        public decimal Tax { get; set; }
        public decimal Net { get; set; }
        public decimal TotalDiscount => LineDiscount + InvoiceDiscount;
    }

    public sealed class PriceResult
    {
        public List<PricedLine> Lines { get; } = new List<PricedLine>();
        public decimal Gross { get; set; }
        public decimal Discount { get; set; }
        public decimal Taxable { get; set; }
        public decimal Tax { get; set; }
        public decimal Net { get; set; }
        public decimal DiscountPercent => Gross == 0 ? 0 : Money.Round2(Discount / Gross * 100m);
    }

    /// <summary>
    /// Sales arithmetic (BRD 7.1, FR-064). The same code runs on the client preview and the host.
    /// Invoice discount is allocated pro-rata to lines after line discounts; the rounding remainder goes to the largest line.
    /// Exclusive tax: tax = round(afterDiscount x rate). Inclusive tax: taxable = round(afterDiscount / (1 + rate)), tax = afterDiscount - taxable.
    /// </summary>
    public static class PricingCalculator
    {
        public static PriceResult Calculate(IList<PriceLine> lines, decimal invoiceDiscount, bool taxInclusive)
        {
            if (lines == null) throw new ArgumentNullException(nameof(lines));
            if (invoiceDiscount < 0) throw new ArgumentException("Invoice discount cannot be negative.");
            var result = new PriceResult();
            var afterLine = new decimal[lines.Count];
            for (int i = 0; i < lines.Count; i++)
            {
                var l = lines[i];
                if (l.Qty <= 0) throw new ArgumentException($"Line {i + 1}: quantity must be positive.");
                if (l.UnitPrice < 0) throw new ArgumentException($"Line {i + 1}: price cannot be negative.");
                var gross = Money.Round2(l.UnitPrice * l.Qty);
                var ld = Money.Round2(l.LineDiscount);
                if (ld < 0 || ld > gross) throw new ArgumentException($"Line {i + 1}: discount must be between 0 and the line amount.");
                afterLine[i] = gross - ld;
                result.Lines.Add(new PricedLine { Gross = gross, LineDiscount = ld });
            }

            var baseTotal = afterLine.Sum();
            var inv = Money.Round2(invoiceDiscount);
            if (inv > baseTotal) throw new ArgumentException("Invoice discount is larger than the invoice amount.");
            if (inv > 0 && baseTotal > 0)
            {
                decimal allocated = 0;
                for (int i = 0; i < lines.Count; i++)
                {
                    var share = Money.Round2(inv * afterLine[i] / baseTotal);
                    result.Lines[i].InvoiceDiscount = share;
                    allocated += share;
                }
                var remainder = inv - allocated;
                if (remainder != 0)
                {
                    int largest = 0;
                    for (int i = 1; i < lines.Count; i++) if (afterLine[i] > afterLine[largest]) largest = i;
                    result.Lines[largest].InvoiceDiscount += remainder;
                }
            }

            for (int i = 0; i < lines.Count; i++)
            {
                var pl = result.Lines[i];
                var after = afterLine[i] - pl.InvoiceDiscount;
                var rate = lines[i].TaxRatePercent / 100m;
                if (rate <= 0)
                {
                    pl.Taxable = after; pl.Tax = 0; pl.Net = after;
                }
                else if (taxInclusive)
                {
                    pl.Taxable = Money.Round2(after / (1 + rate));
                    pl.Tax = after - pl.Taxable;
                    pl.Net = after;
                }
                else
                {
                    pl.Taxable = after;
                    pl.Tax = Money.Round2(after * rate);
                    pl.Net = after + pl.Tax;
                }
                result.Gross += pl.Gross;
                result.Discount += pl.TotalDiscount;
                result.Taxable += pl.Taxable;
                result.Tax += pl.Tax;
                result.Net += pl.Net;
            }
            return result;
        }

        /// <summary>Gross for a quantity in base units sold at a per-pack rate (FEFO can split a pack line across batches).</summary>
        public static decimal LineGross(decimal ratePerPack, int baseQty, int packBaseQty) =>
            Money.Round2(packBaseQty <= 1 ? ratePerPack * baseQty : ratePerPack * baseQty / packBaseQty);

        /// <summary>Batch price override is stored per default sale pack; scale it to another pack size.</summary>
        public static decimal ScalePrice(decimal priceForPack, int fromPackBaseQty, int toPackBaseQty) =>
            fromPackBaseQty == toPackBaseQty ? priceForPack : Money.Round2(priceForPack * toPackBaseQty / Math.Max(1, fromPackBaseQty));

        /// <summary>Change due for a cash tender.</summary>
        public static decimal Change(decimal tendered, decimal cashDue) => tendered > cashDue ? tendered - cashDue : 0m;
    }
}
