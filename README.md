# MediCare Practice

Medical Practice & Prescription Management System: an offline, ERP-style web application in **core PHP 8** (no framework, no Composer) with **MySQL / MariaDB**. It runs on the clinic PC through XAMPP and needs no internet.

The most important screen is the **one-page consultation**:
Patient → Vitals → Presenting Complaints → Symptoms → Diagnosis → Investigations → Medicines (auto quantity, price and source) → Follow-up & Fee → Checkout → Print (A4 / A5 / Thermal / Legal).

---

## 0. Easiest: the offline Windows installer (setup.exe)

Run **`MediCarePractice-Setup-1.0.0.exe`** and click Next. That is all. The installer contains:

- **PHP 8.2** and **MariaDB 10.11**, both portable and bound to `127.0.0.1` only (no network exposure)
- the Microsoft Visual C++ runtime, installed only if it is missing
- **`MediCare.exe`**, a small launcher that lives in the system tray

On first start, the launcher creates the database and loads the master data (optionally demo data too). It then opens the app in the default browser. From the tray icon you can open the app, open the backups or logs folder, or stop the server.

- Installed to `C:\MediCarePractice` by default. The database is in `data\` and backups in `app\storage\backups\`.
- Ports: web **8765**, database **33106**. If a port is busy, the next free port is chosen and remembered in `data\launcher.ini`.
- Optional tasks during setup: desktop icon, *start with Windows*, and demo data.
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

The passwords are stored in plain text, as the requirements ask. Change them after the first login.

| Role | Username | Password |
|---|---|---|
| Super Admin | `superadmin` | `admin123` |
| Department Admin | `deptadmin` | `admin123` |
| Doctor | `doctor` | `doctor123` |
| Doctor (Paediatrics) | `doctor2` | `doctor123` |
| Reporting User | `reporting` | `report123` |

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

## 4. Folder structure (module-based)

```
medicare-practice/
├── config/        config.php (Base URL, DB), permissions.php, masters.php, menu.php
├── core/          framework replacement: DB, Auth, Router, View, Validator, CodeGenerator,
│                  Backup, SqlSplitter, XlsxWriter, Exporter, Installer, Settings, Icons …
├── modules/       one folder per feature: routes.php + controller + service + views/
│   ├── auth  dashboard  patients  visits  templates  reports
│   ├── masters  users  settings  system  search
├── views/         shared layouts, partials (sidebar, header, footer), error pages
├── public/        web root: index.php (front controller), install.php, assets/, uploads/
├── database/      schema.sql, seed.php, demo.php, install.php, reset.php
├── storage/       backups/, logs/ (not web-accessible)
└── tests/         run.php (unit + integration), smoke.php (HTTP, all roles), lint.php
```

All 11 master data screens (list, search, add, edit, activate/deactivate, delete, Excel) come from one engine driven by `config/masters.php`. To add a field, add it to the table and to that file.

---

## 5. Tests

```
php tests/lint.php                       # syntax check of every PHP file
php tests/run.php                        # 42 unit + integration tests (uses a separate "<db>_test" database)
php tests/smoke.php http://localhost/medicare-practice/public   # 286 HTTP checks across all 4 roles
```

- `run.php` covers:
  - MRN, visit and employee code patterns
  - quantity, billing and Rx split maths
  - checkout validation and double-submit protection
  - price/source snapshots, repeat and templates
  - scoping, soft delete, backup → restore, and both reset modes
- `smoke.php` does the following:
  - logs in as every role and opens every page, expecting 200 or 403 as the permissions dictate
  - downloads every Excel export
  - prints all 4 paper sizes
  - performs a real checkout
  - verifies that unauthorised actions and missing CSRF tokens are rejected

---

## 6. Configuration notes

- **Passwords.** `config/config.php → security.password_mode`:
  - `'plain'` is the current requirement.
  - `'bcrypt'` switches to hashing. Nothing else changes, and existing passwords are upgraded automatically at the next login.
- **Pretty URLs.** Set `'pretty_urls' => true` (needs Apache `mod_rewrite`; the `.htaccess` file is included).
- **Large restores.** Raise `upload_max_filesize` and `post_max_size` in `php.ini`.
- **Development stack.** `docker compose up -d` serves the app at http://localhost:8110/medicare-practice/public (PHP 8.2 + MariaDB 10.6).
