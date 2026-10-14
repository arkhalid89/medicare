# Testing strategy and acceptance

## Levels
| Level | Tooling | Runs | Scope |
|---|---|---|---|
| Unit (Core) | xUnit, **both net48 and net10** | every slice | Money, pricing, tax, discounts, FEFO, unit conversion, GS1, costing |
| Domain | xUnit + temp SQLite file (WAL on) | every slice | Posting rules, journal balance, stock ledger, approvals, numbering, triggers |
| Host/API | `WebApplicationFactory` + real SQLite | every slice | Auth, permissions on every endpoint, idempotency, error codes |
| Report snapshots | Verify | S21+ | Each report against a seeded dataset |
| Load / concurrency | `tests/MediCare.LoadTest` | S10 (mini), S25 (full) | N simulated counters + purchases + reports |
| Crash / power loss | process kill + VM hard reset | S03, S25 | Durability (NFR-007) |
| UI smoke | manual scripted checklist on Win7 SP1 VM and Win11 VM | each phase checkpoint | Flows F1-F8, DPI, keyboard |
| Install/upgrade | clean VMs | S24, each release | Host and client installers, N-1 → N upgrade |
| Restore drill | real backup | S23, S25, then monthly in production | NFR-008 |

Run only the slice's tests while building a slice; run everything at phase checkpoints ([runbook.md](runbook.md)).

## Calculation vectors (FR-064, ADR-006)
Rounding is half away from zero to 2 dp for money and 4 dp for unit cost.

| # | Input | Expected |
|---|---|---|
| P1 | Lines 68.00, 412.00, 420.00, 74.00, 165.00; invoice discount 39.00; tax 0 | Allocation 2.33, 14.11, 14.38, 2.53, 5.65 (sum 39.00); net 1,100.00 |
| P2 | 1 × 100.00, line disc 10 %, tax 18 % exclusive | taxable 90.00, tax 16.20, net 106.20 |
| P3 | 1 × 118.00, tax 18 % inclusive | taxable 100.00, tax 18.00, net 118.00 |
| P4 | 1 × 50.00, tax 18 % inclusive | taxable 42.37, tax 7.63 (= net − taxable), net 50.00 |
| P5 | 3 × 33.33, line disc 10 % | gross 99.99, disc 10.00, net 89.99 |
| P6 | Strip of 10 sold at 34.00; sell 1 strip + 3 loose tablets (loose 3.40 each) | base qty 13, amount 44.20 |
| P7 | Cash tender 1,500.00 on net 1,100.00 | change 400.00 |
| P8 | Split 800.00 cash + 299.00 card on 1,100.00 | Complete disabled, remaining 1.00 |

## Costing worked example (FR-042, FR-090, ADR-007)
Medicine: Risek 20 mg, box = 5 strips × 14 capsules = 70 base units.
1. Purchase: 20 boxes @ 1,754.00, 2 free boxes, discount 2 %, freight 350.00 (single-line invoice).
   - Line net = 35,080.00 − 701.60 = 34,378.40; with freight 34,728.40; base units = 22 × 70 = 1,540.
   - Unit cost = 34,728.40 / 1,540 = **22.5509**; avg cost 22.5509; qty 1,540.
2. Sale: 1 strip (14 caps) → COGS **315.71**.
3. Purchase: 10 boxes @ 1,800.00 (700 caps, unit 25.7143).
   - Value on hand 1,526 × 22.5509 = 34,412.6734; + 18,000.00 → 52,412.6734 / 2,226 = avg **23.5457**.
4. Sale: 2 strips (28 caps) → COGS **659.28**.
5. Return of the step-4 sale → COGS reversed at **659.28** (recorded line cost, not the current average).

## GS1 parser vectors (FR-038, ADR-014)
`<GS>` = ASCII 29. GTIN-14 `08964000202029` (EAN-13 `8964000202029`, valid check digit).

| # | Raw scan | Expected |
|---|---|---|
| G1 | `010896400020202917270831` + `10RK0925` + `<GS>` + `21A7Q2K9` | GTIN 08964000202029, expiry 2027-08-31, batch RK0925, serial A7Q2K9 |
| G2 | `]d2` + G1 | same as G1 |
| G3 | `(01)08964000202029(17)270800(10)RK0925` | expiry 2027-08-31 (day 00 = last day of month) |
| G4 | `0108964000202029` + `10RK0925` + `<GS>` + `17270831` + `21X1` | batch RK0925 (ends at GS), expiry 2027-08-31, serial X1 |
| G5 | `0108964000202029` + `11250901` + `17270831` + `10RK0925` | mfg 2025-09-01, batch RK0925 |
| G6 | `8964000202029` | not GS1 → treated as 1D barcode lookup |
| G7 | `0108964000202028172708311` + `0RK0925` (wrong check digit) | error `INVALID_GTIN` |
| G8 | `01089640002020291727133110AB` (month 13) | error `INVALID_EXPIRY` |

