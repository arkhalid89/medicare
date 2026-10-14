# S10 - Payment posting printing
Status: todo      Size: L      Depends on: S09

## Goal
The cashier takes cash, card, wallet, split or credit payment; the sale posts exactly once with stock, COGS, journal and cashbook; the receipt prints with Urdu lines; host-down keeps a draft that posts once after reconnect.

## Covers
FR-013, FR-065, FR-066, FR-068, FR-107, NFR-003   Screens: SCR-05, SCR-33 (host-down overlay)   ADRs: ADR-008, ADR-011, ADR-013

## Data
sales_headers, sales_items, sales_payments, stock movements (SI), journal, cash_transactions

## Interfaces
`POST /sales` (Idempotency-Key), `GET /sales/{id}/receipt`

## Acceptance
- [ ] Split must equal net; credit needs customer and limit (over-limit returns `APPROVAL_REQUIRED`)
- [ ] Host re-checks stock and returns `STOCK_CHANGED` with availability; client offers re-pick
- [ ] 20 parallel identical requests → one invoice
- [ ] Journal per data-model.md; COGS at average cost
- [ ] Receipt templates 58/80 mm + A4 with Urdu name and footer (Naskh); printer failure does not affect posting; reprint DUPLICATE
- [ ] Host down: overlay, local draft file with key, Retry posts once (integration test with host restart)
- [ ] Mini load test: 10 simulated counters x 3 sales/min for 10 min, p95 <= 500 ms

## Files expected
Domain/Sales/SalePosting.cs, Client/Screens/Pos/Payment*, Client/Printing/*, tests/MediCare.LoadTest

## Tests
Domain tests (journal, COGS), Host concurrency + idempotency tests, print snapshot to PDF

## Out of scope
Returns (S14)

## Notes
Read CLAUDE.md working rules. Use error codes and copy from flows.md.
