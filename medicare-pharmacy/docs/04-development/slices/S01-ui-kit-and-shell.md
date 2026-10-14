# S01 - Ui kit and shell
Status: todo      Size: M      Depends on: S00

## Goal
The client shell (nav, header, status bar, F-key strip host) and the token-driven component kit look like the prototype on both runtimes.

## Covers
(no requirement IDs: enabling slice)   Screens: SCR-03 (shell only), SCR-04 (layout only)   ADRs: ADR-010

## Data
none (client only)

## Interfaces
none

## Acceptance
- [ ] `Tokens.cs` mirrors tokens.css; a test parses tokens.css and fails on any difference
- [ ] Owner-drawn Button (primary, accent, secondary, ghost, danger; loading state), TextInput (numeric, error), ScanField, Chip, Panel, Banner, Toast, Dialog base, KeycapStrip, DataGrid theme (virtual mode), Chart control (bar, line, donut, sparkline with table fallback)
- [ ] Comfortable and Compact density switch per user; light and dark theme switch
- [ ] Focus ring visible on every control; tab order follows layout; high-contrast mode falls back to system colours
- [ ] Fonts installed check: missing Atkinson/Plex falls back to Segoe UI/Consolas and logs a warning
- [ ] A component gallery form (dev only) shows every component and state; screenshots at 1366x768 @100 % and 1920x1080 @150 % on Win7 and Win11 stored in docs/04-development/screens/

## Files expected
src/MediCare.Client/Theme/*, Controls/*, Shell/*, Dev/GalleryForm.cs

## Tests
Theme parity test; snapshot of control metrics (heights, paddings) per density

## Out of scope
Business logic, API calls

## Notes
Match prototype layout in docs/02-design/prototype/index.html; tokens only, no raw colours.
