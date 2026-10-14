# S00 - Walking skeleton
Status: done      Size: M      Depends on: -

## Goal
Solution builds for host (.NET 10) and client (net48 + net10.0-windows); the client pairs with the host over TLS with a pinned fingerprint and shows a live connection status.

## Covers
FR-012, NFR-010, NFR-016, NFR-017   Screens: SCR-33   ADRs: ADR-002, ADR-003, ADR-004, ADR-015

## Data
workstations (id, name, machine_guid, os, client_version, paired_at); schema_version; app_settings

## Interfaces
`GET /api/v1/health`, `GET /api/v1/pairing/fingerprint`, `POST /api/v1/workstations/pair`; host runs as Windows service and console (dev)

## Acceptance
- [ ] `dotnet build` succeeds for all projects; client produces two outputs (net48, net10.0-windows)
- [ ] Host starts as console and as Windows service; generates a self-signed cert on first run (stored in LocalMachine\My, private key ACL to service account)
- [ ] Client pairing screen shows host fingerprint; mismatch on later start blocks with the SCR-33 error copy
- [ ] Status bar shows connected/latency; turns red within 3 s when the host stops
- [ ] Migration runner applies `0001_init.sql`, records checksum in schema_version, refuses a changed checksum
- [ ] Typed config module (`HostOptions`, `ClientOptions`) is the only place reading settings files
- [ ] Serilog rolling logs on host and client with redaction enricher (unit test proves `password`/`pin`/`token` are masked)
- [ ] Host stamps UTC; `BusinessDate` helper returns Asia/Karachi date (unit test across midnight UTC+5)
- [ ] Spike notes written to docs/04-development/spikes.md: (1) client runs on Win7 SP1 VM with TLS 1.2 enabled, (2) SQLite version >= 3.51.3 confirmed, (3) Urdu Naskh and Nastaliq printed via GDI on Win7 + Win11 to a PDF/thermal driver, with a photo or PDF attached

## Files expected
src/* project skeletons, Directory.Build.props, .editorconfig, db/migrations/0001_init.sql, src/MediCare.Host/Program.cs, src/MediCare.Client/Program.cs, tests/*

## Tests
Core.Tests run on net48 and net10; Host.Tests: health + pairing; CI script `build.ps1` builds both client targets

## Out of scope
Business screens, auth (S02)

## Notes
Use `#if NET48` only in bootstrap files; record each in conventions.md. Do not use gRPC (ADR-003).

## Implementation
Solution `MediCarePharmacy.sln`; host `src/MediCare.Host/Program.cs`; client `src/MediCare.Client/Program.cs`; migrations `Sql/0001_init.sql`.
