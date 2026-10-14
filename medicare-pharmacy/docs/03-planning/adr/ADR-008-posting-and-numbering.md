# ADR-008: Idempotent posting pipeline and host-assigned document numbers
Status: accepted   Date: 2026-10-14   Deciders: owner (gates G3/G4/G6), architect

## Context
Double-clicks, network retries and host restarts must never duplicate a sale (BRD s6.8). Numbers must be unique per series (BRD s9).

## Options
Client-generated numbers (collide); DB autoincrement as number (gaps, no format); **host sequences inside the posting transaction**.

## Decision
Every posting command carries a client-generated GUID `Idempotency-Key`. In one `BEGIN IMMEDIATE` transaction the host checks `idempotency_keys`: a hit returns the stored response; otherwise it validates (stock re-check, prices, permissions, approvals, day closed), allocates the next number from `doc_sequences(series, period_key)`, writes header, lines, movements, balances, journal and audit, stores the response, and commits. Drafts have no number. A failed transaction rolls back the number, so there are no gaps from failures.

## Consequences
Exactly-once posting from the client's point of view, including after host-down recovery (ADR-013). The sequence row is a hot spot, which is acceptable because writes are serialised anyway (ADR-005).
