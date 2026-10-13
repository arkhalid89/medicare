<?php
declare(strict_types=1);

namespace App\Modules\Radiology;

use App\Core\ActivityLog;
use App\Core\Auth;
use App\Core\Controller;
use App\Core\DB;
use App\Core\Upload;

final class RadiologyController extends Controller
{
    private const LIST_SQL = 'SELECT o.*, c.name AS procedure_name, c.uses_ionizing_radiation, c.contrast_possible, c.prep_instructions, m.code AS modality_code, m.name AS modality_name,
                                     p.name AS patient_name, p.mrn, p.gender, p.dob, p.allergies, u.name AS doctor_name, r.name AS room_name
                                FROM radiology_orders o JOIN radiology_procedure_catalog c ON c.id = o.procedure_id JOIN radiology_modalities m ON m.id = o.modality_id
                                JOIN patients p ON p.id = o.patient_id LEFT JOIN users u ON u.id = o.ordered_by LEFT JOIN radiology_rooms r ON r.id = o.room_id';

    /** Reception: walk-in orders, safety screening, scheduling, today's room board. */
    public function reception(): void
    {
        if (is_post()) {
            $id = (int) input('order_id', 0);
            try {
                switch ((string) input('do')) {
                    case 'order':
                        $pid = (int) input('patient_id');
                        if (!$pid) {
                            throw new \InvalidArgumentException('Select the patient.');
                        }
                        $ids = RadiologyService::order(['patient_id' => $pid, 'source' => input('referring_doctor') ? 'external' : 'walk_in', 'priority' => (string) input('priority', 'routine'),
                            'referring_doctor' => trim((string) input('referring_doctor', '')) ?: null, 'clinical_indication' => (string) input('clinical_indication', '')],
                            (array) ($_POST['procedures'] ?? []), ['pregnancy_status' => (string) input('pregnancy_status', ''), 'contrast' => (bool) input('contrast')]);
                        flash('success', count($ids) . ' imaging order(s) created and billed.');
                        break;
                    case 'screen':
                        RadiologyService::screen($id, $_POST);
                        flash('success', 'Safety screening recorded.');
                        break;
                    case 'schedule':
                        RadiologyService::schedule($id, (int) input('room_id'), (string) input('start'));
                        flash('success', 'Scheduled.');
                        break;
                    case 'cancel':
                        RadiologyService::cancel($id, (string) input('reason', 'Cancelled at request'));
                        flash('success', 'Cancelled.');
                        break;
                }
            } catch (\Throwable $e) {
                flash('error', $e->getMessage());
            }
            redirect('radiology/reception', ['date' => input('date') ?: null]);
        }
        $date = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) input('date', '')) ? (string) input('date') : today();
        $pending = DB::all(self::LIST_SQL . " WHERE o.status IN ('ordered','scheduled','reprocess_requested') ORDER BY FIELD(o.priority,'stat','urgent') DESC, o.scheduled_at IS NULL DESC, o.ordered_at LIMIT 300");
        foreach ($pending as &$o) {
            $o['issues'] = RadiologyService::safetyIssues($o);
        }
        unset($o);
        $rooms = DB::all('SELECT r.*, m.code AS modality_code FROM radiology_rooms r JOIN radiology_modalities m ON m.id = r.modality_id WHERE r.is_active = 1 AND r.deleted_at IS NULL ORDER BY r.display_order');
        $slots = DB::all("SELECT s.*, o.order_number, o.status AS order_status, p.name AS patient_name, c.name AS procedure_name FROM radiology_schedule_slots s
                            JOIN radiology_orders o ON o.id = s.radiology_order_id JOIN patients p ON p.id = o.patient_id JOIN radiology_procedure_catalog c ON c.id = o.procedure_id
                           WHERE s.status = 'booked' AND s.slot_start BETWEEN ? AND ? ORDER BY s.slot_start", [$date . ' 00:00:00', $date . ' 23:59:59']);
        $procedures = DB::all('SELECT c.id, c.name, c.uses_ionizing_radiation, c.contrast_possible, c.service_id, m.name AS modality FROM radiology_procedure_catalog c
                                JOIN radiology_modalities m ON m.id = c.modality_id WHERE c.is_active = 1 AND c.deleted_at IS NULL ORDER BY m.display_order, c.display_order');
        foreach ($procedures as &$p) {
            $p['price'] = $p['service_id'] ? \App\Modules\Billing\BillingService::price((int) $p['service_id']) : 0;
        }
        unset($p);
        $this->view('radiology/reception', ['title' => 'Radiology Reception', 'pending' => $pending, 'rooms' => $rooms, 'slots' => $slots, 'date' => $date, 'procedures' => $procedures]);
    }

    /** Technician worklist (own modalities): start / finish acquisition. */
    public function technician(): void
    {
        if (is_post()) {
            try {
                if (input('do') === 'start') {
                    RadiologyService::start((int) input('order_id'), $_POST);
                    flash('success', 'Study started.');
                } else {
                    $path = null;
                    if (!empty($_FILES['image']['name'])) {
                        $up = Upload::document($_FILES['image'], 'radiology/' . date('Ym'));
                        if (!$up['ok']) {
                            throw new \RuntimeException($up['message']);
                        }
                        $path = $up['path'];
                    }
                    RadiologyService::finish((int) input('procedure_id'), $_POST, $path);
                    flash('success', 'Study completed — sent to the radiologist.');
                }
            } catch (\Throwable $e) {
                flash('error', $e->getMessage());
            }
            redirect('radiology/technician');
        }
        $mine = RadiologyService::myModalities();
        $where = "o.status IN ('ordered','scheduled','reprocess_requested','in_progress')";
        $params = [];
        if ($mine !== null) {
            $where .= ' AND o.modality_id IN (' . DB::placeholders($mine) . ')';
            $params = $mine;
        }
        $rows = DB::all(self::LIST_SQL . ' WHERE ' . $where . " ORDER BY o.status = 'in_progress' DESC, FIELD(o.priority,'stat','urgent') DESC, o.scheduled_at IS NULL, o.scheduled_at, o.ordered_at LIMIT 300", $params);
        foreach ($rows as &$o) {
            $o['issues'] = RadiologyService::safetyIssues($o);
            $o['procedure'] = $o['status'] === 'in_progress' ? DB::one("SELECT * FROM radiology_procedures WHERE radiology_order_id = ? AND status = 'in_progress' ORDER BY id DESC LIMIT 1", [$o['id']]) : null;
            $o['last_review'] = $o['status'] === 'reprocess_requested' ? DB::value('SELECT r.reason FROM radiology_procedure_reviews r JOIN radiology_procedures p ON p.id = r.radiology_procedure_id WHERE p.radiology_order_id = ? ORDER BY r.id DESC LIMIT 1', [$o['id']]) : null;
        }
        unset($o);
        $this->view('radiology/technician', ['title' => 'Imaging Worklist', 'rows' => $rows, 'scripts' => [],
            'equipment' => DB::all("SELECT id, name, modality_id, room_id FROM radiology_equipment WHERE status = 'active' AND deleted_at IS NULL ORDER BY name"),
            'protocols' => DB::all('SELECT id, name, modality_id FROM radiology_protocols WHERE is_active = 1 ORDER BY name'),
            'contrast' => DB::pairs("SELECT id, name FROM items WHERE sku LIKE 'RAD-OMNI%' OR (item_type = 'radiology_consumable' AND name LIKE '%contrast%') ORDER BY name")]);
    }

    /** Radiologist desk: quality gate and reporting queue. */
    public function radiologist(): void
    {
        $tab = input('tab') === 'report' ? 'report' : 'review';
        if (is_post()) {
            try {
                if (!can('radiology.review')) {
                    abort(403);
                }
                RadiologyService::review((int) input('procedure_id'), (string) input('decision'), (string) input('reason', ''));
                flash('success', ['accept' => 'Accepted — ready for reporting.', 'reprocess' => 'Sent back for a repeat acquisition (no new charge).', 'reject' => 'Rejected — the charge is refunded / voided.'][input('decision')] ?? 'Done.');
            } catch (\Throwable $e) {
                flash('error', $e->getMessage());
            }
            redirect('radiology/radiologist', ['tab' => $tab]);
        }
        $status = $tab === 'review' ? 'under_review' : 'accepted';
        $rows = DB::all(self::LIST_SQL . ' WHERE o.status = ? ORDER BY FIELD(o.priority,\'stat\',\'urgent\') DESC, o.ordered_at LIMIT 300', [$status]);
        foreach ($rows as &$o) {
            $o['procedure'] = DB::one('SELECT rp.*, u.name AS tech_name FROM radiology_procedures rp LEFT JOIN users u ON u.id = rp.performed_by WHERE rp.radiology_order_id = ? ORDER BY rp.id DESC LIMIT 1', [$o['id']]);
            $o['attempts'] = (int) DB::value('SELECT COUNT(*) FROM radiology_procedures WHERE radiology_order_id = ?', [$o['id']]);
            $o['draft'] = DB::value("SELECT 1 FROM radiology_reports WHERE radiology_order_id = ? AND status = 'draft'", [$o['id']]);
        }
        unset($o);
        $this->view('radiology/radiologist', ['title' => 'Radiologist Desk', 'tab' => $tab, 'rows' => $rows,
            'counts' => DB::pairs("SELECT status, COUNT(*) FROM radiology_orders WHERE status IN ('under_review','accepted') GROUP BY status")]);
    }

    /** Report editor (draft / finalize) and addenda. */
    public function report(): void
    {
        $id = $this->requireId();
        if (is_post()) {
            try {
                if (!can('radiology.report')) {
                    abort(403);
                }
                if (input('do') === 'addendum') {
                    RadiologyService::addendum((int) input('report_id'), (string) input('addendum_text'));
                    flash('success', 'Addendum added.');
                } else {
                    $final = input('do') === 'finalize';
                    RadiologyService::saveReport($id, $_POST, $final);
                    flash('success', $final ? 'Report finalized — it can no longer be edited.' : 'Draft saved.');
                    if ($final) {
                        redirect('radiology/radiologist', ['tab' => 'report']);
                    }
                }
            } catch (\Throwable $e) {
                flash('error', $e->getMessage());
            }
            redirect('radiology/report', ['id' => $id]);
        }
        $data = $this->orderData($id);
        $data['templates'] = DB::all('SELECT id, name, body_json FROM radiology_report_templates WHERE is_active = 1 AND (modality_id IS NULL OR modality_id = ?) ORDER BY name', [$data['o']['modality_id']]);
        $data['pregnancy'] = $data['o']['is_obstetric'] && $data['o']['pregnancy_id'] ? DB::one('SELECT * FROM pregnancies WHERE id = ?', [$data['o']['pregnancy_id']]) : null;
        $this->view('radiology/report', ['title' => 'Report — ' . $data['o']['procedure_name']] + $data);
    }

    /** Order detail (timeline) for any viewer. */
    public function order(): void
    {
        $id = $this->requireId();
        if (is_post() && input('do') === 'ack') {
            try {
                RadiologyService::acknowledgeCritical((int) input('report_id'));
                flash('success', 'Critical finding acknowledged.');
            } catch (\Throwable $e) {
                flash('error', $e->getMessage());
            }
            redirect('radiology/order', ['id' => $id]);
        }
        $data = $this->orderData($id);
        if (!can('radiology.view') && (int) $data['o']['ordered_by'] !== Auth::id()) {
            abort(403);
        }
        $this->view('radiology/order', ['title' => 'Imaging ' . $data['o']['order_number']] + $data);
    }

    private function orderData(int $id): array
    {
        try {
            $o = RadiologyService::find($id);
        } catch (\RuntimeException $e) {
            abort(404, $e->getMessage());
        }
        $procs = DB::all('SELECT rp.*, u.name AS tech_name, rv.decision, rv.reason AS review_reason, rv.reviewed_at, rvu.name AS reviewer, e.name AS equipment_name, pr.name AS protocol_name
                            FROM radiology_procedures rp LEFT JOIN users u ON u.id = rp.performed_by LEFT JOIN radiology_procedure_reviews rv ON rv.radiology_procedure_id = rp.id
                            LEFT JOIN users rvu ON rvu.id = rv.reviewed_by LEFT JOIN radiology_equipment e ON e.id = rp.equipment_id LEFT JOIN radiology_protocols pr ON pr.id = rp.protocol_id
                           WHERE rp.radiology_order_id = ? ORDER BY rp.id', [$id]);
        foreach ($procs as &$p) {
            $p['dose'] = DB::one('SELECT * FROM radiology_dose_log WHERE procedure_id = ?', [$p['id']]);
            $p['contrast'] = DB::all('SELECT c.*, i.name AS item_name, b.batch_no FROM radiology_contrast_usage c JOIN items i ON i.id = c.item_id LEFT JOIN stock_batches b ON b.id = c.stock_batch_id WHERE c.procedure_id = ?', [$p['id']]);
        }
        unset($p);
        $report = DB::one('SELECT r.*, u.name AS reported_by_name, n.name AS notified_name FROM radiology_reports r LEFT JOIN users u ON u.id = r.reported_by LEFT JOIN users n ON n.id = r.critical_notified_user_id WHERE r.radiology_order_id = ?', [$id]);
        return ['o' => $o, 'procs' => $procs, 'report' => $report,
            'addenda' => $report ? DB::all('SELECT a.*, u.name AS added_by_name FROM radiology_report_addenda a JOIN users u ON u.id = a.added_by WHERE a.radiology_report_id = ? ORDER BY a.added_at', [$report['id']]) : []];
    }

    /** Printable report (final / addended only). */
    public function print(): void
    {
        $data = $this->orderData($this->requireId());
        if (!$data['report'] || $data['report']['status'] === 'draft') {
            abort(404, 'The report is not finalized yet.');
        }
        if (!can('radiology.view') && (int) $data['o']['ordered_by'] !== Auth::id()) {
            abort(403);
        }
        DB::run('UPDATE radiology_reports SET print_count = print_count + 1 WHERE id = ?', [$data['report']['id']]);
        ActivityLog::record('Radiology report printed', 'radiology', (string) $data['o']['id'], $data['o']['order_number']);
        $this->view('radiology/print', $data, null);
    }

    /** Stored image of a procedure. */
    public function image(): void
    {
        $p = DB::one('SELECT image_path FROM radiology_procedures WHERE id = ?', [(int) input('id')]);
        if (!$p || !$p['image_path']) {
            abort(404);
        }
        Upload::send($p['image_path']);
    }

    /** All imaging orders, filters + export; doctors see their own. */
    public function orders(): void
    {
        $q = trim((string) input('q', ''));
        $status = (string) input('status', '');
        $from = (string) input('from', date('Y-m-d', strtotime('-7 days')));
        $to = (string) input('to', today());
        $where = ['o.ordered_at BETWEEN ? AND ?'];
        $params = [$from . ' 00:00:00', $to . ' 23:59:59'];
        if ($q !== '') {
            $where[] = '(p.name LIKE ? OR p.mrn LIKE ? OR o.order_number LIKE ?)';
            array_push($params, '%' . $q . '%', '%' . $q . '%', '%' . $q . '%');
        }
        if ($status !== '') {
            $where[] = 'o.status = ?';
            $params[] = $status;
        }
        if (!can('radiology.view')) {
            $where[] = 'o.ordered_by = ?';
            $params[] = Auth::id();
        }
        $rows = DB::all(self::LIST_SQL . ' WHERE ' . implode(' AND ', $where) . ' ORDER BY o.id DESC LIMIT 500', $params);
        if ($this->wantsExport()) {
            $this->export('imaging-orders', 'Imaging orders', ['order_number' => 'Order', 'ordered_at' => 'Date', 'patient_name' => 'Patient', 'mrn' => 'MRN',
                'procedure_name' => 'Procedure', 'doctor_name' => 'Ordered by', 'priority' => 'Priority', 'status' => 'Status'], $rows, ['From' => $from, 'To' => $to]);
            return;
        }
        $this->view('radiology/orders', ['title' => 'Imaging Orders', 'rows' => $rows, 'q' => $q, 'status' => $status, 'from' => $from, 'to' => $to]);
    }

    /** Rooms, equipment, procedures & prices, templates, technician modalities, dose register. */
    public function setup(): void
    {
        if (is_post()) {
            try {
                $this->saveSetup();
            } catch (\Throwable $e) {
                flash('error', $e->getMessage());
            }
            redirect('radiology/setup', ['tab' => input('tab') ?: null]);
        }
        $tab = (string) input('tab', 'procedures');
        $procs = DB::all('SELECT c.*, m.name AS modality, t.name AS template_name FROM radiology_procedure_catalog c JOIN radiology_modalities m ON m.id = c.modality_id
                           LEFT JOIN radiology_report_templates t ON t.id = c.default_report_template_id WHERE c.deleted_at IS NULL ORDER BY m.display_order, c.display_order');
        foreach ($procs as &$p) {
            $p['price'] = $p['service_id'] ? \App\Modules\Billing\BillingService::price((int) $p['service_id']) : 0;
        }
        unset($p);
        $this->view('radiology/setup', ['title' => 'Radiology Setup', 'tab' => $tab, 'procs' => $procs,
            'modalities' => DB::pairs('SELECT id, name FROM radiology_modalities WHERE deleted_at IS NULL ORDER BY display_order'),
            'rooms' => DB::all('SELECT r.*, m.name AS modality FROM radiology_rooms r JOIN radiology_modalities m ON m.id = r.modality_id WHERE r.deleted_at IS NULL ORDER BY r.display_order'),
            'equipment' => DB::all('SELECT e.*, r.name AS room, m.name AS modality FROM radiology_equipment e LEFT JOIN radiology_rooms r ON r.id = e.room_id JOIN radiology_modalities m ON m.id = e.modality_id WHERE e.deleted_at IS NULL ORDER BY e.name'),
            'templates' => DB::all('SELECT * FROM radiology_report_templates ORDER BY name'),
            'investigations' => DB::pairs('SELECT id, name FROM investigations WHERE deleted_at IS NULL ORDER BY name'),
            'techs' => DB::all("SELECT DISTINCT u.id, u.name, (SELECT GROUP_CONCAT(um.modality_id) FROM user_radiology_modalities um WHERE um.user_id = u.id) AS mods
                                  FROM users u JOIN user_role_branch urb ON urb.user_id = u.id JOIN roles r ON r.id = urb.role_id
                                 WHERE r.code IN ('RT','SON','RIC') AND u.deleted_at IS NULL ORDER BY u.name"),
            'dose' => $tab === 'dose' ? DB::all('SELECT d.*, o.order_number, p.name AS patient_name, c.name AS procedure_name, e.name AS equipment_name FROM radiology_dose_log d
                                                  JOIN radiology_procedures rp ON rp.id = d.procedure_id JOIN radiology_orders o ON o.id = rp.radiology_order_id JOIN patients p ON p.id = o.patient_id
                                                  JOIN radiology_procedure_catalog c ON c.id = o.procedure_id LEFT JOIN radiology_equipment e ON e.id = d.equipment_id ORDER BY d.recorded_at DESC LIMIT 300') : []]);
    }

    private function saveSetup(): void
    {
        $do = (string) input('do');
        $id = (int) input('id', 0);
        $now = now();
        switch ($do) {
            case 'procedure':
                $name = trim((string) input('name'));
                $mod = (int) input('modality_id');
                if ($name === '' || !$mod) {
                    throw new \InvalidArgumentException('Name and modality are required.');
                }
                $data = ['code' => strtoupper(trim((string) input('code', ''))) ?: null, 'name' => $name, 'modality_id' => $mod, 'body_part' => trim((string) input('body_part', '')) ?: null,
                    'investigation_id' => (int) input('investigation_id') ?: null, 'default_report_template_id' => (int) input('template_id') ?: null,
                    'prep_instructions' => trim((string) input('prep_instructions', '')) ?: null, 'requires_prep' => trim((string) input('prep_instructions', '')) !== '' ? 1 : 0,
                    'est_duration_min' => max(5, (int) input('est_duration_min', 15)), 'contrast_possible' => input('contrast_possible') ? 1 : 0, 'is_obstetric' => input('is_obstetric') ? 1 : 0,
                    'uses_ionizing_radiation' => (int) DB::value('SELECT is_ionizing FROM radiology_modalities WHERE id = ?', [$mod]), 'is_active' => input('is_active') ? 1 : 0];
                if ($id) {
                    DB::update('radiology_procedure_catalog', $data, 'id = ?', [$id]);
                    $sid = (int) DB::value('SELECT service_id FROM radiology_procedure_catalog WHERE id = ?', [$id]);
                } else {
                    $id = DB::insert('radiology_procedure_catalog', $data + ['created_at' => $now]);
                    $sid = 0;
                }
                if (!$sid) {
                    $sid = DB::insert('service_catalog', ['code' => 'RAD-' . ($data['code'] ?: $id), 'name' => $name, 'service_type' => 'radiology', 'default_price' => (float) input('price', 0), 'is_active' => 1, 'created_at' => $now]);
                    DB::update('radiology_procedure_catalog', ['service_id' => $sid], 'id = ?', [$id]);
                }
                if (abs(\App\Modules\Billing\BillingService::price($sid) - (float) input('price', 0)) > 0.004) {
                    \App\Modules\Billing\BillingService::setPrice($sid, (float) input('price', 0), null);
                }
                break;
            case 'room':
                $data = ['name' => trim((string) input('name')), 'modality_id' => (int) input('modality_id'), 'slot_minutes' => max(5, (int) input('slot_minutes', 15)),
                    'opens_at' => (string) input('opens_at', '09:00'), 'closes_at' => (string) input('closes_at', '17:00'), 'is_active' => input('is_active') ? 1 : 0, 'branch_id' => Auth::branchId()];
                if ($data['name'] === '' || !$data['modality_id']) {
                    throw new \InvalidArgumentException('Room name and modality are required.');
                }
                $id ? DB::update('radiology_rooms', $data, 'id = ?', [$id]) : DB::insert('radiology_rooms', $data + ['created_at' => $now]);
                break;
            case 'equipment':
                $data = ['name' => trim((string) input('name')), 'modality_id' => (int) input('modality_id'), 'room_id' => (int) input('room_id') ?: null,
                    'manufacturer' => trim((string) input('manufacturer', '')) ?: null, 'serial_no' => trim((string) input('serial_no', '')) ?: null,
                    'last_qa_at' => input('last_qa_at') ?: null, 'status' => in_array(input('status'), ['active', 'maintenance', 'out_of_service'], true) ? input('status') : 'active'];
                if ($data['name'] === '' || !$data['modality_id']) {
                    throw new \InvalidArgumentException('Equipment name and modality are required.');
                }
                $id ? DB::update('radiology_equipment', $data, 'id = ?', [$id]) : DB::insert('radiology_equipment', $data + ['created_at' => $now]);
                break;
            case 'template':
                $data = ['name' => trim((string) input('name')), 'modality_id' => (int) input('modality_id') ?: null, 'is_active' => 1,
                    'body_json' => json_encode(['technique' => (string) input('technique', ''), 'findings' => (string) input('findings', ''), 'impression' => (string) input('impression', '')], JSON_UNESCAPED_UNICODE)];
                if ($data['name'] === '') {
                    throw new \InvalidArgumentException('Template name is required.');
                }
                $id ? DB::update('radiology_report_templates', $data, 'id = ?', [$id]) : DB::insert('radiology_report_templates', $data + ['created_at' => $now]);
                break;
            case 'tech':
                DB::run('DELETE FROM user_radiology_modalities WHERE user_id = ?', [(int) input('user_id')]);
                foreach ((array) ($_POST['modalities'] ?? []) as $m) {
                    DB::insert('user_radiology_modalities', ['user_id' => (int) input('user_id'), 'modality_id' => (int) $m]);
                }
                break;
            default:
                throw new \InvalidArgumentException('Unknown action.');
        }
        ActivityLog::record('Radiology setup changed', 'radiology', (string) $id, $do);
        flash('success', 'Saved.');
    }
}
