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
            // Rx split (BRD §18 / §45) and the PHC prescription layout
            'rx_split_enabled'      => '1',
            'rx_split_method'       => 'source',
            'rx_main_source_id'     => '',          // '' = the source with display order 1 (lowest)
            'rx_split_print_mode'   => 'slip',       // slip | separate_page
            'rx_unassigned_label'   => 'General',
            'rx_show_urdu'          => '1',
            'rx_slip_title'         => 'Hospital Pharmacy & Billing',
            'rx_slip_prices'        => '1',
            'rx_slip_cumulative'    => '1',
            // PHC letterhead (Punjab Healthcare Commission standard)
            'org_phc_reg_no'        => '',
            'org_phc_rl_no'         => '',
            'org_qr_image'          => '',
            'org_slogan_ur'         => 'علاج ہمیشہ مستند معالج سے کرائیں',
            'clinic_timings'        => 'Mon to Sat, 09:00 to 21:00',
            // Subscription licence
            'license_key'           => '',
            'license_phone'         => '',
            'license_last_seen'     => '',
            // Theme
            'theme_primary'         => '#0A2463',
            'theme_secondary'       => '#C9A227',
            'theme_font_size'       => '13',
            'theme_sidebar'         => 'dark',
            // Backup
            'auto_backup'           => 'off',
            'backup_retention'      => '20',
            'last_auto_backup'      => '',
            // v2 — access control
            'permissions_version'   => '1',
            'seeded_grants'         => '{}',
            'default_branch_id'     => '',
            'enforce_2fa'           => '0',          // 1 = roles flagged "2FA" must set up an authenticator
            // v2 — registration, tokens & billing
            'mrn_mode'              => 'doctor',     // doctor = per-doctor pattern (v1) | chain = one hospital-wide MRN
            'chain_mrn_pattern'     => 'MC-{YYYY}-{000001}',
            'invoice_no_pattern'    => 'INV-{YYYY}-{000001}',
            'receipt_no_pattern'    => 'RCP-{YYYY}-{000001}',
            'token_requires_payment'=> '1',          // no token without a receipt or an authorised waiver (Doc 3 WF 1)
            'registration_fee_new'  => '1',          // charge the registration card service for new patients
            'billing_medicines_at'  => 'checkout',   // checkout | dispense (hospital pharmacy bills on dispensing)
            'receipt_footer'        => 'Thank you. Please keep this receipt for follow-up.',
            'receipt_footer_ur'     => 'شکریہ۔ براہ کرم یہ رسید سنبھال کر رکھیں۔',
            'tax_label'             => 'Sales tax',
            'board_chime'           => '1',
            'last_daily_jobs'       => '',
            // v2 — department modules
            'stock_negative_locations' => '',
            'stock_adjust_limit'    => '5000',
            'stock_po_approval_limit' => '100000',
            'stock_expiry_alert_days' => '90',
            'pharmacy_allow_partial'=> '1',
            'pharmacy_location_id'  => '',
            'lab_barcode_prefix'    => 'L',
            'lab_tat_alert_hours'   => '24',
            'lab_report_footer'     => 'Results relate to the sample as received. Please correlate clinically.',
            'radiology_pregnancy_age_min' => '12',
            'radiology_pregnancy_age_max' => '50',
            'dental_billing_mode'   => 'per_procedure',
            'obgyn_risk_bp_systolic'  => '140',
            'obgyn_risk_bp_diastolic' => '90',
            'obgyn_risk_hb'           => '10',
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
