# Upgrade Plan — MediCare Practice v1.2 → v2.0 (department-based hospital system)

**Inputs:** MedCore HMS Documents 0–8 and `dental_tooth_chart_prototype.html`.
**Decisions (confirmed):**
- Keep the current **core-PHP 8 + MariaDB** stack (no Laravel, no Redis).
- Deploy as a **LAN server**: one server PC, with department PCs using a browser.
- Build in **3 phases**, then run **one testing stage** at the end.

## Ground rules

- **`visits` is the encounter.** Every `encounter_id` in the documents is `visit_id` here.
- **The doctor's one-page consultation stays intact.** Departments plug into it; they do not replace it.
- **Database upgrades go through `core/Migrator.php` (v5 onwards).** Each step is idempotent and takes a safety backup first. `database/schema.sql` always describes the latest schema.
- **Branch-ready.** A `branches` table exists and one default branch is seeded. The branch switcher only appears once there is a second branch.
- **The server enforces every permission, branch and department check.** Hiding something in the UI is never the security boundary (Doc 5 §5, Doc 8 §7 #10).
- **Technology substitutions:**

  | The documents assume | Built here as |
  |---|---|
  | Livewire widget | PHP partial + vanilla-JS module |
  | Redis permission cache | Session cache, refreshed when `permissions_version` changes |
  | Broadcasting | Polling |
  | Queues / scheduler | "Daily job on first request" (same pattern as the auto-backup) |

---

## Phase 1 — Platform, RBAC & Front Desk

**LAN server & security**
- Launcher option "Server mode": PHP listens on the LAN with a fixed port and a Windows firewall rule. MariaDB stays on `127.0.0.1`.
- New installs default to **bcrypt** password hashing.
- Configurable idle timeout per role group (shorter for shared terminals).
- Optional TOTP 2FA, enforced for SA / ITA / HA / BC and approver roles.

**RBAC (Docs 1, 5 §5, 7 §1, 8)**
- New tables: `branches`, `specialties`, `roles` (with base-role inheritance, specialty, department type, default workspace), `permissions`, `role_permission` (scope: own / department / branch / chain), `user_role_branch` (role + branch + department), `service_points`, `workspaces`, `workspace_widgets`, `role_workspace`.
- `users.role` becomes the **user class** (super_admin / dept_admin / doctor / reporting / staff). Existing code keeps working, and fine-grained rights come from the role tables.
- Full role catalog from Docs 1 and 8.
- New screens:
  - Roles & permission matrix with a scope per cell (Super Admin)
  - Multi-role user assignment
  - Branch/department context switcher
  - Menu built from workspaces
- `emergency_access_grants` provides break-glass access: time-boxed, a reason is required, and every grant is reviewed.
- `activity_logs` gains old/new values. The audit log is immutable.

**Patients & registration (Docs 2 §5.1, 7 §2)**
- New patient columns: `registration_type`, `mother_patient_id`, `merged_into_patient_id`, `photo_path`, `occupation`, `marital_status`, `preferred_language`, `created_branch_id`.
- New tables: `patient_identifiers`, `patient_contacts`, `patient_documents`, `patient_merge_log`.
- CNIC validation. Duplicate check on CNIC plus fuzzy name / DOB / phone. Merge duplicates (RGS). Emergency unidentified registration (ERG).
- An optional chain-wide MRN pattern. Patients become hospital-wide, no longer owned by one doctor.

**Billing glue (Docs 2 §5.12, 3 WF 8, 7 §8)**
- New tables: `service_catalog`, `service_prices`, `visit_types`, `invoices` (FBR-style sequential numbers), `invoice_items`, `payments`, `refunds`.
- Billing counter workspace:
  - receipt print, Urdu/English
  - discounts within the user's limit
  - refunds with approval
  - daily cash close
- Consultation checkout mirrors its bill into an invoice.

**Token queues & appointments (Docs 3 WF 1 & 9, 7 §2, 8 §5.1)**
- Token tables: `token_queues`, `tokens`, `token_events`, `display_boards`, `display_board_queues`.
- Scheduling tables: `doctor_schedules`, `doctor_leaves`, `appointment_slots`, `appointments`.
- Vitals: a `vitals` table for the nurse vitals station.
- Workspaces:
  - Registration + token (no token without a receipt or a waiver)
  - Token console (call next / recall / skip / transfer; priority ordering)
  - Display board (no login)
  - Appointments (slots, booking, leave approval)
  - Nurse vitals station
- The doctor's consultation shows their queue. "Call next" opens the consultation prefilled, and checkout completes the token.

---

## Phase 2 — Pharmacy, Stock & Laboratory

**Unified stock (Doc 7 §6)**
- Masters: `item_categories`, `items`, `uoms`, `item_uom_conversions`, `stock_locations`, `item_location_rules`, `vendors`, `vendor_items`.
- Stock core: `stock_batches`, `stock_balances`, `stock_ledger` (append-only). `StockService::move()` is the single write path. It picks batches FEFO, blocks negative stock and blocks expired or quarantined batches.
- Procurement and movements:
  - `purchase_requisitions`, `purchase_orders` (+items), `goods_receipt_notes` (+items)
  - `stock_requisitions` (indents), `stock_issues`, `stock_transfers`, `stock_adjustments` (approval), `stock_counts` (blind)
  - `vendor_returns`, `expiry_watchlist`, `recall_notices`, `assets` + maintenance, `service_consumption_templates`
- Segregation of duties is enforced: the PO creator cannot post the GRN, and the person who proposes an adjustment cannot approve it.
- Migration: every medicine becomes an item, and "Hospital Stock" becomes the pharmacy location.
- Workspaces: store dashboard, receiving, procurement, indent request, stocktake.

**Pharmacy (Docs 2 §5.10, 3 WF 6, 7 §7)**
- New tables: `dispenses` (+items), `pharmacy_sales` (+items), `pharmacy_returns` (+items), `drug_interactions`, `drug_allergy_classes`, `drug_substitutions`, `controlled_drug_register`.
- Dispense queue from the hospital-source lines of signed prescriptions:
  - allergy and interaction alerts
  - FEFO batch selection
  - substitution or partial dispense
  - controlled-drug co-sign
- OTC counter sale, returns, and chief-pharmacist screens (formulary, near-expiry, recalls).

**Laboratory (Docs 1 §4a, 2 §5.8, 3 WF 5, 7 §4, 8 §5.4)**
- Catalog tables: `lab_sections`, `lab_test_categories`, `lab_specimen_types`, `lab_containers`, `lab_tests`, `lab_test_parameters`, `lab_reference_ranges`, `lab_panel_tests`, `lab_analyzers`.
- Workflow tables: `lab_orders` (+items), `lab_samples`, `lab_sample_reviews`, `lab_worksheets` (+items), `lab_results`, `lab_result_values`, `lab_qc_materials`, `lab_qc_runs`, `lab_reports`, `lab_critical_notifications`, `lab_outsource_referrals`, `user_lab_sections`.
- Orders come from the consultation's investigations.
- Collection with barcode labels.
- **Pathologist sample gate:** accept, or reject with exactly one of resample or refund.
- Section-restricted result entry. Reference ranges by age and sex, snapshotted on each value.
- **Verification gate**, blocked when the latest QC failed. Critical-value read-back. Report print.
- Workspaces: reception, collection, bench, pathologist, QC.

---

## Phase 3 — Specialty Departments & Radiology

**Specialty framework (Doc 6)**
- Tier 1 generic fields: `clinical_parameter_templates`, `encounter_clinical_parameters`. They are rendered in the consultation for the department's specialty.
- Body-map framework: `body_map_templates`, `body_map_regions`, `encounter_body_map_findings`.
- Light specialty tables: `eye_exams`, `growth_measurements`, `vaccines`, `immunization_records`, `cardiac_investigations`, `audiograms`.
- `clinical_notes` (restricted flag + share list) and `referrals`.

**Dentistry (Docs 6 §5, 7 §3.1, 8 §5.2, prototype)**
- New tables: `tooth_reference` (52 teeth), `dental_charts`, `dental_tooth_findings`, `dental_finding_surfaces`, `dental_procedure_catalog`, `dental_treatment_plans`, `dental_treatment_plan_items`, `dental_perio_charts`, `dental_perio_measurements`, `dental_lab_cases`, `dental_chart_images`.
- SVG tooth map:
  - multi-select, quadrant and arch selection, keyboard and ARIA support
  - dentition chosen by age
  - compare with the prior chart
- Treatment plan with acceptance. "Done today" posts an invoice item and deducts the procedure's consumables. DRM entries need a co-sign.
- Chairside and dental lab-case workspaces.

**Gynaecology / Obstetrics (Docs 6 §6, 7 §3.2, 8 §5.3)**
- New tables: `gynecologic_history`, `obstetric_history` (derived), `pregnancies`, `pregnancy_deliveries`, `antenatal_visits`, `postnatal_visits`, `obstetric_ultrasound_findings`.
- GA and EDD calculated automatically. Risk rules lead to a referral. Newborn registration is linked to the mother. Notes are restricted by default.
- Midwife ANC workspace.

**Radiology (Docs 1 §4a, 2 §5.9, 3 WF 5B, 7 §5, 8 §5.5)**
- Setup tables: `radiology_modalities`, `radiology_rooms`, `radiology_equipment`, `radiology_procedure_catalog`, `radiology_protocols`, `radiology_report_templates`.
- Order and acquisition tables: `radiology_orders`, `radiology_procedures`, `radiology_procedure_reviews`, `radiology_contrast_usage`, `radiology_dose_log`, `radiology_schedule_slots`, `user_modalities`.
- Reporting tables: `radiology_reports`, `radiology_report_addenda`.
- Pregnancy screening before ionising studies.
- **Radiologist gate:** accept, reprocess, or reject + refund.
- A finalised report is immutable; changes are added as addenda.
- Workspaces: reception, technician, radiologist.

---

## Testing stage (after all three phases)

1. `tests/lint.php`: syntax-check every PHP file.
2. Fresh install and upgrade test. Restore a v1.2 backup, run the Migrator v5→latest, and verify no data is lost.
3. `tests/run.php`: the existing 42 tests, plus new unit and integration tests for:
   - RBAC inheritance and scope
   - token concurrency
   - invoicing
   - StockService (FEFO, negative stock, segregation of duties)
   - the lab gates
   - dental findings and surfaces
   - the obstetric derived cache
   - radiology addenda
   - each Doc 8 §7 acceptance criterion
4. `tests/smoke.php`: log in as every seeded role, open every route, expect 200 or 403 as the matrix dictates, and run each workspace's main action.
5. A LAN check: open the app from a second machine (manual step for the clinic).

### Results (2026-10-13)

All three phases are implemented. Tests ran against a throwaway MariaDB 10.11 and the bundled PHP 8.2.

| Check | Result |
|---|---|
| `tests/lint.php`: every PHP file | 285 files, 0 errors |
| `tests/run.php`: 54 v1 tests (updated for v2 defaults) + 20 v2 tests in `tests/v2.php` | **74 passed, 0 failed** |
| Fresh install with demo data (schema v7) | 5.6 s; every v2 demo block (stock, tokens, lab, radiology, dental, obgyn, EPI, pharmacy, billing) completes |
| Upgrade test: the committed v1 code with demo data (151 patients, 240 visits, 601 Rx lines) migrated with the v2 code | v1 → v7 in 3.1 s. All patients, visits and prescription lines kept, visit totals unchanged. Every user has a role, every visit a department, every medicine a stock item. |
| `tests/smoke.php`: 18 sample users × every GET route, exports, prints, checkout, CSRF and forbidden actions | **3,266 checks passed, 0 failed** |
| Launcher (`MediCareLauncher.cs`, LAN mode) compiled with the .NET `csc` used by `build.ps1` | Compiles cleanly. The installer build and the second-PC LAN test were not run. |
| Browser check: dentist consultation tooth map (select 26 + 27, surfaces M + O, filling planned) | Renders and records the finding as specified; no console errors |

Bugs found and fixed during testing:
- `lines` used as an SQL alias, which is a reserved word in MariaDB. It broke the pharmacy queue, indents, stock counts and the revenue report.
- Lab orders placed without a priority raised an error.
- Immunization drew vaccines from a location without cold storage. It now uses a cold-chain location.
- The tooth map was clipped on narrow screens.
- Historical demo visits flooded the department worklists. The hooks are now off while the v1 demo history is generated.

Still to do on site:
- Build `setup.exe` with `installer\build.ps1`.
- Install it on the hospital server with the *Hospital server (LAN)* task.
- Open `http://<server-IP>:8765/` from a department PC.

## Deferred to v3 (needs further client sign-off)

- IPD wards, beds, ICU, OT and transfers (Doc 3 WF 2–4)
- HR and payroll
- Insurance / TPA and Sehat Sahulat claims
- SMS and WhatsApp gateways
- Patient portal
- Live FBR API
- Analyzer ASTM/HL7 interfaces
- PACS
- Multi-branch data sync (each install is one branch)
