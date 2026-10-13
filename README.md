# MediCare Practice

Hospital & practice management system: an offline, ERP-style web application in **core PHP 8** (no framework, no Composer) with **MySQL / MariaDB**. It needs no internet. It runs on one PC, or (v2) on a **hospital server** that every department PC opens in its browser over the LAN.

**Version 2.0** turns the single-clinic tool into a department-based hospital system. It adds:
- **Platform:** roles per department, workspaces, the front desk and tokens, billing.
- **Pharmacy and stores:** pharmacy dispensing and a unified inventory with batches and FEFO.
- **Diagnostics:** the laboratory, and radiology with the Radiologist quality gate.
- **Specialty departments:** dentistry with a selectable tooth map, gynecology / obstetrics, and configurable fields for every other specialty.

See [docs/UPGRADE_PLAN_v2.md](docs/UPGRADE_PLAN_v2.md) and section 3c.

The most important screen is the **one-page consultation**:
Patient → Vitals → Presenting Complaints → Symptoms → Diagnosis → Investigations → Medicines (auto quantity, price and source) → Follow-up & Fee → Checkout → Print (A4 / A5 / Thermal / Legal).

---

## 0. Easiest: the offline Windows installer (setup.exe)

Run **`MediCarePractice-Setup-2.0.0.exe`** and click Next. That is all. The installer contains:

- **PHP 8.2** and **MariaDB 10.11**, both portable and bound to `127.0.0.1` only (no network exposure)
- the Microsoft Visual C++ runtime, installed only if it is missing
- **`MediCare.exe`**, a small launcher that lives in the system tray

On first start, the launcher creates the database and loads the master data (optionally demo data too). It then opens the app in the default browser. From the tray icon you can open the app, open the backups or logs folder, or stop the server.

- Installed to `C:\MediCarePractice` by default. The database is in `data\` and backups in `app\storage\backups\`.
- Ports: web **8765**, database **33106**. If a port is busy, the next free port is chosen and remembered in `data\launcher.ini`.
- Optional tasks during setup: desktop icon, *start with Windows*, demo data, and **Hospital server (LAN)**.
- **LAN / hospital server mode** (v2):
  - The launcher serves the app with nginx and a pool of PHP workers on port 8765.
  - It opens that port in Windows Firewall for private / domain networks only.
  - Department PCs open `http://<server-IP>:8765/`; the tray menu shows the address.
  - MariaDB stays on `127.0.0.1`, so it is never reachable from the network.
  - Switch modes with `server_mode=local|lan` in `data\launcher.ini`, or from the tray menu.
- **Uninstall never deletes patient data or backups.** Reinstalling into the same folder continues with the same data.

**Building the installer** (developer PC; needs internet once):
```
powershell -ExecutionPolicy Bypass -File installer\build.ps1
```
The script downloads PHP, MariaDB and the VC++ runtime into `installer\cache` and verifies their checksums and signatures. It then compiles the launcher with the C# compiler built into Windows and runs Inno Setup 6 (`winget install JRSoftware.InnoSetup`). The output goes to `installer\output\`.

## 1. Installation on XAMPP (Windows)

