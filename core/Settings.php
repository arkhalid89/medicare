<?php
declare(strict_types=1);

namespace App\Core;

/**
 * Database-backed application settings (organisation, codes, Rx split,
 * theme, backups). Defaults below apply until the admin saves a value.
 */
final class Settings
{
    private static ?array $cache = null;

    public static function defaults(): array
    {
        return [
            // Organisation & branding
            'org_name'              => 'MediCare Clinic',
            'org_tagline'           => 'Quality care, close to home',
            'org_address'           => 'Main Boulevard, Lahore',
            'org_phone'             => '042-000-0000',
            'org_email'             => '',
            'org_logo'              => '',
            // Regional
            'currency_symbol'       => 'Rs.',
            'currency_code'         => 'PKR',
            'date_format'           => 'd-m-Y',
            // Consultation
            'default_paper'         => 'a4',
            'records_per_page'      => '25',
            'extra_vitals'          => 'Blood Sugar (mg/dL)',
            'follow_up_default_days'=> '7',
            'discount_enabled'      => '1',
            'tax_enabled'           => '0',
            // Codes & patterns
            'employee_code_pattern' => 'EMP-{YYYY}-{0001}',
            'default_mrn_pattern'   => 'MR-{YYYY}-{000001}',
            'visit_no_pattern'      => 'V-{YYYY}-{000001}',
            // Rx split (BRD §18 / §45)
            'rx_split_enabled'      => '1',
            'rx_split_method'       => 'source',
            'rx_split_print_mode'   => 'same_page',
            'rx_unassigned_label'   => 'General',
            // Theme
            'theme_primary'         => '#0A2463',
            'theme_secondary'       => '#C9A227',
            'theme_font_size'       => '13',
            'theme_sidebar'         => 'dark',
            // Backup
            'auto_backup'           => 'off',
            'backup_retention'      => '20',
            'last_auto_backup'      => '',
        ];
    }

    public static function all(): array
    {
        if (self::$cache === null) {
            $stored = [];
            try {
                $stored = DB::pairs('SELECT setting_key, setting_value FROM settings');
            } catch (\PDOException $e) {
                $stored = [];
            }
            self::$cache = array_merge(self::defaults(), $stored);
        }
        return self::$cache;
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        $all = self::all();
        return array_key_exists($key, $all) && $all[$key] !== null ? $all[$key] : $default;
    }

    public static function set(array $values): void
    {
        foreach ($values as $key => $value) {
            DB::run(
                'INSERT INTO settings (setting_key, setting_value, updated_at) VALUES (?, ?, ?)
                 ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), updated_at = VALUES(updated_at)',
                [$key, $value === null ? null : (string) $value, now()]
            );
        }
        self::flush();
    }

    public static function flush(): void
    {
        self::$cache = null;
    }
}
