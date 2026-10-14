# S12 - Holds history prescriptions
Status: todo      Size: M      Depends on: S10

## Goal
Cashiers hold and recall sales, search and reprint invoices, and capture prescriptions for Rx and scheduled medicines.

## Covers
FR-067, FR-070, FR-071   Screens: SCR-08, SCR-10   ADRs: ADR-008

## Data
holds, prescriptions

## Interfaces
`POST/GET/DELETE /holds`, `GET /sales?…`, `POST /sales/{id}/reprint`

## Acceptance
- [ ] Holds expire after 24 h (job) and are audited
- [ ] Recall re-runs FEFO and prices
- [ ] Cashier sees own invoices unless `sales.view.all`
- [ ] Schedule B/D lines require prescriber, reg. no., patient; register serial assigned at posting
- [ ] Reprint marked DUPLICATE and audited

## Files expected
Domain/Sales/Holds*, Client/Screens/Held*, Invoices*

## Tests
Host tests; UI smoke

## Out of scope
Anything not listed above.

## Notes
Read CLAUDE.md working rules. Use error codes and copy from flows.md.
