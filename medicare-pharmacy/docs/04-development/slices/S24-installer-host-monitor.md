# S24 - Installer host monitor
Status: todo      Size: L      Depends on: S23

## Goal
One-click host and client installers set up the service, firewall, TLS, fonts and runtime; upgrades keep data; the host monitor shows service health.

## Covers
FR-109, NFR-001, NFR-014   Screens: SCR-33   ADRs: ADR-016

## Data
none new

## Interfaces
Host monitor via local named pipe

## Acceptance
- [ ] Host installer: service, data folder ACLs, cert, firewall rule limited to subnet (with consent), fonts, first-run wizard launch
- [ ] Client installer: OS detection; Win7 → net48 build + .NET 4.8 check + TLS 1.2 enable with consent; Win10/11 → self-contained net10
- [ ] Upgrade N-1 → N with data: safety backup, migrate, health check, rollback on failure
- [ ] Uninstall keeps data unless chosen
- [ ] No outbound internet calls (firewall log during test week)

## Files expected
installer/*.iss, installer/scripts/*.ps1, src/MediCare.HostMonitor/*

## Tests
Install/upgrade/uninstall on clean Win7 SP1 and Win11 VMs

## Out of scope
Anything not listed above.

## Notes
Read CLAUDE.md working rules. Use error codes and copy from flows.md.
