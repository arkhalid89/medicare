# PRD - MediCare Pharmacy (release 1)

Inputs: [brief](../01-idea/brief.md), [concept C3](../01-idea/concept.md), [personas and roles](../01-idea/personas.md), [flows and screens](../02-design/flows.md). Business rules follow the owner's BRD sections in brackets, e.g. [6.8].

## Goals
1. One authoritative record of stock and money across 7-10+ LAN counters, with no sync and no orphan sales.
2. Counter speed: scan to line within 1 s worst case, keyboard only.
3. Correct money: double-entry books, moving-average COGS, gross vs operating profit, per-cashier closing.
4. Batch truth: FEFO, expired hard block, GS1 2D capture of batch and expiry.
5. Control and recovery: approvals, audit, verified backups, tested restore.

## Non-goals (release 1)
Cloud, sync, multi-branch, FBR integration, SMS/WhatsApp, online payments, supplier portals, Urdu UI, mobile app, hospital modules.

## Glossary
| Term | Meaning |
|---|---|
| Base unit | Smallest dispensing unit (tablet, capsule, ml-bottle as 1); all stock is integer base units |
| Pack level | Box / strip / piece etc. with a fixed base-unit multiplier per medicine |
| FEFO | First-expiry-first-out batch selection |
| Saleable / quarantine | Stock states; only saleable, unexpired stock can be sold |
| Posting | Making a document final: number assigned, stock and journal written atomically; never edited after |
| Reversal | A new document that cancels a posted one, with approval and reason |
| Host | The Windows PC running the MediCare service and the SQLite database |
| Approval | A recorded authorisation by a permitted user (PIN at the counter, or from the inbox) |
| GS1 2D | DataMatrix carrying AIs (01) GTIN, (17) expiry, (10) batch, (21) serial, (11) mfg date |

## Functional requirements

### Access, users, session
| ID | Requirement | Priority | Persona | Acceptance criteria | Screen |
|---|---|---|---|---|---|
| FR-001 | Log in with username and password; both mandatory; only active users [6.1] | Must | all | Given an inactive user, when they log in with correct password, then login is refused with "This account is inactive". Empty fields show a field error and do not call the server | SCR-01 |
| FR-002 | Lock account after N failed attempts (default 5) for M minutes (default 15); admin can unlock [6.1] | Must | all | Given 5 wrong passwords within 15 min, the 6th correct attempt is refused until the lock expires or admin unlocks; each failure is audited | SCR-01 |
| FR-003 | Clear error when the host is unreachable at login; never open an empty workspace [6.1] | Must | all | With the service stopped, login shows the host-unreachable message with address and Retry; no menus are shown | SCR-01 |
| FR-004 | Session auto-lock after per-user inactivity (default 5 min cashier, 10 min others); unlock with password; open cart kept [4] | Must | all | After the timeout the lock screen appears; the cart is unchanged after unlock; another user may switch user (cart is held under the first user) | SCR-02 |
| FR-005 | Explicit logout revokes the server session [12] | Must | all | After logout, a request with the old token returns 401 | SCR-03 |
| FR-006 | Users: create, edit, deactivate (no delete); role; per-user permission overrides; session timeout; last login [6.15] | Must | admin | Deactivated users cannot log in; their history remains; changes are audited with before/after | SCR-29 |
| FR-007 | Password change by user; admin reset forces change at next login; password policy (min length 10, not equal to username, last 3 not reused); separate 4-6 digit approval PIN [4, 12] | Must | all | A reset user must set a new password before any other screen; policy violations show the rule broken | SCR-29 |
| FR-008 | Role-permission model enforced by the host on every endpoint; UI hides unavailable actions [4, 12] | Must | admin | Calling a forbidden endpoint directly returns 403 `PERMISSION_DENIED` and an audit entry; menu omits the screen | SCR-29 |
| FR-009 | Inline approval on the requesting PC by approver name + PIN + reason for: discount over limit, refund over threshold, credit over limit, stock adjustment over threshold, reversal, cash difference, back-dated posting [4] | Must | manager | The approval token is single-use, bound to the request hash, expires in 5 min; approver must hold the permission; requester and approver are both audited | SCR-07 |
| FR-010 | Approvals inbox for deferred requests with approve/reject and reason; menu badge count [4] | Should | manager | Approving notifies the requesting PC within 5 s (event long-poll); rejected requests cannot be posted | SCR-24 |
| FR-011 | First-run setup: pharmacy profile and first administrator; no default credentials anywhere [12, 15] | Must | admin | A fresh install has zero users until setup completes; setup cannot be rerun once an admin exists | SCR-34 |
| FR-012 | Client pairing: host address and port, show certificate SHA-256 fingerprint, admin confirms; fingerprint pinned [10, 12] | Must | admin | A changed host certificate blocks connection with a mismatch error until an admin re-pairs | SCR-33 |
| FR-013 | Connection status on every screen; host-down overlay blocks posting; auto-reconnect every 5 s; drafts kept locally and posted once after reconnect [10.8-10.10] | Must | all | Pulling the host's network cable: within 3 s the status bar is red and F4 shows the overlay; reconnect and Retry posts exactly one invoice | SCR-33 |

