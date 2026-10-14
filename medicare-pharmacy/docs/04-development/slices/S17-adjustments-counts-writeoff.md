# S17 - Adjustments counts writeoff
Status: todo      Size: M      Depends on: S08, S11

## Goal
Stock operators adjust batches, run counts and write off quarantine stock, with approvals over thresholds and matching journal entries.

## Covers
FR-080, FR-081, FR-082   Screens: SCR-23   ADRs: ADR-007

## Data
stock_adjustments/_items, stock_counts/_items, movements (SA/SC/WO)

## Interfaces
`POST /stock/adjustments`, `POST /stock/counts`, `POST /stock/counts/{id}/post`, `POST /stock/quarantine`

## Acceptance
- [ ] Difference computed automatically; reason required
- [ ] Approval above value or % threshold
- [ ] Count diff = counted − (frozen + movements since freeze)
- [ ] Journal: shrinkage, quarantine move, write-off per data-model.md

## Files expected
Domain/Inventory/Adjustments*, Counts*, Client/Screens/Adjust*

## Tests
Domain tests

## Out of scope
Anything not listed above.

## Notes
Read CLAUDE.md working rules. Use error codes and copy from flows.md.
