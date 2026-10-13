<?php
declare(strict_types=1);

namespace App\Core;

/**
 * Upgrades an existing database to the current schema without losing data.
 *
 * database/schema.sql always describes the LATEST schema (fresh installs get
 * it directly). Each numbered step below brings an older database forward.
 * The applied version is stored in settings.schema_version. Steps check
 * information_schema before altering, so they are safe to re-run and work on
 * both MariaDB and MySQL 8.
 */
final class Migrator
{
    public const LATEST = 7;

    public static function current(): int
    {
        return (int) Settings::get('schema_version', '1');
    }

    /** Called on every request; costs nothing when already up to date. */
    public static function runIfNeeded(): void
    {
        if (self::current() < self::LATEST) {
            self::run();
        }
    }

    public static function run(): void
    {
        @set_time_limit(0);
        // v5+ is the v2 department system: archive the database before the
        // first structural upgrade so it can always be undone (Restore).
        if (self::current() < 5 && self::current() >= 1 && Migrator::tableExists('visits')) {
            try {
                Backup::create('pre-upgrade', false);
            } catch (\Throwable $e) {
                Logger::error('Pre-upgrade backup failed: ' . $e->getMessage());
            }
        }
        for ($v = self::current() + 1; $v <= self::LATEST; $v++) {
            $method = 'v' . $v;
            self::$method();
            Settings::set(['schema_version' => (string) $v]);
            Logger::info('Database migrated to schema version ' . $v);
        }
    }

    public static function markLatest(): void
    {
        Settings::set(['schema_version' => (string) self::LATEST]);
    }

    // ------------------------------------------------------------------ steps

    /** v2: PHC prescription, Urdu noskha, dose types, main Rx billing, allergies, findings, refills. */
    private static function v2(): void
    {
        self::addColumn('dosage_forms', 'dose_type', "VARCHAR(20) NOT NULL DEFAULT 'other' AFTER short_name");
        self::addColumn('routes', 'name_ur', 'VARCHAR(150) NULL AFTER short_name');
        self::addColumn('frequencies', 'name_ur', 'VARCHAR(150) NULL AFTER calc_mode');
        self::addColumn('instructions', 'name_ur', 'VARCHAR(150) NULL AFTER name');
        self::addColumn('patients', 'allergies', 'VARCHAR(500) NULL AFTER blood_group');
        self::addColumn('visits', 'allergies', 'VARCHAR(500) NULL AFTER other_vitals');
        self::addColumn('visits', 'findings', 'TEXT NULL AFTER current_history');
        self::addColumn('visits', 'refills', 'TINYINT UNSIGNED NOT NULL DEFAULT 0 AFTER overall_instructions');
        self::addColumn('visit_medicines', 'dose_type', 'VARCHAR(20) NULL AFTER dose_unit');
        self::addColumn('visit_medicines', 'is_main_rx', 'TINYINT(1) NOT NULL DEFAULT 1 AFTER rx_group');
        self::addColumn('visit_medicines', 'billable', 'TINYINT(1) NOT NULL DEFAULT 1 AFTER is_main_rx');
        self::addColumn('print_designs', 'show_slogan', 'TINYINT(1) NOT NULL DEFAULT 1 AFTER show_signature');
        self::addColumn('print_designs', 'show_qr', 'TINYINT(1) NOT NULL DEFAULT 1 AFTER show_slogan');
        DB::run('ALTER TABLE medicines MODIFY default_dose DECIMAL(8,3) NOT NULL DEFAULT 1');
        DB::run('ALTER TABLE visit_medicines MODIFY dose DECIMAL(8,3) NOT NULL DEFAULT 1');
        DB::run('ALTER TABLE template_medicines MODIFY dose DECIMAL(8,3) NOT NULL DEFAULT 1');

        // Existing history: the first Rx group was the main Rx; everything stays billed as before.
        DB::run('UPDATE visit_medicines SET is_main_rx = IF(rx_group = 1, 1, 0), billable = 1');

        foreach (DB::all('SELECT id, dose_unit FROM medicines') as $m) {
            DB::update('medicines', ['dose_unit' => Urdu::unitKey($m['dose_unit'])], 'id = ?', [$m['id']]);
        }
        foreach (DB::all('SELECT id, name FROM dosage_forms') as $f) {
            DB::update('dosage_forms', ['dose_type' => Urdu::guessDoseType($f['name'])], 'id = ?', [$f['id']]);
        }
        foreach (Urdu::FREQUENCIES as $code => $ur) {
            DB::run('UPDATE frequencies SET name_ur = ? WHERE code = ? AND (name_ur IS NULL OR name_ur = \'\')', [$ur, $code]);
        }
        foreach (Urdu::ROUTES as $name => $ur) {
            DB::run('UPDATE routes SET name_ur = ? WHERE name = ? AND (name_ur IS NULL OR name_ur = \'\')', [$ur, $name]);
        }
        foreach (Urdu::INSTRUCTIONS as $name => $ur) {
            DB::run('UPDATE instructions SET name_ur = ? WHERE name = ? AND (name_ur IS NULL OR name_ur = \'\')', [$ur, $name]);
        }
        // Older print modes map onto the new PHC layout.
        $mode = (string) Settings::get('rx_split_print_mode', 'slip');
        if (!in_array($mode, ['slip', 'separate_page'], true)) {
            Settings::set(['rx_split_print_mode' => $mode === 'same_page' ? 'slip' : 'separate_page']);
        }
    }

