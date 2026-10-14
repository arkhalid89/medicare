# ADR-007: Double-entry journal backbone and moving-average cost
Status: accepted   Date: 2026-10-14   Deciders: owner (gates G3/G4/G6), architect

## Context
BRD s7.4 demands correct gross and operating profit and no double counting; s9 lists journal tables as optional. The concept (ADR-001) makes them core.

## Options
Single-entry party balances + cashbook (simple, but double counting is likely); **double-entry journal for every financial posting**. Costing: FIFO per batch; **moving weighted average per medicine** (BRD-recommended); standard cost.

## Decision
Every posting writes a balanced journal entry (posting table in [data-model.md](../data-model.md)). Ledgers, cashbook, bankbook, outstanding and profit reports are queries over `journal_lines` (+ party). Moving-average cost per medicine at base-unit level, updated on purchase post, purchase return, and positive adjustments at current average. Free units, discounts and landed charges are spread over received base units (policy setting "landed cost into inventory": on). COGS is recorded on each sale line at the average at that moment; returns reverse at the line's recorded cost.

## Consequences
Reports always tie out, and the self-check verifies balance. Batch purchase rate is kept for reference and supplier returns, but COGS uses the average. An accountant can read the books, but no general journal UI is exposed in release 1 (only system postings).
