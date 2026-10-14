# Brief - MediCare Pharmacy

Status: **approved at G1 (2026-10-14)**   Date: 2026-10-14   Source: owner BRD "Standalone Offline Pharmacy Retail Management System" + discovery rounds 1-2.

## Problem
A Pakistani retail pharmacy needs to sell fast at the counter by barcode, keep batch- and expiry-accurate stock, run supplier and customer credit, and know its real cash and gross profit every day, without internet. Today this is done with generic POS software, registers and spreadsheets that do not enforce expiry, batch traceability, approvals or correct profit (unverified; confirm in research).

## Users and context
| Role | Daily use |
|---|---|
| Cashier / Dispenser | POS billing by barcode, returns within limits, customer selection |
| Pharmacist / Manager | Medicines, purchases, stock, approvals, reports |
| Stock Operator | Goods receiving, stock counts, adjustments |
| Accountant | Cashbook, bankbook, expenses, ledgers, financial reports |
| Administrator (often the owner) | Users, permissions, settings, backup and restore |

Environment: one pharmacy, one site, **7-10+ Windows PCs on a LAN**, busy counter (**1,000+ invoices/day**), USB HID barcode scanners, 58/80 mm thermal printers and an A4 printer, staff with minimal technical knowledge.

## Outcome and metrics (baseline to be measured at pilot)
| Metric | Target |
|---|---|
| Scan-to-line time at POS | <= 1 s typical (BRD s14) |
| Expired units sold | 0 (hard block) |
| Stock ledger vs balance mismatches | 0 (nightly reconciliation check) |
| Daily cash difference recorded and approved | 100 % of days |
| Verified backup in last 24 h | 100 % of days |

## Track
Non-monetized internal system for a single pharmacy. No licensing, pricing or multi-tenant features.

## Scope
**In:** everything in BRD sections 4-16: medicine master with multi-barcode and pack-to-unit conversion; batch/expiry stock with FEFO; POS with split/credit payments, hold/retrieve, shortcuts; sales and purchase returns with quarantine; purchases, suppliers, customers, receipts, payments; cashbook, bankbook, expenses, daily and cashier closing; stock adjustments and counts with approvals; roles, permissions, audit log, session lock; dashboard; 25 reports with CSV/Excel export; automatic and USB backup, verified restore; Windows installer; full documentation and source handover.
**Added in discovery:** Urdu text on printed receipts (UI stays English); one-time **medicine master import from Excel/CSV**; client builds for **Windows 7 SP1** and **Windows 10/11**.
**Out:** cloud, internet-dependent features, online payments, SMS/WhatsApp, supplier integrations, multi-branch sync, hospital/OPD/lab modules, FBR online POS integration, Urdu user interface.

## Hard constraints
- C# Windows Forms clients; central LAN service; SQLite on the host's local disk; no client opens the database file (BRD s2, s10).
- Works fully offline; LAN only; host PC must stay on.
- Monetary values use `decimal`; PKR default; timestamps shown in Pakistan Standard Time.
- No permanent deletion of posted documents; corrections by reversal or return.
- Two client runtimes from one codebase: .NET Framework 4.8 (Windows 7 SP1) and .NET 10 (Windows 10/11).

## Assumptions made on the owner's behalf (veto at G1)
| # | Assumption |
|---|---|
| A1 | The **host PC runs Windows 10/11 64-bit**; Windows 7 is supported for **client** workstations only. Windows 7 has had no security updates since Jan 2023, so it is a documented risk. |
| A2 | English documentation and UI; Urdu only on receipts (medicine name line and footer). |
| A3 | Single legal entity, single branch, single currency (PKR, 2 decimals). |
| A4 | Sales tax defaults to 0 % with per-medicine override; no FBR reporting. |
| A5 | Cash and one or more bank/wallet accounts (e.g. card machine, JazzCash/Easypaisa transfer) are recorded as payment accounts only, never integrated. |
| A6 | Opening stock and party balances are entered through screens (only the medicine list is imported). |

## Open questions (do not block G1; answer before planning, G6)
1. Host PC and workstation hardware specs, and the printer and scanner models in use.
2. Is a controlled/narcotic drug register or prescription record (doctor, Rx number) required on sale? (Researching provincial drug rules in phase 1.)
3. What licence details must appear on receipts (drug sale licence no., NTN)? 
4. A sample of the medicine Excel/CSV to import.
5. Who supports the system after go-live (local IT person, owner, vendor)?
