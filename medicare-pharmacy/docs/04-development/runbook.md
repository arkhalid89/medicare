# Runbook

Status of every build slice and a ready prompt for each. Update the table at the end of every slice.

| Slice | Name | Size | Depends on | Status | Date |
|---|---|---|---|---|---|
| [S00](slices/S00-walking-skeleton.md) | Walking skeleton | M | - | done | 2026-10-14 |
| [S01](slices/S01-ui-kit-and-shell.md) | Ui kit and shell | M | S00 | done | 2026-10-14 |
| [S02](slices/S02-auth-users-permissions.md) | Auth users permissions | M | S01 | done | 2026-10-14 |
| [S03](slices/S03-posting-core-journal-audit.md) | Posting core journal audit | L | S02 | done | 2026-10-14 |
| [S04](slices/S04-medicine-master.md) | Medicine master | M | S03 | done | 2026-10-14 |
| [S05](slices/S05-medicine-import.md) | Medicine import | M | S04 | done | 2026-10-14 |
| [S06](slices/S06-suppliers-customers-opening-balances.md) | Suppliers customers opening balances | S | S03 | done | 2026-10-14 |
| [S07](slices/S07-goods-in-purchases.md) | Goods in purchases | L | S04, S06 | done | 2026-10-14 |
| [S08](slices/S08-stock-views-and-ledger.md) | Stock views and ledger | M | S07 | done | 2026-10-14 |
| [S09](slices/S09-pos-cart-fefo.md) | Pos cart fefo | L | S08 | done | 2026-10-14 |
| [S10](slices/S10-payment-posting-printing.md) | Payment posting printing | L | S09 | done | 2026-10-14 |
| [S11](slices/S11-approvals.md) | Approvals | M | S10 | done | 2026-10-14 |
| [S12](slices/S12-holds-history-prescriptions.md) | Holds history prescriptions | M | S10 | done | 2026-10-14 |
| [S13](slices/S13-customer-ledger-receipts.md) | Customer ledger receipts | M | S10 | done | 2026-10-14 |
| [S14](slices/S14-sales-returns.md) | Sales returns | M | S10, S11 | done | 2026-10-14 |
| [S15](slices/S15-purchase-returns-reversals.md) | Purchase returns reversals | M | S07, S11 | done | 2026-10-14 |
| [S16](slices/S16-supplier-ledger-payments.md) | Supplier ledger payments | M | S07 | done | 2026-10-14 |
| [S17](slices/S17-adjustments-counts-writeoff.md) | Adjustments counts writeoff | M | S08, S11 | done | 2026-10-14 |
| [S18](slices/S18-cashbook-bankbook-expenses.md) | Cashbook bankbook expenses | M | S10 | done | 2026-10-14 |
| [S19](slices/S19-shift-and-day-closing.md) | Shift and day closing | M | S18, S11 | done | 2026-10-14 |
| [S20](slices/S20-dashboard-selfcheck.md) | Dashboard selfcheck | M | S10, S18 | done | 2026-10-14 |
| [S21](slices/S21-reports.md) | Reports | L | S20 | done | 2026-10-14 |
| [S22](slices/S22-audit-viewer-settings.md) | Audit viewer settings | M | S03 | done | 2026-10-14 |
| [S23](slices/S23-backup-restore.md) | Backup restore | L | S03, S22 | done | 2026-10-14 |
| [S24](slices/S24-installer-host-monitor.md) | Installer host monitor | L | S23 | done | 2026-10-14 |
| [S25](slices/S25-hardening-load-a11y-security.md) | Hardening load a11y security | L | S24 | partial | 2026-10-14 |
| [S26](slices/S26-launch-pack.md) | Launch pack | M | S25 | done | 2026-10-14 |

## Prompts

Paste one prompt per session. Each prompt keeps Claude Code to one slice and the documents that slice points to.

### S00 - Walking skeleton
```
Implement or extend slice S00 following CLAUDE.md. Read slices/S00-walking-skeleton.md and only the sections it references. Do not start other slices. When done, run the slice's tests and a full build of both client targets, update the status table in docs/04-development/runbook.md, and summarise what changed in three lines.
```

### S01 - Ui kit and shell
```
Implement or extend slice S01 following CLAUDE.md. Read slices/S01-ui-kit-and-shell.md and only the sections it references. Do not start other slices. When done, run the slice's tests and a full build of both client targets, update the status table in docs/04-development/runbook.md, and summarise what changed in three lines.
```

### S02 - Auth users permissions
```
Implement or extend slice S02 following CLAUDE.md. Read slices/S02-auth-users-permissions.md and only the sections it references. Do not start other slices. When done, run the slice's tests and a full build of both client targets, update the status table in docs/04-development/runbook.md, and summarise what changed in three lines.
```

