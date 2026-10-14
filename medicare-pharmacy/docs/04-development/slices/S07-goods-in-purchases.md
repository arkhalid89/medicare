# S07 - Goods in purchases
Status: todo      Size: L      Depends on: S04, S06

## Goal
The stock operator enters supplier bills by 2D scan, 1D scan or search, posts them, and stock, average cost, payable and any immediate payment are written atomically.

## Covers
FR-030, FR-031, FR-038, FR-040, FR-041, FR-042, FR-043, FR-044, FR-046, FR-090   Screens: SCR-15   ADRs: ADR-007, ADR-014

## Data
purchase_headers, purchase_items, batches, stock_balances, stock_movements (PI), medicine_costs, cash_transactions

## Interfaces
`POST /purchases` (draft), `PUT /purchases/{id}`, `POST /purchases/{id}/post` (Idempotency-Key), `GET /purchases/{id}/voucher`

## Acceptance
- [ ] Gs1Parser passes all vectors in testing.md (GS separator, bracketed, `]d2` prefix, day 00 expiry)
- [ ] Repeat scan of same batch increments qty; batch number with a different expiry is refused
- [ ] Effective unit cost includes free qty, discounts and charges; worked example in testing.md matches to 4 dp
- [ ] Moving average updated per ADR-007; journal Dr Inventory / Cr Payables (+ payment lines)
- [ ] Draft never changes stock; post is atomic and idempotent
- [ ] A4 voucher prints; capture_method stored per line

## Files expected
Core/Gs1Parser.cs, Core/Costing/*, Domain/Purchasing/*, Client/Screens/GoodsIn*

## Tests
Core tests (GS1, costing), Domain posting tests, Host idempotency test

## Out of scope
Purchase returns and reversal (S15)

## Notes
Read CLAUDE.md working rules. Use error codes and copy from flows.md.
