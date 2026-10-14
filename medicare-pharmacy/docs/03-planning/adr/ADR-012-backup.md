# ADR-012: Backups: online API, hourly and daily on host, USB on demand, verified
Status: accepted   Date: 2026-10-14   Deciders: owner (gates G3/G4/G6), architect

## Context
BRD s11; WAL means a file copy is unsafe; RPO 24 h is weak for a busy shop.

## Options
File copy (unsafe); `VACUUM INTO` (rewrites every page, heavier); **SQLite online backup API** (`SqliteConnection.BackupDatabase`).

## Decision
Online backup API from a read connection, in steps so the writer is not blocked for more than about 100 ms. Output `.mcbak` = SQLite file + `manifest.json` (schema version, row counts, SHA-256, app version, host name, time) zipped. Verification opens the copy and runs `integrity_check` (daily, USB) or `quick_check` (hourly), and compares counts. Hourly kept 24, daily kept 30 (configurable), USB never overwritten (unique names). Restore follows BRD s11.3 with maintenance mode and a safety backup.

## Consequences
RPO about 1 h on host (protects against software faults), and the off-host RPO depends on how often staff copy to USB (dashboard nags after 24 h). Disk use is about 54 x DB size on the host; retention is configurable.
