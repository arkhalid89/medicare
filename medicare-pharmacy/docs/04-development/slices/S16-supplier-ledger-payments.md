# S16 - Supplier ledger payments
Status: done      Size: M      Depends on: S07

## Goal
Accountants see supplier statements and pay suppliers with allocation and optional balance enforcement.

## Covers
FR-051, FR-052   Screens: SCR-18, SCR-19   ADRs: ADR-007

## Data
supplier_payments, payment_allocations

## Interfaces
`GET /suppliers/{id}/ledger`, `POST /supplier-payments`

## Acceptance
- [ ] Statement closing = outstanding report
- [ ] Allocation <= payment; advance allowed
- [ ] Balance enforcement blocks overdraw when enabled
- [ ] Voucher and balance-confirmation letter print (A4)

## Files expected
Domain/Accounts/SupplierPayments*, Client/Screens/SupplierLedger*, SupplierPayment*

## Tests
Domain tests

## Out of scope
Anything not listed above.

## Notes
Read CLAUDE.md working rules. Use error codes and copy from flows.md.

## Implementation
Host PartyService supplier ledger, payments, refunds; client SuppliersScreen, SettlementDialog, SupplierRefundDialog.
