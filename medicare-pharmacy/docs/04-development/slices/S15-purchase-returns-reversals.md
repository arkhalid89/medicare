# S15 - Purchase returns reversals
Status: todo      Size: M      Depends on: S07, S11

## Goal
Managers return batches to suppliers and reverse posted sales or purchases with approval, never editing originals.

## Covers
FR-045, FR-047, FR-048, FR-091   Screens: SCR-16, SCR-10 (reverse)   ADRs: ADR-007, ADR-008

## Data
purchase_return_headers/_items, reverses_id on documents

## Interfaces
`POST /purchase-returns`, `POST /sales/{id}/reverse`, `POST /purchases/{id}/reverse`

## Acceptance
- [ ] Return qty <= eligible remaining and available
- [ ] Payable reduced or supplier credit created; supplier refund recorded separately
- [ ] Purchase reversal blocked if units already sold (message suggests purchase return)
- [ ] Reversal creates a reversing document + journal; original shows REVERSED

## Files expected
Domain/Purchasing/Returns*, Domain/Reversals/*

## Tests
Domain tests

## Out of scope
Anything not listed above.

## Notes
Read CLAUDE.md working rules. Use error codes and copy from flows.md.
