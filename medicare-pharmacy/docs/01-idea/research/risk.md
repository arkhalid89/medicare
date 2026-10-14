# Regulation, risk and compliance

Accessed 2026-10-14. **Not legal advice.** Items marked "owner/lawyer" need confirmation by the owner, their pharmacist-in-charge or a lawyer.

## Regulation
| # | Item | What it means for the system | Tag | Who confirms |
|---|---|---|---|---|
| L1 | Punjab Drugs Rules 2007: keep supply records, bills or counterfoils **at least 3 years** | Never delete posted documents; retention >= 3 years; restore keeps history | single-source (district govt + PCDA copies) | owner/lawyer |
| L2 | **Schedule B and D drugs** sold only against a registered practitioner's prescription; **special register** entry at time of supply: serial, date, prescriber, patient, drug, manufacturer, qty, **batch**, qualified person's signature, purchased qty and balance | Optional "controlled" flag on medicine; POS captures prescriber and patient; register report; serial written on prescription | single-source (rule copies) | owner/lawyer |
| L3 | Medical store licensee may not stock **Schedule G** | Licence type setting; block receiving Schedule-G-flagged items when licence = medical store | single-source | owner |
| L4 | Inspections at least every 3 months | Register and stock reports printable on demand | single-source | - |
| L5 | DRAP draft track-and-trace: GS1 DataMatrix (01 GTIN, 17 expiry, 10 batch, 21 serial) on secondary packs; news: barcodes mandatory for medicines made from 19 Oct 2026 | Parse GS1 DataMatrix; store GTIN as barcode; serial optional; phased reality means 1D EAN-13 and no-code items remain for years | single-source (each) | owner to watch DRAP notices |
| L6 | FBR Tier-1 POS integration (internet) | Out of scope; keep an "external fiscal hook" interface; owner confirms the pharmacy is not Tier-1 | verified (FBR STGO docs + news) | owner/tax adviser |
| L7 | Sales tax on medicines: DRAP-registered drugs reported under Eighth Schedule at reduced rates; herbal/homeopathic possibly 18 % | Per-medicine tax rate and inclusive/exclusive mode; default 0 % retail | unverified | owner/tax adviser |
| L8 | Pakistan has **no enacted** personal data protection law as of mid-2026; PECA 2016 applies to misuse | Minimal personal data (name, mobile, address); access by role; no export of customer lists without permission | verified (2 sources) | - |

## Delivery and operational risks
| # | Risk | Likelihood | Impact | Mitigation |
|---|---|---|---|---|
| R1 | Windows 7 clients: no security patches; TLS 1.2 disabled by default | High | High | Win7 = client only; installer enables TLS 1.2 for SChannel with admin consent (this strengthens security); firewall to LAN; recommend replacement plan |
| R2 | Windows 10 out of support (Oct 2025) on host | Medium | Medium | Recommend Windows 11 host; document |
| R3 | Two runtimes double the UI test matrix | High | Medium | Shared UI library multi-targeted; only `Program.cs`/DPI bootstrap differ; CI builds both; VM smoke per release |
| R4 | Host is a single point of failure | Medium | High | UPS on host; hourly on-host backup + daily USB; documented promote-a-spare-PC procedure (RTO 60 min) |
| R5 | Writer starvation by long report queries | Medium | Medium | WAL; separate read pool; reports with paging and date bounds; load test gate |
| R6 | Urdu shaping on Win7 printers | Medium | Low | Spike; Naskh fallback; Urdu line optional |
| R7 | 2D scanners not configured to send GS separator | Medium | Low | Parser also accepts parenthesised AIs and fixed-length heuristics; setup guide per scanner |
| R8 | Data entry quality at import (duplicates, wrong pack sizes) | High | High | Import preview with validation, dedupe by barcode/name+strength, dry run, rollback |
| R9 | Clock drift between PCs affects timestamps | Medium | Medium | Server is the only clock; clients never stamp posted records |
