<?php
declare(strict_types=1);

namespace App\Modules\Lab;

use App\Core\ActivityLog;
use App\Core\Auth;
use App\Core\Controller;
use App\Core\DB;

final class LabController extends Controller
{
    private const ITEM_SQL = 'SELECT li.*, t.name AS test_name, t.code AS test_code, t.fasting_required, t.turnaround_hours, o.order_number, o.patient_id, o.ordered_at,
                                     o.priority AS order_priority, o.visit_id, p.name AS patient_name, p.mrn, p.gender, p.dob, s.name AS section_name,
                                     sp.name AS specimen_name, c.name AS container_name, c.color_code, u.name AS doctor_name,
                                     (SELECT ls.barcode FROM lab_samples ls WHERE ls.lab_order_item_id = li.id ORDER BY ls.id DESC LIMIT 1) AS barcode
                                FROM lab_order_items li JOIN lab_orders o ON o.id = li.lab_order_id JOIN lab_tests t ON t.id = li.lab_test_id
                                JOIN patients p ON p.id = o.patient_id LEFT JOIN lab_sections s ON s.id = li.performing_section_id
                                LEFT JOIN lab_specimen_types sp ON sp.id = t.specimen_type_id LEFT JOIN lab_containers c ON c.id = t.container_id
                                LEFT JOIN users u ON u.id = o.ordered_by';

