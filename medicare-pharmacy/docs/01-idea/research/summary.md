# Research summary (G2)

Details: [competitors](competitors.md) · [user voice](user-voice.md) · [market](market.md) · [stakeholders](stakeholders.md) · [design patterns](design-patterns.md) · [tech](tech.md) · [risk](risk.md) · [sources](sources.md)

## 1. Landscape in five bullets
- Pakistan has a crowded field of small pharmacy POS vendors. All advertise batch and expiry alerts, and pricing runs from about Rs 2,000/month or about Rs 24k one-time (`single-source`).
- Their "offline" usually means **offline with later cloud sync**, which conflicts with the BRD rule that no transaction may exist outside the central database.
- Deep accounting (COGS, ledgers, cash closing) lives in Indian desktop ERPs (Marg, Gofrugal), which are dealer-sold and GST-centric.
- Regulation is moving: Schedule B/D register and 3-year record retention apply today, and DRAP is phasing in **GS1 DataMatrix** (GTIN, batch, expiry, serial) on packs.
- The binding technical fact: **.NET 10 does not run on Windows 7**, and gRPC does not work on Windows 7 clients.

## 2. Competitor matrix (compact)
| | Offline LAN, one truth | FEFO + expired block | Moving-avg COGS & profit | Cash closing & approvals | Schedule B/D register | GS1 2D scan |
|---|---|---|---|---|---|---|
| PK POS (MediPOS, Posify, TransactFlow, Mint, Pharma Pro) | Partial (sync) | Some | Not shown | Not shown | Not shown | No |
| Marg / Gofrugal (IN) | Yes | Yes | Yes | Partial | Marg: yes | Not shown |
| Excel + registers | n/a | No | No | No | Paper | No |
| **Target** | **Yes** | **Yes** | **Yes** | **Yes** | **Yes** | **Yes** |

## 3. Top 5 gaps (pain x how weakly served)
1. **Single-truth multi-counter**: central service, no sync conflicts, no orphan sales when the host is down.
2. **Correct money**: moving-average COGS, gross vs operating profit, per-cashier closing with approved differences.
3. **Batch truth without typing**: GS1 2D scan at goods-in and POS; FEFO with expired hard block.
4. **Control at the counter**: inline manager approval for discounts, refunds and adjustments, with a full audit trail.
5. **Inspector-ready records**: Schedule B/D register and 3-year retention built in.

## 4. Design patterns
**Adopt:** always-focused scan field; on-screen F-key strip; dense right-aligned money grid; one huge total; expiry colour bands with text; held-sale chips; payment dialog with tender buttons.
**Avoid:** product tile grids; dialog chains; colour-only status; equal-weight dashboard cards; WinForms default 8-9 pt type.

## 5. Technical shortlist and constraints
- Server: .NET 10 Windows service, ASP.NET Core minimal API (HTTPS/1.1 + JSON), SQLite (Microsoft.Data.Sqlite) in WAL, a single write queue, online-backup API.
- Clients: one WinForms codebase multi-targeted **net48 (Win7 SP1) + net10.0-windows**; owner-drawn token theme; own GDI+ charts; driver-based printing (Urdu rasterised).
- Installer: Inno Setup 6.5+ (Windows 7 SP1 minimum). TLS with a self-signed certificate pinned at pairing.
- Capacity: 1,000+ invoices/day is low for a single SQLite writer; the risk is long readers, handled with WAL, read pool and load test.

## 6. Risks and unknowns
| Item | Next step | Owner |
|---|---|---|
| Schedule B/D register format and whether this licence needs it | Confirm with pharmacist-in-charge | Owner |
| FBR Tier-1 status of the pharmacy | Confirm with tax adviser | Owner |
| Sales tax treatment per medicine | Confirm with tax adviser; default 0 % | Owner |
| Windows 7 clients unsupported by Microsoft | Accept risk or plan replacement | Owner |
| Urdu shaping on Win7 printers | Spike in S00 | Build |
| 2D scanner models and GS separator config | List models | Owner |
| Host hardware + UPS | Provide specs | Owner |