### S03 - Posting core journal audit
```
Implement or extend slice S03 following CLAUDE.md. Read slices/S03-posting-core-journal-audit.md and only the sections it references. Do not start other slices. When done, run the slice's tests and a full build of both client targets, update the status table in docs/04-development/runbook.md, and summarise what changed in three lines.
```

### S04 - Medicine master
```
Implement or extend slice S04 following CLAUDE.md. Read slices/S04-medicine-master.md and only the sections it references. Do not start other slices. When done, run the slice's tests and a full build of both client targets, update the status table in docs/04-development/runbook.md, and summarise what changed in three lines.
```

### S05 - Medicine import
```
Implement or extend slice S05 following CLAUDE.md. Read slices/S05-medicine-import.md and only the sections it references. Do not start other slices. When done, run the slice's tests and a full build of both client targets, update the status table in docs/04-development/runbook.md, and summarise what changed in three lines.
```

### S06 - Suppliers customers opening balances
```
Implement or extend slice S06 following CLAUDE.md. Read slices/S06-suppliers-customers-opening-balances.md and only the sections it references. Do not start other slices. When done, run the slice's tests and a full build of both client targets, update the status table in docs/04-development/runbook.md, and summarise what changed in three lines.
```

### S07 - Goods in purchases
```
Implement or extend slice S07 following CLAUDE.md. Read slices/S07-goods-in-purchases.md and only the sections it references. Do not start other slices. When done, run the slice's tests and a full build of both client targets, update the status table in docs/04-development/runbook.md, and summarise what changed in three lines.
```

### S08 - Stock views and ledger
```
Implement or extend slice S08 following CLAUDE.md. Read slices/S08-stock-views-and-ledger.md and only the sections it references. Do not start other slices. When done, run the slice's tests and a full build of both client targets, update the status table in docs/04-development/runbook.md, and summarise what changed in three lines.
```

### S09 - Pos cart fefo
```
Implement or extend slice S09 following CLAUDE.md. Read slices/S09-pos-cart-fefo.md and only the sections it references. Do not start other slices. When done, run the slice's tests and a full build of both client targets, update the status table in docs/04-development/runbook.md, and summarise what changed in three lines.
```

### S10 - Payment posting printing
```
Implement or extend slice S10 following CLAUDE.md. Read slices/S10-payment-posting-printing.md and only the sections it references. Do not start other slices. When done, run the slice's tests and a full build of both client targets, update the status table in docs/04-development/runbook.md, and summarise what changed in three lines.
```

### S11 - Approvals
```
Implement or extend slice S11 following CLAUDE.md. Read slices/S11-approvals.md and only the sections it references. Do not start other slices. When done, run the slice's tests and a full build of both client targets, update the status table in docs/04-development/runbook.md, and summarise what changed in three lines.
```

### S12 - Holds history prescriptions
```
Implement or extend slice S12 following CLAUDE.md. Read slices/S12-holds-history-prescriptions.md and only the sections it references. Do not start other slices. When done, run the slice's tests and a full build of both client targets, update the status table in docs/04-development/runbook.md, and summarise what changed in three lines.
```

### S13 - Customer ledger receipts
```
Implement or extend slice S13 following CLAUDE.md. Read slices/S13-customer-ledger-receipts.md and only the sections it references. Do not start other slices. When done, run the slice's tests and a full build of both client targets, update the status table in docs/04-development/runbook.md, and summarise what changed in three lines.
```

### S14 - Sales returns
```
Implement or extend slice S14 following CLAUDE.md. Read slices/S14-sales-returns.md and only the sections it references. Do not start other slices. When done, run the slice's tests and a full build of both client targets, update the status table in docs/04-development/runbook.md, and summarise what changed in three lines.
```

### S15 - Purchase returns reversals
```
Implement or extend slice S15 following CLAUDE.md. Read slices/S15-purchase-returns-reversals.md and only the sections it references. Do not start other slices. When done, run the slice's tests and a full build of both client targets, update the status table in docs/04-development/runbook.md, and summarise what changed in three lines.
```

### S16 - Supplier ledger payments
```
Implement or extend slice S16 following CLAUDE.md. Read slices/S16-supplier-ledger-payments.md and only the sections it references. Do not start other slices. When done, run the slice's tests and a full build of both client targets, update the status table in docs/04-development/runbook.md, and summarise what changed in three lines.
```

