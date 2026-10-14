# S11 - Approvals
Status: todo      Size: M      Depends on: S10

## Goal
Managers approve discounts, refunds, credit over limit, adjustments, reversals, cash differences and back-dated postings with a PIN on the requesting PC or from the inbox.

## Covers
FR-009, FR-010, FR-063   Screens: SCR-07, SCR-24   ADRs: ADR-009

## Data
approvals

## Interfaces
`POST /approvals/inline`, `POST /approvals` (deferred), `POST /approvals/{id}/decide`, events `approval.*`

## Acceptance
- [ ] Token single-use, 5 min, bound to the command hash; approver must hold the permission
- [ ] Discount above role limit cannot post without a valid approval
- [ ] PIN lockout after 3 wrong tries for 15 min
- [ ] Inbox badge updates within 5 s via long-poll; requester notified
- [ ] All approvals audited with requester, approver, PC, reason

## Files expected
Domain/Approvals/*, Host/Events/*, Client/Screens/Approvals*

## Tests
Host tests for token binding, expiry, reuse, permission

## Out of scope
Anything not listed above.

## Notes
Read CLAUDE.md working rules. Use error codes and copy from flows.md.
