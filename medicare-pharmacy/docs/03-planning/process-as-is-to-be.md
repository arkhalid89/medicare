# Process: as-is and to-be

As-is is assumed from the BRD and sector research ([user-voice.md](../01-idea/research/user-voice.md)). **Owner to confirm** with real volumes during UAT preparation.

| Process | As-is (typical) | Pain | To-be | Pain removed |
|---|---|---|---|---|
| Counter sale | Search by name in basic POS or write a cash memo; pick strip from shelf by memory; batch not recorded | Slow at rush; wrong or expired batch handed out; no batch trace | Scan (1D/2D) → FEFO or exact batch shown with rack → F4 → Enter → receipt | Batch truth, speed, expiry block |
| Discount | Cashier decides; owner unaware | Leakage | Role limit; above it, manager PIN on the same PC, audited | Control without walking to an office |
| Credit (udhaar) | Notebook per customer | Lost entries, disputes | Credit tender requires customer, limit check, ledger, statement | Accurate dues |
| Goods-in | Type each line; batch and expiry often skipped | 20-40 min per bill; missing expiry data | Scan 2D code per box; batch/expiry filled; post; payable created | Faster, complete data |
| Supplier dues | Distributor statements trusted; paper ledger | Disputes, overpayment | Ledger from postings; payments allocated; balance confirmation letter | Reconciled balances |
| Expiry control | Periodic manual shelf check | Losses, risk of selling expired | Near-expiry list (90 d), expired blocked automatically, quarantine and supplier return | Fewer losses |
| Stock count | Annual or never; edits to "fix" stock | Unexplained gaps | Count sessions by rack, frozen qty, approvals over threshold, journal entries | Explained differences |
| Cash closing | Count drawer, compare with POS total roughly | Unexplained shortages | Denomination count vs expected; difference with reason and approval | Accountability per cashier |
| Profit | Sales minus purchases paid | Misleading (timing, double counting) | Net sales − COGS (moving average) = gross profit; minus expenses = operating profit | True profit |
| Backups | Ad hoc, untested | Data loss risk | Hourly/daily verified on host, USB copy nag, restore drill | Recoverable within 60 min |
| Controlled drugs | Paper register | Inspection stress | Register produced from sales with prescriber/patient | Inspector-ready |

## Volumes to confirm (owner input)
| Item | Assumed | Confirmed |
|---|---|---|
| Invoices/day | 1,000+ (peak 250/h 7-11 pm) | |
| Counters at peak | 8 | |
| Supplier bills/day | 5-15, 10-60 lines | |
| Active medicines | 5,000-20,000 | |
| Credit customers | 50-200 | |
| Suppliers | 15-40 | |