### Medicines and master data
| ID | Requirement | Priority | Persona | Acceptance criteria | Screen |
|---|---|---|---|---|---|
| FR-020 | Medicine master with BRD fields; auto ID; add, edit, view, deactivate; inactive kept in history [6.3] | Must | manager | Deactivating keeps past documents intact; inactive medicines are excluded from POS search | SCR-11, SCR-12 |
| FR-021 | Multiple barcodes per medicine (EAN-13, UPC, GTIN-14, internal), each linked to a pack level; globally unique [6.3] | Must | manager | Saving a barcode already used shows the owning medicine and is refused | SCR-12 |
| FR-022 | Search by name, generic, brand, manufacturer, barcode; prefix and contains; results show stock and price [6.3, 6.8] | Must | cashier | p95 <= 300 ms on 20,000 medicines (see NFR-002) | SCR-11, SCR-04 |
| FR-023 | Pack hierarchy with integer base-unit multipliers (e.g. box 10 strip, strip 10 tablet); choose purchase unit and sellable levels; conversions consistent everywhere [6.3, 6.16] | Must | manager | Selling 1 strip of a 10-tab strip reduces 10 base units; a box received of 100 base units shows as 10 strips | SCR-12 |
| FR-024 | Configurable lists: categories, dosage forms, units, manufacturers, expense categories, payment accounts [6.3, 6.16] | Must | admin | Items in use cannot be deleted, only deactivated | SCR-32 |
| FR-025 | Show current stock and batch availability on medicine views [6.3] | Must | all | Totals equal the sum of saleable batch balances | SCR-11, SCR-14 |
| FR-026 | Import medicine list from Excel (.xlsx) or CSV: column mapping, validation, duplicate detection (barcode, name+strength+manufacturer), dry run, one-transaction commit, error file, undo until first stock movement | Must | admin | 5,000 rows validate in < 30 s; errors never partially import; import job audited | SCR-13 |
| FR-027 | Price and master-data changes audited; posted documents keep snapshot prices, tax rates, discounts and conversions [6.16] | Must | manager | Changing a price does not change any posted invoice or report for past dates | SCR-12 |
| FR-028 | Flags: prescription required; drug schedule (none, B, D, G); licence-type rule blocks Schedule G for medical-store licence | Should | manager | A Schedule B sale requires prescriber and patient (FR-070) | SCR-12 |
| FR-108 | Optional Urdu name per medicine for receipts | Should | manager | Receipt prints the Urdu line under the English name when set | SCR-12 |

