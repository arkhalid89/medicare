<?php
declare(strict_types=1);

namespace App\Modules\Settings;

use App\Core\ActivityLog;
use App\Core\Auth;
use App\Core\CodeGenerator;
use App\Core\Controller;
use App\Core\DB;
use App\Core\Scope;
use App\Core\Settings;
use App\Core\Upload;
use App\Core\Validator;
use App\Modules\Users\UserService;

final class SettingsController extends Controller
{
    public const DATE_FORMATS = ['d-m-Y' => '26-09-2026', 'd/m/Y' => '26/09/2026', 'd M Y' => '26 Sep 2026', 'Y-m-d' => '2026-09-26', 'm/d/Y' => '09/26/2026'];
    public const SPLIT_MODES = [
        'same_page'              => 'All Rx sections stacked on the same page',
        'separate_pages'         => 'Each Rx section on a separate page',
        'separate_prescriptions' => 'A separate full prescription per source',
    ];

    public function general(): void
    {
        $errors = [];
        if (is_post()) {
            $v = new Validator($_POST);
            $v->required('org_name', 'Organization name')->max('org_name', 'Organization name', 150)
                ->max('org_tagline', 'Tagline', 200)->max('org_address', 'Address', 255)->max('org_phone', 'Phone', 100)
                ->email('org_email', 'Email')
                ->required('currency_symbol', 'Currency symbol')->max('currency_symbol', 'Currency symbol', 10)
                ->required('currency_code', 'Currency code')->max('currency_code', 'Currency code', 10)
                ->in('date_format', 'Date format', array_keys(self::DATE_FORMATS))
                ->in('default_paper', 'Default paper', array_keys(UserService::PAPERS))
                ->integer('records_per_page', 'Records per page', 5, 200)
                ->max('extra_vitals', 'Extra vitals', 500)
                ->integer('follow_up_default_days', 'Default follow-up days', 0, 365)
                ->in('auto_backup', 'Automatic backup', ['off', 'daily', 'weekly'])
                ->integer('backup_retention', 'Backups to keep', 1, 500);
            $errors = $v->errors();

            $logo = Upload::image($_FILES['org_logo'] ?? null, 'branding');
            if (!$logo['ok'] && ($logo['message'] ?? '') !== '') {
                $errors['org_logo'] = $logo['message'];
            }
            if (!$errors) {
                $values = [];
                foreach (['org_name', 'org_tagline', 'org_address', 'org_phone', 'org_email', 'currency_symbol', 'currency_code', 'date_format',
                          'default_paper', 'records_per_page', 'extra_vitals', 'follow_up_default_days', 'auto_backup', 'backup_retention'] as $k) {
                    $values[$k] = trim((string) ($_POST[$k] ?? ''));
                }
                $values['discount_enabled'] = !empty($_POST['discount_enabled']) ? '1' : '0';
                $values['tax_enabled'] = !empty($_POST['tax_enabled']) ? '1' : '0';
                if ($logo['ok']) {
                    Upload::delete((string) setting('org_logo'));
                    $values['org_logo'] = $logo['path'];
                } elseif (!empty($_POST['remove_logo'])) {
                    Upload::delete((string) setting('org_logo'));
                    $values['org_logo'] = '';
                }
                Settings::set($values);
                ActivityLog::record('Settings updated', 'settings', null, 'Organization & system settings updated');
                flash('success', 'Settings saved successfully.');
                redirect('settings/general');
            }
        }
        $this->view('settings/general', ['title' => 'Organization & System', 'errors' => array_values($errors), 'fieldErrors' => $errors]);
    }

