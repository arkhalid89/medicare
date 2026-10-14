# S20 - Dashboard selfcheck
Status: done      Size: M      Depends on: S10, S18

## Goal
The dashboard shows role-filtered KPIs, charts and alerts from posted data; the nightly self-check proves stock, books and backups are consistent.

## Covers
FR-037, FR-095, FR-106   Screens: SCR-03   ADRs: ADR-007

## Data
selfcheck_runs; reads

## Interfaces
`GET /dashboard`, `POST /selfcheck`

## Acceptance
- [ ] Each card's number equals the matching report (test with seeded data)
- [ ] Profit labelled ESTIMATED where it excludes expenses
- [ ] Self-check: balances vs movements, journal balance, doc totals vs lines, audit chain, last verified backup age
- [ ] Red banner when no verified backup in 26 h

## Files expected
Host/Dashboard*, Domain/SelfCheck/*, Client/Screens/Dashboard*

## Tests
Self-check detects seeded corruption

## Out of scope
Anything not listed above.

## Notes
Read CLAUDE.md working rules. Use error codes and copy from flows.md.

## Implementation
Host ReportService.Dashboard, SelfCheckService; client `Screens/DashboardScreen.cs`, HostScreen.
