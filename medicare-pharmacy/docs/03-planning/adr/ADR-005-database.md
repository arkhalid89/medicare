# ADR-005: SQLite configuration, single write queue, data access and fallback
Status: accepted   Date: 2026-10-14   Deciders: owner (gates G3/G4/G6), architect

## Context
1,000+ invoices/day across 10 counters with reports running at the same time. SQLite allows one writer, and deferred transactions cause SQLITE_BUSY ([tech.md](../../01-idea/research/tech.md) T3).

## Options
EF Core with SQLite (decimal stored as TEXT, so SQL aggregation is impossible); **Dapper + explicit SQL**; raw ADO.NET. Writer model: lock per request vs **single in-process write queue**.

## Decision
SQLite >= 3.51.3 (bundled via SQLitePCLRaw). PRAGMAs: WAL, `synchronous=FULL`, `foreign_keys=ON`, `busy_timeout=5000`. All writes go through one `WriteQueue` (a single dedicated connection; commands are executed in order, each in `BEGIN IMMEDIATE`, target < 50 ms). Reads use a pool of read-only connections. Dapper with explicit SQL; own migration runner (numbered embedded `.sql`, checksum, backup before migrate). Reports are paged and date-bounded. **Fallback:** if the S24 load test misses NFR-003/NFR-005, port `MediCare.Data` to PostgreSQL 17 on the same host; clients and Domain do not change.

## Consequences
Predictable latency and no SQLITE_BUSY in normal operation. Long transactions are forbidden by convention. The one exception is the medicine import commit (FR-026): one transaction of about 5,000 rows (target < 2 s), which the UI recommends running outside peak hours. The fallback has a real cost (about 2-3 weeks) and is held in reserve.
