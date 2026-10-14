# S25 - Hardening load a11y security
Status: partial      Size: L      Depends on: S24

## Goal
The full system passes the 10-counter load test, accessibility and DPI checks, and a security review before UAT.

## Covers
NFR-005, NFR-011, NFR-012   Screens: all   ADRs: ADR-005

## Data
seeded 3-year dataset

## Interfaces
tests/MediCare.LoadTest

## Acceptance
- [ ] 10 counters x 3 sales/min for 1 h + purchases + reports: zero duplicates, zero negative stock, self-check passes, p95 targets met
- [ ] If targets fail: stop and raise ADR-005 fallback with the owner
- [ ] Every screen at 1366x768 @100 % and 1920x1080 @150 % on Win7 and Win11 without clipping
- [ ] Keyboard-only walkthrough of flows F1-F8; contrast and focus checks
- [ ] Security checklist in testing.md complete (authz on every endpoint, TLS, secrets in logs, file ACLs)

## Files expected
tests/*, docs/04-development/test-results.md

## Tests
Full suite + load + manual checklists

## Out of scope
Anything not listed above.

## Notes
Read CLAUDE.md working rules. Use error codes and copy from flows.md.

## Implementation
Done: concurrency and idempotency test (10 parallel posts), lockout and PIN counters, permission checks, TLS pinning. Still to run on Windows hardware: the 1,000-bill load test, keyboard and contrast pass of the WinForms screens, restore drill. See docs/05-production/launch-checklist.md.
