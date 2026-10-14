# S04 - Medicine master
Status: done      Size: M      Depends on: S03

## Goal
Managers maintain medicines with barcodes, pack levels, prices, flags and Urdu names; everyone can search fast.

## Covers
FR-020, FR-021, FR-022, FR-023, FR-024, FR-027, FR-028, FR-108   Screens: SCR-11, SCR-12, SCR-32 (lists tab)   ADRs: ADR-006

## Data
medicines, medicine_packs, barcodes, manufacturers, categories, dosage_forms, price_history, medicine_search (FTS5)

## Interfaces
`GET /medicines?q=`, `GET /medicines/by-code/{code}`, `POST/PUT /medicines`, lists CRUD

## Acceptance
- [ ] Barcode uniqueness enforced by DB and API (409 with owning medicine)
- [ ] Pack hierarchy validation: integer base_qty, exactly one base pack, sale price per sellable pack
- [ ] Search by name/generic/brand/manufacturer/barcode with FTS5 prefix; p95 < 300 ms on 20,000 seeded medicines
- [ ] Deactivate instead of delete; inactive excluded from POS search
- [ ] Price change writes price_history and audit; posted docs unaffected (test)
- [ ] Schedule flags and licence-type rule (Schedule G blocked for medical-store licence)

## Files expected
src/MediCare.Domain/Catalogue/*, Host/Endpoints/Medicines*, Client/Screens/Medicines*

## Tests
Domain + Host tests; search performance test with 20k rows

## Out of scope
Import (S05), stock views (S08)

## Notes
Unit conversion lives in Core so the client preview uses the same code.

## Implementation
Host `Modules/CatalogueModule.cs` (CatalogueService); client `Screens/InventoryScreens.cs` (MedicinesScreen), `Dialogs/MedicineEditor.cs`.
