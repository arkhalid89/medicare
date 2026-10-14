# ADR-011: Printing through the Windows driver, Urdu rasterised by the OS
Status: accepted   Date: 2026-10-14   Deciders: owner (gates G3/G4/G6), architect

## Context
Receipts need Urdu lines; most ESC/POS printers cannot shape Arabic script ([tech.md](../../01-idea/research/tech.md) T5).

## Options
Raw ESC/POS text (no Urdu); ESC/POS with self-rendered bitmaps; **GDI printing via the printer's Windows driver**.

## Decision
`System.Drawing.Printing` with templates per paper size (58/80 mm, A4). Urdu set in Noto Naskh Arabic by default (Nastaliq optional where the S00 spike passes). Cash drawer kick via a raw ESC/POS pulse through the spooler. Printing runs after posting succeeds and never inside the posting transaction; reprints are marked DUPLICATE and audited.

## Consequences
Needs the vendor's Windows driver installed on each counter (owner input: printer models). Print speed is slightly lower than raw text, which is acceptable at receipt length.
