<?php
declare(strict_types=1);

namespace App\Core;

/**
 * Creates the database, runs database/schema.sql plus the module schema files
 * in database/schema/ (v2 department system), seeds master data and
 * (optionally) demo patients/visits. Also implements the two database reset
 * modes used by the Super Admin "Database Reset" screen and database/reset.php.
 */
final class Installer
{
    /**
     * Tables emptied by a "clinical data" reset (only those that exist).
     * Masters, users, roles, catalogs, locations and settings stay.
     */
    public const CLINICAL_TABLES = [
        // v1
        'visit_medicines', 'visit_clinical_items', 'visits',
        'template_medicines', 'template_clinical_items', 'templates',
        'patients', 'activity_logs',
        // front desk & billing
        'patient_identifiers', 'patient_contacts', 'patient_documents', 'patient_merge_log',
        'invoice_items', 'payments', 'refunds', 'invoices', 'cash_sessions',
        'tokens', 'token_events', 'appointments', 'appointment_slots', 'doctor_leaves', 'vitals',
        'emergency_access_grants',
        // stock & pharmacy
        'stock_ledger', 'stock_balances', 'stock_batches', 'goods_receipt_notes', 'grn_items',
        'purchase_requisitions', 'purchase_requisition_items', 'purchase_orders', 'purchase_order_items',
        'stock_requisitions', 'stock_requisition_items', 'stock_issues', 'stock_issue_items',
        'stock_transfers', 'stock_transfer_items', 'stock_adjustments', 'stock_adjustment_items',
        'stock_counts', 'stock_count_lines', 'expiry_watchlist', 'vendor_returns', 'vendor_return_items',
        'dispenses', 'dispense_items', 'pharmacy_sales', 'pharmacy_sale_items', 'pharmacy_returns', 'pharmacy_return_items',
        'controlled_drug_register', 'recall_notices', 'asset_maintenance_log',
        // laboratory
        'lab_orders', 'lab_order_items', 'lab_samples', 'lab_sample_reviews', 'lab_results', 'lab_result_values',
        'lab_worksheets', 'lab_worksheet_items', 'lab_qc_runs', 'lab_reports', 'lab_critical_notifications', 'lab_outsource_referrals',
        // radiology
        'radiology_orders', 'radiology_procedures', 'radiology_procedure_reviews', 'radiology_reports', 'radiology_report_addenda',
        'radiology_contrast_usage', 'radiology_dose_log', 'radiology_schedule_slots',
        // specialties
        'clinical_notes', 'referrals', 'encounter_clinical_parameters', 'encounter_body_map_findings',
        'eye_exams', 'growth_measurements', 'immunization_records', 'cardiac_investigations', 'audiograms',
        'dental_charts', 'dental_tooth_findings', 'dental_finding_surfaces', 'dental_treatment_plans', 'dental_treatment_plan_items',
        'dental_perio_charts', 'dental_perio_measurements', 'dental_lab_cases', 'dental_chart_images',
        'gynecologic_history', 'obstetric_history', 'pregnancies', 'pregnancy_deliveries', 'antenatal_visits', 'postnatal_visits',
        'obstetric_ultrasound_findings',
    ];

    /** Counter keys cleared by a clinical reset. */
    public const CLINICAL_COUNTERS = ['mrn:%', 'visit:%', 'invoice:%', 'token:%', 'grn:%', 'po:%', 'req:%', 'lab:%', 'rad:%', 'sale:%', 'disp:%', 'adj:%', 'cnt:%', 'iss:%', 'trf:%', 'dental_lab:%', 'lab_order:%', 'lab_report:%', 'lab_barcode:%'];

    public static function lockFile(): string
    {
        return STORAGE_PATH . '/installed.lock';
    }

    public static function isInstalled(): bool
    {
        return is_file(self::lockFile());
    }

    /**
     * Full fresh install. DESTROYS existing tables of the configured database.
     * @param array{demo?:bool, sample_users?:bool} $options
     */
    public static function install(array $options = []): void
    {
        @set_time_limit(0);
        self::createDatabase();
        self::runSchema();
        self::seed(['sample_users' => $options['sample_users'] ?? true]);
        Migrator::markLatest();
        if (!empty($options['demo'])) {
            \App\Modules\Visits\VisitHooks::$enabled = false;
            try {
                (require BASE_PATH . '/database/demo.php')();
            } finally {
                \App\Modules\Visits\VisitHooks::$enabled = true;
            }
            (require BASE_PATH . '/database/demo_v2.php')();
        }
        self::writeLock();
    }

