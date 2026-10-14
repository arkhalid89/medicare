# S08 - Stock views and ledger
Status: done      Size: M      Depends on: S07

## Goal
Managers see batches by state, near-expiry and low-stock lists; every balance is explained by its movements; negative stock is impossible.

## Covers
FR-025, FR-032, FR-033, FR-034, FR-035, FR-036   Screens: SCR-14   ADRs: ADR-006

## Data
stock_balances, stock_movements, batches

## Interfaces
`GET /batches?state=&window=`, `GET /batches/{id}/movements`, `GET /stock/low`

## Acceptance
- [ ] `StockLedger` is the only code that writes movements/balances (architecture test scans for other INSERTs)
- [ ] CHECK(qty >= 0) and service check return `INSUFFICIENT_STOCK`
- [ ] Expired derived at query time from expiry_date vs business date
- [ ] Near-expiry window from settings (default 90)
- [ ] Movement drill-down per batch

## Files expected
Domain/Inventory/StockLedger.cs, Host/Endpoints/Batches*, Client/Screens/Batches*

## Tests
Domain tests (negative stock, state moves), query tests

## Out of scope
Adjustments (S18)

## Notes
Read CLAUDE.md working rules. Use error codes and copy from flows.md.

## Implementation
Host `Modules/StockModule.cs`; client BatchesScreen in `Screens/InventoryScreens.cs`.