    public function codes(): void
    {
        $errors = [];
        if (is_post()) {
            $emp = trim((string) input('employee_code_pattern', ''));
            $mrn = trim((string) input('default_mrn_pattern', ''));
            $visit = trim((string) input('visit_no_pattern', ''));
            if ($e = CodeGenerator::validate($emp, ['DEPT', 'ROLE'])) {
                $errors['employee_code_pattern'] = 'Employee code: ' . $e;
            }
            if ($e = CodeGenerator::validate($mrn)) {
                $errors['default_mrn_pattern'] = 'Default MRN: ' . $e;
            }
            if ($e = CodeGenerator::validate($visit)) {
                $errors['visit_no_pattern'] = 'Visit number: ' . $e;
            }
            $doctorPatterns = (array) ($_POST['mrn'] ?? []);
            foreach ($doctorPatterns as $doctorId => $pattern) {
                $pattern = trim((string) $pattern);
                if ($pattern !== '' && ($e = CodeGenerator::validate($pattern))) {
                    $errors['mrn_' . (int) $doctorId] = 'MRN pattern for doctor #' . (int) $doctorId . ': ' . $e;
                }
            }
            if (!$errors) {
                DB::transaction(static function () use ($emp, $mrn, $visit, $doctorPatterns): void {
                    Settings::set(['employee_code_pattern' => $emp, 'default_mrn_pattern' => $mrn, 'visit_no_pattern' => $visit]);
                    foreach ($doctorPatterns as $doctorId => $pattern) {
                        if (DB::value("SELECT 1 FROM users WHERE id = ? AND role = 'doctor'", [(int) $doctorId])) {
                            UserService::ensureDoctorSetup((int) $doctorId);
                            DB::update('doctor_profiles', ['mrn_pattern' => trim((string) $pattern) ?: null, 'updated_at' => now()], 'user_id = ?', [(int) $doctorId]);
                        }
                    }
                });
                ActivityLog::record('Code patterns updated', 'settings', null, "Employee: $emp · MRN default: $mrn · Visit: $visit");
                flash('success', 'Code patterns saved. New codes will follow these patterns; existing codes never change.');
                redirect('settings/codes');
            }
        }
        $doctors = DB::all("SELECT u.id, u.name, u.is_active, dp.mrn_pattern FROM users u LEFT JOIN doctor_profiles dp ON dp.user_id = u.id
                             WHERE u.role = 'doctor' AND u.deleted_at IS NULL ORDER BY u.name");
        foreach ($doctors as &$d) {
            $pattern = $d['mrn_pattern'] ?: (string) setting('default_mrn_pattern');
            $d['next'] = CodeGenerator::hasSequence($pattern) ? CodeGenerator::preview('mrn:' . $d['id'], $pattern) : '—';
        }
        unset($d);
        $this->view('settings/codes', [
            'title'       => 'Codes & Patterns',
            'errors'      => array_values($errors),
            'fieldErrors' => $errors,
            'doctors'     => $doctors,
            'previews'    => [
                'employee' => CodeGenerator::preview('emp', (string) setting('employee_code_pattern'), ['DEPT' => 'OPD', 'ROLE' => 'DR']),
                'visit'    => CodeGenerator::preview('visit', (string) setting('visit_no_pattern')),
            ],
        ]);
    }

    public function rx(): void
    {
        $errors = [];
        if (is_post()) {
            $v = new Validator($_POST);
            $v->in('rx_split_print_mode', 'Print mode', array_keys(self::SPLIT_MODES))
                ->in('rx_split_method', 'Split method', ['source'])
                ->required('rx_unassigned_label', 'Label for medicines without a source')->max('rx_unassigned_label', 'Label', 60);
            $errors = $v->errors();
            if (!$errors) {
                $old = [setting('rx_split_enabled'), setting('rx_split_print_mode')];
                DB::transaction(static function (): void {
                    Settings::set([
                        'rx_split_enabled'    => !empty($_POST['rx_split_enabled']) ? '1' : '0',
                        'rx_split_method'     => 'source',
                        'rx_split_print_mode' => (string) $_POST['rx_split_print_mode'],
                        'rx_unassigned_label' => trim((string) $_POST['rx_unassigned_label']),
                    ]);
                    foreach ((array) ($_POST['order'] ?? []) as $sourceId => $order) {
                        DB::update('medicine_sources', ['display_order' => max(0, (int) $order), 'updated_at' => now(), 'updated_by' => Auth::id()], 'id = ?', [(int) $sourceId]);
                    }
                });
                ActivityLog::record('Rx split setting changed', 'settings', null,
                    'Split ' . ($old[0] === '1' ? 'on' : 'off') . ' → ' . (setting('rx_split_enabled') === '1' ? 'on' : 'off')
                    . '; mode ' . $old[1] . ' → ' . setting('rx_split_print_mode'));
                flash('success', 'Rx split configuration saved. It applies to new prescriptions; saved visits keep their grouping.');
                redirect('settings/rx');
            }
        }
        $this->view('settings/rx', [
            'title'   => 'Rx Split Configuration',
            'errors'  => array_values($errors),
            'sources' => DB::all('SELECT s.id, s.name, s.short_code, s.is_active, s.display_order, (SELECT COUNT(*) FROM medicines m WHERE m.source_id = s.id AND m.deleted_at IS NULL) AS medicines
                                   FROM medicine_sources s WHERE s.deleted_at IS NULL ORDER BY s.display_order, s.name'),
        ]);
    }

    public function theme(): void
    {
        $errors = [];
        if (is_post()) {
            if (!empty($_POST['reset'])) {
                $defaults = Settings::defaults();
                Settings::set(['theme_primary' => $defaults['theme_primary'], 'theme_secondary' => $defaults['theme_secondary'], 'theme_font_size' => $defaults['theme_font_size']]);
                ActivityLog::record('Theme updated', 'settings', null, 'Theme reset to default');
                flash('success', 'Theme reset to the default Navy & Gold.');
                redirect('settings/theme');
            }
            $primary = (string) input('theme_primary', '');
            $secondary = (string) input('theme_secondary', '');
            $size = (string) input('theme_font_size', '13');
            if (!preg_match('/^#[0-9a-fA-F]{6}$/', $primary)) {
                $errors[] = 'Primary colour must be a hex colour like #0A2463.';
            }
            if (!preg_match('/^#[0-9a-fA-F]{6}$/', $secondary)) {
                $errors[] = 'Secondary colour must be a hex colour like #C9A227.';
            }
            if (!in_array($size, ['12', '13', '14', '15'], true)) {
                $errors[] = 'Base font size must be 12 to 15 px.';
            }
            if (!$errors) {
                Settings::set(['theme_primary' => strtoupper($primary), 'theme_secondary' => strtoupper($secondary), 'theme_font_size' => $size]);
                ActivityLog::record('Theme updated', 'settings', null, "Primary $primary, secondary $secondary, font {$size}px");
                flash('success', 'Theme updated.');
                redirect('settings/theme');
            }
        }
        $this->view('settings/theme', ['title' => 'Theme', 'errors' => $errors]);
    }

    /** Doctor-specific prescription design per paper size (BRD §51–54). */
    public function printDesign(): void
    {
        $doctors = Scope::doctorOptions();
        if (!$doctors) {
            $this->view('settings/print', ['title' => 'Prescription Designs', 'doctors' => [], 'design' => null, 'doctorId' => 0, 'paper' => 'a4', 'errors' => [], 'sampleVisit' => null]);
            return;
        }
        $doctorId = (int) input('doctor_id', (int) array_key_first($doctors));
        if (!isset($doctors[$doctorId])) {
            abort(403, 'You cannot manage this doctor\'s prescription design.');
        }
        $paper = (string) input('paper', 'a4');
        if (!isset(UserService::PAPERS[$paper])) {
            $paper = 'a4';
        }
        UserService::ensureDoctorSetup($doctorId);
        $design = DB::one('SELECT * FROM print_designs WHERE doctor_id = ? AND paper = ?', [$doctorId, $paper]);

        $errors = [];
        if (is_post()) {
            $v = new Validator($_POST);
            $v->max('header_title', 'Header title', 200)->max('header_subtitle', 'Subtitle', 255)->max('header_lines', 'Header lines', 1000)
                ->max('clinic_info', 'Clinic information', 1000)->max('footer_address', 'Footer address', 255)->max('footer_phone', 'Footer phone', 100)
                ->max('footer_followup', 'Follow-up text', 500)->max('footer_disclaimer', 'Disclaimer', 500)->max('footer_appointment', 'Appointment info', 500)
                ->integer('margin_mm', 'Margin', 2, 30)->numeric('font_size_pt', 'Font size', 6, 16);
            $errors = $v->errors();
            $image = Upload::image($_FILES['header_image'] ?? null, 'headers');
            if (!$image['ok'] && ($image['message'] ?? '') !== '') {
                $errors['header_image'] = $image['message'];
            }
            if (!$errors) {
                $data = ['updated_at' => now(), 'updated_by' => Auth::id()];
                foreach (['header_title', 'header_subtitle', 'header_lines', 'clinic_info', 'footer_address', 'footer_phone', 'footer_followup', 'footer_disclaimer', 'footer_appointment'] as $k) {
                    $data[$k] = trim((string) ($_POST[$k] ?? '')) ?: null;
                }
                $data['margin_mm'] = (int) $_POST['margin_mm'];
                $data['font_size_pt'] = round((float) $_POST['font_size_pt'], 1);
                foreach (['show_logo', 'show_prices', 'show_billing', 'show_signature'] as $k) {
                    $data[$k] = !empty($_POST[$k]) ? 1 : 0;
                }
                if ($image['ok']) {
                    Upload::delete($design['header_image']);
                    $data['header_image'] = $image['path'];
                } elseif (!empty($_POST['remove_header_image'])) {
                    Upload::delete($design['header_image']);
                    $data['header_image'] = null;
                }
                DB::update('print_designs', $data, 'id = ?', [$design['id']]);
                if (!empty($_POST['apply_all'])) {
                    $copy = $data;
                    unset($copy['margin_mm'], $copy['font_size_pt']);
                    DB::update('print_designs', $copy, 'doctor_id = ? AND id <> ?', [$doctorId, $design['id']]);
                }
                ActivityLog::record('Print design updated', 'settings', (string) $design['id'], $doctors[$doctorId] . ' — ' . strtoupper($paper) . (!empty($_POST['apply_all']) ? ' (copied to all paper sizes)' : ''));
                flash('success', 'Prescription design saved for ' . $doctors[$doctorId] . ' (' . UserService::PAPERS[$paper] . ').');
                redirect('settings/print', ['doctor_id' => $doctorId, 'paper' => $paper]);
            }
        }

        $this->view('settings/print', [
            'title'       => 'Prescription Designs',
            'doctors'     => $doctors,
            'doctorId'    => $doctorId,
            'paper'       => $paper,
            'design'      => $design,
            'errors'      => array_values($errors),
            'sampleVisit' => DB::value('SELECT id FROM visits WHERE doctor_id = ? AND deleted_at IS NULL ORDER BY id DESC LIMIT 1', [$doctorId]),
        ]);
    }
}
