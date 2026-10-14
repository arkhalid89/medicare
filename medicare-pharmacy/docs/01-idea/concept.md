# Concept (G3)

Inputs: [brief](brief.md), [research summary](research/summary.md). The owner's G2 emphasis: single-truth multi-counter, correct money and cash control, GS1 2D scanning. All BRD scope stays in release 1; the concept decides emphasis, wedge and architecture.

## 1. Three candidate concepts

### C1 - "Rush-Hour Counter"
- **Promise:** the fastest pharmacy counter in Pakistan: scan, pick the right batch, take payment in under 10 seconds.
- **Wedge:** GS1 DataMatrix scan resolves the exact batch; FEFO auto-pick; keyboard-only POS; held sales.
- **Does not:** invest beyond BRD minimum in accounting; profit is "estimated".
- **Risk:** owner still cannot trust cash and profit numbers. Test: time 50 real bills on the prototype.

### C2 - "Owner's Ledger"
- **Promise:** the owner sees true cash, true dues and true gross profit every night.
- **Wedge:** every posting writes a balanced double-entry journal; moving-average COGS; cashier closing with approved differences; ledgers derived, never typed.
- **Does not:** optimise the counter beyond BRD; manual batch entry.
- **Risk:** cashiers find it slow and revert to paper. Test: cashier usability pilot.

### C3 - "One Truth" (batch-true stock, cash-true books)  **← recommended**
- **Promise:** one authoritative record for every tablet and every rupee across all counters, captured at counter speed.
- **Wedge:**
  1. **Central single-writer service.** Each posting is an idempotent command; no sync and no orphan sales; when the host is down, counters stop rather than fork the data.
  2. **Double-entry journal behind every document.** Cashbook, ledgers and profit are views of the same journal, so double counting cannot happen.
  3. **Batch truth by scan.** GS1 2D codes fill batch and expiry at goods-in and pick the exact batch at POS; FEFO is the fallback for 1D codes.
  4. **Inline approvals and audit.** A manager PIN on the cashier's screen; every override is a recorded approval.
  5. **Nightly self-check.** Stock ledger vs balances, journal balanced, backup verified; shown on the dashboard.
- **Why defensible:** competitors are built on "local DB + cloud sync" and single-entry billing. Retrofitting a central single-writer and a double-entry core is a rewrite for them. The DRAP 2D-code roll-out favours whoever parses it first.
- **Does not:** cloud, sync, FBR, Urdu UI, multi-branch.
- **Risk:** largest build. Mitigation: walking skeleton proves the posting core (S00-S02) before breadth; load test gate.

## 2. Scorecard (1-5, weighted)
| Criterion | Weight | C1 | C2 | C3 |
|---|---|---|---|---|
| Strength of unmet need | 25 % | 4 | 4 | 5 |
| Differentiation and defensibility | 20 % | 3 | 3 | 5 |
| Fit with context (owner emphasis, LAN, Win7) | 20 % | 3 | 3 | 5 |
| Feasibility for timeline | 15 % | 5 | 4 | 3 |
| Impact (adoption, loss reduction) | 15 % | 4 | 4 | 5 |
| Risk (inverted) | 5 % | 4 | 4 | 3 |
| **Weighted** | | **3.75** | **3.60** | **4.60** |

**Recommendation: C3.** It is the only concept that serves all three gaps the owner chose, and the BRD already demands most of its machinery (atomic posting, immutable movements, approvals, audit). C3 makes these the core rather than add-ons. **What would change this:** a hard go-live date within about 8 weeks. Then C1 ships first, and C2's ledger follows in release 1.1.

## 3. Placement
- **Positioning map:** see [competitors](research/competitors.md). C3 sits alone in the "strict data integrity x deep accounting x offline LAN x Pakistan" corner.
- **Positioning statement:** For a busy Pakistani pharmacy with many counters that must know every batch and every rupee, MediCare Pharmacy is an offline LAN pharmacy system that keeps one authoritative record of stock and money and captures it at scan speed. Unlike local POS tools that sync later and track money single-entry, it posts every sale atomically to a central service with double-entry books, so stock, cash and profit always reconcile.
- **Release 1 is:** the full BRD scope on the C3 core, plus GS1 2D scanning, plus a light Schedule B/D register.
- **Release 1 is not:** cloud, sync, FBR integration, Urdu UI, multi-branch, mobile app, supplier portals.

## 4. Success metrics
| Metric | Baseline | Target (90 days after go-live) | How measured |
|---|---|---|---|
| p95 scan-to-line latency | n/a | <= 300 ms LAN, <= 1 s worst | Client telemetry log (local) |
| Expired units sold | unknown | 0 | Sales lines vs batch expiry |
| Nightly reconciliation failures | n/a | 0 | Self-check table |
| Cash difference days without approval | unknown | 0 | Cashier closing |
| Days with verified backup | unknown | 100 % | backup_history |
| Lines received by 2D scan | 0 | >= 50 % of eligible packs | purchase_items.capture_method |

## 5. Carried into later phases
- Architecture tier and stack: [architecture](../03-planning/architecture.md) (summary below, decided at G3).
- Walking skeleton (S00): service + one client on both runtimes, login, one medicine, one posted sale with journal, backup.
- First value slice: POS posting core with FEFO, idempotency and journal (S03-S05).

### Architecture recommendation (for G3 veto)
| Area | Decision | Why |
|---|---|---|
| Tier | **Standard, on-prem** (one org, two deployables: host service + client) | Roles, background jobs, approvals and audit need more than Lean; there is no tenancy, so Platform is overkill |
| Server | .NET 10 LTS Windows service, ASP.NET Core minimal API on Kestrel, HTTPS/1.1 + JSON | LTS until Nov 2028; JSON over HTTP/1.1 is the only protocol both runtimes speak natively |
| Database | SQLite 3.51.3+ via Microsoft.Data.Sqlite, WAL, `synchronous=FULL`, one writer queue, read pool | BRD mandate; capacity has 100x headroom; FULL survives power cuts |
| Clients | WinForms, one codebase multi-targeted `net48` + `net10.0-windows` | Owner needs Win7 and Win10/11 |
| Contracts | `netstandard2.0` DTO library shared by both sides | Compile-time agreement on the API |
| Money | `decimal` in code; stored as INTEGER minor units (paisa), unit costs at 4 dp | No float anywhere; exact sums in SQL |
| Upgrade path | If load test fails at 10 counters: swap SQLite for PostgreSQL 17 on the same host behind the same service (data layer isolated) | BRD s18 fallback without touching clients |
