# MediCare Pharmacy - docs index

Track: non-monetized internal system (single pharmacy). Process: saas-product-architect, phases 0-5 with gates G1-G7.

## Phase status
| Phase | Output | Gate | Status |
|---|---|---|---|
| 0 Discovery | [brief](01-idea/brief.md) | G1 | approved 2026-10-14 |
| 1 Research | [summary](01-idea/research/summary.md) | G2 | approved 2026-10-14 |
| 2 Concept | [concept](01-idea/concept.md), [personas](01-idea/personas.md) | G3 | approved 2026-10-14 |
| 3 Design | [flows](02-design/flows.md), [design system](02-design/design-system.md), [prototype](02-design/prototype/index.html) | G4, G5 | approved 2026-10-14 |
| 4 Planning | [PRD](03-planning/prd.md), [architecture](03-planning/architecture.md), [data model](03-planning/data-model.md), [ADRs](03-planning/adr/), [plan](03-planning/project-plan.md) | G6 | approved 2026-10-14 |
| 5 Handoff | [runbook](04-development/runbook.md), [conventions](04-development/conventions.md), [testing](04-development/testing.md), [deployment](05-production/deployment.md), [launch checklist](05-production/launch-checklist.md), [rollout](05-production/adoption-and-rollout.md), [compliance](05-production/compliance.md), [user manual](05-production/user-manual.md), [CLAUDE.md](../CLAUDE.md) | G7 | built; awaiting owner review |

## Decision log
| Date | Decision | By | Where |
|---|---|---|---|
| 2026-10-14 | Project lives in new folder `medicare-pharmacy/`; existing PHP app untouched | Owner | - |
| 2026-10-14 | Track: single pharmacy, internal | Owner | brief |
| 2026-10-14 | Scale: 7-10+ PCs, 1,000+ invoices/day | Owner | brief |
| 2026-10-14 | Urdu on receipts only; English UI | Owner | brief |
| 2026-10-14 | Import medicine list from Excel/CSV at go-live | Owner | brief |
| 2026-10-14 | Clients for Windows 7 and Windows 10/11 | Owner | brief |
| 2026-10-14 | Name: MediCare Pharmacy | Owner | brief |
| 2026-10-14 | Design: three directions to choose from at G4 | Owner | - |
| 2026-10-14 | G1 brief approved incl. assumptions A1-A6 | Owner | brief |
| 2026-10-14 | G2: emphasis on single truth, correct money, GS1 2D scan; Schedule B/D register at Should level | Owner | research/summary |
| 2026-10-14 | G3: concept C3 "One Truth" and Standard on-prem architecture approved | Owner | concept, ADR-001 |
| 2026-10-14 | G4: design direction A (Clear Dispensary) + C keycap strip + Compact density | Owner | design-system, ADR-010 |
| 2026-10-14 | G5: flows, design system and prototype approved | Owner | 02-design |
| 2026-10-14 | G6: planning pack approved as is | Owner | 03-planning |
| 2026-10-14 | Build the complete application now (host, both clients, installers, docs) | Owner | runbook |
| 2026-10-14 | Added `POST /sales/quote` so the POS shows host-computed prices (same code as posting) | Build | SalesModule, S09 |
