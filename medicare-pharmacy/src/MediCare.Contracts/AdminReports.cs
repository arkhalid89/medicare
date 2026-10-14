using System;
using System.Collections.Generic;

namespace MediCare.Contracts
{
    public sealed class ReportParam
    {
        public string Key { get; set; } = "";
        public string Label { get; set; } = "";
        /// <summary>date, text, int, customer, supplier, medicine, user, choice</summary>
        public string Type { get; set; } = "date";
        public List<string> Choices { get; set; } = new List<string>();
    }

    public sealed class ReportInfo
    {
        public string Key { get; set; } = "";
        public string Title { get; set; } = "";
        public string Group { get; set; } = "";
        public string Description { get; set; } = "";
        public List<ReportParam> Params { get; set; } = new List<ReportParam>();
        public override string ToString() => Title;
    }

    public sealed class ReportColumn
    {
        public string Name { get; set; } = "";
        /// <summary>text, money, int, date, datetime, percent</summary>
        public string Type { get; set; } = "text";
    }

    public sealed class ReportResult
    {
        public string Key { get; set; } = "";
        public string Title { get; set; } = "";
        public string Subtitle { get; set; } = "";
        public List<ReportColumn> Columns { get; set; } = new List<ReportColumn>();
        public List<List<string>> Rows { get; set; } = new List<List<string>>();
        public List<string>? Totals { get; set; }
        public DateTime GeneratedAt { get; set; }
        public long ElapsedMs { get; set; }
        public bool Truncated { get; set; }
    }

    public sealed class DashboardDto
    {
        public string BusinessDate { get; set; } = "";
        public decimal GrossSalesToday { get; set; }
        public decimal NetSalesToday { get; set; }
        public int InvoicesToday { get; set; }
        public decimal ReturnsToday { get; set; }
        public decimal PurchasesToday { get; set; }
        public decimal CashReceivedToday { get; set; }
        public decimal CashPaidToday { get; set; }
        public decimal EstGrossProfitToday { get; set; }
        public decimal InventoryValue { get; set; }
        public int LowStockCount { get; set; }
        public int NearExpiryCount { get; set; }
        public decimal NearExpiryValue { get; set; }
        public int ExpiredCount { get; set; }
        public decimal ExpiredValue { get; set; }
        public decimal CustomerDues { get; set; }
        public int CustomersWithDues { get; set; }
        public decimal SupplierDues { get; set; }
        public int SuppliersWithDues { get; set; }
        public int PendingApprovals { get; set; }
        public DateTime? LastBackupAt { get; set; }
        public bool LastBackupOk { get; set; }
        public DateTime? LastUsbBackupAt { get; set; }
        public DateTime? SelfCheckAt { get; set; }
        public bool SelfCheckOk { get; set; }
        public string SelfCheckSummary { get; set; } = "";
        public decimal MySalesToday { get; set; }
        public int MyInvoicesToday { get; set; }
        public List<KeyValuePair<string, decimal>> DailySales { get; set; } = new List<KeyValuePair<string, decimal>>();
        public List<KeyValuePair<string, decimal>> PaymentSplit { get; set; } = new List<KeyValuePair<string, decimal>>();
        public List<List<string>> TopSellers { get; set; } = new List<List<string>>();
        public List<List<string>> SlowMovers { get; set; } = new List<List<string>>();
        public bool ShowFinance { get; set; }
    }

    public sealed class SettingItem
    {
        public string Key { get; set; } = "";
        public string Group { get; set; } = "";
        public string Label { get; set; } = "";
        /// <summary>text, int, money, percent, bool, choice, multiline, time</summary>
        public string Type { get; set; } = "text";
        public string Value { get; set; } = "";
        public List<string> Choices { get; set; } = new List<string>();
        public string Hint { get; set; } = "";
    }

    public sealed class AuditRow
    {
        public long Id { get; set; }
        public DateTime At { get; set; }
        public string User { get; set; } = "";
        public string Workstation { get; set; } = "";
        public string Action { get; set; } = "";
        public string Module { get; set; } = "";
        public string RecordRef { get; set; } = "";
        public string Before { get; set; } = "";
        public string After { get; set; } = "";
        public string Reason { get; set; } = "";
        public string Approver { get; set; } = "";
    }

    public sealed class BackupDto
    {
        public long Id { get; set; }
        public string Kind { get; set; } = "";
        public string FileName { get; set; } = "";
        public long SizeBytes { get; set; }
        public string Sha256 { get; set; } = "";
        public DateTime StartedAt { get; set; }
        public DateTime? FinishedAt { get; set; }
        public string Result { get; set; } = "";
        public string CheckResult { get; set; } = "";
        public string Message { get; set; } = "";
        public string? CopiedTo { get; set; }
    }

    public sealed class BackupCopiedRequest
    {
        public string Destination { get; set; } = "";
        public bool Verified { get; set; }
        public string Message { get; set; } = "";
    }

    public sealed class RestoreRequest
    {
        public long? BackupId { get; set; }
        public string? UploadId { get; set; }
        public string AdminPassword { get; set; } = "";
        public string ConfirmPhrase { get; set; } = "";
    }

    public sealed class SelfCheckItem
    {
        public string Check { get; set; } = "";
        public bool Ok { get; set; }
        public string Detail { get; set; } = "";
    }

    public sealed class SelfCheckResult
    {
        public DateTime At { get; set; }
        public bool Ok { get; set; }
        public List<SelfCheckItem> Items { get; set; } = new List<SelfCheckItem>();
    }
}
