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
- [x] 10 counters x 3 sales/min + purchases + reports: zero duplicates, zero negative stock, self-check passes, p95 targets met (10 min at spec rate and 5 min at 10x on the build VM; repeat 1 h on site)
- [x] If targets fail: stop and raise ADR-005 fallback with the owner (not needed: p95 post 16 ms)
- [ ] Every screen at 1366x768 @100 % and 1920x1080 @150 % on Win7 and Win11 without clipping
- [ ] Keyboard-only walkthrough of flows F1-F8; contrast and focus checks (done under Mono for F1, F2, F4, F5, F6; repeat on Windows)
- [x] Security checklist in testing.md complete (authz on every endpoint, TLS, secrets in logs; file ACLs and firewall to verify on Windows)

## Files expected
tests/*, docs/04-development/test-results.md

## Tests
Full suite + load + manual checklists

## Out of scope
Anything not listed above.

## Notes
Read CLAUDE.md working rules. Use error codes and copy from flows.md.

## Implementation
Results in [test-results.md](../test-results.md). Automated: `tests/MediCare.Host.Tests/HardeningTests.cs` (security checklist), `tests/MediCare.LoadTest` (load), restore drill. The Windows 7 build was exercised under Mono and 13 UI defects were fixed. Remaining (needs Windows hardware): DPI/scaling pass, Windows keyboard walkthrough, real printers, installer ACL and firewall checks, 1-hour load run on the shop LAN.
