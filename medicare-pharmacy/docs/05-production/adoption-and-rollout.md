# Adoption and rollout

## Stakeholders and what they need to see
| Who | Needs to see before they trust it | Owner of |
|---|---|---|
| Owner / administrator | Cash and stock reconcile every night (self-check green); USB backup verified; one restore drill done | Go-live decision, restore drills |
| Pharmacist-in-charge (manager) | Expired stock cannot be sold; Schedule B/D register prints correctly; approvals from the counter take under 30 s | Medicine master, thresholds, approvals |
| Cashiers | Scan to line in about a second; whole sale by keyboard; F-key strip always visible | Daily selling |
| Stock operator | A 2D scan fills medicine, batch and expiry; repeated boxes add quantity | Goods-in |
| Accountant | Customer and supplier ledgers agree with the old registers; cashbook balances | Opening balances, month-end |
| IT helper | Installer runs, firewall rule present, counters re-pair easily | Install, upgrades |

## Champions
- One cashier per shift who has used the system for a week before the others (POS, returns, holds).
- The pharmacist-in-charge as the approvals and medicine-master champion.

## Pilot
1. **Week 0 (setup):** host installed, medicine list imported and checked, opening stock counted by rack, opening balances entered. Training PC restored from the setup data.
2. **Week 1 (parallel run, one counter):** counter 1 sells in MediCare; the old method continues on the other counters. Daily: compare MediCare's day sales and cash with the old method, list differences, fix data or process.
3. **Week 2 (all counters, old method as fallback):** every counter on MediCare. Paper receipt book kept in the drawer for host outages only.
4. **Week 3:** old method retired; daily close, USB backup and self-check become routine.

Exit criteria for each step: no unexplained cash differences for three days, zero expired sales, self-check green, cashiers at or below their old billing time.

## Training (by role, 45-60 minutes each, on the training PC)
| Role | Covers | Hands-on tasks |
|---|---|---|
| Cashier | Login and lock, scanning, quantity keys (+, −, *3), F2 search, F3 customer, F4 payment and change, F5 hold/recall, F6 discount and approval, F8 reprint, F9 return, shift open/close | 10 practice sales including a split payment, a credit sale, a held sale and a return |
| Manager | Approvals with PIN (at the counter and on the Approvals screen), medicine editor and price changes, batches and quarantine, counts and adjustments, reports, day close | Approve a discount, quarantine an expired batch, post a count |
| Stock operator | Goods-in with 2D scan, batch/expiry, free quantity, charges, post, purchase return | Enter two real supplier bills |
| Accountant | Supplier payments, customer receipts and allocation, cash and bank, expenses, ledgers and statements, profit report | Reconcile a supplier statement |
| Owner/admin | Users and roles, settings, backup and USB copy, restore drill, audit log, Host Monitor | Restore last night's backup on the training PC |

Quick-reference cards: the F-key strip at the bottom of every screen and the F1 help list the keys; the user manual (`user-manual.md`) has one page per role.

## Support model
- First line: the shift champion (how-to questions).
- Second line: the IT helper (hardware, network, printers, pairing, upgrades), using deployment.md troubleshooting.
- Escalation: the developer, with the host log, the client log, and the self-check rows. No remote access is required by the product.

## What makes people go back to the old way (and the counter-measure)
| Risk | Counter-measure |
|---|---|
| Slow scanning in the rush | Keyboard-only POS; quote call measured < 1 s on the LAN; F-keys shown on screen |
| Typing batches at goods-in | GS1 2D capture; repeated scans add quantity; defaults from the last line |
| Approvals hold up the queue | Inline PIN on the same PC; "send to manager's screen" when the manager is elsewhere |
| One wrong report destroys trust | Nightly self-check (stock balances = movements, every journal balanced, invoice totals, audit chain, recent verified backup) visible on the dashboard |
| Host PC switched off | UPS; Host Monitor tray icon; counters show a clear red banner and never lose a posted sale |

## Metrics (baseline measured in pilot week 1)
| Metric | Baseline | Target after week 3 |
|---|---|---|
| Scan-to-line time at POS | measure | ≤ 1 s typical |
| Average billing time per invoice | measure | ≤ old method |
| Expired units sold | measure (old method) | 0 |
| Stock ledger vs balance mismatches | - | 0 every night |
| Days with cash difference recorded and approved | measure | 100 % |
| Days with a verified off-site (USB) backup | measure | 100 % |
| Supplier balances agreeing with supplier statements at month-end | measure | 100 % |
