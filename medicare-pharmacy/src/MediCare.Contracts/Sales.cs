using System;
using System.Collections.Generic;

namespace MediCare.Contracts
{
    public sealed class SaleLineInput
    {
        public long MedicineId { get; set; }
        public long PackId { get; set; }
        /// <summary>Quantity in packs of PackId.</summary>
        public int Qty { get; set; }
        /// <summary>Forced batch (2D scan or manual pick). Null = FEFO.</summary>
        public long? BatchId { get; set; }
        public string Pick { get; set; } = "FEFO";
        public decimal DiscountAmount { get; set; }
    }

    public sealed class PaymentInput
    {
        public string Method { get; set; } = PaymentMethods.Cash;
        public decimal Amount { get; set; }
        public decimal? Tendered { get; set; }
        public long? CashAccountId { get; set; }
        public string? Reference { get; set; }
    }

    public sealed class PrescriptionInput
    {
        public int LineIndex { get; set; }
        public string Prescriber { get; set; } = "";
        public string PrescriberReg { get; set; } = "";
        public string Patient { get; set; } = "";
        public string RxNo { get; set; } = "";
    }

    public sealed class SaleRequest
    {
        public string IdempotencyKey { get; set; } = "";
        public long? CustomerId { get; set; }
        public int CounterNo { get; set; }
        public List<SaleLineInput> Lines { get; set; } = new List<SaleLineInput>();
        public decimal InvoiceDiscount { get; set; }
        public List<PaymentInput> Payments { get; set; } = new List<PaymentInput>();
        public List<PrescriptionInput> Prescriptions { get; set; } = new List<PrescriptionInput>();
        public List<string> ApprovalTokens { get; set; } = new List<string>();
        public long? HoldId { get; set; }
    }

    public sealed class ReceiptLine
    {
        public string Name { get; set; } = "";
        public string UrduName { get; set; } = "";
        public string QtyText { get; set; } = "";
        public decimal Rate { get; set; }
        public decimal Discount { get; set; }
        public decimal Amount { get; set; }
        public string BatchNo { get; set; } = "";
        public string Expiry { get; set; } = "";
    }

    public sealed class ReceiptDto
    {
        public string Title { get; set; } = "Sale Invoice";
        public string PharmacyName { get; set; } = "";
        public string Address { get; set; } = "";
        public string Phone { get; set; } = "";
        public string LicenseNo { get; set; } = "";
        public string Ntn { get; set; } = "";
        public string DocNo { get; set; } = "";
        public DateTime At { get; set; }
        public string Cashier { get; set; } = "";
        public int CounterNo { get; set; }
        public string Customer { get; set; } = "";
        public List<ReceiptLine> Lines { get; set; } = new List<ReceiptLine>();
        public decimal Gross { get; set; }
        public decimal Discount { get; set; }
        public decimal Tax { get; set; }
        public decimal Net { get; set; }
        public List<KeyValuePair<string, decimal>> Payments { get; set; } = new List<KeyValuePair<string, decimal>>();
        public decimal Tendered { get; set; }
        public decimal Change { get; set; }
        public decimal CustomerBalance { get; set; }
        public bool ShowBatch { get; set; }
        public List<string> FooterUrdu { get; set; } = new List<string>();
        public string FooterEnglish { get; set; } = "";
        public bool IsDuplicate { get; set; }
        public string? Note { get; set; }
    }

    public sealed class SaleResult
    {
        public long Id { get; set; }
        public string No { get; set; } = "";
        public decimal Net { get; set; }
        public decimal Change { get; set; }
        public ReceiptDto Receipt { get; set; } = new ReceiptDto();
    }

    public sealed class SaleSummary
    {
        public long Id { get; set; }
        public string No { get; set; } = "";
        public DateTime At { get; set; }
        public int CounterNo { get; set; }
        public string Cashier { get; set; } = "";
        public string Customer { get; set; } = "";
        public string Payment { get; set; } = "";
        public decimal Net { get; set; }
        public decimal Outstanding { get; set; }
        public string Status { get; set; } = "";
        public bool HasReturns { get; set; }
    }

    public sealed class SaleLineDetail
    {
        public long Id { get; set; }
        public int LineNo { get; set; }
        public long MedicineId { get; set; }
        public string Medicine { get; set; } = "";
        public long BatchId { get; set; }
        public string BatchNo { get; set; } = "";
        public DateTime Expiry { get; set; }
        public string PackName { get; set; } = "";
        public int PackBaseQty { get; set; }
        public int BaseQty { get; set; }
        public int ReturnedBaseQty { get; set; }
        public string QtyText { get; set; } = "";
        public decimal Rate { get; set; }
        public decimal Discount { get; set; }
        public decimal Tax { get; set; }
        public decimal Net { get; set; }
        public string Pick { get; set; } = "";
    }

