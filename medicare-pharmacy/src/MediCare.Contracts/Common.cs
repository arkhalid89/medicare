using System;
using System.Collections.Generic;

namespace MediCare.Contracts
{
    /// <summary>Error body returned by the host for every non-2xx response.</summary>
    public sealed class ApiError
    {
        public string Code { get; set; } = "";
        public string Message { get; set; } = "";
        public Dictionary<string, string>? Details { get; set; }
    }

    public static class ErrorCodes
    {
        public const string Validation = "VALIDATION";
        public const string NotFound = "NOT_FOUND";
        public const string Conflict = "CONFLICT";
        public const string Unauthorized = "UNAUTHORIZED";
        public const string SessionExpired = "SESSION_EXPIRED";
        public const string PermissionDenied = "PERMISSION_DENIED";
        public const string AccountLocked = "ACCOUNT_LOCKED";
        public const string AccountInactive = "ACCOUNT_INACTIVE";
        public const string BadCredentials = "BAD_CREDENTIALS";
        public const string MustChangePassword = "MUST_CHANGE_PASSWORD";
        public const string InsufficientStock = "INSUFFICIENT_STOCK";
        public const string BatchNotSaleable = "BATCH_NOT_SALEABLE";
        public const string StockChanged = "STOCK_CHANGED";
        public const string ApprovalRequired = "APPROVAL_REQUIRED";
        public const string ApprovalInvalid = "APPROVAL_INVALID";
        public const string DayClosed = "DAY_CLOSED";
        public const string IdempotencyMismatch = "IDEMPOTENCY_MISMATCH";
        public const string Maintenance = "MAINTENANCE";
        public const string InvalidGtin = "INVALID_GTIN";
        public const string InvalidExpiry = "INVALID_EXPIRY";
        public const string NotGs1 = "NOT_GS1";
        public const string SetupRequired = "SETUP_REQUIRED";
        public const string ShiftRequired = "SHIFT_REQUIRED";
        public const string Internal = "INTERNAL";
    }

    public sealed class IdName
    {
        public long Id { get; set; }
        public string Name { get; set; } = "";
        public override string ToString() => Name;
    }

    public sealed class OkResult
    {
        public bool Ok { get; set; } = true;
        public string? Message { get; set; }
        public long? Id { get; set; }
        public string? No { get; set; }
    }

    public sealed class EventDto
    {
        public long Seq { get; set; }
        public string Type { get; set; } = "";
        public string? Payload { get; set; }
        public DateTime At { get; set; }
    }

    public sealed class EventsResponse
    {
        public long LastSeq { get; set; }
        public List<EventDto> Events { get; set; } = new List<EventDto>();
    }

    public static class EventTypes
    {
        public const string ApprovalRequested = "approval.requested";
        public const string ApprovalDecided = "approval.decided";
        public const string SettingsChanged = "settings.changed";
        public const string Maintenance = "maintenance";
        public const string Restored = "restored";
        public const string SessionRevoked = "session.revoked";
    }

    public static class PaymentMethods
    {
        public const string Cash = "CASH";
        public const string Card = "CARD";
        public const string Wallet = "WALLET";
        public const string Bank = "BANK";
        public const string Credit = "CREDIT";
        public static readonly string[] All = { Cash, Card, Wallet, Bank, Credit };
    }

    public static class ApprovalActions
    {
        public const string Discount = "DISCOUNT";
        public const string Refund = "REFUND";
        public const string CreditLimit = "CREDIT_LIMIT";
        public const string Adjustment = "ADJUSTMENT";
        public const string WriteOff = "WRITE_OFF";
        public const string Reversal = "REVERSAL";
        public const string CashDifference = "CASH_DIFF";
        public const string Expense = "EXPENSE";
        public const string AfterDayClose = "AFTER_DAY_CLOSE";

        public static string Label(string action)
        {
            switch (action)
            {
                case Discount: return "Discount above limit";
                case Refund: return "Refund above threshold";
                case CreditLimit: return "Credit above limit";
                case Adjustment: return "Stock adjustment";
                case WriteOff: return "Write-off";
                case Reversal: return "Reversal";
                case CashDifference: return "Cash difference";
                case Expense: return "Expense above threshold";
                case AfterDayClose: return "Posting after day close";
                default: return action;
            }
        }
    }
}
