using System.Text.Json;

namespace MediCare.HostMonitor;

/// <summary>
/// Shape of <c>host-status.json</c> as written by the host's JobsService (camelCase, System.Text.Json web defaults).
/// Every member is optional so an older or newer host never breaks the monitor.
/// Timestamps other than <see cref="UpdatedUtc"/> are host wall-clock times (Asia/Karachi), without an offset.
/// </summary>
public sealed class HostStatus
{
    public string? ServerVersion { get; set; }
    public int? SchemaVersion { get; set; }
    public string? MachineName { get; set; }
    public string? DbPath { get; set; }
    public long? DbSizeBytes { get; set; }
    public string? CertificateFingerprint { get; set; }
    public int? ActiveSessions { get; set; }
    public int? Port { get; set; }
    public bool? Https { get; set; }
    public DateTime? UpdatedUtc { get; set; }

    /// <summary>Not written by every host version; when absent the monitor asks the host's /health endpoint.</summary>
    public bool? Maintenance { get; set; }

    public LastBackupInfo? LastBackup { get; set; }
    public List<WorkstationInfo>? Workstations { get; set; }
}

public sealed class LastBackupInfo
{
    public DateTime? At { get; set; }
    public string? Result { get; set; }
    public string? Kind { get; set; }
}

public sealed class WorkstationInfo
{
    public string? Name { get; set; }
    public string? Os { get; set; }
    public string? ClientVersion { get; set; }
    public DateTime? LastSeenAt { get; set; }
    public string? LastUser { get; set; }
    public bool? IsActive { get; set; }
}

public enum StatusFileState { Ok, Missing, AccessDenied, Unreadable }

public sealed record StatusReadResult(StatusFileState State, HostStatus? Status, string? Error);

public static class HostStatusReader
{
    private static readonly JsonSerializerOptions Options = new(JsonSerializerDefaults.Web);

    public static StatusReadResult Read(string path)
    {
        try
        {
            if (!File.Exists(path))
                return new StatusReadResult(IsUnderProtectedFolder(path) ? StatusFileState.AccessDenied : StatusFileState.Missing, null, null);

            // The host replaces the file atomically; FileShare.Delete lets that replace succeed while we read.
            using var stream = new FileStream(path, FileMode.Open, FileAccess.Read, FileShare.ReadWrite | FileShare.Delete);
            var status = JsonSerializer.Deserialize<HostStatus>(stream, Options);
            return status == null
                ? new StatusReadResult(StatusFileState.Unreadable, null, "The status file is empty.")
                : new StatusReadResult(StatusFileState.Ok, status, null);
        }
        catch (UnauthorizedAccessException ex)
        {
            return new StatusReadResult(StatusFileState.AccessDenied, null, ex.Message);
        }
        catch (Exception ex) when (ex is IOException or JsonException)
        {
            return new StatusReadResult(StatusFileState.Unreadable, null, ex.Message);
        }
    }

    /// <summary>
    /// File.Exists returns false (instead of throwing) when the folder cannot be listed, so a missing file in an
    /// unreadable folder is reported as an access problem rather than as "host never ran".
    /// </summary>
    private static bool IsUnderProtectedFolder(string path)
    {
        var dir = Path.GetDirectoryName(path);
        if (string.IsNullOrEmpty(dir)) return false;
        try
        {
            Directory.GetFiles(dir, "host-status.json");
            return false;
        }
        catch (UnauthorizedAccessException)
        {
            return true;
        }
        catch (IOException)
        {
            return false;
        }
    }
}
