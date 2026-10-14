# ADR-014: GS1 parsing and batch resolution
Status: accepted   Date: 2026-10-14   Deciders: owner (gates G3/G4/G6), architect

## Context
DRAP is phasing in GS1 DataMatrix (01, 17, 10, 21) on packs; most stock will still carry 1D EAN-13 for years ([risk.md](../../01-idea/research/risk.md) L5).

## Options
Ignore 2D; require 2D; **support both with a single parser**.

## Decision
`Gs1Parser` in Core accepts the raw keyboard-wedge string (GS = ASCII 29; `]d2`/`]C1` symbology prefixes; bracketed AIs typed by hand) and returns GTIN, batch, expiry, mfg and serial. Resolution: GTIN → barcode → medicine + pack. At goods-in it fills the batch and expiry. At POS: if the scanned batch is saleable, use it (pick = 2D); otherwise warn and fall back to FEFO. Serials are not stored in release 1 (no national verification endpoint offline).

## Consequences
Scanners must be configured to emit GS (setup guide per model). Expiry with day `00` means the end of that month.
