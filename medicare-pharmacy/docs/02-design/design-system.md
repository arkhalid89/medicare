# Design system

Direction **A + C** (ADR-010). Tokens: [tokens.css](tokens.css). The WinForms implementation draws every control from these tokens. Never use raw colours or sizes in forms.

## 1. Tokens summary
| Group | Tokens | Notes |
|---|---|---|
| Colour | canvas, surface, surface-alt, ink, ink-muted, line, line-strong, select, accent, primary-action, focus, ok/warn/danger/info (+bg) | All text pairs >= 4.5:1 in light and dark (checked 2026-10-14) |
| Type | `--f-ui` Atkinson Hyperlegible; `--f-num` IBM Plex Mono; `--f-urdu` Noto Naskh Arabic (receipts) | Sizes 11/12/14/15/18/24/40 px at 100 % DPI |
| Spacing | 4/8/12/16/20/24/32 | Layout gaps only from this scale |
| Shape | radius 4 (chips, keys) and 6 (controls, panels); 1 px borders; one dialog shadow | No other shadows |
| Density | Comfortable: row 40, control 36. Compact: row 32, control 30, item text 14 | Per-user setting, POS and grids |
| Motion | 120/180 ms; dialog fade, toast slide | Disabled when Windows "Show animations" is off |

### Fonts in Windows Forms
- Ship the TTF files (all SIL OFL 1.1) and **install them per machine with the installer** (admin rights are already needed for the service). GDI `TextRenderer`, which `DataGridView` uses, cannot see fonts loaded only through `PrivateFontCollection`.
- Fallback when a font is missing: Segoe UI (Win7+) / Consolas, with a status-bar warning to the admin.
- Use tabular figures: IBM Plex Mono is monospaced, so every column of digits aligns.

## 2. Layout
- **Shell:** nav 184 px (rail 56 px) | header 56 px | content | status bar 30 px; POS adds the F-key strip (46 px).
- **Minimum resolution 1366x768 at 100 %.** At 1920x1080 at 125-150 % the layout scales: .NET 10 uses PerMonitorV2; .NET Framework 4.8 uses `app.manifest` DPI awareness and `AutoScaleMode.Dpi`.
- **Content grid:** forms use a 12-column grid inside the content area with 16 px gutters; label above field; two-column forms at >= 1280 px, one column below.
- **POS layout:** cart (flex) | right column 336 px (line inspector, totals, net payable, actions); F-key strip across the bottom.
- **Dialogs:** centred, max 640 px wide (payment 560 px), dim the window to 40 % canvas, Esc closes when nothing is posted.

## 3. Components
Every component defines default, hover, focus, active/pressed, disabled, loading, error, empty and success states where they apply.

| Component | Variants | Key rules |
|---|---|---|
| Button | primary (ink fill), accent (teal fill), secondary (outline), ghost, danger | Height 36 / 44 (primary actions). Shortcut shown right-aligned in mono (`F4`). Loading: label replaced by "Posting…" with spinner; button disabled to prevent double post |
| Text input | default, with prefix/suffix, numeric (right-aligned mono), search | 2 px accent border on focus + 4 px halo; error: danger border + message below; never placeholder-only labels |
| Scan field | POS, goods-in | Always refocused after any action; 48 px; accepts 1D, GS1 2D, typed text; shows parse result chip for 1.5 s |
| Select / lookup | single, typeahead lookup (medicine, customer, supplier) | Lookup opens a list under the field; Enter picks; shows stock and price for medicines |
| Data grid | list, editable (cart, invoice lines), ledger (running balance) | Header surface-alt, 11 px uppercase; numbers right-aligned mono; selected row `--c-select` + 3 px accent left edge; zebra none; sticky header; virtual mode above 500 rows |
| Chip / status | FEFO, 2D SCAN, NN DAYS (warn), EXPIRED (danger), QUARANTINE, DRAFT, POSTED, REVERSED, PENDING | Always text plus colour |
| KPI card | value, delta, sparkline, "estimated" flag | Only on the dashboard |
| Keycap strip | POS only | 10 keys; the primary key (F4) uses accent fill; labels configurable |
| Toast | success, warning, error | Bottom-right, 4 s, never for errors that need action (use inline or dialog) |
| Banner | info, warning, danger | Full-width under header: host down, maintenance, restored data |
| Dialog | standard, approval (SCR-07), confirm-irreversible (typed phrase) | Focus trapped; first field focused |
| Empty state | per list | One sentence on what appears here plus the action to create the first item |
| Chart (own GDI+ control) | bar, line, donut (<= 5 parts), sparkline | Faint grid, area fill under lines, labelled endpoint, text alternative table |

## 4. Accessibility rules (WCAG 2.1 AA, adapted to desktop)
- Contrast as in tokens.css (all >= 4.5:1 text, >= 3:1 UI boundaries).
- **Keyboard complete:** every action reachable without the mouse; tab order follows the visual order; F-keys documented and configurable; Enter confirms, Esc cancels.
- **Focus always visible:** 2 px focus ring; owner-drawn controls must paint it.
- Targets >= 36 px high; primary >= 44 px (touch screens at some counters).
- Colour never carries meaning alone (chips have text; expiry shows days).
- Errors: field-level message plus a summary at the top of the form for long forms; messages say how to fix.
- Sounds: optional scan beep and error tone, configurable volume; never the only signal.
- Respect Windows high-contrast mode by falling back to system colours (`SystemInformation.HighContrast`).
- Charts include a "View as table" toggle.

## 5. Data display rules
- Money: `Rs 1,250.00`, negative `− 39.00` in danger colour only in ledgers; grids omit "Rs".
- Quantity: integer base units shown in sale units, e.g. `2 strip`, `10 tab`, `1 btl`, or mixed `1 box 3 strip`.
- Dates `dd-MMM-yyyy`, expiry `MMM-yy` in grids. Time is 24 h, shown in Pakistan Standard Time.
- Document numbers in mono: `SI-C3-261014-0482` (series-counter-yyMMdd-seq, configurable).

## 6. Printing
- Thermal 80 mm (72 mm printable) and 58 mm (48 mm) templates. Text 9-10 pt mono; header 12 pt bold; Urdu 11 pt Naskh, right-aligned.
- A4 templates: purchase voucher, statements, reports. Use 20 mm margins, 10 pt body, and a header with the pharmacy logo, name, address and licence number.
- A reprint prints "DUPLICATE" in the header and records an audit entry.
