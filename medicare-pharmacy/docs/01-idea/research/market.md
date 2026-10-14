# Market and sector context (non-monetized track)

Accessed 2026-10-14. There is no revenue model; this file covers the operating context that shapes the system.

## Sector context
- **Retail pharmacy in Pakistan** is licensed under provincial drug rules (e.g. Punjab Drugs Rules 2007) under the federal Drugs Act 1976, regulated by DRAP. Licence classes include "pharmacy" and "medical store", with different rights (e.g. medical stores may not stock Schedule G drugs: `single-source`, Pak Law blog copy of the rules).
- **Software adoption is low**: vendor claim that most medical stores run no software or basic billing (`single-source`).
- **Market products** split into cheap cloud/subscription POS (from about Rs 2,000/month, `single-source`) and one-time offline licences (from about Rs 23,750, `single-source`). Indian desktop ERPs (Marg, Gofrugal) set the bar for depth.
- **DRAP track and trace**: a draft guideline (29 Jun 2026) puts a GS1 DataMatrix on secondary packs carrying GTIN (01), expiry (17), batch (10) and serial (21). News reports a barcode and serial mandate for medicines manufactured from 19 Oct 2026. Exact final timelines are not confirmed (`single-source` each; see [risk.md](risk.md)).
- **FBR**: Tier-1 retailers (chains, mall shops, 1,000 sq ft+, high electricity bills, or 236H withholding above Rs 100,000) must integrate POS with FBR. Pharmacies are not singled out. The integration needs internet, so it is out of scope for this offline system (owner decision), with an extension point.

## Comparable open-source or public systems
| System | Relevance |
|---|---|
| Odoo POS + pharmacy add-ons | Cloud or on-prem web; not a WinForms/offline-LAN fit; proves the FEFO and lot model |
| OpenBoxes (open-source supply chain, lot/expiry) | Lot and expiry data model reference (Java/web) |
| JAHASOFT Quetta case study | Pakistani offline-first pharmacy POS: every stock line carries batch, mfg, expiry, purchase and sale price (`single-source`) |

## Operating context for this pharmacy
| Factor | Value | Source |
|---|---|---|
| Workstations | 7-10+ on LAN | Owner, G1 |
| Volume | 1,000+ invoices/day; peaks in evening rush | Owner, G1; peak pattern assumed |
| Windows | Host on Windows 10/11; some clients on Windows 7 | Owner, G1 (A1) |
| Hours | Long opening hours (often 12-16 h; some 24 h) | assumed, confirm |
| Connectivity | LAN only, power cuts (load-shedding) likely | assumed, confirm UPS on host |
| Users' skill | Minimal technical knowledge | BRD s1 |
| Language | English UI; Urdu on receipts | Owner, G1 |

Power cuts matter for the design. The host needs a UPS, and SQLite WAL with `synchronous=FULL` on the host survives sudden power loss without corruption. Clients must handle "host unreachable" without losing the cart.
