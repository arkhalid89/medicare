# S09 - Pos cart fefo
Status: done      Size: L      Depends on: S08

## Goal
The cashier builds a cart by scan or search with FEFO (or exact 2D batch), sees batch details, and the preview totals match the host's calculation exactly.

## Covers
FR-060, FR-061, FR-062, FR-064, FR-069, FR-072, NFR-002   Screens: SCR-04, SCR-06   ADRs: ADR-006, ADR-014

## Data
reads medicines, barcodes, batches, balances

## Interfaces
`GET /medicines/by-code/{code}` returns medicine + FEFO candidates; `GET /stock/fefo?medicineId&qty`

## Acceptance
- [ ] Scan field always refocused; `*n`/`+n` sets qty; Up/Down/Del; F-keys from settings
- [ ] FEFO split across batches when needed; F7 picker logs manual pick
- [ ] Expired/quarantined/inactive never added; error copy per flows.md
- [ ] Pricing/tax/discount calculation in Core; test vectors in testing.md pass on net48 and net10
- [ ] Client timing log records scan→line ms; p95 <= 300 ms on LAN with 20k medicines

## Files expected
Core/Pricing/*, Core/Fefo/*, Client/Screens/Pos/*

## Tests
Core calc tests (both runtimes), FEFO tests, UI smoke script

## Out of scope
Payment and posting (S10)

## Notes
The client never decides the final price; the host recalculates at posting.

## Implementation
Host `POST /sales/quote` + PriceCart in `Modules/SalesModule.cs`; client `Screens/PosScreen.cs`, `Dialogs/SalesDialogs.cs` (BatchPickDialog, LineDialog, DiscountDialog).
