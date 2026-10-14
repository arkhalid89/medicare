# Competitors and alternatives

Accessed 2026-10-14. Most Pakistani vendor sites (`*.pk`) did not resolve from the research sandbox (DNS failure). Their claims below come from search-engine extracts of the vendor's own page, so they are tagged `single-source` (vendor claim, not observed in the product). Sources: [sources.md](sources.md).

## Rings
| Ring | Products |
|---|---|
| Direct, Pakistan | MediPOS (Innobrains), TransactFlow, Posify, Pharma Pro, Mint POS, Switcher Techno, CloudPOS, Moneypex, Sadahisab, MARS (Softronix), EloERP |
| Direct, regional (India, desktop ERP) | Marg ERP 9+, Gofrugal RetailEasy |
| Indirect | Generic retail POS, accounting packages (Tally-style), Odoo with pharmacy add-ons |
| Do nothing | Hand-written cash memo + purchase register + Schedule B/D register; Excel stock sheets; no software at all (a vendor says most Pakistani medical stores have none: `single-source`, sadahisab) |

## Feature matrix (vendor claims)
`Y` = claimed, `-` = not mentioned in what we could read, `?` = unclear.

| Product | Deploy | Batch/expiry | FEFO/blocks expired | Multi-counter | Accounting depth | FBR | Price (claimed) | Tag |
|---|---|---|---|---|---|---|---|---|
| MediPOS | Offline licence or cloud | Y, alerts 7/30/60/90 d | Y / Y | ? | ? | ? | from Rs 23,750 one-time | single-source |
| TransactFlow | Offline with cloud sync | Y, alerts 3 m/1 m/1 w | - | Y (sync) | ? | ? | ? | single-source |
| Posify | Offline-first, LAN sync | Y | ? | Y, LAN | ? | Y | from Rs 2,000/month | single-source |
| Pharma Pro | Offline | Y, alerts | FIFO (not FEFO) | ? | ? | ? | ? | single-source |
| Mint POS | ? | Y, red/yellow/green 30/60/90 d | ? | ? | ? | Y | ? | single-source |
| EloERP | Cloud | Y | Y FEFO | Y | ERP | Y | ? | single-source |
| Switcher Techno | Offline billing | Y | ? | ? | ? | ? | ? | single-source |
| CloudPOS | Cloud, multi-branch | Y | ? | Y | ? | ? | ? | single-source |
| MARS (Softronix) | ? | ? | ? | ? | ? | ? | ? | unverified (G2 listing only) |
| Marg ERP 9+ | Windows on-prem, optional cloud | Y, batch and salt-wise | Y, short-expiry control | Y | Strong (GST, ledgers) | n/a (India) | ~Rs(INR) 8,100-25,200 + AMC, dealer-priced | verified (2 aggregators) |
| Gofrugal | On-prem or cloud | Y | Blocks short expiry at inward and sale | Y | ERP | n/a (India) | ? | single-source |
| Excel + registers | Paper/PC | Manual | No | No | No | No | Free | - |

## Strengths and weaknesses we can infer
- **Everyone advertises batch and expiry alerts.** That is table stakes, not a differentiator.
- **Pharma Pro says FIFO, not FEFO.** Selling by receipt date instead of expiry date is a known cause of expiry losses ([runmypharmacy guide](https://runmypharmacy.com/guides/reduce-expired-medicine-losses), `single-source`).
- **"Offline" often means "offline with cloud sync".** The products sync over the internet when it comes back (TransactFlow, Posify). That is the opposite of BRD s10 rule 9, which says workstations must never accept transactions that are not recorded centrally.
- **Accounting is shallow or not shown** in the Pakistani POS products. None advertises correct COGS by moving-average cost, separate gross and operating profit, or cashier closing with approved differences. Marg ERP and Gofrugal are deep but built for Indian GST and sold through dealers.
- **No Pakistani product we found advertises GS1 DataMatrix (2D) scanning** to capture batch and expiry automatically, although DRAP's draft track-and-trace guideline puts exactly that code on every secondary pack (see [risk.md](risk.md)).
- **Schedule B/D register:** no Pakistani vendor page we read mentions it. Marg ERP mentions restricted-drug records.

## Positioning map (axes that actually separate this market)
X: **data integrity** (per-product expiry and loose sync → batch-true stock, central single truth, approvals, audit).
Y: **accounting depth** (billing only → ledgers, COGS and profit, cash closing).

```
 accounting depth
   high |  Marg, Gofrugal (IN, dealer ERP)        [ target: MediCare Pharmacy ]
        |                                          
        |  EloERP (cloud)                          
   low  |  Posify, TransactFlow, MediPOS, Mint, Pharma Pro, Excel
        +------------------------------------------------------------ data integrity
          loose (sync, per-product)                     strict (central, batch-true, audited)
```
The upper-right corner is empty for an **offline, LAN, Pakistan-specific** product.
