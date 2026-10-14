# ADR-016: Installer and runtime packaging
Status: accepted   Date: 2026-10-14   Deciders: owner (gates G3/G4/G6), architect

## Context
BRD s15: one installer, repeatable upgrades, data preserved, firewall with consent, no weakening of security.

## Options
WiX (MSI, steeper); MSIX (no Win7); **Inno Setup 6.5+** (Win7 SP1 minimum supported).

## Decision
Two installers from one script set: **Host** (service self-contained .NET 10 x64, host monitor, DB creation via migrations, firewall rule restricted to the LAN subnet, TLS certificate generation, fonts, data folder ACLs) and **Client** (detects OS: Win10/11 gets the self-contained .NET 10 build; Win7 SP1 gets the net48 build plus a .NET Framework 4.8 prerequisite check and, with consent, enables TLS 1.2 in SChannel). Upgrades: stop service → safety backup → replace binaries → migrate → start → health check; automatic rollback of binaries if health fails. Uninstall keeps `D:\MediCareData` unless the admin ticks remove.

## Consequences
Code-signing certificate recommended (owner input) to avoid SmartScreen warnings. Offline installers are larger (self-contained, about 70-90 MB).
