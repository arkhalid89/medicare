# Deployment and operations

MediCare Pharmacy runs entirely on the pharmacy's LAN. There is no cloud component and no internet requirement.

## Environments
| Environment | Where | Purpose |
|---|---|---|
| Production | Host PC in the pharmacy (Windows 10/11 x64, always on, UPS) + counter PCs | Live trading |
| Training | Any spare PC with the host installed, data restored from last night's backup | Staff practice without touching live data |
| Development | Developer machine; `dotnet run --project src/MediCare.Host` | Building and testing |

## Hardware and network
- Host: Windows 10/11 64-bit, 4 cores, 8 GB RAM, SSD, wired gigabit Ethernet, fixed IP or DHCP reservation, UPS that holds 15 minutes. Sleep disabled.
- Counters: Windows 7 SP1 (with .NET Framework 4.8) or Windows 10/11; 1366×768 or larger; wired Ethernet preferred; USB/2D barcode scanner in keyboard mode (FNC1 → GS, suffix Enter); 58/80 mm thermal printer with Windows driver; optional cash drawer on the printer.
- One switch for all PCs. No port forwarding from the internet to the host.

## Install order
1. **Host PC:** run `MediCarePharmacy-Host-Setup-<version>.exe` (from `artifacts/installers`). It installs the service `MediCarePharmacyHost` (delayed auto-start, restart on failure, runs as `NT SERVICE\MediCarePharmacyHost`; the Data folder is readable only by that account, SYSTEM and Administrators, so the Host Monitor asks for administrator rights), the Host Monitor, the fonts, and a firewall rule for TCP 8443 limited to the local subnet. Data lives in `%ProgramData%\MediCarePharmacy\Data`.
2. Open **MediCare Host Monitor** on the host: check the service is running and note the **certificate fingerprint**.
3. **Each counter PC:** run `MediCarePharmacy-Client-win10-Setup-<version>.exe` (Windows 10/11) or `MediCarePharmacy-Client-win7-Setup-<version>.exe` (Windows 7; install .NET Framework 4.8 first; the installer turns on TLS 1.2 and may ask for a restart).
4. Start MediCare Pharmacy on the first counter: enter the host name or IP, press **Check host**, compare the fingerprint with the Host Monitor, tick the box, set the counter number.
5. First counter only: **first-time setup** creates the pharmacy profile and the administrator.
6. Administrator: Settings (pharmacy, tax, thresholds, receipt footers, payment methods), users and PINs, import the medicine list, opening stock (goods-in marked *Opening stock*), supplier and customer opening balances, cash opening balance.
7. Pair the remaining counters (step 4) and run a test receipt on each (user menu → This PC → Print a test receipt).

## Host configuration
`appsettings.json` next to the host executable (defaults are right for most pharmacies):
```json
{ "MediCare": { "DataDir": "", "Port": 8443, "UseHttps": true, "BindAddress": "0.0.0.0" } }
```
- `DataDir` empty = `%ProgramData%\MediCarePharmacy\Data`.
- `UseHttps: false` only on an isolated network where TLS cannot work; counters then pair with "This host does not use HTTPS".

Data folder layout:
| Path | Contents |
|---|---|
| `medicare.db` (+ `-wal`, `-shm`) | The database. Never copy it while the service runs; use backups. |
| `certs/host.pfx`, `certs/host.key` | Self-signed TLS certificate (created on first start). Losing it means re-pairing every counter. Back it up with the server image. |
| `Backups/`, `Backups/hourly/` | Automatic backups (`.mcbak` = zip of database + manifest with SHA-256). |
| `pre-restore/` | Safety copy taken automatically before every restore. |
| `logs/host-YYYYMMDD.log` | Host log, 14 days kept. |
| `host-status.json` | Written every 30 seconds for the Host Monitor. |

Counter PCs keep `client.json` (host, fingerprint, counter, printers) in `%ProgramData%\MediCarePharmacy` and logs in `%LocalAppData%\MediCarePharmacy\logs`.

## Backups
| What | When | Kept | Where |
|---|---|---|---|
| Hourly | Each hour, first 10 minutes, if enabled | 24 | `Backups/hourly` on the host |
| Daily | `backup.dailyTime` (default 03:00) | 30 | `Backups` on the host |
| Self-check | Daily time + 5 minutes | latest | dashboard and Host screen |
| **Off-site USB** | **Every day after day close** | 7 rotating USB drives | taken home by the owner |

Every backup is made with SQLite's online backup API while counters keep selling, then integrity-checked. The USB copy (Backup screen → Copy to USB) re-reads the copied file and compares its SHA-256 with the host's; the result is in the audit log and the dashboard.

## Restore
Only an administrator. Backup screen → Restore → choose a host backup or a `.mcbak` from USB → administrator password → type `RESTORE`. The host validates the manifest, checksum, integrity and schema version, enters maintenance (counters are told to log out), keeps the current database in `pre-restore/`, swaps the file, re-checks, and comes back. Every PC logs in again. Sales after the backup time must be re-entered from the paper receipts.

Run the restore drill (testing.md) every quarter on the training PC.

## Upgrade
1. Day closed, USB backup taken.
2. Run the new host installer on the host (upgrade in place; the service is stopped and started). Migrations run at start; a failed migration leaves the database unchanged and the service does not start.
3. Run the new client installer on each counter (or let them keep the old client if the release notes say it is compatible).

## Rollback
1. Stop the service. Reinstall the previous host version.
2. If the upgrade changed the schema, restore the backup taken in upgrade step 1.
3. Reinstall the previous client on counters that were upgraded.

## Monitoring
- **Host Monitor** on the host PC: service state, connected PCs, last backup, fingerprint. Tray icon turns red when the service stops.
- **Dashboard** tiles: last backup (red if older than 26 h or failed), USB copy age (amber after 7 days), self-check result, approvals waiting, expiry risk.
- **Status bar** on every counter: green when connected to the host, red with automatic retry when not.
- Logs: host `logs/`, client `%LocalAppData%\MediCarePharmacy\logs`. Passwords, PINs and tokens are never logged.

## Troubleshooting
| Symptom | Check | Fix |
|---|---|---|
| Counters show "Host unreachable" | Host PC on? Service running (Host Monitor)? Cable? | Start the service; `ping` the host; check the firewall rule `MediCare Host 8443` |
| "Presented a different security certificate" | Was the host reinstalled or `certs/` deleted? | If yes: pair each counter again (Connection settings). If no: stop and call support (possible impersonation). |
| Windows 7 counter cannot connect but Windows 10 can | TLS 1.2 disabled on Windows 7 | Run `enable-tls12-win7.ps1` as administrator and restart |
| Receipt prints blank Urdu | Urdu font missing on that PC | Re-run the client installer (installs Noto Naskh Arabic) |
| Backup tile red | Disk full? Backup folder unreachable? | Free space; fix `backup.folder`; Back up now |
| Self-check "PROBLEM" | Open Host screen for details | Do not close the day; call support with the self-check rows |
| Cashier locked out | 5 wrong passwords | Admin: Users → Unlock (or wait 15 minutes) |