1. Install **XAMPP with PHP 8.0 or newer**. Start **Apache** and **MySQL** from the XAMPP Control Panel.
2. Copy the `medicare-practice` folder into `C:\xampp\htdocs\`.
3. Open `config/config.php`. This is the **only file you edit**:
   ```php
   'base_url' => 'http://localhost/medicare-practice/public',   // no trailing slash
   'db' => ['host' => '127.0.0.1', 'port' => 3306, 'name' => 'medicare_practice', 'user' => 'root', 'pass' => ''],
   ```
   If you leave `base_url` empty (`''`), it is detected automatically.
4. Open **http://localhost/medicare-practice/public/install.php** and click **Install Now**. The installer creates the database and tables and loads the default master data. It can also load demo patients and visits.
5. Log in at **http://localhost/medicare-practice/public**.

Command-line alternative: `php database/install.php --demo`

### Default logins

Sample users are created on install, one per department role. Change the passwords after the first login. From v2, passwords are stored **bcrypt-hashed** by default (see section 6).

| Role | Username | Password |
|---|---|---|
| Super Admin | `superadmin` | `admin123` |
| Hospital Admin | `hadmin` | `admin123` |
| Department Admin | `deptadmin` | `admin123` |
| Consultant (General OPD) | `doctor` | `doctor123` |
| Paediatrician | `doctor2` | `doctor123` |
| Dentist | `dentist` | `doctor123` |
| Gynecologist | `gynae` | `doctor123` |
| Reporting User | `reporting` | `report123` |
| Receptionist | `reception` | `staff123` |
| Billing Cashier | `cashier` | `staff123` |
| Staff Nurse | `nurse` | `staff123` |
| Midwife | `midwife` | `staff123` |
| Pharmacist | `pharmacist` | `staff123` |
| Store Manager | `store` | `staff123` |
| Lab Technician | `labtech` | `staff123` |
| Pathologist | `pathologist` | `staff123` |
| Radiology Technician | `radtech` | `staff123` |
| Radiologist | `radiologist` | `staff123` |

On the clinic PC, set `'debug' => false` in `config/config.php`. Debug mode shows technical error details and lists the sample logins on the login page.

---

## 2. Roles

| Role | What the role can do |
|---|---|
| **Super Admin** | Everything: users, departments, all master data, settings, code patterns, Rx split, print designs, theme, backup **and restore**, **soft delete + Recycle Bin**, **database reset**, activity logs. |
| **Department Admin** | Master data (add, edit, activate/deactivate), print designs, **full database backup & download**, reports, and viewing the users and visits of **their assigned departments** (one admin can have several departments). Cannot delete records or restore backups. |
| **Doctor** | One-page consultation, patients, repeat prescriptions, own favourite templates, own reports, printing. |
| **Reporting User** | Read-only: dashboard, patients, visits, reports, printing and Excel export. |

Only the Super Admin can delete. Deletes are **soft**: the record moves to the Recycle Bin and can be restored.

---

## 3. Key features

- **Base URL in one place.** Every link, asset and redirect is built from `config/config.php`. The session cookie is scoped to the application's own path, so it never clashes with other apps on the same localhost. Opening the app through `127.0.0.1` or a LAN IP also keeps working.
- **Custom code patterns** (Settings → Codes & Patterns):
  - Employee codes, e.g. `EMP-{YYYY}-{0001}` or `{DEPT}-{ROLE}-{0001}`.
  - A separate MRN pattern per doctor, e.g. `DR01-{YYYY}-{000001}`.
  - Visit numbers.
  - Numbers never repeat and restart every year when the pattern contains `{YYYY}`.
- **Quantity engine.**
  - The rules are `dose × doses/day × days`, and `dose × doses/week × weeks` for weekly medicines.
  - SOS, PRN and Custom frequencies use a manual quantity.
  - Liquids convert to whole bottles.
  - A manual override is never overwritten silently: the screen offers **Recalculate**.
- **Billing.**
  - Unit price comes from the medicine master and can be changed per visit.
  - The screen shows line totals, a discount (Rs. or %), optional tax, medicine net, consultation fee and grand total.
  - Prices and sources are saved with each visit, so later master changes never alter old prescriptions.
- **Source-wise Rx split.** Rx 1 (Local Pharmacy), Rx 2 (Hospital Stock) and so on. The prescription prints in one of three ways: stacked on one page, on separate pages, or as separate prescriptions.
- **Family handling.** Several patients can share a CNIC or phone. A new patient with a CNIC or phone that already exists triggers a popup, where the doctor selects the existing patient or registers a new one.
- **Printing.**
  - Each doctor has an own design for each paper size: A4, A5, Thermal 80 mm and Legal 8.5×14.
  - The layout is 20% left column / 80% Rx.
  - Empty sections are hidden, and the footer is configurable.
- **Export to Excel** on every listing and report. Real `.xlsx` files are written with a built-in writer, so no PHP extension is needed.
- **Backup & Restore.**
  - One click creates a full backup (`MediCare_Backup_YYYYMMDD_HHMMSS.sql`) and downloads it.
  - Backups can also run automatically, daily or weekly, and old backups are cleaned up according to a retention setting.
  - Before a restore, the current database is archived.
- **Database reset script.** Two modes: *clinical data only* or *factory reset*. Run it from Administration → Database Reset, or on the command line:
  ```
  php database/reset.php --mode=clinical --user=superadmin --yes
  ```
  A safety backup is always taken first.
- **Activity logs** record logins, patients, visits, prints and reprints, master changes (including price and source), settings and backups. The log can be exported to Excel.

---

## 3a. Version 1.1 additions

- **Vitals never block saving.** Values outside the usual range get an amber hint while typing and a warning after checkout, but they are always saved.
- **Trending quick picks.** Complaints, symptoms, diagnoses and investigations show the doctor's own top 20 most-used items, with a usage count.
- **Registration order: phone → CNIC → name → father/husband → gender → DOB ↔ age.** Age can be entered in years / months / days (infants), and DOB and age calculate each other. Allergies are stored on the patient and printed.
- **Consultation screen for desktop, tablet and phone.** Complaints / symptoms / diagnosis / investigations sit in two columns on PC and tablet. Medicines are responsive cards with dose dropdowns:
  - tablets: ¼ ⅓ ½ 1 1½ 2 3 4
  - syrups: teaspoon / tablespoon (converted to ml for the bottle count)
  - drops and injections: their own choices

  Each medicine also shows a live English + **Urdu noskha** preview.
- **Previous Visits popup.** A grid of the patient's past visits (date, doctor, complaints, diagnosis, medicines, total) with expandable details. **Repeat** refills everything into today's visit so the doctor can modify it before checkout.
- **Patient-friendly Visit Summary.** Vitals carry Normal/High/Low labels, the findings are in plain words, tests are listed, and each medicine has "how to take it" with times of day in English and Urdu, next to the next visit date and the bill.
- **Doctor dashboard.** Adds month-over-month change, returning patients, pending fees, average visits per clinic day, and **pie charts of this year and last year** by month.
- **PHC-standard prescription** (Punjab Healthcare Commission letterhead):
  - The main Rx holds the medicines of the source with order 1. They get no prices and are written in the 3-line format with Urdu.
  - Every other source prints **below a cut line** as the hospital pharmacy & billing slip, one section per source with prices.
  - The slip also shows the patient's bill and the **day's cumulative total** for the doctor.
  - Settings → Rx Split has a **live preview** of the layout.
- **Activity Logs → Clean by date range** (optionally by module or user). The count is previewed, and a safety backup is taken first.
- **Database Reset → Delete by table & date.** Covers visits, patients, templates, activity logs and a Recycle-Bin purge, with count preview, password confirmation and a safety backup.
- **Automatic database migration.** Older databases upgrade on the first request after an update, and no data is lost.

## 3a-2. Version 1.2 changes

- **Doctor dashboard uses the same layout as the admin dashboard.** It shows:
  - 4 stat cards
  - a 30-day visit line chart and a this-year vs last-year bar chart (no pie charts)
  - follow-ups, top diagnoses and top medicines
  - recent visits
- **New Visit.**
  - The age box has roomy Years / Months / Days fields.
  - The bottom has two cards:
    - **Next Visit & Visit History**: the follow-up date and instructions next to the patient's last visits, each with Repeat.
    - **Consultation Fee & Hospital Bill.**
- **Main Rx holds exactly one source.** Every other source, and medicines without a source, print below the cut.
- **Refill is counted automatically.** 0 for a new prescription, +1 each time a prescription is repeated (repeat of a repeat = 2). The print circles it.
- **The slip shows the day's prescription count** (#n of the day) next to the cumulative amount.
- **Subscription keys from the Super Admin screen** (Administration → Subscription):
  - Generate keys with clinic name, amount and payment reference.
  - An issued-keys log with status (Active / Expiring / Scheduled / Expired) and an activation history (In use / Replaced / Expired), both exportable to Excel.
  - Generation works only where `tools/license-private.key` exists (the vendor's installation). Clinic installers never include it.

## 3b. Subscription (offline licence key)

Payments are manual, and a key is issued per clinic:

```
php tools/license.php issue --phone=03001234567 --from=2026-10-01 --to=2027-09-30
```

- **How the key works.**
  - The key contains the phone number and the dates.
  - It is signed with the vendor's **Ed25519 private key** (`tools/license-private.key`). This key is never shipped: the installer excludes `tools/`.
  - The app verifies the key offline with the built-in public key, so a key cannot be edited, extended or forged.
- **Activating.** The clinic enters its phone and key at **Administration → Subscription**, or at `index.php?r=license` when login is blocked.
- **Lifecycle.**
  - A new install gets a 14-day trial.
  - A warning appears 15 days before expiry.
  - After expiry, login is blocked and active sessions are ended.
  - Setting the Windows clock back is detected and blocks the software until the date is corrected.
- **PHP requirement.** Needs PHP's `sodium` extension (enabled in the installer bundle; on XAMPP set `extension=sodium` in php.ini).
- **Keep a safe copy of `tools/license-private.key`.** Without it no new keys can be issued, and anyone who has it can make keys.

## 3c. Version 2.0 — department-based hospital system

Built in three phases on the same PHP stack; existing databases upgrade automatically (schema v7). Before the first v2 upgrade step, a **pre-upgrade backup** is written.

**Phase 1 — Platform, RBAC and front desk**
- **Roles and access**
  - 69 roles, with base-role inheritance and deny rules.
  - Scopes: own, department, branch or chain.
  - A user can hold several roles in several departments.
  - A context switcher selects the active branch and department.
  - Per-role 2FA, session timeout, discount limit and co-sign rule.
- **Workspaces:** every role lands on its own workspace, and the menu shows only what the role may open.
- **Front desk**
  - Registration with duplicate check and patient merge, documents, contacts and identifiers.
  - Emergency registration and break-glass access.
- **Tokens and appointments**
  - Atomic token numbering with priority calling.
  - Public display board (with chime) and token transfer.
  - Appointments, doctor schedules and leave.
- **Vitals / triage**, and a co-sign queue for residents.
- **Billing**
  - Invoices, partial payments, discounts that need approval, and refunds.
  - Cash sessions and dated service prices.
  - Every department posts its charges to the patient's invoice.
- **Audit trail:** old → new values, never purged.

**Phase 2 — Stock, pharmacy and laboratory**
- **Unified inventory**
  - Items, locations, batches with expiry, and an append-only ledger.
  - FEFO; blocked, expired and quarantined batches never issue; negative stock is blocked.
  - PO → GRN, indents, issues, transfers, adjustments, stock counts, recalls, vendor returns and assets.
  - Segregation of duties is enforced in code.
  - A daily expiry watch.
- **Pharmacy**
  - The e-Rx queue arrives at checkout.
  - Allergy and interaction alerts, substitutions and partial dispensing.
  - Controlled drugs need a second pharmacist's co-sign and are kept in a controlled-drug register.
  - Counter sales and returns.
- **Laboratory**
  - Orders are billed when placed.
  - Barcode labels, and a phlebotomy worklist.
  - **Pathologist gate 1:** accept a sample, or reject it with *resample XOR refund*.
  - Section-restricted result entry, with age / sex / pregnancy reference ranges and flags.
  - Westgard QC with a Levey-Jennings chart.
  - **Pathologist gate 2:** verification is blocked while a parameter's QC has failed.
  - Critical values go to the ordering doctor, who reads back to acknowledge.
  - Versioned reports and amendments, outsourcing, and a test catalog editor.
- **Reports:** revenue by department / module, token waiting times, stock valuation.

**Phase 3 — Specialty departments and radiology**
- **Specialty framework**
  - Tier 1 fields per specialty, configured by the admin. Starter fields are included for cardiology, ENT, ophthalmology, orthopaedics, paediatrics, dermatology, psychiatry and urology.
  - Body map; eye exam, growth chart (approximate WHO centiles), cardiac and audiogram forms.
  - EPI immunization card that draws vaccines from cold-chain stock.
  - Restricted clinical notes and referrals.
- **Dentistry**
  - A selectable FDI tooth map on the consultation (32 permanent + 20 deciduous teeth, multi-select, surfaces, colour per condition).
  - Treatment plans whose steps are billed when done, with consumables deducted from dental stock.
  - Perio chart, chairside consumables, lab cases, images, and comparison with an earlier chart.
- **Gynecology / Obstetrics**
  - Gynae history.
  - Pregnancies with gestational age and EDD, where **G/P/A/L is derived** from the pregnancy records, not typed.
  - ANC with a risk banner (e.g. BP + proteinuria → pre-eclampsia).
  - Deliveries: a midwife's record stays a draft until the gynecologist confirms it, and the newborn is registered and linked to the mother.
  - PNC, obstetric ultrasound with Hadlock fetal-weight estimate, and a pregnancy register.
- **Radiology**
  - Orders are billed when placed.
  - Mandatory safety screen: pregnancy for ionizing studies; allergy and creatinine for contrast.
  - Room scheduling, and a technician worklist per modality.
  - Contrast is drawn from radiology stock, with a dose register.
  - **Radiologist gate:** accept / reprocess (no new charge) / reject + refund.
  - Report templates; a finalized report is immutable and corrected only by **addenda**.
  - Critical findings notify the ordering doctor.
- A specialty panel appears on the consultation **only when the encounter's department has that specialty**. The server ignores specialty data from any other department.

## 4. Folder structure (module-based)

```
medicare-practice/
├── config/        config.php (Base URL, DB), permissions.php, masters.php, menu.php
├── core/          framework replacement: DB, Auth, Router, View, Validator, CodeGenerator,
│                  Backup, SqlSplitter, XlsxWriter, Exporter, Installer, Settings, Icons …
├── modules/       one folder per feature: routes.php + controller + service + views/
│   ├── auth  dashboard  patients  visits  templates  reports
│   ├── masters  users  settings  system  search
│   ├── (v2) admin  roles  workspace  frontdesk  appointments  billing
│   ├── (v2) inventory  pharmacy  lab  radiology
│   ├── (v2) specialty  dental  obgyn
├── views/         shared layouts, partials (sidebar, header, footer), error pages
├── public/        web root: index.php (front controller), install.php, assets/, uploads/
├── database/      schema.sql (+ schema/10–90_*.sql per domain), seed.php, demo.php, demo_v2.php, install.php, reset.php
├── storage/       backups/, logs/ (not web-accessible)
└── tests/         run.php (unit + integration), smoke.php (HTTP, all roles), lint.php
```

All 11 master data screens (list, search, add, edit, activate/deactivate, delete, Excel) come from one engine driven by `config/masters.php`. To add a field, add it to the table and to that file.

---

## 5. Tests

```
php tests/lint.php                       # syntax check of every PHP file
php tests/run.php                        # 74 unit + integration tests (uses a separate "<db>_test" database)
php tests/smoke.php http://localhost/medicare-practice/public   # ~3,270 HTTP checks across all 18 sample users
```

- `run.php` covers:
  - MRN, visit and employee code patterns
  - quantity, billing and Rx split maths
  - checkout validation and double-submit protection
  - price/source snapshots, repeat and templates
  - scoping, soft delete, backup → restore, and both reset modes
  - (v2, `tests/v2.php`):
    - roles and workspaces, tokens and billing
    - stock FEFO, expiry and segregation of duties
    - the lab sample and QC gates, critical values, amendments
    - the radiology screen, gate and addenda
    - specialty fields, growth and EPI
    - the dental chart, plan and billing
    - obstetrics, risk and the derived G/P/A/L
    - the Doc 8 §7 acceptance criteria
- `smoke.php` does the following:
  - logs in as every sample user and opens every page, expecting 200 or 403 from the live RBAC (and record-level scope)
  - needs the PHP `curl` extension and an install **with demo data**
  - downloads every Excel export
  - prints all 4 paper sizes
  - performs a real checkout
  - verifies that unauthorised actions and missing CSRF tokens are rejected

---

## 6. Configuration notes

- **Passwords.** `config/config.php → security.password_mode` (environment `PASSWORD_MODE`):
  - `'bcrypt'` is the v2 default.
  - Plain-text passwords from v1 still log in, and are re-hashed at the next login.
  - `'plain'` keeps the v1 behaviour.
- **Pretty URLs.** Set `'pretty_urls' => true` (needs Apache `mod_rewrite`; the `.htaccess` file is included).
- **Large restores.** Raise `upload_max_filesize` and `post_max_size` in `php.ini`.
- **Development stack.** `docker compose up -d` serves the app at http://localhost:8110/medicare-practice/public (PHP 8.2 + MariaDB 10.6).