### Batches and stock
| ID | Requirement | Priority | Persona | Acceptance criteria | Screen |
|---|---|---|---|---|---|
| FR-030 | Batch per medicine with number, supplier, purchase ref, mfg date, expiry, received, available, purchase rate per base unit, sale price, batch MRP, rack [6.4] | Must | stock op. | Same batch number on a later invoice adds to that batch only if expiry matches, else it is refused with a message | SCR-14, SCR-15 |
| FR-031 | Batch number and expiry mandatory per policy setting; expiry >= mfg date [6.4] | Must | stock op. | Validation errors name the line and field | SCR-15 |
| FR-032 | Stock states saleable / quarantine; expired is derived from date; only saleable unexpired stock is sellable [6.4, 6.9] | Must | manager | At 00:00 on the expiry day the batch drops out of POS | SCR-14 |
| FR-033 | Near-expiry alerts by configurable window (default 90 days) [6.4] | Must | manager | Dashboard count and list match the batch report | SCR-03, SCR-14 |
| FR-034 | Low-stock alert vs minimum stock [6.2] | Must | manager | Dashboard count = medicines with saleable stock < minimum | SCR-03, SCR-14 |
| FR-035 | Immutable stock movement for every change, linked to its source document line; stock balances updated in the same transaction [6.4, 9] | Must | - | No code path updates balances without a movement (enforced by a single StockLedger service + nightly check) | SCR-14 |
| FR-036 | Negative stock prohibited [6.4] | Must | - | A posting that would go below zero fails with `INSUFFICIENT_STOCK` and nothing is written | SCR-14 |
| FR-037 | Self-check nightly and on demand: balances = sum(movements), journal balanced per entry, document totals = lines, backup verified; result shown on dashboard [9] | Must | admin | A deliberately corrupted balance in a test DB is reported with the batch ID | SCR-03 |
| FR-038 | Parse GS1 2D/GS1-128 scans from keyboard wedge (with GS separator or bracketed AIs; `]d2` prefix tolerated): AIs 01, 10, 17, 11, 21 | Must | cashier, stock op. | Test vectors in testing.md all parse; GTIN maps to a medicine barcode; at POS the exact batch is used when saleable | SCR-04, SCR-15 |

### Purchases and suppliers
| ID | Requirement | Priority | Persona | Acceptance criteria | Screen |
|---|---|---|---|---|---|
| FR-040 | Purchase invoice with header, lines and footer per BRD; save draft or post [6.7] | Must | stock op. | Drafts never affect stock, ledgers or reports | SCR-15 |
| FR-041 | Add lines by 2D scan (auto medicine, batch, expiry), 1D scan or search; repeat scan of same batch increments qty; "repeat last batch" | Must | stock op. | Scanning 3 boxes of one batch gives one line qty 3 | SCR-15 |
| FR-042 | Free qty, line discount, tax, additional charges; effective cost per base unit includes free units, discounts and landed charges per policy [6.7, 7.3] | Must | stock op. | Worked example in testing.md produces the expected unit cost to 4 dp | SCR-15 |
| FR-043 | Posting increases stock per batch, updates moving-average cost, creates supplier payable and journal [6.7] | Must | - | One transaction; failure leaves no partial rows | SCR-15 |
| FR-044 | Immediate payment on posting with cash/bank movement [6.7] | Must | stock op. | Payable reduced by the amount paid; cashbook shows one line | SCR-15 |
| FR-045 | Posted purchase is read-only; correction by reversal with approval or by purchase return [6.7] | Must | manager | Reversal blocked if any received units are already sold (message points to purchase return) | SCR-15 |
| FR-046 | Print purchase voucher (A4) [6.7] | Must | stock op. | Voucher shows invoice no., supplier, lines, totals, paid, outstanding | SCR-15 |
| FR-047 | Purchase return referencing original invoice and batch; qty <= eligible remaining and available; reduces payable or creates supplier credit; credit note ref [6.10] | Must | manager | Over-return refused with remaining qty | SCR-16 |
| FR-048 | Supplier cash refund recorded separately from the return [6.10] | Must | accountant | Refund increases cash and reduces supplier debit balance | SCR-16 |
| FR-050 | Supplier master per BRD; opening balance as an opening journal entry [6.5] | Must | accountant | Opening balance appears as a ledger line dated the go-live date | SCR-17 |
| FR-051 | Supplier ledger/statement, outstanding, purchase history, balance confirmation print [6.5] | Must | accountant | Statement closing balance = supplier outstanding report | SCR-18 |
| FR-052 | Supplier payment with allocation to invoices or advance; methods; voucher; optional balance enforcement [6.13] | Must | accountant | Allocation total cannot exceed payment; enforcement blocks overdraw | SCR-19 |

