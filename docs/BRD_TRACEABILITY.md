# BRD v2.0 → implementation map

## Decisions that differ from the BRD (client instructions take priority)

| # | BRD says | Implemented | Why |
|---|---|---|---|
| D1 | C# desktop (WPF/WinForms) + SQLite (§6) | Core PHP 8 web app + MySQL/MariaDB, running offline on localhost (XAMPP) | Client instruction: "core PHP, version 8, no framework". |
| D2 | Passwords hashed with PBKDF2 (§28) | Stored in plain text (`security.password_mode = 'plain'`) | Client instruction. Switching to `'bcrypt'` needs one config change, and existing passwords are upgraded automatically. |
| D3 | Roles: Admin + Doctor (§4) | Super Admin (= BRD Admin), Department Admin, Doctor, Reporting User | Client instruction: department admins with multiple departments, and a reporting user. |
| D4 | Separate junction tables per list (§84) | `visit_clinical_items` / `template_clinical_items` with an `item_type` column | One table and one code path for complaints, symptoms, diagnoses and investigations (BRD §76 "no duplicate code"). |
| D5 | `rx_split_config` table | Settings keys `rx_split_*` + `medicine_sources.display_order` for the section order | Same behaviour with less schema. |
| D6 | Backup file `.db` (§77) | `.sql` full dump (`MediCare_Backup_YYYYMMDD_HHMMSS.sql`) | MySQL is not a single file. The dump is written in pure PHP, so no `mysqldump` is needed. |
| D7 | Activity log "Windows user" instead of IP (§63) | IP address + browser user agent | It is a web application. |
| D8 | Font Segoe UI 12px (§9) | Segoe UI, 13px default; Theme lets you choose 12–15px | 13px reads better in a browser. 12px is available. |
| D9 | "Scheduled" backup | Runs at the first login after the daily or weekly period has passed | A plain offline PHP install has no cron. |

## Additions requested by the client

| Requirement | Where |
|---|---|
| Base URL in one place, no conflicts | `config/config.php`, `base_url()` / `url()` in `core/helpers.php`, cookie path in `core/Session.php` |
| Soft delete of any record by the Super Admin | `deleted_at/deleted_by` on every table, `records.delete` permission, `modules/system` Recycle Bin |
| Department Admin with multiple departments | `user_departments`, `core/Scope.php`, Users form |
| Master data active/inactive, update, delete | `modules/masters` (generic engine) |
| Department Admin full DB backup + download | `system/backup` (`backup.create`) |
| Employee code custom pattern | Settings → Codes & Patterns, `UserService::nextEmployeeCode()` |
| Reporting user | role `reporting` in `config/permissions.php` |
| Export to Excel on every listing | `core/XlsxWriter.php`, `core/Exporter.php`, `?export=xlsx` on every list |
| Database reset script | `database/reset.php`, Administration → Database Reset, `Installer::reset()` |
| Tested, bug-free | `tests/run.php` (42), `tests/smoke.php` (286), `tests/lint.php` |
| Professional ERP index page | `modules/auth/views/login.php` (landing + login), role dashboards |

## BRD sections

| BRD § | Implementation |
|---|---|
| 7 Central config | `config/config.php` + Settings screens (organization, currency, date format, paper, theme) |
| 8–13 Layout, header, sidebar, footer | `views/layouts/main.php`, `views/partials/*`: global search, follow-up alerts, collapsible role-based sidebar with tooltips |
| 14–15 Dashboards | `modules/dashboard` (cards, SVG charts, widgets, quick actions) |
| 16–26 Masters | `config/masters.php` + `modules/masters`, Medicine–Source Mapping (bulk assign) |
| 27 User management | `modules/users` (role, departments, doctor profile, MRN pattern, reset password, activate/deactivate) |
| 29 MRN configuration | `CodeGenerator` + doctor `mrn_pattern`; unique index on `patients.mrn` |
| 30–32 Patients, duplicates, live search | `modules/patients`, `PatientService::duplicates()` / `search()` |
| 33–48 One-page visit + checkout | `modules/visits/views/new.php`, `public/assets/js/consult.js`, `VisitService::checkout()` (single transaction, checkout token) |
| 41–43 Quantity rules | `PrescriptionMath::quantity()` (daily / weekly / manual, billing-unit conversion) |
| 44 Price & billing | `PrescriptionMath::bill()`, price snapshot in `visit_medicines` |
| 45 Rx split | `PrescriptionMath::assignGroups()`, persisted `rx_group`, three print modes |
| 49–54, 72–73 Printing | `modules/visits/views/print.php`, Settings → Prescription Designs (per doctor and per paper) |
| 55–57 Templates & repeat | `modules/templates`, `VisitService::repeatLines()` |
| 58 Patient history | `patients/view` |
| 59–60 Reports | `modules/reports` (visit report; day-wise and patient-wise fee reports + summary) |
| 62 Global search | `modules/search` |
| 63 Activity logs | `core/ActivityLog.php`, `system/logs` |
| 64 Security | Prepared statements everywhere, output escaping `e()`, CSRF on every POST, role checks in the router, image-only uploads, protected folders |
| 65 Scalability | Indexes on MRN, CNIC, phone, name, visit date, doctor and source; prefix searches; pagination; keyset-paged backups |
| 68–70 Errors, confirmations, loading | `core/ErrorHandler.php` (friendly page + log file), `data-confirm` dialogs, loading overlay and spinners |
| 74 Data integrity | Snapshots, unique MRN and visit number, checkout token, doctor-scoped templates, soft delete |
| 77 Backup/restore | `core/Backup.php`: pre-restore archive, retention, signature check |
