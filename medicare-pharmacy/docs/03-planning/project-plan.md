# Project plan

Builder: Claude Code driven by the owner (or the owner's developer) one slice at a time, using [runbook.md](../04-development/runbook.md). Estimates assume one person reviewing and testing on real Windows machines, with VMs for Windows 7 SP1 and Windows 11.

## Phases and exit criteria
| Phase | Slices | Exit criterion |
|---|---|---|
| A. Foundations | S00-S03 | Both client builds pair with the host over TLS; login and permissions work; posting core passes concurrency, idempotency and crash tests; Win7 and Urdu-print spikes pass |
| B. Core value path | S04-S10 | A real sale can be scanned (1D and 2D), paid, posted once and printed with Urdu; goods-in creates batches and payables; mini load test passes |
| C. Control and money | S11-S19 | Approvals, returns, reversals, ledgers, cashbook, expenses and closing tie out in the self-check |
| D. Visibility and safety | S20-S23 | Dashboard and 25 reports reconcile; backups and the restore drill meet RPO/RTO |
| E. Ship | S24-S26 | Installers on clean VMs; 1-hour 10-counter load test; a11y/DPI/security checks; manuals; UAT sign-off |

## Milestones
| # | Milestone | After | Target (from build start) |
|---|---|---|---|
| M1 | Walking skeleton on Win7 + Win11 | S00-S01 | week 2 |
| M2 | First posted sale, printed | S10 | week 7 |
| M3 | Books balance end-to-end (self-check green) | S19 | week 12 |
| M4 | Feature complete + backup/restore | S23 | week 15 |
| M5 | UAT at pharmacy (1 week shadow run beside the old process) | S25 | week 17 |
| M6 | Go-live | S26 | week 18 |

## Slice overview
| ID | Name | Requirements | Depends on | Size |
|---|---|---|---|---|
| S00 | Walking skeleton | FR-012, NFR-010, NFR-016, NFR-017 | - | M |
| S01 | UI kit and shell | (enabling) | S00 | M |
| S02 | Auth, users, permissions | FR-001-008, FR-011, NFR-009 | S01 | M |
| S03 | Posting core, journal, audit | FR-085, FR-102, NFR-006, NFR-007, NFR-013 | S02 | L |
| S04 | Medicine master | FR-020-024, FR-027, FR-028, FR-108 | S03 | M |
| S05 | Medicine import | FR-026 | S04 | M |
| S06 | Suppliers, customers, opening balances | FR-050, FR-055, FR-056 | S03 | S |
| S07 | Goods-in | FR-030, FR-031, FR-038, FR-040-044, FR-046, FR-090 | S04, S06 | L |
| S08 | Stock views and ledger | FR-025, FR-032-036 | S07 | M |
| S09 | POS cart and FEFO | FR-060-062, FR-064, FR-069, FR-072, NFR-002 | S08 | L |
| S10 | Payment, posting, printing, host-down | FR-013, FR-065, FR-066, FR-068, FR-107, NFR-003 | S09 | L |
| S11 | Approvals | FR-009, FR-010, FR-063 | S10 | M |
| S12 | Holds, history, prescriptions | FR-067, FR-070, FR-071 | S10 | M |
| S13 | Customer ledger and receipts | FR-057, FR-058 | S10 | M |
| S14 | Sales returns | FR-075-077 | S10, S11 | M |
| S15 | Purchase returns and reversals | FR-045, FR-047, FR-048, FR-091 | S07, S11 | M |
| S16 | Supplier ledger and payments | FR-051, FR-052 | S07 | M |
| S17 | Adjustments, counts, write-off | FR-080-082 | S08, S11 | M |
| S18 | Cashbook, bankbook, expenses | FR-086, FR-087 | S10 | M |
| S19 | Shift and day closing | FR-088, FR-089 | S18, S11 | M |
| S20 | Dashboard and self-check | FR-037, FR-095, FR-106 | S10, S18 | M |
| S21 | Reports | FR-096, FR-097, NFR-004 | S20 | L |
| S22 | Audit viewer and settings | FR-100, FR-101 | S03 | M |
| S23 | Backup and restore | FR-103-105, NFR-008 | S03, S22 | L |
| S24 | Installer and host monitor | FR-109, NFR-001, NFR-014 | S23 | L |
| S25 | Hardening: load, a11y, security | NFR-005, NFR-011, NFR-012 | S24 | L |
| S26 | Launch pack | NFR-015 | S25 | M |

**Critical path:** S00 → S01 → S02 → S03 → S04 → S07 → S08 → S09 → S10 → S11 → S14/S19 → S20 → S21 → S23 → S24 → S25 → S26. S05, S06, S12, S13, S15-S18 and S22 can run in parallel with the path once their dependencies are done.

## Effort estimate (ranges, assumptions stated)
| Size | Agent build time | Owner/dev review + Windows testing |
|---|---|---|
| S | 0.5 day | 0.5 day |
| M | 1-1.5 days | 1 day |
| L | 2-3 days | 1.5-2 days |

27 slices (1 S, 18 M, 8 L) ≈ 65-86 working days including review, plus 1 week UAT and 1 week buffer → **about 15-19 weeks** calendar. Assumptions: VMs available from week 1; owner answers open inputs within 2 working days; no change of the database decision at S25.

## Risks and mitigations
| Risk | Mitigation | Owner |
|---|---|---|
| SQLite throughput or locking at 10+ counters | Single write queue; mini load test in S10; full test in S25; PostgreSQL fallback (ADR-005) | Build |
| Windows 7 runtime surprises (TLS, fonts, DPI) | S00 spike on a real Win7 SP1 VM; smoke each release | Build |
| Urdu shaping on receipts | S00 spike; Naskh default | Build |
| Import data quality | Validation, dry run, undo; owner review of error file | Owner |
| Staff resist the change | Keyboard-first POS like Marg; 1-week shadow run; champion cashier | Owner |
| Host failure on go-live day | UPS, spare-host drill, paper emergency book | Owner |
| Scope creep (FBR, SMS) | Out of scope by brief; new requests go to a release-2 list | Owner |

## RACI
| Activity | Owner (Rehan) | Pharmacist (Sana) | Builder (Claude Code + dev) | Local IT helper |
|---|---|---|---|---|
| Gate approvals G6/G7, UAT sign-off | A/R | C | I | I |
| Medicine import file, settings values | A | R | C | I |
| Slice build and tests | I | I | R/A | - |
| Hardware, network, UPS, printers, scanners | A | I | C | R |
| Training and shadow run | A | R | C | I |
| Backup drills after go-live | A | R | C | R |
