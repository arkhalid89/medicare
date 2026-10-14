# Technology research

Accessed 2026-10-14. Versions are as found today; re-verify on build day. Sources: [sources.md](sources.md).

## T1. Runtime vs. Windows 7 (the decisive constraint)
| Fact | Tag |
|---|---|
| .NET 10 supported Windows client list starts at Windows 10 1607 (E) / 1809 (E) and Windows 11; Windows 7 and 8.1 are not listed | verified (dotnet/core supported-os.md) |
| Windows 10 22H2 mainstream support ended 14 Oct 2025 (consumer ESU to Oct 2026) | single-source |
| .NET Framework 4.8 runs on Windows 7 SP1 | known (Microsoft docs) |
| TLS 1.2 on Windows 7 SP1 is supported but **not enabled by default** at OS level (SChannel registry keys required); `.NET 4.6+` targets default `SchUseStrongCrypto`=1 | verified (Microsoft TLS guidance via 2 sources) |
| WinForms `SystemColorMode` dark mode is **experimental** (WFO5001) in .NET 9/10 and **Windows 11 only** | verified (MS API docs) |

**Implication:** one codebase, two client targets: `net10.0-windows` (Win10/11) and `net48` (Win7 SP1). The server is .NET 10 only (host is Win10/11). Theming must be our own owner-drawn, token-driven renderer, not `SystemColorMode`.

## T2. Client-server protocol (must work on net48 and net10)
| Option | net48/Win7 | Notes | Verdict |
|---|---|---|---|
| gRPC (Grpc.Net.Client) | **No**: needs WinHttpHandler, client fully supported only on Windows 11 | gRPC-Web fallback is possible but adds complexity | Reject |
| Grpc.Core (native, deprecated) | Yes, but deprecated | Maintenance risk | Reject |
| **HTTPS/1.1 + JSON (ASP.NET Core minimal API on Kestrel)** | Yes (`HttpClient`, `System.Text.Json` package) | Debuggable, tooling everywhere, versionable | **Choose** |
| Raw TCP + MessagePack over SslStream | Yes | Fast but custom framing, harder to debug | Reject |
| WCF | Server side not supported on .NET 10 (CoreWCF exists) | Legacy | Reject |

Server push (approvals, "host going down", session revoke): **long-poll endpoint** (`GET /events?since=`), which works on both runtimes without WebSockets on Win7.

## T3. Database
| Fact | Tag |
|---|---|
| Microsoft.Data.Sqlite 10.0.x targets netstandard2.0, depends on SQLitePCLRaw 2.1.x | verified (NuGet listings) |
| WAL allows concurrent readers with **one writer**; use `BEGIN IMMEDIATE` for write transactions, `busy_timeout` >= 5 s, keep writes short | verified (several sources incl. sqlite.org forum) |
| Funnel writes through one in-process queue for heavy write load | single-source |
| Online backup API (`sqlite3_backup_*`) or `VACUUM INTO` give consistent copies of a live WAL DB; copying the `.db` file alone is unsafe | verified (2 sources) |
| Possible WAL-reset race in old SQLite versions; recommended >= 3.51.3 | unverified, confirm against sqlite.org release log at S00 |

**Capacity estimate (assumption, verify in load test):** 1,000 invoices/day at a 3x evening peak is about 250 invoices/hour, roughly 1 write transaction per 15 s. Each posting touches about 10-30 rows. A single SQLite writer on SSD typically sustains hundreds to thousands of such transactions per second, so headroom is more than 100x. The real risks are long transactions and report queries starving the writer, not raw throughput. Mitigations: WAL, a read-connection pool for reports, a single write queue, and indexes.

## T4. UI components (must compile for net48 and net10)
| Need | Options | Choice |
|---|---|---|
| Charts | ScottPlot.WinForms 5.1.x (targets net462 + net8/10-windows; depends on SkiaSharp native, Win7 behaviour unverified); WinForms DataVisualization (net48 only built-in, community port for .NET); **own GDI+ chart control** | **Own small GDI+ control** (bar, line, donut, sparkline): 4 chart types, no native deps, identical on Win7 |
| Grid | Built-in `DataGridView` (virtual mode) | **DataGridView** with owner-draw theme; virtual mode for 20k rows |
| Excel import | ExcelDataReader, MiniExcel, ClosedXML | Runs **on the server only** (.NET 10): **MiniExcel** for xlsx and CSV (verify licence, Apache-2.0, at S00) |
| Excel-compatible export | CSV (UTF-8 BOM) + xlsx | CSV on client; xlsx via server endpoint (MiniExcel) |
| Polyfills for modern C# on net48 | PolySharp | **PolySharp** (source-only) |
| JSON | System.Text.Json (NuGet on net48) | **System.Text.Json** both sides |

## T5. Printing and Urdu
| Fact | Tag |
|---|---|
| Most ESC/POS printers support only Latin code pages; Arabic-script text must be sent as a bitmap | single-source (thermal_unicode_print docs) |
| Windows GDI+ via the printer's Windows driver rasterises any font the OS can shape | known |
| Nastaliq shaping under GDI/Uniscribe on Windows 7 is not documented; Naskh-style fonts shape reliably under Uniscribe | unverified, spike required |

**Plan:** print receipts through the Windows driver with `System.Drawing.Printing` (works on both runtimes), so Urdu is rasterised by the driver. Bundle **Noto Naskh Arabic** (OFL) as the safe default and **Noto Nastaliq Urdu** (OFL) as optional where the spike shows correct shaping. Raw ESC/POS is used only for the cash-drawer kick command. Spike in S00.

## T6. Host service, installer, security
| Need | Choice | Tag |
|---|---|---|
| Windows service | .NET 10 Worker + `UseWindowsService()`, Kestrel bound to LAN IP, port configurable | known |
| TLS | Self-signed certificate generated at install; clients **pin the thumbprint** captured during pairing | design |
| Installer | **Inno Setup 6.5+** (supports Windows 7 SP1 minimum, Vista dropped) | verified (jrsoftware) |
| Passwords | PBKDF2-SHA256 (`Rfc2898DeriveBytes`, 600k iterations) in the server only; clients never hash or store passwords | known (OWASP guidance) |
| 2D barcode | USB HID 2D imager in keyboard mode; GS1 FNC1 is sent as ASCII 29 (GS) if the scanner is configured for it | known; scanner config is an owner task |

## T7. Testing
xUnit on the server and domain; a headless API test harness drives N concurrent simulated counters (load and race test, BRD s10); a Windows 7 VM and a Windows 11 VM smoke test per release.
