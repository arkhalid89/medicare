# ADR-002: Topology: host service with thin LAN clients
Status: accepted   Date: 2026-10-14   Deciders: owner (gates G3/G4/G6), architect

## Context
SQLite is a file database; BRD s2/s10 forbid concurrent file access over a share. 7-10+ PCs must share one authoritative record offline.

## Options
(a) SQLite file on a network share: unsafe locking over SMB; rejected by BRD. (b) Local DB per PC + sync: forks data, violates BRD s10.9. (c) **Central Windows service on one host owning the DB; clients talk to it over LAN.** (d) SQL Server Express/PostgreSQL server: possible, but the BRD prefers SQLite when it suffices.

## Decision
(c). `MediCare.Host` is the only process that opens `medicare.db`, on the host's local disk. Clients hold no business data except drafts and cached lookups.

## Consequences
The host is a single point of failure: UPS, hourly backups and the spare-PC procedure are required ([deployment.md](../../05-production/deployment.md)). Clients are simple and cannot corrupt data. Cost: one extra deployable (service + monitor).
