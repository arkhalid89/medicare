using System;
using System.Collections.Generic;

namespace MediCare.Contracts
{
    public sealed class PurchaseLineDto
    {
        public long Id { get; set; }
        public long MedicineId { get; set; }
        public string MedicineName { get; set; } = "";
        public long PackId { get; set; }
        public string PackName { get; set; } = "";
        public int PackBaseQty { get; set; } = 1;
        public string BatchNo { get; set; } = "";
        public DateTime? Expiry { get; set; }
        public DateTime? Mfg { get; set; }
        public int Qty { get; set; }
        public int FreeQty { get; set; }
        public decimal Rate { get; set; }
        public decimal DiscountPercent { get; set; }
        public decimal TaxPercent { get; set; }
        public decimal Mrp { get; set; }
        public decimal SalePrice { get; set; }
        public string Rack { get; set; } = "";
        public string CaptureMethod { get; set; } = "SEARCH";
        public decimal Net { get; set; }
        public decimal UnitCost { get; set; }
        public long? BatchId { get; set; }
        public int ReturnedBaseQty { get; set; }
    }

    public sealed class PurchaseDto
    {
        public long Id { get; set; }
        public string? No { get; set; }
        public string Status { get; set; } = "DRAFT";
        public long SupplierId { get; set; }
        public string SupplierName { get; set; } = "";
        public string SupplierInvNo { get; set; } = "";
        public DateTime InvDate { get; set; } = DateTime.Today;
        public DateTime? DueDate { get; set; }
        public bool IsOpeningStock { get; set; }
        public string Notes { get; set; } = "";
        public decimal Charges { get; set; }
        public decimal PaidNow { get; set; }
        public long? PaidFromAccountId { get; set; }
        public string PaidReference { get; set; } = "";
        public decimal Gross { get; set; }
        public decimal Discount { get; set; }
        public decimal Tax { get; set; }
        public decimal Net { get; set; }
        public decimal Paid { get; set; }
        public decimal Returned { get; set; }
        public decimal Outstanding { get; set; }
        public DateTime? PostedAt { get; set; }
        public string? PostedBy { get; set; }
        public List<PurchaseLineDto> Lines { get; set; } = new List<PurchaseLineDto>();
    }

    public sealed class PurchaseSummary
    {
        public long Id { get; set; }
        public string? No { get; set; }
        public string Status { get; set; } = "";
        public string Supplier { get; set; } = "";
        public string SupplierInvNo { get; set; } = "";
        public DateTime InvDate { get; set; }
        public DateTime? DueDate { get; set; }
        public decimal Net { get; set; }
        public decimal Outstanding { get; set; }
        public int Lines { get; set; }
    }

    public sealed class PostRequest
    {
        public string IdempotencyKey { get; set; } = "";
        public List<string> ApprovalTokens { get; set; } = new List<string>();
    }

    public sealed class PurchaseReturnLineInput
    {
        public long PurchaseItemId { get; set; }
        public int BaseQty { get; set; }
        /// <summary>SALEABLE or QUARANTINE: which stock state the units leave from.</summary>
        public string FromState { get; set; } = "SALEABLE";
        public string Reason { get; set; } = "";
    }

    public sealed class PurchaseReturnRequest
    {
        public string IdempotencyKey { get; set; } = "";
        public long PurchaseId { get; set; }
        public string CreditNoteRef { get; set; } = "";
        public List<PurchaseReturnLineInput> Lines { get; set; } = new List<PurchaseReturnLineInput>();
    }

    public sealed class SupplierDto
    {
        public long Id { get; set; }
        public string Code { get; set; } = "";
        public string Name { get; set; } = "";
        public string Contact { get; set; } = "";
        public string Mobile { get; set; } = "";
        public string Address { get; set; } = "";
        public string TaxNo { get; set; } = "";
        public int TermsDays { get; set; } = 30;
        public bool IsActive { get; set; } = true;
        public string Notes { get; set; } = "";
        public decimal OpeningBalance { get; set; }
        public bool OpeningPosted { get; set; }
        public decimal Balance { get; set; }
        public override string ToString() => Name;
    }

    public sealed class CustomerDto
    {
        public long Id { get; set; }
        public string Code { get; set; } = "";
        public string Name { get; set; } = "";
        public string Mobile { get; set; } = "";
        public string Address { get; set; } = "";
        /// <summary>WALKIN, REGULAR or CREDIT</summary>
        public string Type { get; set; } = "REGULAR";
        public decimal CreditLimit { get; set; }
        public bool IsActive { get; set; } = true;
        public decimal OpeningBalance { get; set; }
        public bool OpeningPosted { get; set; }
        public decimal Balance { get; set; }
        public bool IsWalkIn => Type == "WALKIN";
        public override string ToString() => Mobile.Length > 0 ? $"{Name} ({Mobile})" : Name;
    }

    public sealed class OpeningBalanceRequest
    {
        public decimal Amount { get; set; }
    }

    public sealed class LedgerLine
    {
        public DateTime At { get; set; }
        public string DocNo { get; set; } = "";
        public string Details { get; set; } = "";
        public decimal Debit { get; set; }
        public decimal Credit { get; set; }
        public decimal Balance { get; set; }
    }

    public sealed class LedgerResult
    {
        public string Title { get; set; } = "";
        public DateTime From { get; set; }
        public DateTime To { get; set; }
        public decimal Opening { get; set; }
        public decimal Closing { get; set; }
        public string BalanceSide { get; set; } = "Dr";
        public List<LedgerLine> Lines { get; set; } = new List<LedgerLine>();
    }

    public sealed class OutstandingDoc
    {
        public long Id { get; set; }
        public string No { get; set; } = "";
        public DateTime Date { get; set; }
        public DateTime? DueDate { get; set; }
        public decimal Total { get; set; }
        public decimal Outstanding { get; set; }
    }

    public sealed class AllocationInput
    {
        public long DocId { get; set; }
        public decimal Amount { get; set; }
    }

    public sealed class ReceiptRequest
    {
        public string IdempotencyKey { get; set; } = "";
        public long CustomerId { get; set; }
        public decimal Amount { get; set; }
        public string Method { get; set; } = PaymentMethods.Cash;
        public long? CashAccountId { get; set; }
        public string Reference { get; set; } = "";
        public string Notes { get; set; } = "";
        public List<AllocationInput> Allocations { get; set; } = new List<AllocationInput>();
    }

    public sealed class SupplierPaymentRequest
    {
        public string IdempotencyKey { get; set; } = "";
        public long SupplierId { get; set; }
        public decimal Amount { get; set; }
        public long CashAccountId { get; set; }
        public string Reference { get; set; } = "";
        public string Notes { get; set; } = "";
        public List<AllocationInput> Allocations { get; set; } = new List<AllocationInput>();
    }

    public sealed class SupplierRefundRequest
    {
        public string IdempotencyKey { get; set; } = "";
        public long SupplierId { get; set; }
        public decimal Amount { get; set; }
        public long CashAccountId { get; set; }
        public string Reference { get; set; } = "";
    }

    public sealed class VoucherResult
    {
        public long Id { get; set; }
        public string No { get; set; } = "";
        public decimal Amount { get; set; }
        public ReceiptDto Print { get; set; } = new ReceiptDto();
    }
}
