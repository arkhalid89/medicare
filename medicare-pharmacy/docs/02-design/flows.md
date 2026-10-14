# Flows and information architecture

Direction: **A + C blend** (G4, 2026-10-14). Clear Dispensary visual system, C's keycap F-key strip on the POS, and a per-user density (Comfortable 40 px rows / Compact 32 px rows). Requirement IDs refer to [prd.md](../03-planning/prd.md).

## Navigation model
The shell has a left navigation (184 px, collapsible to a 56 px icon rail), a top header (screen title, document context, user, clock), the content area, a status bar (host connection, shift, backup, self-check) and, on POS only, the F-key strip. Menu items appear only when the role has the view permission. The server still enforces every action.

| Group | Items (screen) | Cashier | Manager | Stock op. | Accountant | Admin |
|---|---|---|---|---|---|---|
| Dashboard | SCR-03 | own | all | stock cards | finance cards | all |
| POS / Sales | New sale SCR-04, Held SCR-08, Returns SCR-09, Invoices SCR-10 | yes | yes | - | view | yes |
| Inventory | Medicines SCR-11, Batches & stock SCR-14, Adjustments & counts SCR-23, Import SCR-13 | view | yes | yes | view | yes |
| Purchases | Goods-in SCR-15, Purchase returns SCR-16, Suppliers SCR-17 | - | yes | yes | view | yes |
| Accounts | Cashbook SCR-25, Expenses SCR-26, Receipts SCR-22, Supplier payments SCR-19, Closing SCR-27 | closing, receipts | yes | - | yes | yes |
| Customers | Customers SCR-20 (ledger SCR-21) | quick add | yes | - | yes | yes |
| Reports | SCR-28, Controlled register SCR-35 | own sales | yes | stock | finance | yes |
| Approvals | SCR-24 (badge with count) | - | yes | - | expenses | yes |
| Admin | Users SCR-29, Audit SCR-30, Backup SCR-31, Settings SCR-32 | - | backup only | - | audit view | yes |

## Screen inventory
| ID | Screen | Purpose | Roles | Primary action | Requirements |
|---|---|---|---|---|---|
| SCR-01 | Login | Authenticate; show host status | all | Log in | FR-001, FR-002, FR-003, FR-007 |
| SCR-02 | Session lock | Re-authenticate after inactivity; keep cart | all | Unlock | FR-004 |
| SCR-03 | Dashboard | Today's money, stock risk, dues, approvals, backup, self-check | all (filtered) | Open alert target | FR-033, FR-034, FR-037, FR-095, FR-106, FR-005 |
| SCR-04 | POS sale | Scan, FEFO, edit lines, discounts, customer | cashier, manager | Take payment (F4) | FR-022, FR-038, FR-060, FR-061, FR-062, FR-063, FR-064, FR-069, FR-070, FR-072 |
| SCR-05 | Payment | Tender cash/card/wallet/split/credit, post, print | cashier, manager | Complete sale | FR-065, FR-066, FR-068 |
| SCR-06 | Batch picker | Override FEFO, see all batches with expiry and rack | cashier, manager | Use batch | FR-061 |
| SCR-07 | Manager approval | Inline PIN approval with reason | manager, admin | Approve | FR-009, FR-063 |
| SCR-08 | Held sales | Park and recall carts per counter | cashier, manager | Recall | FR-067 |
| SCR-09 | Sales return | Return against invoice, inspect, refund | cashier (request), manager | Post return | FR-075, FR-076 |
| SCR-10 | Invoices | Search posted sales; reprint; reverse | cashier (own), manager | Reprint | FR-068, FR-071, FR-091 |
| SCR-11 | Medicines | Search list with stock and status | all view | New medicine | FR-020, FR-022, FR-025 |
| SCR-12 | Medicine editor | Master data, barcodes, packs and units, prices, flags, Urdu name | manager, admin | Save | FR-020, FR-021, FR-023, FR-027, FR-028, FR-108 |
| SCR-13 | Medicine import | Map, validate, preview, dry-run, commit | admin, manager | Import | FR-026 |
| SCR-14 | Batches & stock | Batch list by state, expiry bands, low stock | manager, stock op. | Open batch | FR-025, FR-030, FR-032, FR-033, FR-034, FR-035 |
| SCR-15 | Goods-in (purchase invoice) | Supplier bill entry with 2D scan; draft/post; pay | stock op., manager | Post invoice | FR-030, FR-031, FR-038, FR-040, FR-041, FR-042, FR-043, FR-044, FR-045, FR-046 |
| SCR-16 | Purchase return | Return batches to supplier; credit note | manager | Post return | FR-047, FR-048 |
| SCR-17 | Suppliers | List and editor with opening balance | manager, accountant | New supplier | FR-050 |
| SCR-18 | Supplier ledger | Statement, outstanding, history, confirmation | accountant, manager | Print statement | FR-051 |
| SCR-19 | Supplier payment | Pay and allocate to bills or advance | accountant | Post payment | FR-052 |
| SCR-20 | Customers | Search, editor, credit limit, opening balance | cashier (quick add), manager | New customer | FR-055, FR-056 |
| SCR-21 | Customer ledger | Invoices, receipts, returns, balance | accountant, manager | Print statement | FR-057 |
| SCR-22 | Customer receipt | Collect and allocate | cashier, accountant | Post receipt | FR-058 |
| SCR-23 | Adjustments & counts | Adjust batch qty; count sessions; write-off quarantine | stock op., manager | Post | FR-080, FR-081, FR-082 |
| SCR-24 | Approvals | Pending requests across counters | manager, admin | Approve / reject | FR-010 |
| SCR-25 | Cashbook & bankbook | Running balances, cash in/out, transfers | accountant | New entry | FR-086, FR-085 |
| SCR-26 | Expenses | Expense vouchers | accountant | Post voucher | FR-087 |
| SCR-27 | Cashier & day closing | Shift open/close, counted vs expected, day lock | cashier, manager | Close shift | FR-088, FR-089 |
| SCR-28 | Reports | Catalogue and viewer with filters, print, export | by role | Run report | FR-096 |
| SCR-29 | Users & permissions | Users, roles, permission matrix, resets | admin | Save | FR-006, FR-007, FR-008 |
| SCR-30 | Audit log | Filter sensitive actions; before/after | admin, accountant | Export | FR-100 |
| SCR-31 | Backup & restore | Schedule, history, USB backup, verify, restore | admin (restore), manager (backup) | Back up now | FR-103, FR-104, FR-105 |
| SCR-32 | Settings | Pharmacy profile, numbering, tax, thresholds, receipt, shortcuts, LAN | admin | Save | FR-024, FR-101, FR-102, FR-107 |
| SCR-33 | Connection & host | Pair with host; host down overlay; host monitor | all; admin on host | Connect | FR-012, FR-013, FR-109 |
| SCR-34 | First-run setup | Pharmacy profile and first administrator | installer user | Finish setup | FR-011 |
| SCR-35 | Controlled register | Schedule B/D register by period | manager | Print | FR-097 |

