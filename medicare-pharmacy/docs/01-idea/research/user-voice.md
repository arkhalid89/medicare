# User voice

Accessed 2026-10-14. **Limitation:** we found no independent reviews (G2, Capterra, Reddit, app stores) of Pakistani pharmacy software. Every result was vendor content. The themes below combine (a) problems vendors say they solve, which reflect what buyers complain about, (b) the owner's BRD, which encodes this pharmacy's own pain, and (c) general pharmacy-operations guides. Treat frequencies as indicative, not measured.

## Themes
| # | Theme | Evidence | Strength |
|---|---|---|---|
| T1 | **Expiry losses**: old batches sit behind newer ones because expiry is tracked per product, not per batch | runmypharmacy guide; every vendor lists expiry alerts; BRD s6.4 | Strong |
| T2 | **Stock never matches**: loose tablets vs strips vs boxes, returns not traced, manual edits | TransactFlow claims multi-level unit stock; BRD s6.3, s6.11 | Strong |
| T3 | **Slow counter at rush hour**: searching names, picking batches by hand | BRD s6.8 ("optimized for speed"), Shopify/FTx POS guidance | Strong |
| T4 | **Profit is a guess**: owners see sales, not COGS; supplier payments counted as expenses | BRD s7.4 says this is "mandatory to avoid misleading reports" | Strong (owner) |
| T5 | **Cash leakage**: no per-cashier closing, discounts and refunds without approval | BRD s4, s6.14 | Strong (owner) |
| T6 | **Multi-counter sync conflicts** in "offline + sync" products | vendors advertise auto-catch-up; the market guide advises asking vendors to "demonstrate offline sync conflicts" (search extract) | Medium |
| T7 | **Credit ("udhaar") customers** and supplier dues tracked in notebooks | Every PK vendor lists customer credit; BRD s6.6, s6.12 | Medium |
| T8 | **Backups that were never tested** | BRD s11; general SQLite guidance | Medium |
| T9 | **Regulatory records**: Schedule B/D register kept on paper; 3-year record retention | Punjab Drugs Rules 2007 (see [risk.md](risk.md)) | Medium |

## Top unmet jobs (feed into concepts)
1. Sell the right batch in under a second, and never an expired one (T1, T3).
2. Know today's real cash and gross profit per cashier without a spreadsheet (T4, T5).
3. Make stock self-reconciling and explainable movement by movement (T2, T6).
4. Capture batch and expiry without typing, using the new DRAP 2D codes (T1, T2, T9).
5. Keep the Schedule B/D register and credit book inside the same system (T7, T9).