Other valid test barcodes: EAN-13 `8964000101018`, `8964000303030`.

## Report catalogue and reconciliation checks (FR-096, FR-097)
| # | Report | Reconciles to |
|---|---|---|
| 1 | Daily sales | Σ sales_headers.net for the business date |
| 2 | Sales summary (day/week/month/custom) | Report 1 summed |
| 3 | Item-wise sales | Σ sales_items − returns per medicine |
| 4 | Profit (net sales, COGS, gross, expenses, operating) | Journal 4000/4010/4020/5000/6xxx |
| 5 | Purchase report | Σ posted purchase net |
| 6 | Purchase returns | Σ purchase_return amount |
| 7 | Sales returns | Σ sales_return refund |
| 8 | Current stock | Σ stock_balances |
| 9 | Stock valuation (moving average) | Journal 1200 balance (± rounding account 5020) |
| 10 | Batch/expiry | batches + balances |
| 11 | Near-expiry | Report 10 filtered by window |
| 12 | Expired/quarantine | Journal 1210 + expired saleable |
| 13 | Low stock | medicines below min |
| 14 | Stock movement | Σ movements = change in balance over period |
| 15 | Customer ledger | Journal 1100 by party |
| 16 | Customer outstanding | Report 15 closing balances |
| 17 | Supplier ledger | Journal 2000 by party |
| 18 | Supplier outstanding | Report 17 closing balances |
| 19 | Cashbook | Journal 1000 |
| 20 | Bankbook | Journal 1010 children |
| 21 | Expense report | Journal 6xxx |
| 22 | Cashier closing | shifts |
| 23 | Discount report (invoice, cashier, approver) | Journal 4020 |
| 24 | Audit report | audit_logs (chain verified) |
| 25 | Backup report | backup_history |
| 26 | Controlled register (Schedule B/D) | movements of scheduled medicines |

## Load and concurrency test (NFR-002, NFR-003, NFR-005)
- Dataset: 20,000 medicines, 60,000 batches, 3 years of history (about 1.1 M invoices, 3.5 M lines).
- Actors: 10 counter bots (3 sales/min each, 1-8 lines, 30 % 2D scans, 5 % returns, 10 % credit), 1 goods-in bot (1 invoice/5 min, 30 lines), 1 report bot (daily sales every 2 min, monthly profit every 10 min), 1 hourly backup.
- Duration 60 min. Pass: p95 lookup <= 300 ms, p95 post <= 500 ms, zero 5xx, zero duplicate numbers, zero negative balances, self-check green, no posting blocked > 1 s during backup.
- Also: 20 identical concurrent posts with one idempotency key → exactly one invoice.

## Restore drill (FR-105, NFR-008)
1. Note the latest invoice number; take a USB backup (T0); post 5 more sales.
2. Restore the T0 backup as admin. Time it from "Restore" click to the first successful login.
3. Expect: safety backup created; integrity and FK checks ok; the 5 later sales absent; banner shown on clients; audit entry for the restore; elapsed time <= 60 min (target < 15 min for a 1 GB DB).

## Security checklist (NFR-009)
- [ ] Every endpoint has a permission (test); forbidden calls are audited
- [ ] TLS 1.2+ only; certificate pinned; mismatch blocks
- [ ] PBKDF2 600k; lockout works; no default users
- [ ] No passwords, PINs or tokens in logs (grep the log folder after the test run)
- [ ] Data folder ACL: service account + Administrators only
- [ ] Firewall rule restricted to the LAN subnet; no other listening ports
- [ ] Posted-row triggers block UPDATE/DELETE; audit chain verified
- [ ] Backups contain no secrets beyond the DB itself; USB guidance in the admin manual

## UI smoke checklist (per phase checkpoint, Win7 SP1 + Win11)
Flows F1-F8 from [flows.md](../02-design/flows.md), keyboard only; every screen at 1366×768 @100 % and 1920×1080 @150 %; dark theme and compact density on the POS; receipt print on the 80 mm and 58 mm drivers with the Urdu lines.

## Acceptance process
1. The slice's acceptance checklist is ticked in its file, and tests pass.
2. At each phase checkpoint the owner (or delegate) runs the UI smoke checklist and records the result in `test-results.md`.
3. UAT: a 1-week shadow run at the pharmacy on 2 counters in parallel with the old process; daily reconciliation of totals; the owner signs the UAT sheet.
