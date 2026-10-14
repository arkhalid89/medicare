# Test results

Recorded by the build team on 2026-10-14. Re-run the same commands on the pharmacy's hardware before go-live and add a dated section below.

## Summary
| Check | Result |
|---|---|
| Core unit tests (`tests/MediCare.Core.Tests`) | 29 / 29 pass |
| Host end-to-end and hardening tests (`tests/MediCare.Host.Tests`) | 15 / 15 pass |
| Load test, spec rate (10 counters × 3 sales/min, 10 min, 20,000 medicines, 30,000 invoices of history) | PASS |
| Load test, stress (10 counters × 30 sales/min, 5 min) | PASS |
| Restore drill (188 MB database) | PASS: 9.6 s from Restore to first login |
| Security checklist (automated parts) | PASS |
| Optimisation pass (dashboard, reports, gzip) | Dashboard 458 → 7 ms; slowest report 886 → 613 ms; large reports 6–12 × smaller on the wire |
| UI smoke, Windows 7 build (net48) run under Mono on Linux, 1366×768 | Flows F1, F2, F4, F5, F6 and every screen opened; 13 defects found and fixed |
| UI smoke on real Windows 7 SP1 and Windows 10/11 PCs, 100 % and 150 % scaling, real printers | **Not yet run** (needs the pharmacy's hardware) |

Environment: Linux VM, 4 vCPU, 16 GB RAM, host in Release mode over HTTPS (self-signed certificate, pinned by fingerprint), load tool on the same VM.

## Load test, spec rate
Command: `dotnet run -c Release --project tests/MediCare.LoadTest -- --url https://localhost:8443 --medicines 20000 --preload 30000 --minutes 10 --rate 3`
History preload: 30,000 invoices posted in 2.2 min (230 invoices/s).

10 counters × 3 sales/min for 10.0 min against `https://localhost:8443`; 20000 medicines / 40000 batches.

| Operation | Count | p50 ms | p95 ms | p99 ms | max ms |
|---|---|---|---|---|---|
| backup | 1 | 6193 | 6193 | 6193 | 6193 |
| lookup | 2257 | 2 | 10 | 14 | 90 |
| post purchase | 1 | 15 | 15 | 15 | 15 |
| post return | 16 | 5 | 55 | 55 | 55 |
| post sale | 284 | 6 | 16 | 42 | 592 |
| quote | 1609 | 2 | 2 | 5 | 58 |
| report | 8 | 465 | 699 | 699 | 699 |
| save purchase | 1 | 9 | 9 | 9 | 9 |

- Sales posted: 284 (credit 26, 2D scans 409), returns 16, purchases 1, report rounds 4.
- Duplicate invoice numbers: 0. Server errors (5xx): 0. Out-of-stock refusals: 0. Client-side failures: 0.
- Sales posted while the backup ran: 3, slowest 10 ms.
- Self-check: all green: Database file integrity OK; Stock balances equal their movements OK; Every journal entry balances OK; Invoice totals equal their lines OK; Costing quantities match stock OK; Audit log chain intact OK; Inventory ledger close to valuation OK; Verified backup in the last 26 hours OK
- Targets: p95 lookup 10/300 ms, p95 post 16/500 ms.

## Load test, stress (10 × the spec rate)
Command: same, `--minutes 5 --rate 30` on the same database (≈ 36,000 invoices).

10 counters × 30 sales/min for 5.0 min against `https://localhost:8443`; 20000 medicines / 40000 batches.

| Operation | Count | p50 ms | p95 ms | p99 ms | max ms |
|---|---|---|---|---|---|
| backup | 1 | 5136 | 5136 | 5136 | 5136 |
| lookup | 10923 | 2 | 10 | 12 | 175 |
| post purchase | 9 | 11 | 21 | 21 | 21 |
| post return | 70 | 4 | 7 | 15 | 15 |
| post sale | 1429 | 5 | 15 | 26 | 235 |
| quote | 7855 | 2 | 2 | 4 | 142 |
| report | 50 | 407 | 526 | 560 | 560 |
| save purchase | 9 | 5 | 16 | 16 | 16 |

- Sales posted: 1429 (credit 133, 2D scans 1999), returns 70, purchases 9, report rounds 23.
- Duplicate invoice numbers: 0. Server errors (5xx): 0. Out-of-stock refusals: 0. Client-side failures: 0.
- Sales posted while the backup ran: 25, slowest 235 ms.
- Self-check: all green: Database file integrity OK; Stock balances equal their movements OK; Every journal entry balances OK; Invoice totals equal their lines OK; Costing quantities match stock OK; Audit log chain intact OK; Inventory ledger close to valuation OK; Verified backup in the last 26 hours OK
- Targets: p95 lookup 10/300 ms, p95 post 15/500 ms.

Notes:
- "report" is the daily sales report over all of today's invoices (30,000+ in this run, far above a real day), the monthly profit report and the dashboard.
- The full testing.md dataset (3 years, 1.1 M invoices, ≈ 6–7 GB at the measured ≈ 6 KB per invoice) was not generated; repeat with a copy of real data during the pilot.

## Restore drill
Script: back up, post 5 more sales, restore that backup as administrator, wait for the host, log in, verify.
| Step | Result |
|---|---|
| Database size | 188 MB |
| Backup (online, while counters could post) | 39 MB `.mcbak` in 5.6 s, integrity ok, SHA-256 recorded |
| Restore → first successful login | 9.6 s |
| 5 sales posted after the backup | absent (5 / 5) |
| Audit entry `RESTORE` | present |
| Self-check after restore | all green |

## Security checklist (testing.md)
| Item | Evidence |
|---|---|
| Every endpoint has a permission; forbidden calls audited | `Every_Endpoint_Declares_Access_And_Rejects_Anonymous_Callers` (all API routes declare access; only 5 anonymous routes; all others answer 401 to anonymous callers); `Cashier_Is_Refused_Every_Endpoint_Outside_Their_Role_And_Refusals_Are_Audited` (403 + `PERMISSION_DENIED` audit row each) |
| TLS, pinned certificate | Load test and drill ran over HTTPS; client refuses a different certificate (`ApiClient.ValidateCertificate`); TLS version policy is Windows' (host Win10/11, client Win7 needs the TLS 1.2 keys the installer sets) |
| PBKDF2 600k, lockout, no default users | Lockout and PIN lockout tests in `ScenarioTests`; no user exists until first-run setup |
| No passwords, PINs or tokens in logs | `Passwords_Pins_And_Tokens_Never_Reach_The_Log` (greps the log folder; checks only hashes are stored) |
| Data folder ACL | Set by the host installer (service account + SYSTEM + Administrators); verify on Windows |
| Firewall limited to the subnet | Set by the installer script; verify on Windows |
| Posted rows immutable; audit chain | `Posted_Rows_Cannot_Be_Edited_Or_Deleted_And_Audit_Chain_Holds` |
| Backups | Contain only the database and a manifest; USB guidance in user-manual.md and compliance.md |

## UI smoke (Windows 7 build under Mono)
The net48 client was run under Mono 6.8 + Xvfb + openbox at 1366×768 against a live host, driven by keyboard (xdotool), with screenshots of each step. Mono is not Windows: fonts and some native calls differ, so this finds logic and layout defects but does not replace the Windows smoke test.

Verified: first-run data, login and lockout counter, forced password change, POS (name search, barcode scan, quantity keys, host quote, FEFO near-expiry highlighting, payment with change, receipt failure message), discount above the cashier's limit approved with a manager's PIN (audit rows checked), goods-in with GS1 2D capture (second scan of the same batch adds quantity) and posting, sales return, shift open/close with denomination count, and every screen in the navigation.

Defects found and fixed in this pass:
1. Undocked labels overlapped at the top of stacked layouts (login title hidden).
2. Dialogs sized themselves before layout (`Control.Visible` is false until shown), so the payment dialog opened too small.
3. POS right column pushed **Take payment** off a 768-pixel screen.
4. POS cart grid needed horizontal scrolling at the minimum window size.
5. Totals rows disappeared; net payable figure did not fit its card.
6. Success bar measured too tall, clipping POS buttons.
7. Enter in a dialog's scan or search box pressed the dialog's OK button (goods-in posted instead of adding the scanned line; same for sales-return and medicine-editor barcode boxes).
8. Batches and Approvals screens never loaded on first open (re-selecting the same item fires no event).
9. Screens without inputs (dashboard) left no control with keyboard focus; the menu now takes focus and supports ↑ / ↓ / Enter.
10. `&` in names (e.g. "Muller & Phipps") drawn as a mnemonic in custom-painted text.
11. Ghost and secondary buttons painted black on transparent toolbars.
12. Keycap hints set after measuring truncated button labels ("Post inv…").
13. Return-slip print failure message pointed to a reprint that does not exist.

## Interface upgrade (Windows 7 build under Mono)
Same rig as the UI smoke test. Checked with screenshots at 1366×768: login (gradient brand panel), menu icons with the sliding selection and hover fade, screen icon in the header, dashboard greeting banner with quick actions, KPI cards (icons, tone stripe, count-up, hover lift, rows filled edge to edge), 14-day area chart with tooltips, payments donut, grid zebra, hover and empty states, POS line flash on scan, net payable count-up (dimmed while the host reprices), sale-complete card with change, card hidden by the next scan, approval toast (click opens Approvals), message bars with icons. Both client targets build with no warnings. Mono draws gradients with visible banding; Windows GDI+ does not.

## Optimisation pass
Database: the stress run's database (20,000 medicines, about 36,000 invoices posted on one day, 188 MB). Each figure is the median of three warm requests over HTTPS; 4 vCPU VM.

| Request | Before | After | Change |
|---|---|---|---|
| Dashboard (`/dashboard`) | 458 ms | 5–8 ms (cached), about 210 ms (rebuild after a write) | Cached on the host; rebuilt at most every 10 s and only after a write; finance figures removed per user after the cache |
| Report: current stock | 886 ms | 613 ms | Packs loaded in one query instead of one per medicine |
| Report: daily sales | 568 ms | 376 ms | Payment methods aggregated once in a CTE, not per row |
| Report: audit log | 532 ms | 330 ms | Same pack and join fixes |
| Reports: near expiry, batch expiry, expired / quarantine | about 180 ms | 11–14 ms | Date and status filters moved into SQL |
| Report: low stock | 105 ms | 31 ms | Stock totals in one grouped CTE |
| Stock screen, "Low" view | 85 ms | 40 ms | Skips the batch query it never used |
| Top sellers query (inside dashboard) | 265 ms | 71 ms | Join order forced with `CROSS JOIN` |

Response size with gzip (GET only; POST responses stay uncompressed so session tokens never share a compressed body with user input):

| Response | Plain | Gzip |
|---|---|---|
| Audit log report | 9.1 MB | 740 KB |
| Cash book report | 4.0 MB | 568 KB |
| Current stock report | 2.1 MB | 338 KB |
| Stock batches list | 1.3 MB | 110 KB |

Other changes: SQLite page cache 64 MB and 256 MB memory-mapped reads; release build publishes the host, host monitor and Windows 10/11 client ReadyToRun (precompiled) for faster start. All 44 tests pass after the changes.

## Re-run after the optimisation pass and release build (2026-10-14)
Same command and data size as the spec-rate run above, against the Release host over HTTPS. RESULT: PASS.

| Operation | Count | p50 ms | p95 ms | p99 ms | max ms | Before (p95 / max) |
|---|---|---|---|---|---|---|
| lookup | 2257 | 1 | 7 | 9 | 46 | 10 / 90 |
| quote | 1609 | 1 | 2 | 3 | 5 | 2 / 58 |
| post sale | 284 | 5 | 17 | 22 | 30 | 16 / 592 |
| post return | 16 | 5 | 46 | 46 | 46 | 55 / 55 |
| report | 8 | 189 | 296 | 296 | 296 | 699 / 699 |
| backup | 1 | 5254 | 5254 | 5254 | 5254 | 6193 / 6193 |

- History preload: 30,000 invoices in 1.9 min (264 invoices/s; before 230/s).
- Duplicate invoice numbers 0, server errors 0, client failures 0; sales during the backup: 3, slowest 6 ms; self-check all green.
- Unit and end-to-end tests: 44 / 44 pass in Release.

## Release build and installers (2026-10-14)
Built on Linux with `build.sh` (.NET 10 publish; Inno Setup 6.5.4 under Wine): `MediCarePharmacy-Host-Setup-1.0.0.exe` (71 MB), `MediCarePharmacy-Client-win10-Setup-1.0.0.exe` (36 MB), `MediCarePharmacy-Client-win7-Setup-1.0.0.exe` (2.7 MB). All three compile with no warnings; published executables carry version 1.0.0.0 and the app icon. The Windows 10/11 client installer was installed silently under Wine: 236 program files, the 8 fonts and 3 licence files in place (Wine cannot set the ACL on the shared data folder; Windows can). Fixed during the build: the Windows 7 installer did not compile (a [Files]-only flag on [Registry] entries), and the regular font weights now register under their full names. Not yet done: install on real Windows (service, firewall rule, pairing) and code signing.

## Still to do on the pharmacy's hardware (S25)
- UI smoke checklist on Windows 7 SP1 and Windows 10/11 at 100 % and 150 % scaling, keyboard only.
- Receipt printing on the actual 58/80 mm printers including the Urdu footer; cash drawer.
- Load test against the host PC from the counter PCs over the shop LAN.
- Installer, service account, data folder ACL and firewall rule checks.
