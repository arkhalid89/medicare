using System.Net;
using System.Net.Sockets;
using System.Security.Cryptography;
using System.Security.Cryptography.X509Certificates;
using Dapper;
using MediCare.Contracts;
using Microsoft.Data.Sqlite;
using Serilog.Core;
using Serilog.Events;

namespace MediCare.Host.Infrastructure;

public sealed class ErrorMiddleware(RequestDelegate next, ILogger<ErrorMiddleware> log)
{
    public async Task Invoke(HttpContext http)
    {
        try
        {
            await next(http);
        }
        catch (AppException ex)
        {
            await Write(http, ex.Status, new ApiError { Code = ex.Code, Message = ex.Message, Details = ex.Details });
        }
        catch (ArgumentException ex)
        {
            await Write(http, 400, new ApiError { Code = ErrorCodes.Validation, Message = ex.Message });
        }
        catch (BadHttpRequestException ex)
        {
            await Write(http, 400, new ApiError { Code = ErrorCodes.Validation, Message = "The request could not be read: " + ex.Message });
        }
        catch (SqliteException ex) when (ex.SqliteErrorCode == 19)
        {
            log.LogWarning(ex, "Constraint violation on {Path}", http.Request.Path);
            var msg = ex.Message.Contains("UNIQUE", StringComparison.OrdinalIgnoreCase) ? "This record already exists (duplicate value)."
                : ex.Message.Contains("immutable", StringComparison.OrdinalIgnoreCase) || ex.Message.Contains("append-only", StringComparison.OrdinalIgnoreCase) ? "Posted records cannot be changed. Use a return or reversal."
                : ex.Message.Contains("CHECK", StringComparison.OrdinalIgnoreCase) ? "A value is outside the allowed range." : "The change conflicts with existing data.";
            await Write(http, 409, new ApiError { Code = ErrorCodes.Conflict, Message = msg });
        }
        catch (OperationCanceledException) when (http.RequestAborted.IsCancellationRequested)
        {
        }
        catch (Exception ex)
        {
            log.LogError(ex, "Unhandled error on {Method} {Path}", http.Request.Method, http.Request.Path);
            await Write(http, 500, new ApiError { Code = ErrorCodes.Internal, Message = "The host hit an unexpected error. The action was not saved. Details are in the host log." });
        }
    }

    private static async Task Write(HttpContext http, int status, ApiError error)
    {
        if (http.Response.HasStarted) return;
        http.Response.Clear();
        http.Response.StatusCode = status;
        http.Response.ContentType = "application/json; charset=utf-8";
        await http.Response.WriteAsync(Json.Serialize(error));
    }
}

/// <summary>Removes secrets from log properties (NFR-017).</summary>
public sealed class RedactionEnricher : ILogEventEnricher
{
    private static readonly string[] Sensitive = { "password", "pin", "token", "authorization", "secret" };
    public void Enrich(LogEvent logEvent, ILogEventPropertyFactory propertyFactory)
    {
        foreach (var key in logEvent.Properties.Keys.ToList())
            if (Sensitive.Any(s => key.Contains(s, StringComparison.OrdinalIgnoreCase)))
                logEvent.AddOrUpdateProperty(propertyFactory.CreateProperty(key, "***"));
    }
}

public sealed record CertificateInfo(X509Certificate2? Certificate, string Fingerprint);

/// <summary>Self-signed host certificate generated on first run; clients pin its SHA-256 fingerprint (ADR-003).</summary>
public static class Certificates
{
    private static CertificateInfo? _cached;

