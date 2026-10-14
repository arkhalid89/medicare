# Launch checklist

## Owner inputs required
- [ ] Pharmacy name, address, phone, drug sale licence number, NTN (Settings → Pharmacy).
- [ ] Licence type (pharmacy or medical store) and whether prices include sales tax.
- [ ] Final Urdu and English receipt footer wording.
- [ ] Discount limits per role and approval thresholds (refund, adjustment, expense).
- [ ] Staff list with roles; which managers get approval PINs.
- [ ] Medicine list in Excel/CSV (name, barcode/GTIN, generic, manufacturer, packs and sizes, purchase and sale price, rack, schedule).
- [ ] Opening stock count by batch and expiry (or a count on go-live morning).
- [ ] Supplier and credit-customer opening balances, cash in hand, bank balances.
- [ ] Seven USB drives for daily off-site backups and who takes them home.
- [ ] Fonts: download the three OFL fonts into `installer/fonts/` before building the installers (see `installer/fonts/README.md`).

## Technical readiness
- [ ] `pwsh ./build.ps1` on a Windows build PC: all tests pass, installers produced.
- [ ] Host PC meets deployment.md (UPS, fixed IP, sleep off, SSD).
- [ ] Host installed; Host Monitor green; fingerprint recorded on paper and kept with the owner.
- [ ] Every counter paired by comparing the fingerprint; counter numbers unique.
- [ ] Test receipt printed on every counter (58/80 mm), Urdu footer readable; cash drawer opens.
- [ ] Barcode scanners: 1D EAN scans add the right medicine; a 2D DataMatrix scan fills batch and expiry at goods-in.
- [ ] Windows 7 counters: .NET 4.8 installed, TLS 1.2 script run, client connects.
- [ ] S25 checks on site: load test (10 counters), keyboard-only pass, restore drill on the training PC.

## Data readiness
- [ ] Import dry run shows 0 errors (or the error rows are fixed in the sheet).
- [ ] Import committed; spot-check 20 medicines (price, pack size, barcode).
- [ ] Opening stock posted; stock valuation report agrees with the count sheet total.
- [ ] Opening balances posted; customer and supplier outstanding reports agree with the old registers.
- [ ] First backup taken and copied to USB with "verified".

## Go-live day
- [ ] Shift opened at each counter with the counted float.
- [ ] First 10 sales watched by a manager; returns, a discount approval and a credit sale tried once.
- [ ] Day closed; shift differences explained; USB backup taken.
- [ ] Self-check passed the next morning.

## Known limitations
- The Windows client was compiled and statically reviewed for both targets in this build environment; it must be exercised on real Windows 7 and Windows 10/11 PCs during the pilot (S25).
- The system is single-pharmacy and LAN-only by design (no branches, no cloud sync, no FBR POS integration).
- Barcode scanners must be in keyboard-wedge mode; serial/COM scanners are not supported.
- Printing uses the Windows printer driver; ESC/POS is only used for the cash drawer pulse.
- No automatic supplier price-list import; purchase prices are entered at goods-in.
