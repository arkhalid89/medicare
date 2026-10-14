using System;
using System.Collections.Generic;

namespace MediCare.Contracts
{
    public static class StockStates
    {
        public const string Saleable = "SALEABLE";
        public const string Quarantine = "QUARANTINE";
    }

    public sealed class BatchRow
    {
        public long BatchId { get; set; }
        public long MedicineId { get; set; }
        public string Medicine { get; set; } = "";
        public string BatchNo { get; set; } = "";
        public DateTime Expiry { get; set; }
        public int DaysLeft { get; set; }
        public string State { get; set; } = "";
        public int Qty { get; set; }
        public string QtyText { get; set; } = "";
        public string Rack { get; set; } = "";
        public decimal CostValue { get; set; }
        public string Supplier { get; set; } = "";
        public int MinStockQty { get; set; }
    }

    public sealed class MovementRow
    {
        public DateTime At { get; set; }
        public string Type { get; set; } = "";
        public string DocNo { get; set; } = "";
        public string State { get; set; } = "";
        public int QtyDelta { get; set; }
        public string User { get; set; } = "";
    }

    public sealed class AdjustmentLineInput
    {
        public long BatchId { get; set; }
        public string State { get; set; } = StockStates.Saleable;
        public int PhysicalQty { get; set; }
    }

    public sealed class AdjustmentRequest
    {
        public string IdempotencyKey { get; set; } = "";
        public string Reason { get; set; } = "";
        public string Notes { get; set; } = "";
        public List<AdjustmentLineInput> Lines { get; set; } = new List<AdjustmentLineInput>();
        public string? ApprovalToken { get; set; }
    }

    public sealed class AdjustmentPreview
    {
        public decimal Value { get; set; }
        public decimal MaxPercent { get; set; }
        public bool NeedsApproval { get; set; }
    }

    public sealed class QuarantineRequest
    {
        public string IdempotencyKey { get; set; } = "";
        public long BatchId { get; set; }
        public int Qty { get; set; }
        public string Reason { get; set; } = "";
        /// <summary>MOVE (saleable to quarantine) or WRITE_OFF (quarantine to loss)</summary>
        public string Action { get; set; } = "MOVE";
        public string? ApprovalToken { get; set; }
    }

    public sealed class StockCountItem
    {
        public long Id { get; set; }
        public long BatchId { get; set; }
        public string Medicine { get; set; } = "";
        public string BatchNo { get; set; } = "";
        public string Rack { get; set; } = "";
        public int FrozenQty { get; set; }
        public int? CountedQty { get; set; }
        public int MovementsSince { get; set; }
    }

    public sealed class StockCountDto
    {
        public long Id { get; set; }
        public string No { get; set; } = "";
        public string Scope { get; set; } = "";
        public string Status { get; set; } = "";
        public DateTime FrozenAt { get; set; }
        public List<StockCountItem> Items { get; set; } = new List<StockCountItem>();
    }

    public sealed class StockCountCreate
    {
        public string RackPrefix { get; set; } = "";
        public string Category { get; set; } = "";
    }

    public sealed class StockCountSave
    {
        public List<StockCountItem> Items { get; set; } = new List<StockCountItem>();
    }

    // ---- accounts ----
    public sealed class CashAccountDto
    {
        public long Id { get; set; }
        public string Name { get; set; } = "";
        public string Kind { get; set; } = "CASH";
        public string AccountCode { get; set; } = "";
        public decimal Balance { get; set; }
        public bool EnforceBalance { get; set; }
        public bool IsActive { get; set; } = true;
        public override string ToString() => Name;
    }

    public sealed class CashEntryRequest
    {
        public string IdempotencyKey { get; set; } = "";
        public long CashAccountId { get; set; }
        /// <summary>OPENING_BALANCE, OWNER_CAPITAL, OWNER_DRAWINGS, OTHER_INCOME, PAID_OUT</summary>
        public string Category { get; set; } = "PAID_OUT";
        public decimal Amount { get; set; }
        public string Memo { get; set; } = "";
    }

    public sealed class TransferRequest
    {
        public string IdempotencyKey { get; set; } = "";
        public long FromAccountId { get; set; }
        public long ToAccountId { get; set; }
        public decimal Amount { get; set; }
        public string Reference { get; set; } = "";
    }

    public sealed class ExpenseRequest
    {
        public string IdempotencyKey { get; set; } = "";
        public string Category { get; set; } = "";
        public string Description { get; set; } = "";
        public decimal Amount { get; set; }
        public long CashAccountId { get; set; }
        public string Reference { get; set; } = "";
        public string? ApprovalToken { get; set; }
    }

    public sealed class ExpenseDto
    {
        public long Id { get; set; }
        public string No { get; set; } = "";
        public DateTime Date { get; set; }
        public string Category { get; set; } = "";
        public string Description { get; set; } = "";
        public decimal Amount { get; set; }
        public string Account { get; set; } = "";
        public string Reference { get; set; } = "";
        public string EnteredBy { get; set; } = "";
        public string ApprovedBy { get; set; } = "";
    }

    public sealed class ShiftDto
    {
        public long Id { get; set; }
        public string User { get; set; } = "";
        public string Workstation { get; set; } = "";
        public int CounterNo { get; set; }
        public DateTime OpenedAt { get; set; }
        public decimal Float { get; set; }
        public DateTime? ClosedAt { get; set; }
        public decimal Expected { get; set; }
        public decimal Counted { get; set; }
        public decimal Difference { get; set; }
        public string Reason { get; set; } = "";
        public string Status { get; set; } = "OPEN";
        public decimal CashSales { get; set; }
        public decimal Receipts { get; set; }
        public decimal Refunds { get; set; }
        public decimal PaidOuts { get; set; }
    }

    public sealed class OpenShiftRequest
    {
        public decimal Float { get; set; }
        public int CounterNo { get; set; }
    }

    public sealed class DenominationCount
    {
        public int Denomination { get; set; }
        public int Count { get; set; }
    }

    public sealed class CloseShiftRequest
    {
        public List<DenominationCount> Counts { get; set; } = new List<DenominationCount>();
        public string Reason { get; set; } = "";
        public string? ApprovalToken { get; set; }
    }

    public sealed class DayStatusDto
    {
        public string BusinessDate { get; set; } = "";
        public bool Closed { get; set; }
        public DateTime? ClosedAt { get; set; }
        public string? ClosedBy { get; set; }
        public List<ShiftDto> OpenShifts { get; set; } = new List<ShiftDto>();
    }
}
