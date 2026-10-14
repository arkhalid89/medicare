# S23 - Backup restore
Status: done      Size: L      Depends on: S03, S22

## Goal
Automatic hourly and daily backups, USB backup and verified restore work without stopping sales, and a restore drill meets the RTO.

## Covers
FR-103, FR-104, FR-105, NFR-008   Screens: SCR-31   ADRs: ADR-012

## Data
backup_history

## Interfaces
`POST /backups`, `GET /backups`, `POST /restore` (maintenance mode), event `maintenance.*`

## Acceptance
- [ ] Online backup while 10 simulated counters sell: no posting blocked > 1 s
- [ ] Manifest with schema version and SHA-256; verify runs integrity_check
- [ ] USB: free-space check, unique name, never overwrite, safe failure on removal
- [ ] Restore: admin re-auth + typed RESTORE, clients logged out, safety backup kept, checks, audit, banner on clients
- [ ] Restore drill documented and timed (<= 60 min) in testing.md

## Files expected
Data/Backup/*, Host/Maintenance/*, Client/Screens/Backup*

## Tests
Integration tests with live load; restore drill

## Out of scope
Anything not listed above.

## Notes
Read CLAUDE.md working rules. Use error codes and copy from flows.md.

## Implementation
Host `Modules/BackupModule.cs`; client BackupScreen (USB copy with SHA-256 verification), RestoreDialog. Test: Backup_And_Restore_RoundTrip.
