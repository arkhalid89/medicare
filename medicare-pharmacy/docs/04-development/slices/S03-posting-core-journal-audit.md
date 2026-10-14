# S03 - Posting core journal audit
Status: done      Size: L      Depends on: S02

## Goal
A generic, idempotent posting pipeline writes documents, journal entries and audit rows atomically through a single write queue, proven with a test document type.

## Covers
FR-085, FR-102, NFR-006, NFR-007, NFR-013   Screens: -   ADRs: ADR-005, ADR-006, ADR-007, ADR-008

## Data
accounts (seed chart), journal_entries, journal_lines, doc_sequences, idempotency_keys, audit_logs (hash chain), immutability triggers

## Interfaces
internal `IPostingService`, `WriteQueue`, `JournalBuilder`, `AuditWriter`, `DocumentNumberer`

## Acceptance
- [ ] WriteQueue executes commands sequentially in `BEGIN IMMEDIATE`; read pool separate; PRAGMAs per ADR-005
- [ ] JournalBuilder refuses unbalanced entries; self-check query for balance exists
- [ ] Idempotency: same key returns the stored response; different payload with the same key returns 409
- [ ] Numbers from doc_sequences per series and period; format strings from settings; 1,000 concurrent postings in a test produce no duplicates and no gaps
- [ ] Triggers block UPDATE of posted rows and DELETE of document/movement/journal/audit rows
- [ ] Audit hash chain verifies; tampering a row is detected
- [ ] Analyzer/test bans float and double in Core, Domain and Data
- [ ] Power-loss simulation: kill the process during 500 postings; integrity_check ok; every acknowledged posting present

## Files expected
src/MediCare.Data/WriteQueue.cs, Db/*, Migrations/0002_ledger.sql, src/MediCare.Domain/Posting/*, Accounting/*, Audit/*

## Tests
Domain.Tests: journal balance, numbering concurrency, idempotency, triggers, hash chain; crash test harness

## Out of scope
Business documents (later slices)

## Notes
Keep each write transaction < 50 ms; no network or printing inside a transaction.

## Implementation
Host `Domain/Foundation.cs` (journal, audit hash chain, doc numbers, idempotency, stock ledger), `Infrastructure/Core.cs` (write gate).
