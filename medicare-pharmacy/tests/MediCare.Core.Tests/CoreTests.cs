using MediCare.Core;
using Xunit;

namespace MediCare.Core.Tests;

public class PricingTests
{
    static PriceLine L(decimal price, int qty = 1, decimal disc = 0, decimal tax = 0) => new() { UnitPrice = price, Qty = qty, LineDiscount = disc, TaxRatePercent = tax };

    [Fact]
    public void P1_InvoiceDiscountAllocatedProRata()
    {
        var r = PricingCalculator.Calculate(new[] { L(68), L(412), L(420), L(74), L(165) }, 39m, false);
        Assert.Equal(new[] { 2.33m, 14.11m, 14.38m, 2.53m, 5.65m }, r.Lines.Select(l => l.InvoiceDiscount));
        Assert.Equal(1139m, r.Gross);
        Assert.Equal(1100m, r.Net);
        Assert.Equal(39m, r.Discount);
    }

    [Fact]
    public void P2_ExclusiveTaxAfterLineDiscount()
    {
        var r = PricingCalculator.Calculate(new[] { L(100, 1, 10, 18) }, 0, false);
        Assert.Equal(90m, r.Taxable); Assert.Equal(16.20m, r.Tax); Assert.Equal(106.20m, r.Net);
    }

    [Fact]
    public void P3_P4_InclusiveTaxExtracted()
    {
        var r = PricingCalculator.Calculate(new[] { L(118, 1, 0, 18) }, 0, true);
        Assert.Equal(100m, r.Taxable); Assert.Equal(18m, r.Tax); Assert.Equal(118m, r.Net);
        var r2 = PricingCalculator.Calculate(new[] { L(50, 1, 0, 18) }, 0, true);
        Assert.Equal(42.37m, r2.Taxable); Assert.Equal(7.63m, r2.Tax); Assert.Equal(50m, r2.Net);
    }

    [Fact]
    public void P5_Rounding()
    {
        var r = PricingCalculator.Calculate(new[] { L(33.33m, 3, Money.Round2(99.99m * 0.10m)) }, 0, false);
        Assert.Equal(99.99m, r.Gross); Assert.Equal(10.00m, r.Discount); Assert.Equal(89.99m, r.Net);
    }

    [Fact]
    public void P7_Change() => Assert.Equal(400m, PricingCalculator.Change(1500m, 1100m));

    [Fact]
    public void RejectsDiscountAboveAmount() =>
        Assert.Throws<ArgumentException>(() => PricingCalculator.Calculate(new[] { L(10) }, 11m, false));
}

public class CostingTests
{
    [Fact]
    public void WorkedExample_MovingAverage()
    {
        var lineNet = 20 * 1754.00m - Money.Round2(20 * 1754.00m * 0.02m);
        Assert.Equal(34378.40m, lineNet);
        var u1 = Costing.UnitCost(lineNet + 350m, 22 * 70);
        Assert.Equal(22.5509m, u1);
        var avg = Costing.AddToAverage(0, 0, 1540, lineNet + 350m);
        Assert.Equal(22.5509m, avg);
        Assert.Equal(315.71m, Costing.Cogs(14, avg));
        var avg2 = Costing.AddToAverage(1526, avg, 700, 18000m);
        Assert.Equal(23.5457m, avg2);
        Assert.Equal(659.28m, Costing.Cogs(28, avg2));
    }

    [Fact]
    public void ChargesAllocation_SumsExactly()
    {
        var parts = Costing.AllocatePro(350m, new[] { 34378.40m, 17340m });
        Assert.Equal(350m, parts.Sum());
    }

    [Fact]
    public void QtyFormat_Packs()
    {
        var packs = new[] { new PackSize("Box", 70), new PackSize("Strip", 14), new PackSize("Capsule", 1) };
        Assert.Equal("21 Box 4 Strip", QtyFormat.Format(1526, packs, "Capsule"));
        Assert.Equal("1 Strip 3 Capsule", QtyFormat.Format(17, packs, "Capsule"));
        Assert.Equal("109 Strip + 3 Tablet", QtyFormat.InPack(1093, new PackSize("Strip", 10), "Tablet"));
    }
}

public class FefoTests
{
    static readonly DateTime Today = new(2026, 10, 14);

    [Fact]
    public void SplitsAcrossBatchesEarliestExpiryFirst()
    {
        var batches = new[] {
            new BatchStock { BatchId = 2, Expiry = new DateTime(2027, 5, 31), Available = 62 },
            new BatchStock { BatchId = 1, Expiry = new DateTime(2026, 11, 30), Available = 5 },
            new BatchStock { BatchId = 3, Expiry = new DateTime(2026, 8, 31), Available = 40 } };
        var r = Fefo.Allocate(batches, 8, Today);
        Assert.True(r.Complete);
        Assert.Equal(new[] { (1L, 5), (2L, 3) }, r.Allocations.Select(a => (a.BatchId, a.Qty)));
    }