    public static CertificateInfo LoadOrCreate(string dataDir)
    {
        if (_cached != null) return _cached;
        var dir = Path.Combine(dataDir, "certs");
        Directory.CreateDirectory(dir);
        var pfx = Path.Combine(dir, "host.pfx");
        var keyFile = Path.Combine(dir, "host.key");
        if (!File.Exists(pfx) || !File.Exists(keyFile))
        {
            var password = Hashing.NewToken();
            using var rsa = RSA.Create(2048);
            var req = new CertificateRequest($"CN=MediCare Host {Environment.MachineName}", rsa, HashAlgorithmName.SHA256, RSASignaturePadding.Pkcs1);
            var san = new SubjectAlternativeNameBuilder();
            san.AddDnsName(Environment.MachineName);
            san.AddDnsName("localhost");
            san.AddIpAddress(IPAddress.Loopback);
            try
            {
                foreach (var ip in Dns.GetHostAddresses(Dns.GetHostName()).Where(a => a.AddressFamily == AddressFamily.InterNetwork)) san.AddIpAddress(ip);
            }
            catch { }
            req.CertificateExtensions.Add(san.Build());
            req.CertificateExtensions.Add(new X509KeyUsageExtension(X509KeyUsageFlags.DigitalSignature | X509KeyUsageFlags.KeyEncipherment, true));
            req.CertificateExtensions.Add(new X509EnhancedKeyUsageExtension(new OidCollection { new Oid("1.3.6.1.5.5.7.3.1") }, false));
            using var cert = req.CreateSelfSigned(DateTimeOffset.UtcNow.AddDays(-1), DateTimeOffset.UtcNow.AddYears(10));
            File.WriteAllBytes(pfx, cert.Export(X509ContentType.Pfx, password));
            File.WriteAllText(keyFile, password);
        }
        var loaded = X509CertificateLoader.LoadPkcs12FromFile(pfx, File.ReadAllText(keyFile).Trim(),
            OperatingSystem.IsWindows() ? X509KeyStorageFlags.MachineKeySet | X509KeyStorageFlags.PersistKeySet : X509KeyStorageFlags.DefaultKeySet);
        _cached = new CertificateInfo(loaded, Fingerprint(loaded));
        return _cached;
    }

    public static string Fingerprint(X509Certificate2 cert) =>
        string.Join(":", SHA256.HashData(cert.RawData).Select(b => b.ToString("X2")));
}

/// <summary>Seeds role permissions on first run and keeps the Administrator role complete after upgrades.</summary>
public static class Seeder
{
    public static readonly Dictionary<string, string[]> DefaultRolePermissions = new()
    {
        ["Pharmacist / Manager"] = Perm.Catalog.Select(p => p.Key).Except(new[] { Perm.UsersManage, Perm.SettingsManage, Perm.BackupRestore }).ToArray(),
        ["Cashier"] = new[] { Perm.DashboardView, Perm.SalesPos, Perm.ReturnPost, Perm.MedicinesView, Perm.StockView, Perm.CustomersView, Perm.CustomersEdit, Perm.ReceiptsPost, Perm.ShiftClose, Perm.ReportsSales },
        ["Accountant"] = new[] { Perm.DashboardView, Perm.SalesViewAll, Perm.CustomersView, Perm.SuppliersView, Perm.ReceiptsPost, Perm.SupplierPaymentsPost, Perm.CashView, Perm.CashPost,
            Perm.ExpensesPost, Perm.ReportsFinance, Perm.ReportsSales, Perm.ReportsExport, Perm.AuditView, Perm.ApprovalsView, Perm.CostView, Perm.PurchasesView, Perm.MedicinesView },
        ["Stock operator"] = new[] { Perm.DashboardView, Perm.MedicinesView, Perm.StockView, Perm.StockAdjust, Perm.PurchasesView, Perm.PurchasesPost, Perm.PurchasesReturn, Perm.SuppliersView, Perm.ReportsStock },
    };

    public static void Run(Db db)
    {
        db.Write((c, tx) =>
        {
            var adminRole = c.ExecuteScalar<long>("SELECT id FROM roles WHERE name='Administrator'", transaction: tx);
            foreach (var p in Perm.Catalog)
                c.Execute("INSERT OR IGNORE INTO role_permissions(role_id, permission) VALUES(@r,@p)", new { r = adminRole, p = p.Key }, tx);
            foreach (var kv in DefaultRolePermissions)
            {
                var roleId = c.ExecuteScalar<long?>("SELECT id FROM roles WHERE name=@n", new { n = kv.Key }, tx);
                if (roleId == null) continue;
                var has = c.ExecuteScalar<long>("SELECT COUNT(*) FROM role_permissions WHERE role_id=@r", new { r = roleId }, tx);
                if (has > 0) continue;
                foreach (var p in kv.Value) c.Execute("INSERT OR IGNORE INTO role_permissions(role_id, permission) VALUES(@r,@p)", new { r = roleId, p }, tx);
            }
        });
    }
}
