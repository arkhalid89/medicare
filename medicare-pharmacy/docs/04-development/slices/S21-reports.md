# S21 - Reports
Status: done      Size: L      Depends on: S20

## Goal
All 25 BRD reports and the controlled register run with filters, print and export, posted data only, within time targets.

## Covers
FR-096, FR-097, NFR-004   Screens: SCR-28, SCR-35   ADRs: ADR-005

## Data
read pool only

## Interfaces
`GET /reports/{key}`, `GET /reports/{key}/export?format=csv|xlsx`

## Acceptance
- [ ] Report catalogue matches testing.md list (25 + register)
- [ ] Drafts excluded; reversals/returns per policy setting
- [ ] Daily reports <= 5 s, monthly <= 15 s on the 3-year seeded DB
- [ ] CSV UTF-8 BOM; xlsx via MiniExcel; A4 print templates
- [ ] Each report has a reconciliation test against journal or movements

## Files expected
Host/Reports/*, Client/Screens/Reports*

## Tests
Verify snapshots per report; timing test

## Out of scope
Anything not listed above.

## Notes
Split into S21a (sales/profit/stock) and S21b (accounts/admin/register) if it runs long.

## Implementation
Host `Modules/ReportModule.cs` (26 reports, CSV/XLSX); client `Screens/ReportsScreen.cs`, `Printing/TablePrinter.cs`.