    [Fact]
    public void ExpiredOnExpiryDayAndShortfallReported()
    {
        var batches = new[] { new BatchStock { BatchId = 1, Expiry = Today, Available = 10 } };
        var r = Fefo.Allocate(batches, 2, Today);
        Assert.Equal(2, r.Shortfall);
        Assert.Empty(r.Allocations);
    }

    [Fact]
    public void PreferredBatchFirst()
    {
        var batches = new[] {
            new BatchStock { BatchId = 1, Expiry = new DateTime(2026, 11, 30), Available = 5 },
            new BatchStock { BatchId = 2, Expiry = new DateTime(2027, 5, 31), Available = 62 } };
        var r = Fefo.Allocate(batches, 3, Today, preferredBatchId: 2);
        Assert.Equal(2, r.Allocations.Single().BatchId);
    }
}

public class Gs1Tests
{
    const string Gtin = "08964000202029";
    static readonly string GS = ((char)29).ToString();

    [Fact]
    public void G1_RawWithSeparator()
    {
        var r = Gs1Parser.Parse("0108964000202029172708311" + "0RK0925" + GS + "21A7Q2K9");
        Assert.True(r.Ok, r.ErrorMessage);
        Assert.Equal(Gtin, r.Gtin); Assert.Equal(new DateTime(2027, 8, 31), r.Expiry); Assert.Equal("RK0925", r.Batch); Assert.Equal("A7Q2K9", r.Serial);
    }

    [Fact]
    public void G2_SymbologyPrefix()
    {
        var r = Gs1Parser.Parse("]d2" + "010896400020202917270831" + "10RK0925" + GS + "21A7Q2K9");
        Assert.True(r.Ok); Assert.Equal("RK0925", r.Batch);
    }

    [Fact]
    public void G3_BracketedDayZero()
    {
        var r = Gs1Parser.Parse("(01)08964000202029(17)270800(10)RK0925");
        Assert.True(r.Ok); Assert.Equal(new DateTime(2027, 8, 31), r.Expiry); Assert.Equal("RK0925", r.Batch);
    }

    [Fact]
    public void G4_BatchBeforeExpiry()
    {
        var r = Gs1Parser.Parse("0108964000202029" + "10RK0925" + GS + "17270831" + "21X1");
        Assert.True(r.Ok); Assert.Equal("RK0925", r.Batch); Assert.Equal(new DateTime(2027, 8, 31), r.Expiry); Assert.Equal("X1", r.Serial);
    }

    [Fact]
    public void G5_ManufacturingDate()
    {
        var r = Gs1Parser.Parse("0108964000202029" + "11250901" + "17270831" + "10RK0925");
        Assert.True(r.Ok); Assert.Equal(new DateTime(2025, 9, 1), r.Manufactured); Assert.Equal("RK0925", r.Batch);
    }

    [Fact]
    public void G6_PlainEanIsNotGs1() => Assert.False(Gs1Parser.IsCandidate("8964000202029"));

    [Fact]
    public void G7_BadCheckDigit() => Assert.Equal("INVALID_GTIN", Gs1Parser.Parse("0108964000202028172708311" + "0RK0925").Error);

    [Fact]
    public void G8_BadMonth() => Assert.Equal("INVALID_EXPIRY", Gs1Parser.Parse("01089640002020291727133110AB").Error);

    [Fact]
    public void BuildRoundTrips()
    {
        var s = Gs1Parser.Build(Gtin, new DateTime(2027, 8, 31), "RK0925", "S1");
        var r = Gs1Parser.Parse(s);
        Assert.True(r.Ok); Assert.Equal("S1", r.Serial);
    }

    [Theory]
    [InlineData("8964000202029", true)]
    [InlineData("8964000101018", true)]
    [InlineData("8964000101011", false)]
    public void Ean13CheckDigits(string code, bool valid) => Assert.Equal(valid, MediCare.Core.Gtin.IsValid(code));

    [Fact]
    public void GtinVariantsIncludeEan13() => Assert.Contains("8964000202029", MediCare.Core.Gtin.Variants(Gtin));
}

public class DateTests
{
    [Theory]
    [InlineData("08/27", 2027, 8, 31)]
    [InlineData("02/2028", 2028, 2, 29)]
    [InlineData("2027-08-15", 2027, 8, 15)]
    [InlineData("Nov-26", 2026, 11, 30)]
    public void ParsesExpiry(string s, int y, int m, int d)
    {
        Assert.True(Dates.TryParseExpiry(s, out var dt));
        Assert.Equal(new DateTime(y, m, d), dt);
    }
}