    /** Lab reception: walk-in / referral orders, today's orders, report hand-over. */
    public function reception(): void
    {
        if (is_post()) {
            try {
                if (input('do') === 'handover') {
                    if (!can('lab.report.handover')) {
                        abort(403);
                    }
                    LabService::handover((int) input('report_id'), (string) input('to', ''));
                    flash('success', 'Report handed over.');
                } else {
                    if (!can('lab.reception') && !can('lab.order.create')) {
                        abort(403);
                    }
                    $pid = (int) input('patient_id');
                    if (!$pid || !DB::value('SELECT 1 FROM patients WHERE id = ? AND deleted_at IS NULL', [$pid])) {
                        throw new \InvalidArgumentException('Select the patient.');
                    }
                    $id = LabService::order(['patient_id' => $pid, 'source' => input('referring_doctor') ? 'external' : 'walk_in', 'priority' => (string) input('priority', 'routine'),
                        'referring_doctor' => trim((string) input('referring_doctor', '')) ?: null, 'clinical_notes' => (string) input('clinical_notes', ''),
                        'doctor_id' => null], (array) ($_POST['tests'] ?? []));
                    $inv = DB::value('SELECT invoice_id FROM lab_orders WHERE id = ?', [$id]);
                    flash('success', 'Lab order created.' . ($inv && can('billing.collect') ? ' Collect payment at the counter.' : ''));
                    redirect('lab/order', ['id' => $id]);
                }
            } catch (\Throwable $e) {
                flash('error', $e->getMessage());
            }
            redirect('lab/reception');
        }
        $tests = DB::all('SELECT t.id, t.code, t.name, t.fasting_required, s.name AS section_name, t.service_id FROM lab_tests t LEFT JOIN lab_sections s ON s.id = t.section_id
                           WHERE t.is_active = 1 AND t.deleted_at IS NULL ORDER BY s.display_order, t.display_order, t.name');
        foreach ($tests as &$t) {
            $t['price'] = $t['service_id'] ? \App\Modules\Billing\BillingService::price((int) $t['service_id']) : 0;
        }
        unset($t);
        $orders = DB::all("SELECT o.*, p.name AS patient_name, p.mrn, i.status AS bill_status, i.net_amount, i.paid_amount,
                                  (SELECT COUNT(*) FROM lab_order_items x WHERE x.lab_order_id = o.id) AS tests,
                                  r.id AS report_id, r.report_number, r.handed_over_at
                             FROM lab_orders o JOIN patients p ON p.id = o.patient_id LEFT JOIN invoices i ON i.id = o.invoice_id
                             LEFT JOIN lab_reports r ON r.id = (SELECT MAX(id) FROM lab_reports WHERE lab_order_id = o.id)
                            WHERE o.ordered_at >= ? OR (r.id IS NOT NULL AND r.handed_over_at IS NULL)
                            ORDER BY o.id DESC LIMIT 150", [today() . ' 00:00:00']);
        $this->view('lab/reception', ['title' => 'Lab Reception', 'tests' => $tests, 'orders' => $orders]);
    }

    /** Phlebotomy worklist: patients with tests waiting for a sample. */
    public function collection(): void
    {
        if (is_post()) {
            try {
                $ids = LabService::collect((array) ($_POST['items'] ?? []), input('fasting') === null || input('fasting') === '' ? null : (bool) input('fasting'),
                    (string) input('notes', ''));
                flash('success', count($ids) . ' sample(s) collected and labelled.');
                redirect('lab/collection', ['labels' => implode(',', $ids)]);
            } catch (\Throwable $e) {
                flash('error', $e->getMessage());
            }
            redirect('lab/collection');
        }
        $rows = DB::all(self::ITEM_SQL . " WHERE li.status IN ('ordered','sample_pending','resample_pending')
                         ORDER BY FIELD(li.priority, 'stat', 'urgent') DESC, o.ordered_at LIMIT 300");
        $groups = [];
        foreach ($rows as $r) {
            $groups[$r['lab_order_id']]['order'] ??= $r;
            $groups[$r['lab_order_id']]['items'][] = $r;
        }
        $this->view('lab/collection', ['title' => 'Sample Collection', 'groups' => $groups, 'labels' => (string) input('labels', '')]);
    }

    /** Barcode labels for collected samples. */
    public function labels(): void
    {
        $ids = array_filter(array_map('intval', explode(',', (string) input('ids', ''))));
        $rows = $ids ? DB::all('SELECT s.barcode, s.collected_at, t.code, t.name AS test_name, p.name AS patient_name, p.mrn, p.gender, p.dob, c.name AS container_name
                                  FROM lab_samples s JOIN lab_order_items li ON li.id = s.lab_order_item_id JOIN lab_tests t ON t.id = li.lab_test_id
                                  JOIN lab_orders o ON o.id = s.lab_order_id JOIN patients p ON p.id = o.patient_id LEFT JOIN lab_containers c ON c.id = s.container_id
                                 WHERE s.id IN (' . DB::placeholders($ids) . ')', array_values($ids)) : [];
        $this->view('lab/labels', ['rows' => $rows], null);
    }

    /** Bench: accepted samples by section, result entry. */
    public function bench(): void
    {
        $mine = LabService::mySections();
        $section = (int) input('section_id', 0);
        $where = "li.status IN ('accepted','result_entered')";
        $params = [];
        if ($mine !== null) {
            $where .= ' AND li.performing_section_id IN (' . DB::placeholders($mine) . ')';
            $params = $mine;
        }
        if ($section) {
            $where .= ' AND li.performing_section_id = ?';
            $params[] = $section;
        }
        $rows = DB::all(self::ITEM_SQL . ' WHERE ' . $where . " ORDER BY FIELD(li.priority, 'stat', 'urgent') DESC, li.accepted_at LIMIT 300", $params);
        $sections = DB::pairs('SELECT id, name FROM lab_sections WHERE is_active = 1 AND deleted_at IS NULL ORDER BY display_order');
        $this->view('lab/bench', ['title' => 'Bench / Results', 'rows' => $rows, 'sections' => $sections, 'section' => $section, 'mine' => $mine]);
    }

    /** Result entry (and amendment) for one order item. */
    public function result(): void
    {
        $id = $this->requireId();
        if (is_post()) {
            try {
                if (input('do') === 'amend') {
                    if (!can('lab.result.verify')) {
                        abort(403);
                    }
                    LabService::amend($id, (array) ($_POST['v'] ?? []), (string) input('amend_reason', ''), (string) input('comment', ''));
                    flash('success', 'Amended — the new version needs verification.');
                } else {
                    LabService::enterResults($id, (array) ($_POST['v'] ?? []), (string) input('comment', ''));
                    flash('success', 'Results saved — waiting for pathologist verification.');
                }
                redirect(input('next') === 'bench' ? 'lab/bench' : 'lab/order', input('next') === 'bench' ? [] : ['id' => (int) DB::value('SELECT lab_order_id FROM lab_order_items WHERE id = ?', [$id])]);
            } catch (\Throwable $e) {
                flash('error', $e->getMessage());
                redirect('lab/result', ['id' => $id]);
            }
        }
        try {
            $ws = LabService::worksheet($id);
        } catch (\RuntimeException $e) {
            abort(404, $e->getMessage());
        }
        $history = DB::all('SELECT r.*, u.name AS entered_by_name, v.name AS verified_by_name FROM lab_results r LEFT JOIN users u ON u.id = r.entered_by
                             LEFT JOIN users v ON v.id = r.verified_by WHERE r.lab_order_item_id = ? ORDER BY r.version DESC', [$id]);
        $this->view('lab/result', ['title' => 'Result — ' . $ws['item']['test_name'], 'ws' => $ws, 'history' => $history]);
    }

    /** Pathologist desk: gate 1 (samples) and gate 2 (verification). */
    public function pathologist(): void
    {
        $tab = input('tab') === 'verify' ? 'verify' : 'gate';
        if (is_post()) {
            try {
                if (input('do') === 'review') {
                    if (!can('lab.sample.review')) {
                        abort(403);
                    }
                    LabService::review((int) input('sample_id'), (string) input('decision'), (string) input('reason', '') ?: null, (string) input('resolution', '') ?: null);
                    flash('success', input('decision') === 'accept' ? 'Sample accepted.' : 'Sample rejected — ' . (input('resolution') === 'refund' ? 'test refunded.' : 'resample requested.'));
                } elseif (input('do') === 'verify') {
                    if (!can('lab.result.verify')) {
                        abort(403);
                    }
                    $n = 0;
                    foreach ((array) ($_POST['items'] ?? [input('item_id')]) as $iid) {
                        LabService::verify((int) $iid);
                        $n++;
                    }
                    flash('success', $n . ' result(s) verified and released.');
                } elseif (input('do') === 'sendback') {
                    if (!can('lab.result.verify')) {
                        abort(403);
                    }
                    LabService::sendBack((int) input('item_id'), (string) input('note', 'Recheck'));
                    flash('success', 'Sent back to the bench.');
                }
            } catch (\Throwable $e) {
                flash('error', $e->getMessage());
            }
            redirect('lab/pathologist', ['tab' => $tab]);
        }
        $samples = $tab === 'gate' ? DB::all('SELECT s.*, t.name AS test_name, sp.name AS specimen_name, c.name AS container_name, p.name AS patient_name, p.mrn,
                                                     u.name AS collected_by_name, li.priority, sp.max_hold_hours
                                                FROM lab_samples s JOIN lab_order_items li ON li.id = s.lab_order_item_id JOIN lab_tests t ON t.id = li.lab_test_id
                                                JOIN lab_orders o ON o.id = s.lab_order_id JOIN patients p ON p.id = o.patient_id
                                                LEFT JOIN lab_specimen_types sp ON sp.id = s.specimen_type_id LEFT JOIN lab_containers c ON c.id = s.container_id
                                                LEFT JOIN users u ON u.id = s.collected_by
                                               WHERE s.status = \'collected\' ORDER BY FIELD(li.priority, \'stat\', \'urgent\') DESC, s.collected_at LIMIT 200') : [];
        $results = [];
        if ($tab === 'verify') {
            $results = DB::all(self::ITEM_SQL . " WHERE li.status = 'result_entered' ORDER BY FIELD(li.priority, 'stat', 'urgent') DESC, li.result_entered_at LIMIT 200");
            foreach ($results as &$r) {
                $res = DB::one('SELECT r.*, u.name AS entered_by_name FROM lab_results r LEFT JOIN users u ON u.id = r.entered_by WHERE r.lab_order_item_id = ? AND r.is_current = 1', [$r['id']]);
                $r['result'] = $res;
                $r['values'] = $res ? DB::all('SELECT v.*, p.name, p.data_type FROM lab_result_values v JOIN lab_test_parameters p ON p.id = v.parameter_id
                                                WHERE v.lab_result_id = ? ORDER BY p.display_order', [$res['id']]) : [];
                $r['qc_blocked'] = false;
                foreach ($r['values'] as $v) {
                    if ($v['data_type'] === 'numeric' && LabService::qcBlocked((int) $v['parameter_id'])) {
                        $r['qc_blocked'] = true;
                    }
                }
            }
            unset($r);
        }
        $counts = ['gate' => (int) DB::value("SELECT COUNT(*) FROM lab_samples WHERE status = 'collected'"),
            'verify' => (int) DB::value("SELECT COUNT(*) FROM lab_order_items WHERE status = 'result_entered'")];
        $this->view('lab/pathologist', ['title' => 'Pathologist Desk', 'tab' => $tab, 'samples' => $samples, 'results' => $results, 'counts' => $counts]);
    }

    /** QC: materials, runs with Westgard flags, release of failed runs. */
    public function qc(): void
    {
        if (is_post()) {
            try {
                $do = (string) input('do');
                if ($do === 'run') {
                    $r = LabService::qcRun((int) input('material_id'), (float) input('value'), (int) input('analyzer_id', 0) ?: null);
                    flash($r['status'] === 'fail' ? 'error' : ($r['status'] === 'warn' ? 'warning' : 'success'),
                        'QC ' . strtoupper($r['status']) . ' (z = ' . $r['z'] . ($r['rule'] ? ', ' . $r['rule'] : '') . ').'
                        . ($r['status'] === 'fail' ? ' Verification of this parameter is blocked until a passing run or a documented release.' : ''));
                } elseif ($do === 'override') {
                    if (!can('lab.qc.manage')) {
                        abort(403);
                    }
                    LabService::qcOverride((int) input('run_id'), (string) input('note', ''));
                    flash('success', 'QC run released.');
                } elseif ($do === 'material') {
                    if (!can('lab.qc.manage')) {
                        abort(403);
                    }
                    $pid = (int) input('parameter_id');
                    DB::insert('lab_qc_materials', ['name' => trim((string) input('name')), 'lot_no' => trim((string) input('lot_no', '')) ?: null,
                        'level' => trim((string) input('level', '')) ?: null, 'expiry_date' => input('expiry_date') ?: null, 'parameter_id' => $pid,
                        'section_id' => DB::value('SELECT t.section_id FROM lab_test_parameters p JOIN lab_tests t ON t.id = p.lab_test_id WHERE p.id = ?', [$pid]),
                        'target_mean' => (float) input('target_mean'), 'target_sd' => (float) input('target_sd'), 'is_active' => 1, 'created_at' => now()]);
                    ActivityLog::record('QC material added', 'lab', null, (string) input('name'));
                    flash('success', 'QC material added.');
                } elseif ($do === 'material_off' && can('lab.qc.manage')) {
                    DB::update('lab_qc_materials', ['is_active' => 0], 'id = ?', [(int) input('id')]);
                }
            } catch (\Throwable $e) {
                flash('error', $e->getMessage());
            }
            redirect('lab/qc', ['material_id' => (int) input('material_id', 0) ?: null]);
        }
        $materials = DB::all('SELECT m.*, p.name AS parameter_name, p.unit, t.name AS test_name,
                                     (SELECT status FROM lab_qc_runs r WHERE r.qc_material_id = m.id ORDER BY r.run_at DESC, r.id DESC LIMIT 1) AS last_status
                                FROM lab_qc_materials m LEFT JOIN lab_test_parameters p ON p.id = m.parameter_id LEFT JOIN lab_tests t ON t.id = p.lab_test_id
                               WHERE m.is_active = 1 ORDER BY m.name');
        $mid = (int) input('material_id', 0) ?: (int) ($materials[0]['id'] ?? 0);
        $runs = $mid ? DB::all('SELECT r.*, u.name AS recorded_by_name, o.name AS override_by_name FROM lab_qc_runs r LEFT JOIN users u ON u.id = r.recorded_by
                                 LEFT JOIN users o ON o.id = r.override_by WHERE r.qc_material_id = ? ORDER BY r.run_at DESC, r.id DESC LIMIT 30', [$mid]) : [];
        $params = DB::pairs("SELECT p.id, CONCAT(t.name, ' — ', p.name) FROM lab_test_parameters p JOIN lab_tests t ON t.id = p.lab_test_id
                              WHERE p.data_type = 'numeric' AND p.is_active = 1 ORDER BY t.name, p.display_order");
        $analyzers = DB::pairs('SELECT id, name FROM lab_analyzers WHERE is_active = 1 AND deleted_at IS NULL ORDER BY name');
        $this->view('lab/qc', ['title' => 'Quality Control', 'materials' => $materials, 'mid' => $mid, 'runs' => array_reverse($runs), 'params' => $params, 'analyzers' => $analyzers]);
    }

    /** All lab orders with filters. */
    public function orders(): void
    {
        $q = trim((string) input('q', ''));
        $status = (string) input('status', '');
        $from = (string) input('from', date('Y-m-d', strtotime('-7 days')));
        $to = (string) input('to', today());
        $where = ['o.ordered_at BETWEEN ? AND ?'];
        $params = [$from . ' 00:00:00', $to . ' 23:59:59'];
        if ($q !== '') {
            $where[] = '(p.name LIKE ? OR p.mrn LIKE ? OR o.order_number LIKE ? OR EXISTS (SELECT 1 FROM lab_samples s WHERE s.lab_order_id = o.id AND s.barcode = ?))';
            array_push($params, '%' . $q . '%', '%' . $q . '%', '%' . $q . '%', $q);
        }
        if ($status !== '') {
            $where[] = 'o.status = ?';
            $params[] = $status;
        }
        if (!can('lab.view') && can('lab.order.create')) {
            $where[] = 'o.ordered_by = ?';
            $params[] = Auth::id();
        }
        $rows = DB::all('SELECT o.*, p.name AS patient_name, p.mrn, u.name AS doctor_name, i.status AS bill_status,
                                (SELECT GROUP_CONCAT(t.code ORDER BY t.display_order SEPARATOR \', \') FROM lab_order_items x JOIN lab_tests t ON t.id = x.lab_test_id WHERE x.lab_order_id = o.id) AS tests
                           FROM lab_orders o JOIN patients p ON p.id = o.patient_id LEFT JOIN users u ON u.id = o.ordered_by LEFT JOIN invoices i ON i.id = o.invoice_id
                          WHERE ' . implode(' AND ', $where) . ' ORDER BY o.id DESC LIMIT 500', $params);
        if ($this->wantsExport()) {
            $this->export('lab-orders', 'Lab orders', ['order_number' => 'Order', 'ordered_at' => 'Date', 'patient_name' => 'Patient', 'mrn' => 'MRN',
                'tests' => 'Tests', 'doctor_name' => 'Ordered by', 'status' => 'Status', 'bill_status' => 'Bill'], $rows, ['From' => $from, 'To' => $to]);
            return;
        }
        $this->view('lab/orders', ['title' => 'Lab Orders', 'rows' => $rows, 'q' => $q, 'status' => $status, 'from' => $from, 'to' => $to]);
    }

    /** One order: items, samples, results, timeline, actions. */
    public function order(): void
    {
        $id = $this->requireId();
        if (is_post() && input('do') === 'cancel') {
            try {
                if (!can('lab.reception') && !can('lab.result.verify')) {
                    abort(403);
                }
                LabService::cancelItem((int) input('item_id'), (string) input('reason', 'Cancelled at request'));
                flash('success', 'Test cancelled.');
            } catch (\Throwable $e) {
                flash('error', $e->getMessage());
            }
            redirect('lab/order', ['id' => $id]);
        }
        try {
            $o = LabService::report($id);
        } catch (\RuntimeException $e) {
            abort(404, $e->getMessage());
        }
        if (!can('lab.view') && (int) $o['ordered_by'] !== Auth::id()) {
            abort(403);
        }
        $samples = DB::all('SELECT s.*, r.decision, r.rejection_reason, r.resolution, r.refund_amount, u.name AS reviewed_by_name, c.name AS collected_by_name, t.name AS test_name
                              FROM lab_samples s LEFT JOIN lab_sample_reviews r ON r.lab_sample_id = s.id LEFT JOIN users u ON u.id = r.reviewed_by
                              LEFT JOIN users c ON c.id = s.collected_by JOIN lab_order_items li ON li.id = s.lab_order_item_id JOIN lab_tests t ON t.id = li.lab_test_id
                             WHERE s.lab_order_id = ? ORDER BY s.id', [$id]);
        $reports = DB::all('SELECT r.*, u.name AS released_by_name, h.name AS handed_by_name FROM lab_reports r LEFT JOIN users u ON u.id = r.released_by
                             LEFT JOIN users h ON h.id = r.handed_over_by WHERE r.lab_order_id = ? ORDER BY r.version DESC', [$id]);
        $invoice = $o['invoice_id'] ? DB::one('SELECT id, invoice_no, status, net_amount, paid_amount FROM invoices WHERE id = ?', [$o['invoice_id']]) : null;
        $this->view('lab/order', ['title' => 'Lab Order ' . $o['order_number'], 'o' => $o, 'samples' => $samples, 'reports' => $reports, 'invoice' => $invoice]);
    }

    /** Printable report (only released results are printed). */
    public function report(): void
    {
        $id = $this->requireId();
        try {
            $o = LabService::report($id);
        } catch (\RuntimeException $e) {
            abort(404, $e->getMessage());
        }
        if (!can('lab.view') && (int) $o['ordered_by'] !== Auth::id()) {
            abort(403);
        }
        if ($o['report']) {
            ActivityLog::record('Lab report printed', 'lab', (string) $id, $o['report']['report_number']);
        }
        $this->view('lab/report', ['o' => $o], null);
    }

    /** Doctor's critical-value inbox with read-back acknowledgement. */
    public function critical(): void
    {
        if (is_post()) {
            try {
                LabService::acknowledgeCritical((int) input('id'), (string) input('read_back', 'Acknowledged'));
                flash('success', 'Critical value acknowledged.');
            } catch (\Throwable $e) {
                flash('error', $e->getMessage());
            }
            redirect('lab/critical');
        }
        $all = can('lab.result.verify') && input('all');
        $rows = DB::all('SELECT n.*, p.name AS patient_name, p.mrn, pr.name AS parameter_name, v.value_numeric, v.value_text, v.unit, v.flag, v.ref_low, v.ref_high,
                                t.name AS test_name, li.lab_order_id, u.name AS doctor_name
                           FROM lab_critical_notifications n JOIN patients p ON p.id = n.patient_id JOIN lab_result_values v ON v.id = n.lab_result_value_id
                           JOIN lab_test_parameters pr ON pr.id = v.parameter_id JOIN lab_order_items li ON li.id = n.lab_order_item_id
                           JOIN lab_tests t ON t.id = li.lab_test_id LEFT JOIN users u ON u.id = n.notified_user_id
                          WHERE ' . ($all ? '1 = 1' : 'n.notified_user_id = ?') . ' ORDER BY n.acknowledged_at IS NULL DESC, n.notified_at DESC LIMIT 200', $all ? [] : [Auth::id()]);
        $this->view('lab/critical', ['title' => 'Critical Lab Values', 'rows' => $rows, 'all' => $all]);
    }

    /** Test catalog: tests, parameters, reference ranges. */
    public function catalog(): void
    {
        if (is_post()) {
            try {
                $this->saveCatalog();
            } catch (\Throwable $e) {
                flash('error', $e->getMessage());
            }
            redirect('lab/catalog', ['id' => (int) input('test_id', 0) ?: null]);
        }
        $id = (int) input('id', 0);
        $tests = DB::all('SELECT t.*, s.name AS section_name, sp.name AS specimen_name, (SELECT COUNT(*) FROM lab_test_parameters p WHERE p.lab_test_id = t.id) AS params
                            FROM lab_tests t LEFT JOIN lab_sections s ON s.id = t.section_id LEFT JOIN lab_specimen_types sp ON sp.id = t.specimen_type_id
                           WHERE t.deleted_at IS NULL ORDER BY s.display_order, t.display_order, t.name');
        $test = $id ? DB::one('SELECT * FROM lab_tests WHERE id = ? AND deleted_at IS NULL', [$id]) : null;
        $params = $test ? DB::all('SELECT * FROM lab_test_parameters WHERE lab_test_id = ? ORDER BY display_order, id', [$id]) : [];
        foreach ($params as &$p) {
            $p['ranges'] = DB::all('SELECT * FROM lab_reference_ranges WHERE parameter_id = ? ORDER BY sex, age_min_days', [$p['id']]);
        }
        unset($p);
        $this->view('lab/catalog', ['title' => 'Lab Test Catalog', 'tests' => $tests, 'test' => $test, 'params' => $params,
            'sections' => DB::pairs('SELECT id, name FROM lab_sections WHERE deleted_at IS NULL ORDER BY display_order'),
            'specimens' => DB::pairs('SELECT id, name FROM lab_specimen_types WHERE deleted_at IS NULL ORDER BY display_order'),
            'containers' => DB::pairs('SELECT id, name FROM lab_containers WHERE deleted_at IS NULL ORDER BY display_order'),
            'investigations' => DB::pairs('SELECT id, name FROM investigations WHERE deleted_at IS NULL ORDER BY name'),
            'price' => $test && $test['service_id'] ? \App\Modules\Billing\BillingService::price((int) $test['service_id']) : null]);
    }

    private function saveCatalog(): void
    {
        $do = (string) input('do');
        $testId = (int) input('test_id', 0);
        if ($do === 'test') {
            $name = trim((string) input('name'));
            if ($name === '') {
                throw new \InvalidArgumentException('Test name is required.');
            }
            $data = ['code' => strtoupper(trim((string) input('code', ''))) ?: null, 'name' => mb_substr($name, 0, 150), 'section_id' => (int) input('section_id') ?: null,
                'specimen_type_id' => (int) input('specimen_type_id') ?: null, 'container_id' => (int) input('container_id') ?: null,
                'investigation_id' => (int) input('investigation_id') ?: null, 'method' => trim((string) input('method', '')) ?: null,
                'result_type' => in_array(input('result_type'), ['numeric', 'select', 'text', 'structured'], true) ? input('result_type') : 'numeric',
                'fasting_required' => input('fasting_required') ? 1 : 0, 'is_outsourceable' => input('is_outsourceable') ? 1 : 0,
                'turnaround_hours' => max(1, (int) input('turnaround_hours', 24)), 'instructions' => trim((string) input('instructions', '')) ?: null,
                'is_active' => input('is_active') ? 1 : 0];
            $price = (float) input('price', 0);
            if ($testId) {
                $old = DB::one('SELECT * FROM lab_tests WHERE id = ?', [$testId]);
                DB::update('lab_tests', $data + ['updated_at' => now(), 'updated_by' => Auth::id()], 'id = ?', [$testId]);
                $serviceId = (int) $old['service_id'];
            } else {
                $testId = DB::insert('lab_tests', $data + ['created_at' => now(), 'created_by' => Auth::id()]);
                $serviceId = 0;
            }
            if (!$serviceId) {
                $serviceId = DB::insert('service_catalog', ['code' => 'LAB-' . ($data['code'] ?: $testId), 'name' => $data['name'], 'service_type' => 'lab_test',
                    'default_price' => $price, 'is_active' => 1, 'created_at' => now()]);
                DB::update('lab_tests', ['service_id' => $serviceId], 'id = ?', [$testId]);
            } else {
                DB::update('service_catalog', ['name' => $data['name'], 'is_active' => $data['is_active']], 'id = ?', [$serviceId]);
            }
            if (abs(\App\Modules\Billing\BillingService::price($serviceId) - $price) > 0.004) {
                \App\Modules\Billing\BillingService::setPrice($serviceId, $price, null);
            }
            ActivityLog::record('Lab test saved', 'lab', (string) $testId, $data['name']);
            $_POST['test_id'] = $testId;
            flash('success', 'Test saved.');
        } elseif ($do === 'param') {
            $pid = (int) input('param_id', 0);
            $data = ['lab_test_id' => $testId, 'code' => trim((string) input('code', '')) ?: null, 'name' => trim((string) input('name')), 'unit' => trim((string) input('unit', '')) ?: null,
                'data_type' => in_array(input('data_type'), ['numeric', 'select', 'text'], true) ? input('data_type') : 'numeric',
                'options_json' => trim((string) input('options', '')) !== '' ? json_encode(array_values(array_filter(array_map('trim', explode(',', (string) input('options')))))) : null,
                'decimals' => max(0, min(4, (int) input('decimals', 1))), 'critical_low' => input('critical_low') === '' ? null : (float) input('critical_low'),
                'critical_high' => input('critical_high') === '' ? null : (float) input('critical_high'), 'display_order' => (int) input('display_order', 0), 'is_active' => 1];
            if ($data['name'] === '') {
                throw new \InvalidArgumentException('Parameter name is required.');
            }
            $pid ? DB::update('lab_test_parameters', $data, 'id = ?', [$pid]) : DB::insert('lab_test_parameters', $data);
            flash('success', 'Parameter saved.');
        } elseif ($do === 'param_off') {
            DB::update('lab_test_parameters', ['is_active' => (int) input('active', 0)], 'id = ? AND lab_test_id = ?', [(int) input('param_id'), $testId]);
        } elseif ($do === 'range') {
            $sex = in_array(input('sex'), ['A', 'M', 'F'], true) ? input('sex') : 'A';
            $unit = (string) input('age_unit', 'years');
            $mul = ['days' => 1, 'months' => 30, 'years' => 365][$unit] ?? 365;
            DB::insert('lab_reference_ranges', ['parameter_id' => (int) input('param_id'), 'sex' => $sex,
                'age_min_days' => (int) round((float) input('age_min', 0) * $mul), 'age_max_days' => input('age_max') === '' ? 54750 : (int) round((float) input('age_max') * $mul) - 1,
                'pregnant' => input('pregnant') === '' || input('pregnant') === null ? null : (int) input('pregnant'),
                'low' => input('low') === '' ? null : (float) input('low'), 'high' => input('high') === '' ? null : (float) input('high'),
                'text_range' => trim((string) input('text_range', '')) ?: null]);
            ActivityLog::record('Reference range added', 'lab', (string) input('param_id'), $sex . ' ' . input('low') . '–' . input('high'));
            flash('success', 'Range added.');
        } elseif ($do === 'range_del') {
            DB::run('DELETE FROM lab_reference_ranges WHERE id = ?', [(int) input('range_id')]);
            ActivityLog::record('Reference range removed', 'lab', (string) input('range_id'));
        }
    }

    /** JSON: outsource send / receive (lab manager). */
    public function outsource(): void
    {
        try {
            if (input('do') === 'receive') {
                LabService::outsourceReceived((int) input('referral_id'));
            } else {
                LabService::outsource((int) input('item_id'), (int) input('vendor_id'), (string) input('tracking_no', ''), (string) input('expected_at', '') ?: null,
                    input('cost') === '' || input('cost') === null ? null : (float) input('cost'));
            }
            flash('success', 'Outsource referral updated.');
        } catch (\Throwable $e) {
            flash('error', $e->getMessage());
        }
        back('lab/orders');
    }
}
