# ADR-010: UI: owner-drawn token theme (direction A + C), own charts
Status: accepted   Date: 2026-10-14   Deciders: owner (gates G3/G4/G6), architect

## Context
The owner chose design direction A with C's keycap strip and a compact density (G4). WinForms dark mode is experimental and Windows 11 only; third-party suites cost money and some do not support both runtimes.

## Options
DevExpress/Telerik/Syncfusion (licence cost, version split); stock WinForms look; **own thin component layer over standard controls**.

## Decision
A `Theme` built from [tokens.css](../../02-design/tokens.css) values (mirrored in `Tokens.cs`, parity test). Owner-drawn buttons, chips, panels, keycap strip, scan field; DataGridView with custom painting in virtual mode; own GDI+ chart control (bar, line, donut, sparkline). Fonts (OFL) installed per machine by the installer. Light theme default; dark theme and compact density are per-user settings.

## Consequences
Full control and an identical look on Win7/Win10/11; more initial UI work (S00 builds the kit). No licence fees or vendor lock-in.
