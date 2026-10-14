# Design patterns

Accessed 2026-10-14. References are in [sources.md](sources.md). Pattern observations tagged `known` come from long-standing, widely documented product conventions rather than a page fetched today. Confirm them against the product before copying a detail.

## References
| # | Product / source | Pattern worth noting | Tag |
|---|---|---|---|
| R1 | Shopify POS UI guidance | Clean checkout: names and prices prominent, minimal clutter, big targets for frequent actions | single-source |
| R2 | FTx "checkout speed" guide | Layout redesign is the fastest speed win; logical left-to-right flow | single-source |
| R3 | Framna, payment interfaces for POS | Payment is the riskiest moment: one clear amount, explicit confirmation | single-source |
| R4 | skills.cat POS sales UI | Customer → items → payment, three-step rhythm; focus cue on the active field | single-source |
| R5 | Marg ERP / Tally-style subcontinent desktop software | Keyboard-first, F-key everything, dense grids; staff learn it by muscle memory | known |
| R6 | Mint POS (PK) | Expiry traffic-light: red under 30 d, yellow under 60 d, green safe | single-source |
| R7 | Microsoft Fluent 2 | Desktop density, focus visuals, accessible colour tokens for Windows apps | known |
| R8 | Odoo POS | Big numeric keypad, payment-method tiles, hold orders as tabs | known |
| R9 | Square POS | Receipt-like cart, one dominant "Charge" amount button | known |

## Adopt (users expect these)
- **Scan field always focused** after every action; a visible focus cue (R4, BRD s6.8).
- **F-key map shown on screen** (footer strip), because staff come from Marg/Tally-style tools (R5).
- **Dense, zebra-free grid with strong row separation** for the cart; tabular numerals, right-aligned amounts.
- **One dominant total** that is readable from 2 m away (R9).
- **Expiry colour bands** for batch pickers and alerts (R6), always paired with text ("Exp 03/27 · 41 d"), never colour alone.
- **Held sales as visible tabs or chips** (R8).
- **Payment dialog** with tender buttons per method and a live "Remaining / Change" readout (R3, R8).

## Avoid
- Touch-first giant tiles for products. A pharmacy has 5,000-20,000 SKUs, so search plus scan wins over a tile grid.
- Modal chains (dialog over dialog) at the counter.
- Colour-only status, low-contrast grey text, tiny 9 pt WinForms default fonts.
- "Dashboard soup" with 12 equal cards. Rank by what the owner acts on first (cash, expiry, dues).

## Differentiation opportunities
- **Batch truth at the counter.** Show the batch, expiry and rack of the line just scanned in a slim inspector; the cashier sees exactly what to pick.
- **2D code scan**: GS1 DataMatrix fills batch and expiry at goods-in, and picks the exact batch at POS.
- **Approval inline, not a separate screen.** A manager types a PIN on the cashier's screen when a discount exceeds the limit, and the approval is recorded.
- **Calm, legible, printed-matter aesthetic**. Receipts, statements and screens share one typographic system.

## Accessibility and localisation issues to avoid (seen industry-wide)
- WinForms default 96-DPI layouts break at 125-150 % scaling on Windows 10/11 laptops. Design for PerMonitorV2 DPI on .NET 10, and for system-DPI-aware fallback on .NET Framework 4.8 / Windows 7.
- Urdu shaping on thermal printers: most ESC/POS fonts cannot render Arabic script, so Urdu must be rasterised (see [tech.md](tech.md)).
- PKR formatting: `Rs 1,250.00` (Western grouping), dates `dd-MMM-yyyy` to avoid dd/mm vs mm/dd confusion.
