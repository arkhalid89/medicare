# S05 - Medicine import
Status: todo      Size: M      Depends on: S04

## Goal
The admin imports the existing medicine list from Excel or CSV with mapping, validation, dry run and one-transaction commit.

## Covers
FR-026   Screens: SCR-13   ADRs: ADR-005

## Data
import_jobs; writes medicines, packs, barcodes

## Interfaces
`POST /imports/medicines` (upload) → `/{id}/map` → `/{id}/validate` → `/{id}/commit` → `/{id}/undo`

## Acceptance
- [ ] Reads .xlsx (first sheet) and UTF-8 CSV with header row; up to 50,000 rows
- [ ] Column mapping saved per file layout
- [ ] Validation: required name and pack size; barcode format and uniqueness (in file and DB); duplicates by name+strength+manufacturer flagged as warnings
- [ ] Dry run reports counts without writing
- [ ] Commit in one transaction; error rows downloadable as CSV
- [ ] Undo allowed until any stock movement references an imported medicine

## Files expected
src/MediCare.Host/Import/*, Client/Screens/Import*

## Tests
Tests with sample files in tests/fixtures/import/ (clean, duplicates, bad headers, 5k rows < 30 s)

## Out of scope
Opening stock import (not in scope; stock via goods-in)

## Notes
Owner to supply a real sample file (launch checklist).