    public static function createDatabase(): void
    {
        $name = str_replace('`', '', (string) config('db.name'));
        $pdo = DB::connect(false);
        $pdo->exec('CREATE DATABASE IF NOT EXISTS `' . $name . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
        DB::disconnect();
    }

    /** Drops every table and view of the configured database. */
    public static function dropAllTables(): void
    {
        $pdo = DB::pdo();
        $pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
        try {
            foreach (DB::all('SHOW FULL TABLES') as $row) {
                $values = array_values($row);
                $kind = strtoupper((string) ($values[1] ?? 'BASE TABLE'));
                $pdo->exec(($kind === 'VIEW' ? 'DROP VIEW IF EXISTS `' : 'DROP TABLE IF EXISTS `') . str_replace('`', '', (string) $values[0]) . '`');
            }
        } finally {
            $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
        }
    }

    /** The module schema files, in order (database/schema/NN_name.sql). */
    public static function schemaFiles(): array
    {
        $files = glob(BASE_PATH . '/database/schema/*.sql') ?: [];
        sort($files, SORT_STRING);
        return $files;
    }

    public static function runSchema(): void
    {
        self::dropAllTables();
        $pdo = DB::pdo();
        foreach (array_merge([BASE_PATH . '/database/schema.sql'], self::schemaFiles()) as $file) {
            foreach (SqlSplitter::fromFile($file) as $statement) {
                $pdo->exec($statement);
            }
        }
        Settings::flush();
        Rbac::flush();
    }

    /** Runs one module schema file (idempotent CREATE TABLE IF NOT EXISTS). */
    public static function runSchemaFile(string $name): void
    {
        $pdo = DB::pdo();
        foreach (SqlSplitter::fromFile(BASE_PATH . '/database/schema/' . $name) as $statement) {
            $pdo->exec($statement);
        }
    }

    /** @param array{sample_users?:bool, super_admin?:array} $options */
    public static function seed(array $options = []): void
    {
        (require BASE_PATH . '/database/seed.php')($options);
        Settings::flush();
        Rbac::flush();
    }

    public static function writeLock(): void
    {
        file_put_contents(self::lockFile(), 'Installed ' . date('Y-m-d H:i:s') . PHP_EOL);
    }

    /**
     * Reset modes
     *   clinical : removes patients, visits, prescriptions, templates, billing,
     *              tokens, stock movements, lab / radiology / specialty records,
     *              activity logs and their counters. Keeps users, roles,
     *              masters, catalogs, locations and settings.
     *   factory  : rebuilds every table, reloads default master data and keeps
     *              only the given Super Admin account (same username/password).
     * A full backup is always taken first.
     * @return string name of the safety backup
     */
    public static function reset(string $mode, array $superAdmin): string
    {
        if (!in_array($mode, ['clinical', 'factory'], true)) {
            throw new \InvalidArgumentException('Unknown reset mode.');
        }
        $backup = Backup::create('pre-reset');
        $pdo = DB::pdo();

        if ($mode === 'clinical') {
            $pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
            try {
                foreach (self::CLINICAL_TABLES as $table) {
                    if (Migrator::tableExists($table)) {
                        $pdo->exec('TRUNCATE TABLE `' . $table . '`');
                    }
                }
                foreach (self::CLINICAL_COUNTERS as $like) {
                    DB::run('DELETE FROM counters WHERE counter_key LIKE ?', [$like]);
                }
            } finally {
                $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
            }
        } else {
            self::runSchema();
            Migrator::markLatest();
            self::seed([
                'sample_users' => false,
                'super_admin'  => [
                    'name'     => $superAdmin['name'],
                    'username' => $superAdmin['username'],
                    'password' => $superAdmin['password'],
                    'mobile'   => $superAdmin['mobile'] ?? null,
                    'email'    => $superAdmin['email'] ?? null,
                ],
            ]);
        }

        Settings::flush();
        Rbac::flush();
        ActivityLog::record(
            'Database reset',
            'system',
            null,
            'Database reset (' . $mode . ') by ' . $superAdmin['username'] . '. Safety backup: ' . $backup,
            null,
            $superAdmin['username']
        );
        return $backup;
    }
}
