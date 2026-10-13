<?php
declare(strict_types=1);

namespace App\Modules\Dental;

use App\Core\ActivityLog;
use App\Core\Auth;
use App\Core\Controller;
use App\Core\DB;
use App\Core\Upload;
use App\Modules\Inventory\StockService;
use App\Modules\Specialty\SpecialtyContext;

final class DentalController extends Controller
{
    /** Dental clinic: today's dental encounters, or one patient's dental record. */
    public function index(): void
    {
        $pid = (int) input('patient_id', 0);
        if ($pid) {
            $this->record($pid);
            return;
        }
        $depts = SpecialtyContext::departmentsWith('dental');
        $today = $depts ? DB::all('SELECT v.id, v.visit_no, v.visit_date, p.id AS patient_id, p.name, p.mrn, p.gender, p.dob, u.name AS doctor,
                                          (SELECT COUNT(*) FROM dental_tooth_findings f JOIN dental_charts c ON c.id = f.dental_chart_id WHERE c.encounter_id = v.id) AS findings
                                     FROM visits v JOIN patients p ON p.id = v.patient_id LEFT JOIN users u ON u.id = v.doctor_id
                                    WHERE v.department_id IN (' . DB::placeholders($depts) . ') AND v.visit_date >= ? ORDER BY v.visit_date DESC LIMIT 100',
            array_merge($depts, [today() . ' 00:00:00'])) : [];
        $waiting = $depts && \App\Core\Migrator::tableExists('tokens') ? DB::all("SELECT t.id, t.token_label, t.priority, t.status, p.name, p.mrn FROM tokens t JOIN token_queues q ON q.id = t.queue_id
                                       JOIN patients p ON p.id = t.patient_id WHERE q.department_id IN (" . DB::placeholders($depts) . ") AND t.issue_date = ? AND t.status IN ('waiting','called','serving')
                                       ORDER BY t.issued_at", array_merge($depts, [today()])) : [];
        $this->view('dental/index', ['title' => 'Dental Clinic', 'today' => $today, 'waiting' => $waiting, 'hasDept' => (bool) $depts]);
    }

    private function record(int $pid): void
    {
        $patient = DB::one('SELECT id, name, mrn, gender, dob, phone, allergies FROM patients WHERE id = ? AND deleted_at IS NULL', [$pid]);
        if (!$patient) {
            abort(404);
        }
        $charts = DB::all('SELECT c.*, v.visit_no, u.name AS by_name FROM dental_charts c LEFT JOIN visits v ON v.id = c.encounter_id LEFT JOIN users u ON u.id = c.created_by
                            WHERE c.patient_id = ? ORDER BY c.chart_date DESC, c.id DESC', [$pid]);
        foreach ($charts as &$c) {
            $c['findings'] = DentalService::chartFindings((int) $c['id']);
        }
        unset($c);
        $compare = (int) input('compare', 0);
        $this->view('dental/record', [
            'title' => 'Dental record — ' . $patient['name'], 'patient' => $patient, 'charts' => $charts,
            'state' => DentalService::toothState($pid), 'teeth' => DentalService::teeth(), 'conditions' => DentalService::CONDITIONS,
            'compareState' => $compare ? $this->stateAt($pid, $compare) : null, 'compare' => $compare,
            'plans' => $this->plans($pid), 'procedures' => DentalService::procedures(),
            'perio' => DB::all('SELECT c.*, u.name AS by_name, (SELECT COUNT(*) FROM dental_perio_measurements m WHERE m.perio_chart_id = c.id AND m.pocket_depth_mm >= 4) AS deep,
                                       (SELECT COUNT(*) FROM dental_perio_measurements m WHERE m.perio_chart_id = c.id AND m.bleeding_on_probing = 1) AS bop
                                  FROM dental_perio_charts c LEFT JOIN users u ON u.id = c.charted_by WHERE c.patient_id = ? ORDER BY c.charted_at DESC', [$pid]),
            'images' => DB::all('SELECT i.*, u.name AS by_name FROM dental_chart_images i LEFT JOIN users u ON u.id = i.uploaded_by WHERE i.patient_id = ? ORDER BY i.uploaded_at DESC', [$pid]),
            'cases' => DB::all('SELECT c.*, u.name AS tech_name FROM dental_lab_cases c LEFT JOIN users u ON u.id = c.technician_user_id WHERE c.patient_id = ? ORDER BY c.id DESC', [$pid]),
        ]);
    }

    /** Tooth state as it was at a given chart (for the progression overlay). */
    private function stateAt(int $pid, int $chartId): array
    {
        $date = DB::value('SELECT chart_date FROM dental_charts WHERE id = ? AND patient_id = ?', [$chartId, $pid]);
        $out = [];
        foreach (DB::all('SELECT f.tooth_number, f.`condition`, f.procedure_status FROM dental_tooth_findings f JOIN dental_charts c ON c.id = f.dental_chart_id
                           WHERE c.patient_id = ? AND (c.chart_date < ? OR c.id = ?) ORDER BY c.chart_date, f.id', [$pid, $date, $chartId]) as $r) {
            $out[$r['tooth_number']] = $r;
        }
        return $out;
    }

    private function plans(int $pid): array
    {
        $plans = DB::all('SELECT pl.*, u.name AS dentist FROM dental_treatment_plans pl LEFT JOIN users u ON u.id = pl.dentist_id WHERE pl.patient_id = ? ORDER BY pl.id DESC', [$pid]);
        foreach ($plans as &$p) {
            $p['items'] = DB::all('SELECT i.*, c.name AS procedure_name, c.needs_lab_case, c.hygienist_allowed, u.name AS performed_by_name, v.visit_no
                                     FROM dental_treatment_plan_items i JOIN dental_procedure_catalog c ON c.id = i.procedure_id LEFT JOIN users u ON u.id = i.performed_by
                                     LEFT JOIN visits v ON v.id = i.performed_encounter_id WHERE i.plan_id = ? ORDER BY i.sequence_no', [$p['id']]);
        }
        return $plans;
    }

    /** JSON: current tooth state for the consult map. */
    public function state(): void
    {
        $pid = (int) input('patient_id');
        $state = DentalService::toothState($pid);
        $this->json(['ok' => true, 'state' => $state, 'last_date' => $state ? fmt_date(max(array_column($state, 'chart_date'))) : null,
            'open_items' => (int) DB::value("SELECT COUNT(*) FROM dental_treatment_plan_items i JOIN dental_treatment_plans p ON p.id = i.plan_id
                                              WHERE p.patient_id = ? AND i.status IN ('planned','scheduled') AND p.status IN ('proposed','accepted','in_progress')", [$pid])]);
    }

    /** Open treatment plans + actions (accept, complete step, skip, add step, cancel). */
    public function planList(): void
    {
        if (is_post()) {
            $pid = (int) input('patient_id');
            try {
                $this->planAction();
            } catch (\Throwable $e) {
                flash('error', $e->getMessage());
            }
            input('back') === 'record' ? redirect('dental', ['patient_id' => $pid]) : redirect('dental/plans');
        }
        $status = (string) input('status', 'open');
        $where = $status === 'open' ? "pl.status IN ('proposed','accepted','in_progress')" : 'pl.status = ?';
        $rows = DB::all('SELECT pl.*, p.name AS patient_name, p.mrn, u.name AS dentist,
                                (SELECT COUNT(*) FROM dental_treatment_plan_items i WHERE i.plan_id = pl.id AND i.status = \'done\') AS done,
                                (SELECT COUNT(*) FROM dental_treatment_plan_items i WHERE i.plan_id = pl.id AND i.status <> \'skipped\') AS steps
                           FROM dental_treatment_plans pl JOIN patients p ON p.id = pl.patient_id LEFT JOIN users u ON u.id = pl.dentist_id
                          WHERE ' . $where . ' ORDER BY pl.id DESC LIMIT 300', $status === 'open' ? [] : [$status]);
        $this->view('dental/plans', ['title' => 'Treatment Plans', 'rows' => $rows, 'status' => $status]);
    }

    private function planAction(): void
    {
        $do = (string) input('do');
        $planId = (int) input('plan_id');
        $pid = (int) DB::value('SELECT patient_id FROM dental_treatment_plans WHERE id = ?', [$planId]) ?: (int) input('patient_id');
        switch ($do) {
            case 'accept':
                $this->need('dental.plan.create');
                DentalService::acceptPlan($planId, (string) input('accepted_by_name'));
                flash('success', 'Plan accepted.');
                break;
            case 'complete':
                // Performed today: attach to today's dental visit of the patient when there is one.
                $visit = DB::one('SELECT id, department_id, token_id FROM visits WHERE patient_id = ? AND visit_date >= ? ORDER BY visit_date DESC LIMIT 1', [$pid, today() . ' 00:00:00']);
                DentalService::completeItem((int) input('item_id'), $visit ? (int) $visit['id'] : null,
                    ['department_id' => $visit['department_id'] ?? null, 'token_id' => $visit['token_id'] ?? null, 'doctor_id' => Auth::id()]);
                flash('success', 'Step completed and billed' . ($visit ? ' on today\'s visit.' : ' (new invoice — collect at the billing counter).'));
                break;
            case 'skip':
                $this->need('dental.plan.create');
                DentalService::skipItem((int) input('item_id'), (string) input('reason', 'Not required'));
                break;
            case 'add':
                $this->need('dental.plan.create');
                $tooth = trim((string) input('tooth_number', ''));
                if ($tooth !== '' && !isset(DentalService::teeth()[$tooth])) {
                    throw new \InvalidArgumentException('Invalid FDI tooth number.');
                }
                DentalService::addPlanItem($pid, null, (int) input('procedure_id'), $tooth ?: null);
                flash('success', 'Step added.');
                break;
            case 'cancel':
                $this->need('dental.plan.create');
                DentalService::cancelPlan($planId, (string) input('reason', 'Cancelled'));
                break;
            default:
                throw new \InvalidArgumentException('Unknown action.');
        }
    }

    private function need(string $perm): void
    {
        if (!can($perm)) {
            abort(403);
        }
    }

    /** Printable treatment plan / estimate for the patient. */
    public function planPrint(): void
    {
        $id = $this->requireId();
        $plan = DB::one('SELECT pl.*, p.name AS patient_name, p.mrn, p.gender, p.dob, u.name AS dentist FROM dental_treatment_plans pl JOIN patients p ON p.id = pl.patient_id
                           LEFT JOIN users u ON u.id = pl.dentist_id WHERE pl.id = ?', [$id]);
        if (!$plan) {
            abort(404);
        }
        $plan['items'] = DB::all('SELECT i.*, c.name AS procedure_name FROM dental_treatment_plan_items i JOIN dental_procedure_catalog c ON c.id = i.procedure_id
                                   WHERE i.plan_id = ? AND i.status <> \'skipped\' ORDER BY i.sequence_no', [$id]);
        $this->view('dental/plan_print', ['plan' => $plan], null);
    }

    /** Chairside: today's dental patients — consumables, hygiene steps, perio chart. */
    public function chairside(): void
    {
        if (is_post()) {
            $pid = (int) input('patient_id');
            try {
                if (input('do') === 'consumable') {
                    $this->need('dental.consumables');
                    $loc = StockService::locationOfType('dental_stock');
                    if (!$loc) {
                        throw new \RuntimeException('No dental stock location is configured.');
                    }
                    $n = 0;
                    foreach ((array) ($_POST['lines'] ?? []) as $l) {
                        $qty = (float) ($l['qty'] ?? 0);
                        if ((int) ($l['item_id'] ?? 0) && $qty > 0) {
                            StockService::takeFefo((int) $l['item_id'], $loc, $qty, 'consumption', ['reference_type' => 'dental_chairside', 'reference_id' => (int) input('visit_id', 0) ?: null,
                                'patient_id' => $pid ?: null, 'visit_id' => (int) input('visit_id', 0) ?: null, 'note' => 'Chairside']);
                            $n++;
                        }
                    }
                    ActivityLog::record('Dental consumables used', 'dental', (string) $pid, $n . ' line(s)');
                    flash('success', $n . ' consumable line(s) deducted from dental stock.');
                } elseif (input('do') === 'perio') {
                    $this->need('dental.perio.edit');
                    DentalService::savePerio($pid, (int) input('visit_id', 0) ?: null, (array) ($_POST['m'] ?? []), (string) input('notes', ''));
                    flash('success', 'Perio chart saved.');
                }
            } catch (\Throwable $e) {
                flash('error', $e->getMessage());
            }
            redirect('dental/chairside', ['patient_id' => $pid ?: null]);
        }
        $depts = SpecialtyContext::departmentsWith('dental');
        $today = $depts ? DB::all('SELECT v.id, v.visit_no, v.visit_date, p.id AS patient_id, p.name, p.mrn, u.name AS doctor FROM visits v JOIN patients p ON p.id = v.patient_id
                                     LEFT JOIN users u ON u.id = v.doctor_id WHERE v.department_id IN (' . DB::placeholders($depts) . ') AND v.visit_date >= ? ORDER BY v.visit_date DESC',
            array_merge($depts, [today() . ' 00:00:00'])) : [];
        $pid = (int) input('patient_id', 0);
        $patient = $pid ? DB::one('SELECT id, name, mrn, dob FROM patients WHERE id = ?', [$pid]) : null;
        $this->view('dental/chairside', ['title' => 'Dental Chairside', 'today' => $today, 'patient' => $patient,
            'visitId' => $pid ? (int) DB::value('SELECT id FROM visits WHERE patient_id = ? AND visit_date >= ? ORDER BY id DESC LIMIT 1', [$pid, today() . ' 00:00:00']) : 0,
            'plans' => $pid ? $this->plans($pid) : [], 'teeth' => DentalService::teeth(), 'scripts' => ['js/stock.js']]);
    }

    /** Dental lab cases (crowns, bridges, dentures …). */
    public function labCases(): void
    {
        if (is_post()) {
            try {
                if (input('do') === 'create') {
                    $this->need('dental.labcase.manage');
                    DentalService::createLabCase($_POST);
                    flash('success', 'Lab case created.');
                } else {
                    if (!can('dental.labcase.update') && !can('dental.labcase.manage')) {
                        abort(403);
                    }
                    DentalService::updateLabCase((int) input('id'), (string) input('status'), (string) input('note', ''));
                    flash('success', 'Case updated.');
                }
            } catch (\Throwable $e) {
                flash('error', $e->getMessage());
            }
            input('back') === 'record' ? redirect('dental', ['patient_id' => (int) input('patient_id')]) : redirect('dental/lab-cases');
        }
        $own = Auth::scope('dental.labcase.view') === 'own';
        $status = (string) input('status', 'open');
        $where = $status === 'open' ? "c.status IN ('created','sent','in_progress','received')" : '1 = 1';
        $params = [];
        if ($own) {
            $where .= ' AND (c.technician_user_id = ? OR c.technician_user_id IS NULL)';
            $params[] = Auth::id();
        }
        $rows = DB::all('SELECT c.*, p.name AS patient_name, p.mrn, u.name AS tech_name, v.name AS vendor_name, cb.name AS created_by_name
                           FROM dental_lab_cases c JOIN patients p ON p.id = c.patient_id LEFT JOIN users u ON u.id = c.technician_user_id
                           LEFT JOIN vendors v ON v.id = c.external_vendor_id LEFT JOIN users cb ON cb.id = c.created_by
                          WHERE ' . $where . ' ORDER BY c.due_date IS NULL, c.due_date, c.id DESC LIMIT 300', $params);
        $this->view('dental/lab_cases', ['title' => 'Dental Lab Cases', 'rows' => $rows, 'status' => $status, 'own' => $own,
            'technicians' => DB::pairs("SELECT DISTINCT u.id, u.name FROM users u JOIN user_role_branch urb ON urb.user_id = u.id JOIN roles r ON r.id = urb.role_id
                                         WHERE r.code = 'DTC' AND u.deleted_at IS NULL ORDER BY u.name"),
            'vendors' => DB::pairs('SELECT id, name FROM vendors WHERE deleted_at IS NULL AND is_active = 1 ORDER BY name')]);
    }

    /** Upload an intra-oral photo / dental X-ray to the patient's dental record. */
    public function image(): void
    {
        $pid = (int) input('patient_id');
        if (is_post()) {
            $this->need('dental.chart.edit');
            $up = Upload::document($_FILES['file'] ?? null, 'dental/' . $pid);
            if (!$up['ok']) {
                flash('error', $up['message']);
            } else {
                DB::insert('dental_chart_images', ['dental_chart_id' => DB::value('SELECT id FROM dental_charts WHERE patient_id = ? ORDER BY id DESC LIMIT 1', [$pid]) ?: null,
                    'patient_id' => $pid, 'image_type' => in_array(input('image_type'), ['periapical', 'bitewing', 'opg', 'cbct', 'intraoral_photo'], true) ? input('image_type') : 'intraoral_photo',
                    'tooth_number' => trim((string) input('tooth_number', '')) ?: null, 'file_path' => $up['path'], 'caption' => mb_substr((string) input('caption', ''), 0, 255) ?: null,
                    'uploaded_by' => Auth::id(), 'uploaded_at' => now()]);
                flash('success', 'Image added.');
            }
            redirect('dental', ['patient_id' => $pid]);
        }
        $img = DB::one('SELECT * FROM dental_chart_images WHERE id = ?', [(int) input('id')]);
        if (!$img || !$img['file_path']) {
            abort(404);
        }
        Upload::send($img['file_path']);
    }
}