### Customers
| ID | Requirement | Priority | Persona | Acceptance criteria | Screen |
|---|---|---|---|---|---|
| FR-055 | Customer master; built-in Walk-in; types; credit limit; opening balance as journal [6.6] | Must | cashier | Walk-in cannot be edited or used for credit | SCR-20 |
| FR-056 | Search customer by name, ID or mobile [6.6] | Must | cashier | Mobile search ignores dashes and spaces | SCR-20 |
| FR-057 | Customer ledger with invoices, receipts, returns, adjustments, balance [6.6] | Must | accountant | Balance = opening + credit invoices − receipts − credit notes ± adjustments [7.2] | SCR-21 |
| FR-058 | Customer receipt with allocation to invoices or unallocated credit; no over-allocation; print [6.12] | Must | cashier | Allocation > receipt or > invoice balance is refused | SCR-22 |

### POS and sales
| ID | Requirement | Priority | Persona | Acceptance criteria | Screen |
|---|---|---|---|---|---|
| FR-060 | POS: always-focused scan field; add by scan or search; quantity entry (`*n`), unit choice, line delete; cashier and invoice context [6.8] | Must | cashier | After every action focus returns to scan field | SCR-04 |
| FR-061 | FEFO auto-pick across saleable batches, splitting a line across batches when needed; F7 explicit batch choice (logged) [6.8] | Must | cashier | With batches expiring Nov-26 (5 left) and May-27, selling 8 takes 5 + 3 | SCR-04, SCR-06 |
| FR-062 | Show batch, expiry, days left, rack and available for the selected line [6.8] | Must | cashier | Values match batch record | SCR-04 |
| FR-063 | Line and invoice discounts within role limit; above limit requires approval (FR-009) [6.8] | Must | cashier | 6 % by a 5 % cashier opens SCR-07; without approval the discount is not applied | SCR-04, SCR-07 |
| FR-064 | Tax per medicine rate, inclusive or exclusive mode; extracted correctly when inclusive; invoice-level discount allocated pro-rata to lines; no double count [7.1] | Must | - | Calculation test vectors in testing.md pass on client preview and server | SCR-04 |
| FR-065 | Payment: cash, card, wallet, bank, split, credit; split reconciles exactly; change due for cash; credit requires named customer and limit check [6.8, 7.2] | Must | cashier | Complete is disabled until tenders = net (or credit covers the rest) | SCR-05 |
| FR-066 | Post sale atomically with client idempotency key; re-check stock and prices at posting; double-click or retry never duplicates [6.8] | Must | cashier | 20 parallel identical requests create one invoice; others return the same invoice | SCR-05 |
| FR-067 | Hold and recall sales per counter, stored on host for 24 h; stock not reserved [6.8] | Must | cashier | Recall re-runs FEFO and prices | SCR-08 |
| FR-068 | Receipt printing 58/80 mm and A4; print failure never affects posting; reprint marked DUPLICATE and audited [6.8, 13] | Must | cashier | Printer offline: sale stays posted, error tells cashier to F8 | SCR-05, SCR-10 |
| FR-069 | Keyboard shortcuts F2-F9, Del, Esc, Enter; configurable; shown on the keycap strip [6.8] | Must | cashier | Remapping F6 updates the strip and works immediately | SCR-04 |
| FR-070 | Prescription capture (prescriber, registration no., patient, Rx no.) when a line requires it | Should | cashier | Posting a Schedule B line without prescriber is refused | SCR-04 |
| FR-071 | Invoice history search and reprint [13] | Must | cashier | Cashier sees own invoices only unless permitted | SCR-10 |
| FR-072 | Inactive medicines and expired or quarantined batches can never be sold [6.8] | Must | - | Server rejects with `BATCH_NOT_SALEABLE` even if the client is bypassed | SCR-04 |
| FR-091 | Reversal of posted sale or purchase via reversing document with approval and reason; original unchanged [4, 6.8] | Must | manager | Reversed sale restores stock and reverses journal; both documents visible | SCR-10 |

### Returns
| ID | Requirement | Priority | Persona | Acceptance criteria | Screen |
|---|---|---|---|---|---|
| FR-075 | Sales return against original invoice; qty <= sold − prior returns; reason; refund to cash, bank or customer credit; approval above threshold [6.9] | Must | manager | Over-return refused; refund linked to cashbook or customer ledger | SCR-09 |
| FR-076 | Inspection outcome per line: restock to original batch (if unexpired and approved) or quarantine [6.9] | Must | manager | Quarantined units never appear in POS | SCR-09 |
| FR-077 | Returns reverse COGS at the original sale cost [7.4] | Must | - | Profit report after a full return equals profit before the sale | SCR-09 |

