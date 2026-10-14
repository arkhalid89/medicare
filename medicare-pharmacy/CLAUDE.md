# MediCare Pharmacy
Offline LAN pharmacy retail system: one host PC owns the data, every counter PC is a thin client. Track: internal system (single pharmacy). Stack: .NET 10 host service (ASP.NET Core minimal APIs, SQLite via Dapper), WinForms client built twice (net48 for Windows 7, net10.0-windows for Windows 10/11), Inno Setup installers.

## Read order
1. This file. 2. `docs/04-development/runbook.md` (status table, next slice).
3. The slice file, then only the doc sections it points to.
Do not read all of `docs/` up front.

## Document map (read when)
| Need | File |
|---|---|
| Requirements (FR/NFR ids) | docs/03-planning/prd.md |
| Architecture, topology, threading | docs/03-planning/architecture.md |
| Tables and money units | docs/03-planning/data-model.md |
| Past decisions (final) | docs/03-planning/adr/ |
| Screens (SCR ids) and flows | docs/02-design/flows.md, prototype/index.html |
| Tokens and components | docs/02-design/design-system.md, tokens.css (mirrored in `src/MediCare.Client/Ui/Theme.cs`) |
| Conventions | docs/04-development/conventions.md |
| Test vectors, load and restore drills | docs/04-development/testing.md |
| Deploy, backup, operations | docs/05-production/deployment.md |

## Code map
| Project | What lives there |
|---|---|
| `src/MediCare.Contracts` | DTOs, error codes, permissions (netstandard2.0, shared by host and both clients) |
| `src/MediCare.Core` | Money, pricing, FEFO, GS1, costing; pure and unit-tested |
| `src/MediCare.Host` | Windows service: `Modules/*Module.cs` (endpoints + service per area), `Domain/` (journal, audit, approvals, settings), `Security/`, `Sql/NNNN_*.sql` migrations |
| `src/MediCare.Client` | WinForms: `Shell/` (main form, login, navigation), `Screens/`, `Dialogs/`, `Ui/` (theme and controls), `Printing/`, `Infrastructure/` (API client, session, config) |
| `src/MediCare.HostMonitor` | Tray app on the host PC: service state, fingerprint, backups |
| `tests/` | `MediCare.Core.Tests` (unit), `MediCare.Host.Tests` (end-to-end over HTTP with a temp database) |
| `installer/`, `build.ps1` | Installers and the release build |

## Commands
```
dotnet build MediCarePharmacy.sln                      # all projects (Linux needs EnableWindowsTargeting, already set)
dotnet test tests/MediCare.Core.Tests                  # unit tests
dotnet test tests/MediCare.Host.Tests                  # end-to-end scenarios (temp data dir, HTTP)
dotnet build src/MediCare.Client -f net48              # Windows 7 client only
dotnet run --project src/MediCare.Host                 # host on http(s)://0.0.0.0:8443, data in %ProgramData%\MediCarePharmacy\Data (or ~/medicare-data)
pwsh ./build.ps1                                       # Windows: tests, publish all, compile installers
dotnet run -c Release --project tests/MediCare.LoadTest -- --url https://HOST:8443 --minutes 60 --rate 3   # S25 load test (results: docs/04-development/test-results.md)
```
Migrations run automatically at host start (`Db.Migrate`); add a new `Sql/NNNN_name.sql`, never edit a shipped one.

## Working rules
- One slice at a time. Follow its acceptance checklist; do not add scope.
- Decisions in ADRs are final. If a spec conflicts with reality, stop and ask the owner.
- Money is integer paisa in the database (`*_paisa`), unit costs ×10,000 (`*_e4`), percentages in basis points. Use `MediCare.Core.Money`; never `double`.
- Every write goes through `Db.Write` (single write gate, BEGIN IMMEDIATE). Postings are idempotent (client key) and get host-assigned numbers. Never update a posted row; reverse it.
- Record failures (lockout counters, audit of failed attempts) in their own committed write before throwing; an exception inside `Db.Write` rolls everything back.
- The POS shows prices from `POST /sales/quote`, which runs the same code as posting. Do not price on the client.
- Client code must compile for net48: no APIs newer than .NET Framework 4.8 unless polyfilled (see `Infrastructure/Compat.cs`). Never block the UI thread on a task (`.Result`, `.Wait()`), except via `Task.Run(...)`.
- UI uses `Theme` tokens only; screens derive from `AppScreen`, dialogs from `DialogForm`; every action has a key (F-keys on the keycap strip).
- Errors say what happened and what to do next. Never "An error occurred".
- Run the slice's tests and a full `dotnet build` (both client targets) before finishing; run both test projects at checkpoints.
- Finish by updating the slice status and the runbook table, then commit.

## Definition of done (per slice)
Acceptance checklist ticked, tests pass, both client targets build without warnings, empty/loading/error/success states covered, keyboard path works, status updated.
