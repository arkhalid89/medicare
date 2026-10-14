# S14 - Sales returns
Status: done      Size: M      Depends on: S10, S11

## Goal
Returns reference the original invoice, respect returnable quantities, route goods to restock or quarantine, reverse COGS and refund correctly.

## Covers
FR-075, FR-076, FR-077   Screens: SCR-09   ADRs: ADR-007

## Data
sales_return_headers, sales_return_items, movements (SR), journal

## Interfaces
`GET /sales/{no}/returnable`, `POST /sales-returns`

## Acceptance
- [ ] Qty <= sold − prior returns per line
- [ ] Restock only if batch unexpired and approved; else quarantine
- [ ] Refund to cash/bank/customer credit; approval above threshold
- [ ] Profit after full return equals profit before sale (test)

## Files expected
Domain/Sales/Returns*, Client/Screens/Returns*

## Tests
Domain tests

## Out of scope
Anything not listed above.

## Notes
Read CLAUDE.md working rules. Use error codes and copy from flows.md.

## Implementation
Host ReturnService; client SalesReturnDialog.
