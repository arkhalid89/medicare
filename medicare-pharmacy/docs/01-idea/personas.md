# Personas, jobs and roles

Grounded in the BRD roles, [user voice](research/user-voice.md) and [stakeholders](research/stakeholders.md). Names are illustrative.

## P1 - Asad, Cashier / Dispenser (5-8 of them across shifts)
- **Context:** stands at counter 2 of 8, 80 mm printer and USB scanner, 8-hour shift, evening rush 7-11 pm, 60-120 bills per hour per counter at peak (assumed).
- **Goals:** bill fast; never hand out the wrong strip; balance the drawer at shift end.
- **Frustrations:** searching by spelling; picking batches by hand; waiting for the manager for every discount.
- **Workaround today:** remembers rack positions; writes credit on paper.
- **Devices:** older PC (may be Windows 7), 1366x768 or 1920x1080 monitor, keyboard plus scanner, rarely the mouse.
- **Success moment:** "Scan, scan, F4, Enter, receipt out, and my closing matched."

## P2 - Sana, Pharmacist-in-charge / Manager
- **Context:** floats between counters and the back office; signs off returns, discounts and stock adjustments; faces drug inspectors.
- **Goals:** correct batch dispensed; expiry under control; approvals quick but recorded.
- **Frustrations:** being called to a counter to type a password; near-expiry found too late.
- **Success moment:** approves a refund with a 4-digit PIN at the counter, and it shows in the audit log.

## P3 - Imran, Stock operator
- **Context:** receives 5-15 supplier invoices a day, each 10-60 lines.
- **Goals:** enter goods-in fast with the correct batch and expiry; count stock monthly.
- **Frustrations:** typing batch and expiry for every line; pack vs loose unit mistakes.
- **Success moment:** scans the 2D code on each box, and batch, expiry and product fill in themselves.

## P4 - Rehan, Owner / Administrator (also the buyer)
- **Context:** checks the dashboard morning and night; worries about theft, backups and real profit.
- **Goals:** trust the cash, dues and gross profit numbers; backups that work.
- **Frustrations:** reports that do not tie out; supplier payments counted as expenses.
- **Success moment:** the night summary shows sales, gross profit and cash per cashier, plus a green self-check and a verified backup.

(Accountant role: part-time, uses ledgers, cashbook and profit report. Covered by P4's needs; no separate persona.)

## Roles and permissions matrix (defaults; admin-configurable per BRD s4)
`V` view · `C` create/draft · `P` post · `A` approve · `X` export · `M` administer · `-` none

| Module | Admin | Pharmacist/Manager | Cashier | Accountant | Stock operator |
|---|---|---|---|---|---|
| Dashboard | V | V | V (own counter) | V | V (stock cards) |
| POS sale | V C P | V C P | V C P | - | - |
| Discount over limit | A | A | request | - | - |
| Sales return / refund | A | C P A | C (request) | V | - |
| Medicines and barcodes | M | C P | V | V | V |
| Purchases | V A | C P A | - | V | C P |
| Purchase returns | A | C P A | - | V | C |
| Stock adjustment / count | A | C P A | - | V | C |
| Customers | M | C | C (quick add) | C | - |
| Customer receipts | V | C P | C P | C P | - |
| Suppliers and payments | M | C | - | C P | - |
| Expenses | A | C | - | C P | - |
| Cashier closing | A | A | C | V | - |
| Reports | V X | V X | own sales only | V X (financial) | V X (stock) |
| Users and permissions | M | - | - | - | - |
| Audit log | V X | V | - | V | - |
| Backup and restore | M | backup only | - | - | - |
| Settings | M | - | - | - | - |

## Primary journeys
| Persona | Trigger | Steps | Outcome |
|---|---|---|---|
| P1 | Customer at counter | Scan items → (batch auto) → F3 customer if credit → F4 pay → Enter → receipt prints | Posted sale, stock and journal updated, scan field ready |
| P2 | Discount request pops on counter 3 | Walk over → enter PIN → approve with reason | Approval recorded, sale continues |
| P3 | Supplier delivery | New purchase → supplier → scan 2D per box (or type) → qty/free/rate → post → pay part | Batches created, payable and journal posted |
| P4 | End of day | Each cashier closes → owner reviews differences → approves → backup to USB | Day closed, verified backup |
