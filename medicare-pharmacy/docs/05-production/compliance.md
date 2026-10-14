# Compliance and controls

This is not legal advice. Items marked **Owner/legal** need confirmation by the pharmacy owner or their adviser.

## Data classification
| Data | Class | Where | Notes |
|---|---|---|---|
| Sales, purchases, stock, ledgers | Business confidential | Host database, backups | Needed for tax and drug inspection |
| Customer names, mobiles, balances | Personal | Host database | Only what is needed for credit and returns |
| Prescription register (prescriber, patient, Rx no.) | Sensitive personal (health) | Host database, controlled register report | Access limited to roles with admin reports |
| User accounts, password hashes, PIN hashes | Security | Host database | PBKDF2-SHA256, 600,000 iterations; never logged |
| TLS certificate and key | Security | `Data/certs` on the host | Protect with the host PC's own Windows login |

## Controls in the product
| Control | How | Requirement |
|---|---|---|
| Access | Named users, roles, per-user grants/revokes, permissions checked on the host for every request | FR-006..FR-008 |
| Authentication | Password policy (length, letters and digits, not the username, last 3 not reused); lockout after 5 failures for 15 min; screen lock after inactivity | FR-001..FR-004 |
| Separation of duties | Discounts above the role limit, refunds, credit above limit, adjustments, write-offs, reversals, cash differences, large expenses and postings after day close need a second person's PIN or decision | FR-009, FR-010, FR-063 |
| Integrity | Posted documents cannot be edited or deleted (database triggers); corrections are reversals; double-entry journal behind every posting | NFR-013, ADR-007, ADR-008 |
| Audit trail | Every sensitive action with user, PC, time, before/after, reason and approver; SHA-256 hash chain checked nightly | FR-100 |
| Expired medicines | Expired batches cannot be sold (no override); near-expiry alerts; quarantine and write-off with approval | FR-061, FR-033 |
| Controlled drugs | Schedule B/D sales need prescriber and patient; register report by period | FR-097 |
| Transport security | TLS with the host certificate pinned on every counter; firewall rule limited to the local subnet; workstations can be blocked | ADR-003, FR-012, FR-013 |
| Availability | Hourly and daily backups with integrity check; verified USB copy; restore with safety copy and confirmation | FR-103..FR-105 |

## Retention
- Posted records, journal, stock movements and audit log are kept online for at least 3 years (NFR-013); nothing is hard-deleted by the application.
- Daily backups: 30 on the host; hourly: 24. Off-site USB rotation: 7 drives (owner's policy).
- Host logs: 14 days. Client logs: kept on each PC until cleaned by the IT helper.

## Open questions (Owner/legal)
1. Exact retention period required by the provincial drug rules for sale and purchase records and the Schedule B/D register (3 years assumed).
2. Whether the pharmacy is required to integrate with FBR's POS invoice system (not in scope; would need a separate project).
3. Format and wording of the controlled-drug register expected by the local drug inspector (current report: date, invoice, medicine, batch, quantity, prescriber, registration no., patient).
4. Customer consent wording for keeping mobile numbers for credit accounts.
5. Who may take the USB backups home, and how they are stored (the backups contain the whole database, unencrypted).

## Owner responsibilities
- Keep the administrator password and the certificate fingerprint on paper in a safe place.
- Review the audit report and discount report weekly; review the self-check result daily.
- Remove users the same day staff leave (Users → untick Active).
- Run the quarterly restore drill.
