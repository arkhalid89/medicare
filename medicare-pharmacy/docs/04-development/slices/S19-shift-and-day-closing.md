# S19 - Shift and day closing
Status: done      Size: M      Depends on: S18, S11

## Goal
Cashiers open and close shifts with denomination counts; managers approve differences and close the day, locking its date.

## Covers
FR-088, FR-089   Screens: SCR-27   ADRs: ADR-015

## Data
shifts, shift_counts, day_closings

## Interfaces
`POST /shifts/open`, `POST /shifts/{id}/close`, `POST /days/{date}/close`

## Acceptance
- [ ] Expected = float + cash sales + cash receipts − refunds − paid-outs
- [ ] Non-zero difference needs reason + approval; journal to 6900
- [ ] Day close refused with open shifts; posting into a closed date returns `DAY_CLOSED` unless approved

## Files expected
Domain/Accounts/Shifts*, Client/Screens/Closing*

## Tests
Domain tests

## Out of scope
Anything not listed above.

## Notes
Read CLAUDE.md working rules. Use error codes and copy from flows.md.

## Implementation
Host shifts and day close in AccountModule; client ClosingScreen, CloseShiftDialog, OpenShiftDialog.
