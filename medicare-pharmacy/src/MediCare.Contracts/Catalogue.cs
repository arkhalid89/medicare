using System;
using System.Collections.Generic;

namespace MediCare.Contracts
{
    public sealed class PackDto
    {
        public long Id { get; set; }
        public string Name { get; set; } = "";
        /// <summary>How many base units this pack contains (integer, >= 1).</summary>
        public int BaseQty { get; set; } = 1;
        public bool IsSellable { get; set; } = true;
        public decimal SalePrice { get; set; }
        public override string ToString() => BaseQty == 1 ? Name : $"{Name} ({BaseQty})";
    }

    public sealed class BarcodeDto
    {
        public long Id { get; set; }
        public string Code { get; set; } = "";
        public string? PackName { get; set; }
    }

    public sealed class MedicineDto
    {
        public long Id { get; set; }
        public string Code { get; set; } = "";
        public string Name { get; set; } = "";
        public string Generic { get; set; } = "";
        public string Brand { get; set; } = "";
        public string Manufacturer { get; set; } = "";
        public string Category { get; set; } = "";
        public string DosageForm { get; set; } = "";
        public string Strength { get; set; } = "";
        public string BaseUnit { get; set; } = "Tablet";
        public List<PackDto> Packs { get; set; } = new List<PackDto>();
        public List<BarcodeDto> Barcodes { get; set; } = new List<BarcodeDto>();
        public string DefaultSalePack { get; set; } = "";
        public string PurchasePack { get; set; } = "";
        public int MinStockQty { get; set; }
        public decimal TaxRatePercent { get; set; }
        public bool RxRequired { get; set; }
        public string Schedule { get; set; } = "";
        public string UrduName { get; set; } = "";
        public string Rack { get; set; } = "";
        public bool IsActive { get; set; } = true;
        public decimal DefaultPurchaseCost { get; set; }
        public int StockQty { get; set; }
        public int QuarantineQty { get; set; }
        public decimal AvgCost { get; set; }
        public string? PriceChangeReason { get; set; }
    }

    public sealed class MedicineListItem
    {
        public long Id { get; set; }
        public string Code { get; set; } = "";
        public string Name { get; set; } = "";
        public string Generic { get; set; } = "";
        public string Manufacturer { get; set; } = "";
        public string DosageForm { get; set; } = "";
        public string Strength { get; set; } = "";
        public string DefaultPack { get; set; } = "";
        public decimal SalePrice { get; set; }
        public int StockQty { get; set; }
        public string StockText { get; set; } = "";
        public int MinStockQty { get; set; }
        public bool IsActive { get; set; }
        public string Schedule { get; set; } = "";
        public override string ToString() => Name;
    }

    public sealed class LookupListDto
    {
        public string Kind { get; set; } = "";
        public List<string> Values { get; set; } = new List<string>();
    }

    public static class ListKinds
    {
        public const string Manufacturer = "manufacturer";
        public const string Category = "category";
        public const string DosageForm = "dosage_form";
        public const string Unit = "unit";
        public const string ExpenseCategory = "expense_category";
        public const string AdjustmentReason = "adjustment_reason";
        public static readonly string[] All = { Manufacturer, Category, DosageForm, Unit, ExpenseCategory, AdjustmentReason };
    }

    public sealed class Gs1Info
    {
        public string Gtin { get; set; } = "";
        public string? Batch { get; set; }
        public DateTime? Expiry { get; set; }
        public DateTime? Manufactured { get; set; }
        public string? Serial { get; set; }
    }

    public sealed class BatchAvailDto
    {
        public long BatchId { get; set; }
        public string BatchNo { get; set; } = "";
        public DateTime Expiry { get; set; }
        public int DaysLeft { get; set; }
        public string Rack { get; set; } = "";
        public int AvailableQty { get; set; }
        public bool Saleable { get; set; }
        public string State { get; set; } = "SALEABLE";
        /// <summary>Batch price for the medicine's default sale pack, if the batch overrides it.</summary>
        public decimal? SalePrice { get; set; }
    }

    public sealed class ScanResult
    {
        public MedicineDto Medicine { get; set; } = new MedicineDto();
        public long? PackId { get; set; }
        public Gs1Info? Gs1 { get; set; }
        public long? ScannedBatchId { get; set; }
        public string? Warning { get; set; }
        public List<BatchAvailDto> Batches { get; set; } = new List<BatchAvailDto>();
    }

    // ---- import ----
    public sealed class ImportIssue
    {
        public int Row { get; set; }
        public string Name { get; set; } = "";
        public string Problem { get; set; } = "";
        public string Severity { get; set; } = "ERROR";
    }

    public sealed class ImportPreview
    {
        public long JobId { get; set; }
        public string FileName { get; set; } = "";
        public int RowsRead { get; set; }
        public List<string> Columns { get; set; } = new List<string>();
        public Dictionary<string, string> Mapping { get; set; } = new Dictionary<string, string>();
        public int Ready { get; set; }
        public int Warnings { get; set; }
        public int Errors { get; set; }
        public List<ImportIssue> Issues { get; set; } = new List<ImportIssue>();
        public string Status { get; set; } = "";
    }

    public sealed class ImportMapRequest
    {
        public Dictionary<string, string> Mapping { get; set; } = new Dictionary<string, string>();
    }

    public static class ImportFields
    {
        public const string Name = "Medicine name";
        public const string Barcode = "Barcode";
        public const string Generic = "Generic name";
        public const string Brand = "Brand";
        public const string Manufacturer = "Manufacturer";
        public const string Category = "Category";
        public const string DosageForm = "Dosage form";
        public const string Strength = "Strength";
        public const string PackName = "Pack name";
        public const string PackSize = "Pack size (base units)";
        public const string SubPackName = "Sub-pack name";
        public const string SubPackSize = "Sub-pack size (base units)";
        public const string BaseUnit = "Base unit";
        public const string PurchasePrice = "Purchase price per pack";
        public const string SalePrice = "Sale price per pack";
        public const string MinStock = "Minimum stock (base units)";
        public const string TaxRate = "Tax rate %";
        public const string RxRequired = "Prescription required";
        public const string Rack = "Rack";
        public const string Ignore = "(ignore)";
        public static readonly string[] All = { Name, Barcode, Generic, Brand, Manufacturer, Category, DosageForm, Strength, BaseUnit, PackName, PackSize, SubPackName, SubPackSize, PurchasePrice, SalePrice, MinStock, TaxRate, RxRequired, Rack };
    }
}
