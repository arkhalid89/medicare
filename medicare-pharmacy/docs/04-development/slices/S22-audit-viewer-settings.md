# S22 - Audit viewer settings
Status: done      Size: M      Depends on: S03

## Goal
Admins search the audit log and manage all settings; settings changes reach counters within 30 s.

## Covers
FR-100, FR-101   Screens: SCR-30, SCR-32   ADRs: ADR-009

## Data
audit_logs, app_settings

## Interfaces
`GET /audit?…`, `GET/PUT /settings/{section}`, event `settings.changed`

## Acceptance
- [ ] Audit covers: logins, postings, returns, discount overrides, stock adjustments, price changes, reversals, backup/restore, permission changes
- [ ] No endpoint can modify audit rows
- [ ] Settings sections per BRD 6.16; posted documents unaffected by changes

## Files expected
Host/Audit*, Settings*, Client/Screens/Audit*, Settings*

## Tests
Host tests

## Out of scope
Anything not listed above.

## Notes
Read CLAUDE.md working rules. Use error codes and copy from flows.md.

## Implementation
Host audit endpoint and SettingsService; client AuditScreen, SettingsScreen (settings, lists, cash accounts).
