# ADR-006: Money, cost and quantity representation
Status: accepted   Date: 2026-10-14   Deciders: owner (gates G3/G4/G6), architect

## Context
BRD s7 requires `decimal` and no binary floating point. SQLite has no decimal type. Pack conversions must be exact.

## Options
TEXT decimals; REAL (rejected: float); **INTEGER minor units**.

## Decision
Code: `decimal` wrapped in `Money` (2 dp, PKR) and `UnitCost` (4 dp) value types in `MediCare.Core`. DB: money as INTEGER paisa (`*_paisa`), unit costs and rates per base unit as INTEGER x10,000 (`*_e4`), percentages as basis points. Quantities are INTEGER base units; packs have an integer `base_qty`. Rounding: half away from zero at line level; invoice-level discount is allocated to lines pro-rata, and the remainder goes to the largest line. Currency precision setting fixed at 2 for PKR in release 1.

## Consequences
SQL `SUM` is exact and fast. An analyzer bans `float`/`double` in Core/Domain/Data. Loose-unit sales of liquids (ml) are out of scope: bottles are whole units.
