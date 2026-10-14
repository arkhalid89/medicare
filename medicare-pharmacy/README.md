# MediCare Pharmacy

Offline pharmacy retail management for one pharmacy on a local network: POS with barcode and GS1 2D scanning, batch and expiry stock with FEFO, purchases, suppliers, customer credit, cashbook, expenses, returns, shift and day closing, 26 reports, roles and approvals, audit log, automatic and USB backups with verified restore. Money in PKR, time in Pakistan Standard Time, Urdu on receipts.

```
 Counter PCs (Windows 7 / 10 / 11)           Host PC (Windows 10/11)
 ┌───────────────────────────┐   HTTPS/JSON   ┌──────────────────────────────┐
 │ MediCarePharmacy.exe      │ ─────────────▶ │ MediCarePharmacyHost service │
 │ WinForms, pinned TLS cert │ ◀── events ─── │ ASP.NET Core + SQLite (WAL)  │
 └───────────────────────────┘                │ backups, self-check, monitor │
                                              └──────────────────────────────┘
```

## Repository
| Path | Contents |
|---|---|
| `src/MediCare.Host` | Host Windows service (the only writer of the database) |
| `src/MediCare.Client` | WinForms client; builds `net48` (Windows 7) and `net10.0-windows` (Windows 10/11) |
| `src/MediCare.HostMonitor` | Tray app for the host PC |
| `src/MediCare.Contracts`, `src/MediCare.Core` | Shared DTOs and pure business logic |
| `tests/` | Unit tests and end-to-end host scenarios |
| `installer/`, `build.ps1` | Inno Setup installers and release build |
| `docs/` | Brief, research, design, PRD, architecture, ADRs, slices, operations, user manual |

## Build
Requirements: .NET 10 SDK. Windows to run the client; Inno Setup 6.5+ to build installers.
```
dotnet build MediCarePharmacy.sln
dotnet test tests/MediCare.Core.Tests
dotnet test tests/MediCare.Host.Tests
```
On Linux and macOS everything builds (Windows targeting is enabled) and the host and all tests run; the Windows 7 client build also runs under Mono for smoke testing. Load test against a running host: `dotnet run -c Release --project tests/MediCare.LoadTest -- --url https://localhost:8443`. Latest results: [test-results](docs/04-development/test-results.md).

Run a development host (HTTP on port 8443, data in `~/medicare-data`):
```
MediCare__UseHttps=false dotnet run --project src/MediCare.Host
```
Release build and installers (Windows, PowerShell 7):
```
pwsh ./build.ps1
```
Outputs: `artifacts/installers/MediCarePharmacy-Host-Setup-*.exe`, `MediCarePharmacy-Client-win10-Setup-*.exe`, `MediCarePharmacy-Client-win7-Setup-*.exe`. Details: [installer/README.md](installer/README.md).

## Install and operate
See [deployment](docs/05-production/deployment.md) (install order, pairing, backups, restore, upgrade, troubleshooting), the [launch checklist](docs/05-production/launch-checklist.md) and the [user manual](docs/05-production/user-manual.md).

## Working on the code
Start with [CLAUDE.md](CLAUDE.md) and the [runbook](docs/04-development/runbook.md). Conventions: [conventions.md](docs/04-development/conventions.md). Decisions: [ADRs](docs/03-planning/adr/).