### Stock adjustment
| ID | Requirement | Priority | Persona | Acceptance criteria | Screen |
|---|---|---|---|---|---|
| FR-080 | Adjustment with system qty, physical qty, auto difference, reason, notes; approval above value or % threshold; stock movement and inventory journal [6.11] | Must | stock op. | Reasons: damage, expiry, breakage, shortage, correction, stock count | SCR-23 |
| FR-081 | Stock count session by rack/category: freeze system qty at start, count sheet, post differences | Should | stock op. | Sales during the count are reflected in the posted difference (frozen qty + movements since) | SCR-23 |
| FR-082 | Move to quarantine and write off quarantine stock with approval | Must | manager | Write-off moves value from inventory to loss account | SCR-23 |

### Accounts and closing
| ID | Requirement | Priority | Persona | Acceptance criteria | Screen |
|---|---|---|---|---|---|
| FR-085 | Double-entry journal behind every financial posting; seeded chart of accounts; no manual edits of posted entries [7, 9] | Must | accountant | Every journal entry balances (debits = credits) | SCR-25 |
| FR-086 | Cash and bank accounts; cashbook and bankbook with running balance; cash in/out; transfers [6.14] | Must | accountant | Closing = opening + receipts − payments [7.2] | SCR-25 |
| FR-087 | Expense vouchers with category, account, reference, approval above threshold [6.14] | Must | accountant | Expenses reduce operating profit only | SCR-26 |
| FR-088 | Cashier shift open (float) and close: expected vs counted by denomination, difference with reason and approval [6.14] | Must | cashier | Expected = float + cash sales + cash receipts − cash refunds − paid-outs | SCR-27 |
| FR-089 | Day close locks the date; later postings dated in a closed day need approval (back-dated) [6.16] | Must | manager | Day cannot close with open shifts | SCR-27 |
| FR-090 | Moving weighted-average cost per medicine at base-unit level; COGS at sale [7.3, 7.4] | Must | - | Worked example in testing.md | SCR-15 |

### Dashboard, reports, admin
| ID | Requirement | Priority | Persona | Acceptance criteria | Screen |
|---|---|---|---|---|---|
| FR-095 | Dashboard cards and charts per BRD 6.2, filtered by role; estimated profit labelled [6.2] | Must | owner | All figures from posted data; same numbers as the matching reports | SCR-03 |
| FR-096 | 25 reports per BRD 8 with date filters, search, print A4, export CSV and xlsx; posted only; reversals and returns shown per policy [8] | Must | owner | Report list in testing.md each has a reconciliation check | SCR-28 |
| FR-097 | Controlled-drug register (Schedule B/D) report: serial, date, prescriber, patient, drug, manufacturer, qty, batch, dispensed by, purchased qty, balance | Should | manager | Register lines reconcile to stock movements of scheduled medicines | SCR-35 |
| FR-100 | Audit log of sensitive actions with time, user, workstation, action, record, before/after, reason, approver; viewer with filters and export; append-only [6.15] | Must | admin | No API exists to edit or delete audit rows; hash chain verified by self-check | SCR-30 |
| FR-101 | Settings per BRD 6.16 | Must | admin | Changes audited; clients pick them up within 30 s | SCR-32 |
| FR-102 | Document numbering formats per series; numbers assigned by host at posting; unique per series | Must | admin | 10 counters posting together never produce a duplicate or skip within a series | SCR-32 |
| FR-103 | Automatic backups: daily (default 03:00, keep 30) and hourly on host (keep 24); online backup API; manifest with checksum and schema version; verification; history [11.1] | Must | admin | Backup while 10 counters sell completes without blocking sales > 1 s | SCR-31 |
| FR-104 | Manual USB backup: choose drive, free-space check, progress, verify, never overwrite, open folder [11.2] | Must | manager | Removing USB mid-copy fails safely with a clear reason | SCR-31 |
| FR-105 | Restore: admin re-auth, typed confirmation, maintenance mode, client disconnect, safety backup, validate, restore, integrity and FK checks, restart, audit, user notice [11.3] | Must | admin | Restore drill in testing.md passes within RTO | SCR-31 |
| FR-106 | Backup status and warnings on dashboard [11.1] | Must | admin | No verified backup in 26 h → red banner for admin and manager | SCR-03 |
| FR-107 | Receipt template settings including Urdu footer lines, paper size, batch/expiry printing | Must | admin | Preview matches print | SCR-32 |
| FR-109 | Host monitor on the host PC: service status, port, DB path and size, connected workstations, last backup | Should | admin | Shows each connected client's OS and client version | SCR-33 |