    /** v3: subscription key log (issued keys and activations). */
    private static function v3(): void
    {
        if (!self::tableExists('license_issued')) {
            DB::run('CREATE TABLE license_issued (
    id           INT UNSIGNED NOT NULL AUTO_INCREMENT,
    phone        VARCHAR(20) NOT NULL,
    clinic_name  VARCHAR(150) NULL,
    from_date    DATE NOT NULL,
    to_date      DATE NOT NULL,
    amount       DECIMAL(12,2) NOT NULL DEFAULT 0,
    payment_ref  VARCHAR(100) NULL,
    notes        VARCHAR(500) NULL,
    license_key  TEXT NOT NULL,
    issued_by    INT UNSIGNED NULL,
    created_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_li_phone (phone),
    KEY idx_li_dates (to_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
        }
        if (!self::tableExists('license_activations')) {
            DB::run('CREATE TABLE license_activations (
    id           INT UNSIGNED NOT NULL AUTO_INCREMENT,
    phone        VARCHAR(20) NOT NULL,
    from_date    DATE NOT NULL,
    to_date      DATE NOT NULL,
    license_key  TEXT NOT NULL,
    activated_by INT UNSIGNED NULL,
    activated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_la_date (activated_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
        }
        // Record the key that is already active, so the history starts complete.
        $key = trim((string) Settings::get('license_key', ''));
        $info = $key !== '' ? License::read($key) : null;
        if ($info && !DB::value('SELECT 1 FROM license_activations')) {
            DB::insert('license_activations', ['phone' => $info['phone'], 'from_date' => $info['from'], 'to_date' => $info['to'],
                'license_key' => $key, 'activated_at' => now()]);
        }
    }

    /**
     * v4: the main Rx is never empty. Saved prescriptions whose main Rx came
     * out empty (none of their medicines belonged to the order-1 source) are
     * regrouped with the per-prescription rule and their hospital bill is
     * recalculated (main-Rx medicines are not billed). Each change is logged.
     */
    private static function v4(): void
    {
        $visits = DB::all(
            'SELECT v.* FROM visits v WHERE v.rx_split_enabled = 1
                AND EXISTS (SELECT 1 FROM visit_medicines m WHERE m.visit_id = v.id AND m.source_id IS NOT NULL)
                AND NOT EXISTS (SELECT 1 FROM visit_medicines m WHERE m.visit_id = v.id AND m.is_main_rx = 1)'
        );
        $order = \App\Modules\Visits\MedicineCatalog::sourceOrder();
        $configured = (int) Settings::get('rx_main_source_id', 0) ?: null;
        foreach ($visits as $v) {
            DB::transaction(static function () use ($v, $order, $configured): void {
                $lines = DB::all('SELECT id, source_id, quantity, unit_price FROM visit_medicines WHERE visit_id = ? ORDER BY sort_order, id', [$v['id']]);
                $lines = \App\Modules\Visits\PrescriptionMath::assignGroups($lines, true, $order, $configured);
                foreach ($lines as $l) {
                    DB::update('visit_medicines', ['rx_group' => $l['rx_group'], 'is_main_rx' => $l['is_main_rx'], 'billable' => $l['billable']], 'id = ?', [$l['id']]);
                }
                $bill = \App\Modules\Visits\PrescriptionMath::bill($lines, (string) $v['discount_type'], (float) $v['discount_value'],
                    (float) $v['tax_percent'], (float) $v['consultation_fee'], (string) $v['payment_status']);
                DB::update('visits', [
                    'medicines_total' => $bill['medicines_total'], 'discount_amount' => $bill['discount_amount'], 'tax_amount' => $bill['tax_amount'],
                    'medicine_net' => $bill['medicine_net'], 'grand_total' => $bill['grand_total'],
                ], 'id = ?', [$v['id']]);
                ActivityLog::record('Prescription regrouped', 'visits', (string) $v['id'], $v['visit_no'] . ': main Rx was empty; regrouped by source order. Bill '
                    . money($v['grand_total']) . ' → ' . money($bill['grand_total']));
            });
        }
    }

    /**
     * v5 — v2 Phase 1: branches, specialties, roles & permissions per branch
     * and department, workspaces, service points, break-glass access, patient
     * identifiers/contacts/documents/merge log, service catalog & prices,
     * invoices/payments/refunds, token queues, appointments, nurse vitals.
     * v1 users keep working: each gets the role matching users.role.
     */
    private static function v5(): void
    {
        self::addColumn('departments', 'branch_id', 'INT UNSIGNED NULL AFTER description');
        self::addColumn('departments', 'specialty_id', 'INT UNSIGNED NULL AFTER branch_id');
        self::addColumn('departments', 'department_type', "VARCHAR(20) NOT NULL DEFAULT 'clinical' AFTER specialty_id");
        self::addColumn('departments', 'head_user_id', 'INT UNSIGNED NULL AFTER department_type');

        DB::run('ALTER TABLE users MODIFY role VARCHAR(20) NOT NULL');
        self::addColumn('users', 'primary_role_id', 'INT UNSIGNED NULL AFTER role');
        self::addColumn('users', 'two_factor_secret', 'VARCHAR(64) NULL AFTER primary_role_id');
        self::addColumn('users', 'two_factor_enabled', 'TINYINT(1) NOT NULL DEFAULT 0 AFTER two_factor_secret');
        self::addColumn('users', 'password_changed_at', 'DATETIME NULL AFTER two_factor_enabled');

        DB::run('ALTER TABLE patients MODIFY doctor_id INT UNSIGNED NULL');
        self::addColumn('patients', 'registration_type', "VARCHAR(30) NOT NULL DEFAULT 'normal' AFTER doctor_id");
        self::addColumn('patients', 'mother_patient_id', 'INT UNSIGNED NULL AFTER registration_type');
        self::addColumn('patients', 'merged_into_patient_id', 'INT UNSIGNED NULL AFTER mother_patient_id');
        self::addColumn('patients', 'photo_path', 'VARCHAR(255) NULL AFTER merged_into_patient_id');
        self::addColumn('patients', 'occupation', 'VARCHAR(100) NULL AFTER photo_path');
        self::addColumn('patients', 'marital_status', 'VARCHAR(20) NULL AFTER occupation');
        self::addColumn('patients', 'preferred_language', 'VARCHAR(5) NULL AFTER marital_status');
        self::addColumn('patients', 'created_branch_id', 'INT UNSIGNED NULL AFTER preferred_language');
        self::addColumn('patients', 'is_deceased', 'TINYINT(1) NOT NULL DEFAULT 0 AFTER created_branch_id');

        foreach ([
            'branch_id' => 'INT UNSIGNED NULL', 'department_id' => 'INT UNSIGNED NULL', 'visit_type_id' => 'INT UNSIGNED NULL',
            'token_id' => 'INT UNSIGNED NULL', 'invoice_id' => 'INT UNSIGNED NULL', 'appointment_id' => 'INT UNSIGNED NULL',
            'service_point_id' => 'INT UNSIGNED NULL', 'referred_by_type' => 'VARCHAR(20) NULL', 'referred_by_text' => 'VARCHAR(150) NULL',
            'cosign_required' => 'TINYINT(1) NOT NULL DEFAULT 0', 'cosigned_by' => 'INT UNSIGNED NULL', 'cosigned_at' => 'DATETIME NULL',
        ] as $col => $def) {
            self::addColumn('visits', $col, $def);
        }
        self::addIndex('visits', 'idx_visits_dept', '(department_id, visit_date)');
        self::addIndex('visits', 'idx_visits_token', '(token_id)');
        self::addIndex('visits', 'idx_visits_invoice', '(invoice_id)');

        DB::run('ALTER TABLE activity_logs MODIFY role VARCHAR(30) NULL');
        self::addColumn('activity_logs', 'branch_id', 'INT UNSIGNED NULL AFTER description');
        self::addColumn('activity_logs', 'entity_type', 'VARCHAR(60) NULL AFTER branch_id');
        self::addColumn('activity_logs', 'old_value', 'LONGTEXT NULL AFTER entity_type');
        self::addColumn('activity_logs', 'new_value', 'LONGTEXT NULL AFTER old_value');

        Installer::runSchemaFile('10_platform.sql');
        Installer::runSchemaFile('20_frontdesk.sql');
        Seeder::platform();

        // v1 visits become the OPD encounters of the doctor's first department.
        DB::run('UPDATE visits v SET v.department_id = (SELECT MIN(ud.department_id) FROM user_departments ud WHERE ud.user_id = v.doctor_id)
                  WHERE v.department_id IS NULL');
    }

    /**
     * v6 — v2 Phase 2: unified stock (items, locations, batches, ledger, GRN,
     * indents, issues, transfers, adjustments, counts, recalls, assets),
     * pharmacy dispensing / counter sales / returns / controlled register, and
     * the complete laboratory. Every medicine becomes a stock item; the
     * "Hospital Stock" source points at the main pharmacy location.
     */
    private static function v6(): void
    {
        foreach ([
            'item_id' => 'INT UNSIGNED NULL', 'drap_registration_no' => 'VARCHAR(40) NULL', 'is_controlled' => 'TINYINT(1) NOT NULL DEFAULT 0',
            'is_high_alert' => 'TINYINT(1) NOT NULL DEFAULT 0', 'is_lasa' => 'TINYINT(1) NOT NULL DEFAULT 0', 'is_formulary' => 'TINYINT(1) NOT NULL DEFAULT 1',
            'pregnancy_category' => 'VARCHAR(5) NULL',
        ] as $col => $def) {
            self::addColumn('medicines', $col, $def);
        }
        self::addColumn('medicine_sources', 'stock_location_id', 'INT UNSIGNED NULL AFTER address');
        Installer::runSchemaFile('30_stock.sql');
        Installer::runSchemaFile('40_pharmacy.sql');
        Installer::runSchemaFile('50_lab.sql');
        Seeder::permissions();
        Seeder::roles();
        Seeder::workspaces();
        foreach (['App\\Modules\\Inventory\\InventorySeeder', 'App\\Modules\\Lab\\LabSeeder'] as $class) {
            if (class_exists($class)) {
                $class::run();
            }
        }
        Rbac::bump();
    }

    /**
     * v7 - v2 Phase 3: radiology (modalities, rooms, catalog, gate, reports,
     * addenda, contrast, dose, schedule), the specialty framework (configurable
     * fields, body map, eye / growth / immunization / cardiac / audiogram,
     * clinical notes, referrals), dentistry and gynecology / obstetrics.
     */
    private static function v7(): void
    {
        Installer::runSchemaFile('60_radiology.sql');
        Installer::runSchemaFile('70_specialty.sql');
        Installer::runSchemaFile('80_dental.sql');
        Installer::runSchemaFile('90_obgyn.sql');
        Seeder::permissions();
        Seeder::roles();
        Seeder::workspaces();
        Seeder::modules();
    }

    // ---------------------------------------------------------------- helpers

    public static function addIndex(string $table, string $name, string $columns): void
    {
        $exists = DB::value(
            'SELECT 1 FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ?',
            [$table, $name]
        );
        if (!$exists) {
            DB::run('ALTER TABLE `' . $table . '` ADD INDEX `' . $name . '` ' . $columns);
        }
    }

    public static function tableExists(string $table): bool
    {
        return (bool) DB::value('SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?', [$table]);
    }

    public static function columnExists(string $table, string $column): bool
    {
        return (bool) DB::value(
            'SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?',
            [$table, $column]
        );
    }

    public static function addColumn(string $table, string $column, string $definition): void
    {
        if (!self::columnExists($table, $column)) {
            DB::run('ALTER TABLE `' . $table . '` ADD COLUMN `' . $column . '` ' . $definition);
        }
    }
}
