using System.IO;
using System.Text.Json;

namespace MediCare.Client.Infrastructure;

/// <summary>
/// Per-PC settings (host address, pinned certificate, counter, printers). Stored in
/// %ProgramData%\MediCarePharmacy\client.json so every Windows user on the PC shares them;
/// falls back to %LocalAppData% when ProgramData is not writable.
/// </summary>
public sealed class ClientConfig
{
    public string HostUrl { get; set; } = "";
    public string Fingerprint { get; set; } = "";
    public int CounterNo { get; set; } = 1;
    public string MachineId { get; set; } = "";
    public string WorkstationName { get; set; } = Environment.MachineName;
    public string LastUsername { get; set; } = "";
    public string ReceiptPrinter { get; set; } = "";
    public int ReceiptWidthMm { get; set; } = 80;
    public bool AutoPrintReceipt { get; set; } = true;
    public bool OpenCashDrawer { get; set; }
    public string A4Printer { get; set; } = "";
    public bool Compact { get; set; }
    public bool SoundOnScanError { get; set; } = true;

    public bool IsPaired => HostUrl.Length > 0 && Fingerprint.Length > 0 || HostUrl.StartsWith("http://", StringComparison.OrdinalIgnoreCase);

    private static readonly JsonSerializerOptions Opts = new JsonSerializerOptions { WriteIndented = true, PropertyNameCaseInsensitive = true };

    public static string SharedPath => Path.Combine(Environment.GetFolderPath(Environment.SpecialFolder.CommonApplicationData), "MediCarePharmacy", "client.json");
    public static string UserPath => Path.Combine(Environment.GetFolderPath(Environment.SpecialFolder.LocalApplicationData), "MediCarePharmacy", "client.json");
    public static string LogDir => Path.Combine(Environment.GetFolderPath(Environment.SpecialFolder.LocalApplicationData), "MediCarePharmacy", "logs");
    public static string DraftDir => Path.Combine(Environment.GetFolderPath(Environment.SpecialFolder.LocalApplicationData), "MediCarePharmacy", "drafts");

    public static ClientConfig Load()
    {
        foreach (var path in new[] { UserPath, SharedPath })
        {
            try
            {
                if (!File.Exists(path)) continue;
                var cfg = JsonSerializer.Deserialize<ClientConfig>(File.ReadAllText(path), Opts);
                if (cfg != null) return cfg.Normalise();
            }
            catch (Exception ex) { Log.Error("Reading " + path, ex); }
        }
        return new ClientConfig().Normalise();
    }

    private ClientConfig Normalise()
    {
        if (string.IsNullOrWhiteSpace(MachineId)) MachineId = Guid.NewGuid().ToString("N");
        if (string.IsNullOrWhiteSpace(WorkstationName)) WorkstationName = Environment.MachineName;
        if (CounterNo < 1) CounterNo = 1;
        if (ReceiptWidthMm != 58 && ReceiptWidthMm != 80) ReceiptWidthMm = 80;
        return this;
    }

    public void Save()
    {
        var json = JsonSerializer.Serialize(this, Opts);
        try
        {
            Directory.CreateDirectory(Path.GetDirectoryName(SharedPath)!);
            File.WriteAllText(SharedPath, json);
            if (File.Exists(UserPath)) File.Delete(UserPath);
        }
        catch (Exception)
        {
            Directory.CreateDirectory(Path.GetDirectoryName(UserPath)!);
            File.WriteAllText(UserPath, json);
        }
    }
}
