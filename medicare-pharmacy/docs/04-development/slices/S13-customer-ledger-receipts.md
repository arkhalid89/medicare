# S13 - Customer ledger receipts
Status: todo      Size: M      Depends on: S10

## Goal
Accountants and cashiers see customer ledgers and collect receipts with allocation.

## Covers
FR-057, FR-058   Screens: SCR-21, SCR-22   ADRs: ADR-007

## Data
customer_receipts, receipt_allocations, journal_lines (party)

## Interfaces
`GET /customers/{id}/ledger`, `POST /receipts`

## Acceptance
- [ ] Ledger balance formula per BRD 7.2; equals outstanding report
- [ ] Allocation cannot exceed receipt or invoice balance; remainder becomes customer credit
- [ ] Receipt prints (thermal/A4)

## Files expected
Domain/Accounts/Receipts*, Client/Screens/CustomerLedger*, Receipt*

## Tests
Domain tests

## Out of scope
Anything not listed above.

## Notes
Read CLAUDE.md working rules. Use error codes and copy from flows.md.
