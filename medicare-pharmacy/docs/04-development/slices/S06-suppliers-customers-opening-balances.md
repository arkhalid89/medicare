# S06 - Suppliers customers opening balances
Status: todo      Size: S      Depends on: S03

## Goal
Suppliers and customers exist with opening balances posted as opening journal entries; Walk-in is built in.

## Covers
FR-050, FR-055, FR-056   Screens: SCR-17, SCR-20   ADRs: ADR-007

## Data
suppliers, customers (seed Walk-in C-0001); journal OPEN entries

## Interfaces
suppliers and customers CRUD, `POST /opening-balances`

## Acceptance
- [ ] Opening balance creates a journal entry dated go-live date against 3100
- [ ] Walk-in cannot be edited, deactivated or used for credit
- [ ] Customer search by name, code or mobile (digits normalised)
- [ ] Duplicate mobile warns with the existing customer

## Files expected
Domain/Parties/*, Host/Endpoints/Suppliers*, Customers*, Client/Screens/Suppliers*, Customers*

## Tests
Domain tests for opening entries; search tests

## Out of scope
Ledgers (S13, S16)

## Notes
Read CLAUDE.md working rules. Use error codes and copy from flows.md.
