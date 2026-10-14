# ADR-001: Concept C3 "One Truth"
Status: accepted   Date: 2026-10-14   Deciders: owner (gates G3/G4/G6), architect

## Context
The owner chose single-truth multi-counter, correct money and GS1 2D scanning as emphasis at G2. Three concepts were scored in [concept.md](../../01-idea/concept.md).

## Options
C1 Rush-Hour Counter (3.75); C2 Owner's Ledger (3.60); C3 One Truth (4.60).

## Decision
Build C3: a central single-writer service; a double-entry journal behind every document; batch truth by GS1 scan with FEFO as fallback; inline approvals; nightly self-check. All BRD scope stays in release 1.

## Consequences
Largest build of the three, so the posting core is proven first (S02, S08-S09) and a load test gates release (S24). Retrofitting C1/C2 later would have meant rewriting the posting model, so the cost is paid up front.