    public sealed class SaleDetail
    {
        public long Id { get; set; }
        public string No { get; set; } = "";
        public DateTime At { get; set; }
        public int CounterNo { get; set; }
        public string Cashier { get; set; } = "";
        public long CustomerId { get; set; }
        public string Customer { get; set; } = "";
        public decimal Gross { get; set; }
        public decimal Discount { get; set; }
        public decimal Tax { get; set; }
        public decimal Net { get; set; }
        public decimal Credit { get; set; }
        public decimal Outstanding { get; set; }
        public string Status { get; set; } = "";
        public string? ReversedBy { get; set; }
        public List<SaleLineDetail> Lines { get; set; } = new List<SaleLineDetail>();
        public List<PaymentInput> Payments { get; set; } = new List<PaymentInput>();
    }

    public sealed class ReverseRequest
    {
        public string Reason { get; set; } = "";
        public string ApprovalToken { get; set; } = "";
        public string IdempotencyKey { get; set; } = "";
    }

    public sealed class HoldDto
    {
        public long Id { get; set; }
        public int CounterNo { get; set; }
        public string User { get; set; } = "";
        public string Customer { get; set; } = "";
        public int Lines { get; set; }
        public decimal Amount { get; set; }
        public string Note { get; set; } = "";
        public DateTime CreatedAt { get; set; }
        public string PayloadJson { get; set; } = "";
    }

    public sealed class HoldSaveRequest
    {
        public int CounterNo { get; set; }
        public long? CustomerId { get; set; }
        public string CustomerName { get; set; } = "";
        public string Note { get; set; } = "";
        public int Lines { get; set; }
        public decimal Amount { get; set; }
        public string PayloadJson { get; set; } = "";
    }

    // ---- returns ----
    public sealed class SalesReturnLineInput
    {
        public long SaleItemId { get; set; }
        public int BaseQty { get; set; }
        /// <summary>RESTOCK or QUARANTINE</summary>
        public string Outcome { get; set; } = "RESTOCK";
        public string Reason { get; set; } = "";
    }

    public sealed class SalesReturnRequest
    {
        public string IdempotencyKey { get; set; } = "";
        public long SaleId { get; set; }
        public List<SalesReturnLineInput> Lines { get; set; } = new List<SalesReturnLineInput>();
        /// <summary>CASH, BANK or CREDIT_NOTE</summary>
        public string RefundMethod { get; set; } = "CASH";
        public long? CashAccountId { get; set; }
        public string? ApprovalToken { get; set; }
    }

    public sealed class SalesReturnResult
    {
        public long Id { get; set; }
        public string No { get; set; } = "";
        public decimal Refund { get; set; }
        public ReceiptDto Receipt { get; set; } = new ReceiptDto();
    }

    // ---- approvals ----
    public sealed class InlineApprovalRequest
    {
        public string Action { get; set; } = "";
        public string ContextKey { get; set; } = "";
        public decimal Value { get; set; }
        public string ApproverUsername { get; set; } = "";
        public string Pin { get; set; } = "";
        public string Reason { get; set; } = "";
    }

    public sealed class ApprovalTokenResponse
    {
        public string Token { get; set; } = "";
        public string ApproverName { get; set; } = "";
        public DateTime ExpiresAt { get; set; }
    }

    public sealed class DeferredApprovalRequest
    {
        public string Action { get; set; } = "";
        public string ContextKey { get; set; } = "";
        public decimal Value { get; set; }
        public string Summary { get; set; } = "";
        public string Reason { get; set; } = "";
    }

    public sealed class ApprovalDto
    {
        public long Id { get; set; }
        public string Action { get; set; } = "";
        public string ActionLabel { get; set; } = "";
        public string ContextKey { get; set; } = "";
        public decimal Value { get; set; }
        public string Summary { get; set; } = "";
        public string Requester { get; set; } = "";
        public string Workstation { get; set; } = "";
        public string Status { get; set; } = "";
        public DateTime CreatedAt { get; set; }
        public string? DecidedBy { get; set; }
        public string Reason { get; set; } = "";
        public string? Token { get; set; }
    }

    public sealed class DecideApprovalRequest
    {
        public bool Approve { get; set; }
        public string Pin { get; set; } = "";
        public string Reason { get; set; } = "";
    }
}
