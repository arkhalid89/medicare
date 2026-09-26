<?php
declare(strict_types=1);

namespace App\Core;

/**
 * Creates the database, runs database/schema.sql, seeds master data and
 * (optionally) demo patients/visits. Also implements the two database reset
 * modes used by the Super Admin "Database Reset" screen and database/reset.php.
 */
final class Installer
{
    /** Tables emptied by a "clinical data" reset. Masters, users and settings stay. */
    public const CLINICAL_TABLES = [
        'visit_medicines', 'visit_clinical_items', 'visits',
        'template_medicines', 'template_clinical_items', 'templates',
        'patients', 'activity_logs',
    ];

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
        if (!empty($options['demo'])) {
            (require BASE_PATH . '/database/demo.php')();
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

    public static function runSchema(): void
    {
        $pdo = DB::pdo();
        foreach (SqlSplitter::fromFile(BASE_PATH . '/database/schema.sql') as $statement) {
            $pdo->exec($statement);
        }
        Settings::flush();
    }

    /** @param array{sample_users?:bool, super_admin?:array} $options */
    public static function seed(array $options = []): void
    {
        (require BASE_PATH . '/database/seed.php')($options);
        Settings::flush();
    }

    public static function writeLock(): void
    {
        file_put_contents(self::lockFile(), 'Installed ' . date('Y-m-d H:i:s') . PHP_EOL);
    }

    /**
     * Reset modes
     *   clinical : removes patients, visits, prescriptions, templates, activity
     *              logs and MRN/visit counters. Keeps users, masters, settings.
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
                    $pdo->exec('TRUNCATE TABLE `' . $table . '`');
                }
                $pdo->exec("DELETE FROM counters WHERE counter_key LIKE 'mrn:%' OR counter_key LIKE 'visit:%'");
            } finally {
                $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
            }
        } else {
            self::runSchema();
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