## Key flows

### F1. Counter sale (happy path, keyboard only)
1. Scan field focused (SCR-04). Scan 1D EAN → server resolves medicine → FEFO picks the earliest-expiry saleable batch with stock → line added with qty 1 of the default sale unit.
2. Scan 2D GS1 → GTIN, batch and expiry parsed → that exact batch is used if saleable; otherwise a warning plus a FEFO fallback (F7 to pick).
3. Type `+3` or `*3` then Enter to set the quantity of the last line; Up/Down moves the selection; Del removes a line.
4. F4 → payment (SCR-05): Cash focused with amount = net; type tendered → change shown → Enter.
5. Server posts atomically (idempotency key = client GUID) → receipt prints → new empty sale; scan field focused.

Failure and recovery:
- **Unknown barcode** → inline error "Barcode 8961… not found. F2 to search, or ask a manager to add it." The sound plays once and the scan field is reselected.
- **Expired or quarantined batch scanned** → blocked: "Batch AG1180 expired 31-Aug-2026. It cannot be sold." No override exists.
- **Stock taken by another counter before posting** → the server returns `STOCK_CHANGED` with current availability → the cart marks the line, offers FEFO re-pick, and the cashier confirms.
- **Host unreachable on F4** → overlay "Host PHARM-HOST unreachable. This sale is not saved yet." The cart is kept on this PC as a draft. On reconnect the cashier presses Retry and the same key posts once.
- **Printer error** → sale already posted; toast "Printed failed: printer offline. Sale SI-… is saved. F8 to reprint." Reprint is marked DUPLICATE.

### F2. Discount over limit (inline approval)
F6 → enter % or amount → over the role limit → SCR-07 appears on the same PC: the approver picks their name, enters their PIN and a reason → the server checks the approver's permission and issues a single-use approval bound to this cart's hash → the discount is applied → audit entry written.

### F3. Credit sale
F3 → search customer (name, mobile, ID) → F4 → Credit tender → server checks limit: within → post; over → SCR-07 approval or reduce credit.

### F4. Goods-in with 2D scan
SCR-15: pick supplier, enter supplier bill no./date → scan a box's DataMatrix → medicine, batch, expiry filled; qty defaults to the last qty for this medicine → enter qty, free qty, rate → next box of the same batch increments qty → Post (Ctrl+Enter) → batches created, average cost updated, payable posted → optional immediate payment → print voucher.

### F5. Sales return
SCR-09: scan or type invoice no. → lines with returnable qty → choose qty and reason → inspection outcome (Restock / Quarantine) → refund method (cash, bank, customer credit) → approval if over threshold → post → stock and COGS reversed, refund in cashbook.

### F6. Shift close and day close
SCR-27: cashier enters counted cash by denomination → expected cash computed from posted cash sales, receipts, refunds and paid-outs → difference shown → if non-zero, reason and manager approval → shift closed. When all shifts are closed, the manager closes the day → the day is locked; backup to USB is prompted.

### F7. Host down and recovery
Client loses the host for more than 3 s → status bar turns red, a modal overlay blocks posting. Navigation of cached screens is allowed read-only (marked "Offline: read-only"). Auto-retry every 5 s. On reconnect: re-validate session → pending draft cart can be posted once (idempotency).

### F8. Restore (admin)
SCR-31 → Restore → re-enter admin password → choose backup → server validates manifest, checksum, integrity and schema version → confirm with typed phrase `RESTORE` → maintenance mode broadcast, clients disconnected → safety backup → swap → integrity and FK checks → service restarts → audit entry → banner on every client: "Data restored to 13-Oct-2026 23:00. Sales after that time are not in the system."

## Content and microcopy rules
- Name things the way the counter does: **strip, box, bottle, tablet**, **party** only in accounting screens, **walk-in**.
- Amounts: `Rs 1,100.00`; dates `14-Oct-2026`; times 24 h `19:42`; expiry `Nov-26` in grids and `30-Nov-2026` in detail.
- Errors say what happened and what to do next, in one or two sentences. Never "An error occurred".
- Buttons are verbs: "Post invoice", "Take payment", "Back up now". Destructive or irreversible actions name the consequence: "Post return (cannot be edited later)".
- Statuses are always text plus colour (e.g. `47 DAYS` amber chip).
- Localisation: UI English only. Urdu only on printed receipts (medicine Urdu name, footer lines), right-to-left, Naskh by default.
