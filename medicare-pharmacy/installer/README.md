# MediCare Pharmacy installers

Inno Setup scripts and helper scripts for the host PC and the counter PCs (ADR-016, slice S24).

| File | Purpose |
|---|---|
| `MediCareHost.iss` | Host installer: Windows service, Host Monitor, firewall rule, fonts, data folder ACL |
| `MediCareClient.iss` | Counter PC installer, compiled twice: `/DTarget=win10` and `/DTarget=win7` |
| `fonts.iss` | Font list shared by both installers (`#include`) |
| `fonts/` | Put the OFL font files here before a release build (see [fonts/README.md](fonts/README.md)) |
| `scripts/host-service.ps1` | Register, start, health-check, upgrade, roll back and remove the host service |
| `scripts/configure-firewall.ps1` | Inbound rule for the host port, local subnet only, Private and Domain networks |
| `scripts/enable-tls12-win7.ps1`, `.reg` | Turn on TLS 1.2 on a Windows 7 SP1 counter PC by hand |

## Building

Requirements on the build PC: Windows 10/11, .NET 10 SDK, and [Inno Setup 6.5 or later](https://jrsoftware.org/isinfo.php) (ADR-016; the scripts use the `x64compatible` architecture identifier, which needs 6.3 or later).

```powershell
cd medicare-pharmacy
.\build.ps1                 # tests, publish, installers
.\build.ps1 -SkipTests      # faster local packaging
.\build.ps1 -SkipInstallers # only the published folders
```

`build.ps1` publishes into `artifacts\host`, `artifacts\hostmonitor`, `artifacts\client-win10` and `artifacts\client-win7` (the paths the `.iss` scripts read), then runs `ISCC.exe` and writes:

```
artifacts\installers\MediCarePharmacy-Host-Setup-<version>.exe
artifacts\installers\MediCarePharmacy-Client-win10-Setup-<version>.exe
artifacts\installers\MediCarePharmacy-Client-win7-Setup-<version>.exe
```

The version comes from `<Version>` in `Directory.Build.props`. To compile one installer by hand after publishing:

```powershell
& "${env:ProgramFiles(x86)}\Inno Setup 6\ISCC.exe" /DAppVersion=1.0.0 installer\MediCareHost.iss
& "${env:ProgramFiles(x86)}\Inno Setup 6\ISCC.exe" /DTarget=win7 installer\MediCareClient.iss
```

### Building on Linux (no Windows PC)

`build.sh` does the same steps on Linux: .NET publishes the Windows builds directly (including the net48 client, with version and icon resources), and Inno Setup's compiler runs under Wine. One-time setup and usage are at the top of `build.sh`:

```bash
sudo dpkg --add-architecture i386 && sudo apt-get update && sudo apt-get install wine64 wine32:i386 xvfb
WINEPREFIX=~/.wine-inno wine innosetup-6.5.4.exe /VERYSILENT /SUPPRESSMSGBOXES /DIR='C:\InnoSetup6'
./build.sh                  # tests, publish, installers
./build.sh --skip-tests
```

Inno Setup itself is 32-bit, so Wine needs its 32-bit part (`wine32:i386`). The installers are byte-for-byte ordinary Inno Setup output; Wine is only the compiler's runtime. Installing them still needs Windows (the host installer registers a Windows service and firewall rule with PowerShell).

Code signing: sign `MediCare.Host.exe`, `MediCare.HostMonitor.exe`, `MediCarePharmacy.exe` and the setup files with the owner's certificate before release (avoids SmartScreen warnings, ADR-016). Add a `SignTool=` line to the `[Setup]` sections once the certificate is available.

## Installing a shop

**Install the host first, then the counters.**

1. **Host PC** (Windows 10 22H2+ or 11, x64; on a UPS): run `MediCarePharmacy-Host-Setup-<version>.exe` as an administrator.
   - Installs to `C:\Program Files\MediCare Pharmacy\Host\` (`Service\`, `Monitor\`, `Scripts\`).
   - Registers the service **MediCarePharmacyHost** ("MediCare Pharmacy Host"): Automatic (Delayed Start), restarts after 5 s, 15 s and 60 s if it fails, and runs as the virtual account `NT SERVICE\MediCarePharmacyHost`.
   - Creates `C:\ProgramData\MediCarePharmacy\Data\`, readable only by SYSTEM, Administrators and the service account. The database (`medicare.db`), TLS certificate (`certs\`), backups (`Backups\`) and logs (`logs\`) live here.
   - With your consent (task ticked by default), adds the firewall rule "MediCare Pharmacy Host (counter PCs)": TCP 8443 from the local subnet only, on Private and Domain networks. If Windows says the shop network is **Public**, change it to Private, or counters cannot connect.
   - Starts the service. The first start creates the database and the self-signed TLS certificate. Setup then waits until `https://127.0.0.1:8443/api/v1/health` answers.
   - Opens **MediCare Host Monitor** (Start menu > MediCare Pharmacy). Leave it running in the notification area: the icon is green when the host is running and red when it is not.
2. **Counter PCs**: run the client installer for the PC's Windows version.
   - Windows 10/11: `MediCarePharmacy-Client-win10-Setup-<version>.exe` (includes .NET 10, nothing else needed).
   - Windows 7 SP1: `MediCarePharmacy-Client-win7-Setup-<version>.exe` (see the Windows 7 section below).
   - Creates the "MediCare Pharmacy" Start menu and desktop shortcuts and gives Users modify rights on `C:\ProgramData\MediCarePharmacy\` (the shared `client.json` lives there).
3. **Pair each counter** (FR-012, SCR-33): on the counter, enter the host address and port shown on the Host Monitor ("Counters connect to: ..."). The counter displays the host's certificate fingerprint. **Compare it character by character with the large fingerprint on the Host Monitor** (shown in two halves). Confirm only if every character matches. The counter then pins it; if the host certificate ever changes, the counter refuses to connect until an administrator pairs it again.

If the host and a counter run on the same PC, install both. The order does not matter.

## Windows 7 SP1 counters

- **.NET Framework 4.8 is required.** Setup checks the registry (`NDP\v4\Full\Release >= 528040`) and stops with instructions if it is missing. Shops are often offline, so carry the offline installer `ndp48-x86-x64-allos-enu.exe` from https://dotnet.microsoft.com/download/dotnet-framework/net48 on a USB drive. Install it, restart, then run the MediCare setup again.
- **TLS 1.2.** Windows 7 has TLS 1.2 switched off by default, and the host only accepts TLS 1.2 or newer. With consent (task ticked by default), setup turns on the TLS 1.2 client in SChannel and sets `SchUseStrongCrypto=1` and `SystemDefaultTlsVersions=1` for .NET Framework 4.x in both the 64-bit and 32-bit registry views. Setup asks for a **restart** when it changed SChannel. Without a restart, the first connection fails.
- To prepare or repair a PC by hand, run `scripts\enable-tls12-win7.ps1` as administrator (it works with the PowerShell 2.0 that ships with Windows 7), or import `scripts\enable-tls12-win7.reg`. Restart afterwards.
- **KB3140245** is only needed for software that uses WinHTTP (`enable-tls12-win7.ps1 -WinHttp` also sets `DefaultSecureProtocols`). The MediCare .NET 4.8 client uses SChannel through .NET's `SslStream`, so the .NET Framework keys above are what matter. KB3140245 is not required for MediCare.
- Windows 7 is out of Microsoft support. Retire these PCs when possible (ADR-004). Test each new release on a Windows 7 VM before rollout. If a future Inno Setup version drops Windows 7, build the win7 client with the last version that supports it.

## Upgrades

Run the newer installer over the old one. The **AppId GUIDs are fixed** and must never change:

| Installer | AppId |
|---|---|
| Host | `{4E65573A-68F3-40DC-B816-C47014436E6D}` |
| Client (both editions) | `{F8C0317C-5458-493F-9CB6-9A1E7F0F7C58}` |

Host upgrade sequence (ADR-016):

1. Stops the service (and kills it after 120 s if it hangs). Closes the Host Monitor.
2. **Safety copy**: copies `medicare.db` (plus `-wal`/`-shm` if present) to `Data\pre-upgrade\<yyyymmdd-hhnnss>\`. While the service is stopped a file copy is consistent. If this fails, setup stops and changes nothing.
3. Keeps the old binaries in `Host\Service.previous\` and replaces `Host\Service\`.
4. Starts the service. The host applies database migrations on start.
5. **Health check**: waits up to 2 minutes for `/api/v1/health`.
6. **Rollback** if the check fails: restores `Service.previous` and the safety copy of the database (the failed upgrade's database is kept in `pre-upgrade\<stamp>\failed-upgrade\`), starts the old version and tells the administrator.

Upgrade at closing time. Delete old `pre-upgrade\` folders once the new version has run well for a few days.

Settings: `Service\appsettings.json` is replaced on every upgrade. Put local changes (for example another port) in `Service\appsettings.Production.json`. That file is never overwritten and survives rollback. If you change the port, run `Scripts\configure-firewall.ps1 -Port <port>` as administrator and tell the counters the new port.

The client upgrade replaces the program files only. `client.json` (host address, pinned fingerprint) and anything else the client keeps under ProgramData or the user profile is not touched.

## Uninstall

- **Host**: stops and deletes the service, removes the firewall rule and the program files. It then asks whether to keep the pharmacy data. **Yes is the default**: `C:\ProgramData\MediCarePharmacy\Data` (database, backups, certificate, logs) stays and is picked up again by a reinstall, so counters do not need to pair again. Choosing No asks a second time before deleting. A silent uninstall always keeps the data. Backups already copied to USB drives are never touched.
- **Client**: removes the program and shortcuts. `client.json` stays.
- Fonts and the Windows 7 TLS settings are left in place, because other programs may use them.

## Notes for maintainers

- **Data folder location.** ADR-016 mentions `D:\MediCareData`. The host currently resolves its data folder to `%ProgramData%\MediCarePharmacy\Data` when `MediCare:DataDir` is empty, and the installer, Host Monitor and ACLs follow that. To use another drive, set `MediCare:DataDir` in `appsettings.Production.json`, run `Scripts\host-service.ps1 -Action Install -DataDir <path>` to apply the ACL, and start Host Monitor with the folder as its argument (`MediCare.HostMonitor.exe D:\MediCareData`). The installer's safety copy, rollback and uninstall still assume the default folder, so moving it needs a matching change in `MediCareHost.iss` (`DataDir`).
- **Service account.** The virtual account `NT SERVICE\MediCarePharmacyHost` has no rights beyond the data folder. If a backup target (for example a NAS share) is not writable by it, grant that account access or set `#define ServiceAccount "LocalSystem"` in `MediCareHost.iss`.
- **Host Monitor** reads `Data\host-status.json` (written by the host every 30 s) and calls `/api/v1/health` on 127.0.0.1. It asks for administrator rights (`highestAvailable`) because the data folder is protected and it can start and stop the service.
- Setup logs: `%TEMP%\Setup Log *.txt` (Inno) and `Data\logs\setup-service.log` (service steps).