## Non-functional requirements
| ID | Requirement | Priority | Acceptance criteria | Screen |
|---|---|---|---|---|
| NFR-001 | Fully offline; no call to the internet at runtime | Must | Running with WAN unplugged for a week of test scripts: no failures, no outbound attempts in firewall log | - |
| NFR-002 | Barcode lookup p95 <= 300 ms on LAN, max 1 s; search p95 <= 300 ms on 20,000 medicines | Must | Measured by load test harness and client timing log | SCR-04 |
| NFR-003 | Sale posting p95 <= 500 ms with 10 concurrent counters | Must | Load test report | SCR-05 |
| NFR-004 | Daily reports <= 5 s, monthly <= 15 s on a 3-year dataset (about 1.1 M invoices, 3.5 M lines) | Must | Report timing on seeded test DB | SCR-28 |
| NFR-005 | Concurrency: 10 counters x 3 sales/min for 1 h plus purchases and reports: zero duplicates, zero negative stock, self-check passes | Must | Load test + self-check | - |
| NFR-006 | Money uses `decimal` in code and INTEGER minor units in DB; no float/double anywhere in money paths | Must | Analyzer rule bans `double`/`float` in Domain and Data projects | - |
| NFR-007 | Power loss at any moment: no corruption; committed postings survive | Must | Kill-power test (VM hard reset) x 20 during load; integrity_check ok; no committed sale lost | - |
| NFR-008 | RPO <= 1 h on host and <= 24 h off host; RTO <= 60 min | Must | Restore drill timed | SCR-31 |
| NFR-009 | Security: PBKDF2-SHA256 600k iterations; TLS 1.2+ with pinned self-signed cert; server-side authz; no secrets in logs; firewall limited to LAN subnet | Must | Security review checklist in testing.md | - |
| NFR-010 | Platforms: host Windows 10 22H2+/11 x64; clients Windows 7 SP1 (.NET Framework 4.8) and Windows 10/11 (.NET 10) | Must | Smoke suite passes on Win7 SP1 VM and Win11 VM | SCR-33 |
| NFR-011 | Display 1366x768 minimum; 100-150 % scaling without clipping | Must | Screenshots of every screen at 1366x768 @100 % and 1920x1080 @150 % | - |
| NFR-012 | Accessibility: WCAG 2.1 AA contrast, keyboard complete, visible focus, high-contrast fallback | Must | Checklist in design-system.md section 4 | - |
| NFR-013 | Retention >= 3 years online; no hard delete of posted records | Must | No DELETE statements on document, movement, journal or audit tables outside migrations | - |
| NFR-014 | Installer: host and client; upgrade preserves data; versioned migrations with pre-migration backup; uninstall keeps data unless chosen | Must | Upgrade test from N-1 with data | - |
| NFR-015 | Maintainability: source, schema, ERD, data dictionary, build instructions, manuals, test results handed over | Must | Launch checklist complete | - |
| NFR-016 | Time: host clock is the only clock for posted records; stored UTC; shown in Pakistan Standard Time | Must | Client clock skewed by 2 h does not change posted timestamps | - |
| NFR-017 | Observability: rolling local logs on host and client (14 days), structured, no passwords or tokens | Must | Log review shows no secrets | - |

## Events and metrics (local only)
| Event | Where | Use |
|---|---|---|
| `scan.resolved` (ms, kind 1D/2D/search) | client timing log | NFR-002, concept metric |
| `sale.posted` (ms, lines, idempotent-hit) | host log | NFR-003 |
| `selfcheck.result` | host DB | dashboard, FR-037 |
| `backup.result` | backup_history | FR-103, dashboard |
| `purchase_line.capture_method` | DB column | 2D adoption metric |
