# S18 - Cashbook bankbook expenses
Status: todo      Size: M      Depends on: S10

## Goal
Accountants see running cash and bank balances, record cash in/out and transfers, and post expense vouchers without double counting.

## Covers
FR-086, FR-087   Screens: SCR-25, SCR-26   ADRs: ADR-007

## Data
cash_accounts, cash_transactions, expenses

## Interfaces
`GET /cash/{account}/book`, `POST /cash/entries`, `POST /cash/transfers`, `POST /expenses`

## Acceptance
- [ ] Closing = opening + receipts − payments
- [ ] Sales/purchases/receipts/payments appear once (derived), never re-entered
- [ ] Expense approval above threshold; expenses affect operating profit only

## Files expected
Domain/Accounts/Cash*, Expenses*, Client/Screens/Cashbook*, Expenses*

## Tests
Domain tests

## Out of scope
Anything not listed above.

## Notes
Read CLAUDE.md working rules. Use error codes and copy from flows.md.
