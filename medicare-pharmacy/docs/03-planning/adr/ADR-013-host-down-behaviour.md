# ADR-013: When the host is down, counters stop rather than fork
Status: accepted   Date: 2026-10-14   Deciders: owner (gates G3/G4/G6), architect

## Context
BRD s10.9-10.10: no separate databases and no unrecorded transactions; reconnect without duplication.

## Options
Offline queue on each client (forks stock truth, risks overselling and expiry); **block posting, keep drafts, retry idempotently**.

## Decision
Clients detect loss within 3 s (heartbeat on `/health` + failed calls), show a red status bar and block posting. The current cart is saved locally as a draft with its idempotency key; on reconnect the cashier retries and the host posts it once. A paper emergency procedure (pre-numbered manual book) covers long outages; those sales are entered later as back-dated sales with manager approval.

## Consequences
No sales during a host outage without the paper book, so a UPS on the host and the spare-host procedure are mandatory launch items.
