# S26 - Launch pack
Status: todo      Size: M      Depends on: S25

## Goal
Everything needed to hand over and go live exists: demo DB, manuals, recovery docs, test results, release build.

## Covers
NFR-015   Screens: -   ADRs: ADR-016

## Data
demo database (separate file)

## Interfaces
none

## Acceptance
- [ ] Demo database clearly separated (different file name and banner 'DEMO DATA')
- [ ] User manual (per role) and administrator manual; backup/restore/DR guide; release and upgrade notes; third-party licence list; build instructions
- [ ] Test results recorded in test-results.md; UAT sign-off sheet
- [ ] launch-checklist.md owner inputs all resolved or accepted

## Files expected
docs/manuals/*, docs/05-production/*, installer/output/*

## Tests
Release build from clean checkout

## Out of scope
Anything not listed above.

## Notes
Read CLAUDE.md working rules. Use error codes and copy from flows.md.
