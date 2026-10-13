<?php
declare(strict_types=1);

namespace App\Modules\Admin;

use App\Core\ActivityLog;
use App\Core\CodeGenerator;
use App\Core\Controller;
use App\Core\DB;
use App\Core\Paginator;
use App\Core\Settings;

/** Audit trail viewer and the hospital-wide (v2) settings. */
final class AdminController extends Controller
{
    /** Immutable audit trail: who changed what, old → new (Doc 1 §2.11, Doc 5 §7.7). */
    public function audit(): void
    {
        $where = '(l.old_value IS NOT NULL OR l.new_value IS NOT NULL)';
        $params = [];
        $q = trim((string) input('q', ''));
        $entity = (string) input('entity', '');
        $from = (string) input('from', date('Y-m-d', strtotime('-30 days')));
        $to = (string) input('to', today());
        if (input('all')) {
            $where = '1 = 1';
        }
        if ($q !== '') {
            $where .= ' AND (l.action LIKE ? OR l.description LIKE ? OR l.username LIKE ? OR l.record_id = ?)';
            $like = '%' . like_escape($q) . '%';
            array_push($params, $like, $like, $like, $q);
        }
        if ($entity !== '') {
            $where .= ' AND l.entity_type = ?';
            $params[] = $entity;
        }
        if (strtotime($from)) {
            $where .= ' AND l.created_at >= ?';
            $params[] = date('Y-m-d', strtotime($from)) . ' 00:00:00';
        }
        if (strtotime($to)) {
            $where .= ' AND l.created_at <= ?';
            $params[] = date('Y-m-d', strtotime($to)) . ' 23:59:59';
        }
        $select = 'SELECT l.* FROM activity_logs l WHERE ' . $where . ' ORDER BY l.id DESC';
        if ($this->wantsExport()) {
            $this->export('audit_trail', 'Audit Trail', [
                'created_at' => ['Date / Time', static fn ($r) => fmt_datetime($r['created_at'])], 'username' => 'User', 'role' => 'Role',
                'action' => 'Action', 'entity_type' => 'Entity', 'record_id' => 'Record', 'description' => 'Details',
                'old_value' => 'Old value', 'new_value' => 'New value', 'ip_address' => 'IP',
            ], DB::run($select, $params), ['From' => $from, 'To' => $to, 'Search' => $q]);
            return;
        }
        $pager = new Paginator((int) DB::value('SELECT COUNT(*) FROM activity_logs l WHERE ' . $where, $params), 50);
        $this->view('admin/audit', [
            'title'    => 'Audit Trail',
            'rows'     => DB::all($select . $pager->limitSql(), $params),
            'pager'    => $pager,
            'entities' => DB::column('SELECT DISTINCT entity_type FROM activity_logs WHERE entity_type IS NOT NULL ORDER BY entity_type'),
            'from'     => $from,
            'to'       => $to,
        ]);
    }

    /** Hospital settings: MRN mode, invoice numbering, token & billing rules, 2FA, department options. */
    public function hospital(): void
    {
        $errors = [];
        if (is_post()) {
            $in = $_POST;
            foreach (['chain_mrn_pattern', 'invoice_no_pattern'] as $pk) {
                if (($err = CodeGenerator::validate((string) ($in[$pk] ?? ''))) !== null) {
                    $errors[] = ucfirst(str_replace('_', ' ', $pk)) . ': ' . $err;
                }
            }
            if (!$errors) {
                $values = [
                    'mrn_mode'               => ($in['mrn_mode'] ?? 'doctor') === 'chain' ? 'chain' : 'doctor',
                    'chain_mrn_pattern'      => trim((string) $in['chain_mrn_pattern']),
                    'invoice_no_pattern'     => trim((string) $in['invoice_no_pattern']),
                    'token_requires_payment' => !empty($in['token_requires_payment']) ? '1' : '0',
                    'registration_fee_new'   => !empty($in['registration_fee_new']) ? '1' : '0',
                    'billing_medicines_at'   => ($in['billing_medicines_at'] ?? 'checkout') === 'dispense' ? 'dispense' : 'checkout',
                    'receipt_footer'         => mb_substr((string) ($in['receipt_footer'] ?? ''), 0, 255),
                    'receipt_footer_ur'      => mb_substr((string) ($in['receipt_footer_ur'] ?? ''), 0, 255),
                    'tax_label'              => mb_substr((string) ($in['tax_label'] ?? 'Tax'), 0, 40),
                    'board_chime'            => !empty($in['board_chime']) ? '1' : '0',
                    'enforce_2fa'            => !empty($in['enforce_2fa']) ? '1' : '0',
                ];
                foreach (Settings::defaults() as $key => $default) {
                    // Department module settings (lab_*, pharmacy_*, dental_*, obgyn_*, radiology_*) posted by the same form.
                    if (preg_match('/^(lab|pharmacy|dental|obgyn|radiology|stock)_/', $key) && array_key_exists($key, $in)) {
                        $values[$key] = mb_substr(trim((string) $in[$key]), 0, 500);
                    }
                }
                $old = array_intersect_key(Settings::all(), $values);
                Settings::set($values);
                [$o, $n] = ActivityLog::diff($old, $values);
                ActivityLog::record('Settings updated', 'settings', null, 'Hospital & billing settings', null, null, $n ? ['entity' => 'settings', 'old' => $o, 'new' => $n] : []);
                flash('success', 'Hospital settings saved.');
                redirect('settings/hospital');
            }
        }
        $this->view('admin/hospital', ['title' => 'Hospital & Billing Settings', 'errors' => $errors, 's' => Settings::all()]);
    }
}
