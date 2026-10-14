using System;
using System.Collections.Generic;

namespace MediCare.Contracts
{
    public sealed class SetupStatus
    {
        public bool NeedsSetup { get; set; }
        public string PharmacyName { get; set; } = "";
        public string ServerVersion { get; set; } = "";
        public int SchemaVersion { get; set; }
        public bool Maintenance { get; set; }
        public DateTime ServerTimeUtc { get; set; }
    }

    public sealed class SetupRequest
    {
        public string PharmacyName { get; set; } = "";
        public string Address { get; set; } = "";
        public string Phone { get; set; } = "";
        public string LicenseNo { get; set; } = "";
        public string AdminFullName { get; set; } = "";
        public string AdminUsername { get; set; } = "";
        public string AdminPassword { get; set; } = "";
        public string AdminPin { get; set; } = "";
    }

    public sealed class LoginRequest
    {
        public string Username { get; set; } = "";
        public string Password { get; set; } = "";
        public string MachineId { get; set; } = "";
        public string WorkstationName { get; set; } = "";
        public string Os { get; set; } = "";
        public string ClientVersion { get; set; } = "";
        public int CounterNo { get; set; }
    }

    public sealed class UserInfo
    {
        public long Id { get; set; }
        public string Username { get; set; } = "";
        public string FullName { get; set; } = "";
        public string RoleName { get; set; } = "";
    }

    public sealed class LoginResponse
    {
        public string Token { get; set; } = "";
        public UserInfo User { get; set; } = new UserInfo();
        public List<string> Permissions { get; set; } = new List<string>();
        public bool MustChangePassword { get; set; }
        public int SessionTimeoutMin { get; set; }
        public decimal MaxDiscountPercent { get; set; }
        public long WorkstationId { get; set; }
        public ClientSettings Settings { get; set; } = new ClientSettings();
    }

    /// <summary>Settings every client needs at runtime (pushed at login and on settings.changed).</summary>
    public sealed class ClientSettings
    {
        public string PharmacyName { get; set; } = "";
        public string Address { get; set; } = "";
        public string Phone { get; set; } = "";
        public string LicenseNo { get; set; } = "";
        public string Ntn { get; set; } = "";
        public bool TaxInclusive { get; set; }
        public int NearExpiryDays { get; set; } = 90;
        public decimal RefundApprovalAbove { get; set; }
        public List<string> ReceiptFooterUrdu { get; set; } = new List<string>();
        public string ReceiptFooterEnglish { get; set; } = "";
        public bool ReceiptShowBatch { get; set; }
        public string ReceiptUrduFont { get; set; } = "Noto Naskh Arabic";
        public List<string> PaymentMethods { get; set; } = new List<string>();
        public Dictionary<string, string> Shortcuts { get; set; } = new Dictionary<string, string>();
        public string BusinessDate { get; set; } = "";
        public bool DayClosed { get; set; }
    }

    public sealed class ChangePasswordRequest
    {
        public string CurrentPassword { get; set; } = "";
        public string NewPassword { get; set; } = "";
        public string? NewPin { get; set; }
    }

    public sealed class UnlockRequest
    {
        public string Password { get; set; } = "";
    }

    public sealed class UserDto
    {
        public long Id { get; set; }
        public string Username { get; set; } = "";
        public string FullName { get; set; } = "";
        public long RoleId { get; set; }
        public string RoleName { get; set; } = "";
        public bool IsActive { get; set; } = true;
        public DateTime? LastLoginAt { get; set; }
        public string? LastWorkstation { get; set; }
        public int SessionTimeoutMin { get; set; } = 10;
        public bool Locked { get; set; }
        public List<string> GrantedPermissions { get; set; } = new List<string>();
        public List<string> RevokedPermissions { get; set; } = new List<string>();
    }

    public sealed class UserSaveRequest
    {
        public string Username { get; set; } = "";
        public string FullName { get; set; } = "";
        public long RoleId { get; set; }
        public bool IsActive { get; set; } = true;
        public int SessionTimeoutMin { get; set; } = 10;
        public string? Password { get; set; }
        public string? Pin { get; set; }
        public List<string> GrantedPermissions { get; set; } = new List<string>();
        public List<string> RevokedPermissions { get; set; } = new List<string>();
    }

    public sealed class ResetPasswordRequest
    {
        public string NewPassword { get; set; } = "";
        public string? NewPin { get; set; }
    }

    public sealed class RoleDto
    {
        public long Id { get; set; }
        public string Name { get; set; } = "";
        public decimal MaxDiscountPercent { get; set; }
        public List<string> Permissions { get; set; } = new List<string>();
        public override string ToString() => Name;
    }

    public sealed class WorkstationDto
    {
        public long Id { get; set; }
        public string Name { get; set; } = "";
        public string Os { get; set; } = "";
        public string ClientVersion { get; set; } = "";
        public bool IsActive { get; set; }
        public DateTime? LastSeenAt { get; set; }
        public string? LastUser { get; set; }
        public int CounterNo { get; set; }
    }

    public sealed class HostStatusDto
    {
        public string ServerVersion { get; set; } = "";
        public int SchemaVersion { get; set; }
        public string MachineName { get; set; } = "";
        public string DbPath { get; set; } = "";
        public long DbSizeBytes { get; set; }
        public DateTime StartedAtUtc { get; set; }
        public bool Maintenance { get; set; }
        public string CertificateFingerprint { get; set; } = "";
        public List<WorkstationDto> Workstations { get; set; } = new List<WorkstationDto>();
        public int ActiveSessions { get; set; }
    }

    public sealed class FingerprintDto
    {
        public string Sha256 { get; set; } = "";
        public string HostName { get; set; } = "";
    }
}
