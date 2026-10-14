# ADR-015: Time and business dates
Status: accepted   Date: 2026-10-14   Deciders: owner (gates G3/G4/G6), architect

## Context
Clients may have wrong clocks; reports are by local business day; Pakistan does not observe DST at present.

## Options
Client timestamps; **host-only timestamps**.

## Decision
The host stamps all `*_at` in UTC; `business_date` is computed on the host in `Asia/Karachi` (Windows ID "Pakistan Standard Time"). Clients display host-provided times and never send timestamps for posted records. Day close uses business_date.

## Consequences
Correct ordering across PCs. The host clock must be right: the setup checklist covers it, and the host monitor warns if a client differs by more than 5 minutes.