### S17 - Adjustments counts writeoff
```
Implement or extend slice S17 following CLAUDE.md. Read slices/S17-adjustments-counts-writeoff.md and only the sections it references. Do not start other slices. When done, run the slice's tests and a full build of both client targets, update the status table in docs/04-development/runbook.md, and summarise what changed in three lines.
```

### S18 - Cashbook bankbook expenses
```
Implement or extend slice S18 following CLAUDE.md. Read slices/S18-cashbook-bankbook-expenses.md and only the sections it references. Do not start other slices. When done, run the slice's tests and a full build of both client targets, update the status table in docs/04-development/runbook.md, and summarise what changed in three lines.
```

### S19 - Shift and day closing
```
Implement or extend slice S19 following CLAUDE.md. Read slices/S19-shift-and-day-closing.md and only the sections it references. Do not start other slices. When done, run the slice's tests and a full build of both client targets, update the status table in docs/04-development/runbook.md, and summarise what changed in three lines.
```

### S20 - Dashboard selfcheck
```
Implement or extend slice S20 following CLAUDE.md. Read slices/S20-dashboard-selfcheck.md and only the sections it references. Do not start other slices. When done, run the slice's tests and a full build of both client targets, update the status table in docs/04-development/runbook.md, and summarise what changed in three lines.
```

### S21 - Reports
```
Implement or extend slice S21 following CLAUDE.md. Read slices/S21-reports.md and only the sections it references. Do not start other slices. When done, run the slice's tests and a full build of both client targets, update the status table in docs/04-development/runbook.md, and summarise what changed in three lines.
```

### S22 - Audit viewer settings
```
Implement or extend slice S22 following CLAUDE.md. Read slices/S22-audit-viewer-settings.md and only the sections it references. Do not start other slices. When done, run the slice's tests and a full build of both client targets, update the status table in docs/04-development/runbook.md, and summarise what changed in three lines.
```

### S23 - Backup restore
```
Implement or extend slice S23 following CLAUDE.md. Read slices/S23-backup-restore.md and only the sections it references. Do not start other slices. When done, run the slice's tests and a full build of both client targets, update the status table in docs/04-development/runbook.md, and summarise what changed in three lines.
```

### S24 - Installer host monitor
```
Implement or extend slice S24 following CLAUDE.md. Read slices/S24-installer-host-monitor.md and only the sections it references. Do not start other slices. When done, run the slice's tests and a full build of both client targets, update the status table in docs/04-development/runbook.md, and summarise what changed in three lines.
```

### S25 - Hardening load a11y security
```
Implement or extend slice S25 following CLAUDE.md. Read slices/S25-hardening-load-a11y-security.md and only the sections it references. Do not start other slices. When done, run the slice's tests and a full build of both client targets, update the status table in docs/04-development/runbook.md, and summarise what changed in three lines.
```

### S26 - Launch pack
```
Implement or extend slice S26 following CLAUDE.md. Read slices/S26-launch-pack.md and only the sections it references. Do not start other slices. When done, run the slice's tests and a full build of both client targets, update the status table in docs/04-development/runbook.md, and summarise what changed in three lines.
```

## Open work

S25 is partial. Run these on the pharmacy's own Windows hardware before go-live (details in testing.md):
1. Load test: 10 counters, 1,000+ invoices in a day, p95 sale post under 1 s on the LAN.
2. Keyboard-only and contrast pass on every screen, on a Windows 7 PC and a Windows 10/11 PC, at 100% and 125% scaling.
3. Restore drill: back up, copy to USB, restore on a spare PC, run the self-check, compare dashboard figures.
4. Print test on the actual receipt printers (58 and 80 mm) including the Urdu footer.

```
Finish slice S25 following CLAUDE.md. Read docs/04-development/slices/S25-hardening-load-a11y-security.md and docs/04-development/testing.md (load test, restore drill, security checklist). Write any missing automated checks, record results in testing.md, fix defects with a failing test first, then mark S25 done in the runbook.
```

## Checkpoints

| When | Run | Report |
|---|---|---|
| After any host change | `dotnet test tests/MediCare.Host.Tests` | failures with the scenario name |
| After any client change | `dotnet build src/MediCare.Client` (both targets) | warnings count must stay 0 |
| Before release | `pwsh ./build.ps1` on Windows, then the launch checklist | installer paths, test totals |

## When stuck

| Question | Source of truth |
|---|---|
| What should it do? | prd.md (FR ids) and flows.md (SCR ids) |
| Why is it built this way? | adr/ (final; ask the owner before changing) |
| How much / which number? | data-model.md (units), testing.md (vectors) |
| How does it look? | design-system.md, tokens.css, prototype |
| Anything about the pharmacy's own process | ask the pharmacy owner |
