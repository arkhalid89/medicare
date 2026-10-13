<?php
declare(strict_types=1);

namespace App\Modules\Obgyn;

use App\Core\Controller;
use App\Core\DB;
use App\Modules\Specialty\SpecialtyContext;

final class ObgynController extends Controller
{
    /** Clinic list, or one patient's obstetric & gynecologic record. */
    public function index(): void
    {
        $pid = (int) input('patient_id', 0);
        if ($pid) {
            $this->record($pid);
            return;
        }
        $depts = SpecialtyContext::departmentsWith('obgyn');
        $today = $depts ? DB::all('SELECT v.id, v.visit_no, v.visit_date, p.id AS patient_id, p.name, p.mrn, p.dob, u.name AS doctor,
                                          (SELECT pr.risk_level FROM pregnancies pr WHERE pr.patient_id = p.id AND pr.status = \'ongoing\' LIMIT 1) AS risk
                                     FROM visits v JOIN patients p ON p.id = v.patient_id LEFT JOIN users u ON u.id = v.doctor_id
                                    WHERE v.department_id IN (' . DB::placeholders($depts) . ') AND v.visit_date >= ? ORDER BY v.visit_date DESC LIMIT 100',
            array_merge($depts, [today() . ' 00:00:00'])) : [];
        $this->view('obgyn/index', ['title' => 'Gynae / Obs Clinic', 'today' => $today, 'hasDept' => (bool) $depts, 'stats' => [
            'ongoing' => (int) DB::value("SELECT COUNT(*) FROM pregnancies WHERE status = 'ongoing'"),
            'high' => (int) DB::value("SELECT COUNT(*) FROM pregnancies WHERE status = 'ongoing' AND risk_level = 'high'"),
            'due' => (int) DB::value("SELECT COUNT(*) FROM pregnancies WHERE status = 'ongoing' AND edd_date BETWEEN ? AND ?", [today(), date('Y-m-d', strtotime('+30 days'))]),
            'overdue' => (int) DB::value("SELECT COUNT(*) FROM antenatal_visits a JOIN pregnancies p ON p.id = a.pregnancy_id WHERE p.status = 'ongoing' AND a.next_visit_date < ?
                                           AND a.id = (SELECT MAX(x.id) FROM antenatal_visits x WHERE x.pregnancy_id = p.id)", [today()]),
        ]]);
    }

    private function record(int $pid): void
    {
        $patient = DB::one('SELECT id, name, mrn, gender, dob, phone, allergies, blood_group FROM patients WHERE id = ? AND deleted_at IS NULL', [$pid]);
        if (!$patient) {
            abort(404);
        }
        ObgynService::recalcObstetric($pid);
        $pregnancies = DB::all('SELECT p.*, u.name AS created_by_name FROM pregnancies p LEFT JOIN users u ON u.id = p.created_by WHERE p.patient_id = ? ORDER BY p.pregnancy_no DESC', [$pid]);
        foreach ($pregnancies as &$p) {
            $p['deliveries'] = DB::all('SELECT d.*, b.name AS baby_name, b.mrn AS baby_mrn, u.name AS attended_name, c.name AS cosigned_name FROM pregnancy_deliveries d
                                         LEFT JOIN patients b ON b.id = d.baby_patient_id LEFT JOIN users u ON u.id = d.attended_by LEFT JOIN users c ON c.id = d.cosigned_by
                                        WHERE d.pregnancy_id = ? ORDER BY d.id', [$p['id']]);
            $p['anc'] = DB::all('SELECT a.*, u.name AS by_name, v.visit_no FROM antenatal_visits a LEFT JOIN users u ON u.id = a.recorded_by LEFT JOIN visits v ON v.id = a.encounter_id
                                  WHERE a.pregnancy_id = ? ORDER BY a.visit_date, a.id', [$p['id']]);
            $p['pnc'] = DB::all('SELECT * FROM postnatal_visits WHERE pregnancy_id = ? ORDER BY visit_date', [$p['id']]);
            $p['us'] = DB::all('SELECT * FROM obstetric_ultrasound_findings WHERE pregnancy_id = ? ORDER BY scan_date', [$p['id']]);
            $p['labs'] = \App\Core\Migrator::tableExists('lab_orders') ? DB::all('SELECT o.id, o.order_number, o.ordered_at, o.status FROM lab_orders o WHERE o.pregnancy_id = ? ORDER BY o.ordered_at', [$p['id']]) : [];
            $p['reasons'] = json_decode((string) $p['risk_reasons_json'], true) ?: [];
        }
        unset($p);
        $this->view('obgyn/record', ['title' => 'Obstetric record — ' . $patient['name'], 'patient' => $patient, 'gyn' => ObgynService::gynHistory($pid),
            'oh' => DB::one('SELECT * FROM obstetric_history WHERE patient_id = ?', [$pid]), 'pregnancies' => $pregnancies,
            'children' => DB::all("SELECT id, name, mrn, dob, gender FROM patients WHERE mother_patient_id = ? AND deleted_at IS NULL ORDER BY dob", [$pid])]);
    }

    /** JSON summary for the consultation panel. */
    public function summary(): void
    {
        $pid = (int) input('patient_id');
        ObgynService::recalcObstetric($pid);
        $o = ObgynService::ongoing($pid);
        if ($o) {
            $ga = ObgynService::ga($o['lmp_date']);
            $last = DB::one('SELECT * FROM antenatal_visits WHERE pregnancy_id = ? ORDER BY visit_date DESC, id DESC LIMIT 1', [$o['id']]);
            $o = ['id' => (int) $o['id'], 'pregnancy_no' => (int) $o['pregnancy_no'], 'lmp_label' => fmt_date($o['lmp_date']), 'edd_label' => fmt_date($o['edd_date']),
                'ga_label' => $ga ? $ga[0] . 'w ' . $ga[1] . 'd' : null, 'ga_dec' => $ga[2] ?? null, 'risk_level' => $o['risk_level'],
                'risk_reasons' => json_decode((string) $o['risk_reasons_json'], true) ?: [],
                'anc_count' => (int) DB::value('SELECT COUNT(*) FROM antenatal_visits WHERE pregnancy_id = ?', [$o['id']]),
                'last_anc' => $last ? ['date' => fmt_date($last['visit_date']), 'bp' => $last['bp_systolic'] . '/' . $last['bp_diastolic'], 'weight' => $last['weight_kg'], 'fh' => $last['fundal_height_cm']] : null];
        }
        $this->json(['ok' => true, 'patient_id' => $pid, 'gyn' => ObgynService::gynHistory($pid), 'gpal' => ObgynService::gpal($pid), 'ongoing' => $o,
            'pregnancies' => (int) DB::value('SELECT COUNT(*) FROM pregnancies WHERE patient_id = ?', [$pid])]);
    }

    /** ANC / labour room: ongoing pregnancies, ANC without a doctor visit, deliveries, PNC, scans. */
    public function anc(): void
    {
        $filter = (string) input('filter', 'term');
        $where = match ($filter) {
            'high' => "p.status = 'ongoing' AND p.risk_level = 'high'",
            'all' => "p.status = 'ongoing'",
            'delivered' => "p.status = 'delivered' AND p.outcome_date >= " . DB::pdo()->quote(date('Y-m-d', strtotime('-42 days'))),
            default => "p.status = 'ongoing' AND p.edd_date <= " . DB::pdo()->quote(date('Y-m-d', strtotime('+28 days'))),
        };
        $rows = DB::all('SELECT p.*, pt.name, pt.mrn, pt.dob, (SELECT MAX(a.visit_date) FROM antenatal_visits a WHERE a.pregnancy_id = p.id) AS last_anc,
                                (SELECT COUNT(*) FROM pregnancy_deliveries d WHERE d.pregnancy_id = p.id AND d.is_draft = 1) AS drafts
                           FROM pregnancies p JOIN patients pt ON pt.id = p.patient_id WHERE ' . $where . ' ORDER BY p.edd_date LIMIT 300');
        foreach ($rows as &$r) {
            $ga = ObgynService::ga($r['lmp_date']);
            $r['ga'] = $ga ? $ga[0] . 'w ' . $ga[1] . 'd' : '—';
        }
        unset($r);
        $this->view('obgyn/anc', ['title' => 'ANC / Labour Room', 'rows' => $rows, 'filter' => $filter]);
    }

    /** Pregnancy register (all, filterable) with export. */
    public function pregnancies(): void
    {
        $status = (string) input('status', 'ongoing');
        $risk = (string) input('risk', '');
        $where = ['1 = 1'];
        $params = [];
        if ($status !== '') {
            $where[] = 'p.status = ?';
            $params[] = $status;
        }
        if ($risk !== '') {
            $where[] = 'p.risk_level = ?';
            $params[] = $risk;
        }
        $rows = DB::all('SELECT p.*, pt.name, pt.mrn, pt.phone, oh.gravida, oh.para, oh.abortions, oh.living_children
                           FROM pregnancies p JOIN patients pt ON pt.id = p.patient_id LEFT JOIN obstetric_history oh ON oh.patient_id = p.patient_id
                          WHERE p.recorded_historical = 0 AND ' . implode(' AND ', $where) . ' ORDER BY p.edd_date DESC LIMIT 1000', $params);
        if ($this->wantsExport()) {
            $this->export('pregnancies', 'Pregnancy register', ['name' => 'Patient', 'mrn' => 'MRN', 'phone' => 'Phone', 'pregnancy_no' => 'No.', 'lmp_date' => 'LMP',
                'edd_date' => 'EDD', 'status' => 'Status', 'risk_level' => 'Risk', 'gravida' => 'G', 'para' => 'P'], $rows, ['Status' => $status ?: 'all']);
            return;
        }
        $this->view('obgyn/pregnancies', ['title' => 'Pregnancy Register', 'rows' => $rows, 'status' => $status, 'risk' => $risk]);
    }

    /** All record-page actions. */
    public function action(): void
    {
        $pid = (int) input('patient_id');
        $pregId = (int) input('pregnancy_id', 0);
        if ($pregId) {
            $pid = (int) DB::value('SELECT patient_id FROM pregnancies WHERE id = ?', [$pregId]) ?: $pid;
        }
        $need = static function (string $perm): void {
            if (!can($perm)) {
                abort(403);
            }
        };
        try {
            switch ((string) input('do')) {
                case 'gyn':
                    $need('obgyn.history.edit');
                    ObgynService::saveGynHistory($pid, $_POST);
                    flash('success', 'Gynae history saved.');
                    break;
                case 'open':
                    ObgynService::openPregnancy($pid, $_POST);
                    flash('success', 'Pregnancy record opened.');
                    break;
                case 'past':
                    $need('obgyn.history.edit');
                    ObgynService::addPastPregnancy($pid, $_POST);
                    flash('success', 'Past pregnancy added — G/P/A/L recalculated.');
                    break;
                case 'close':
                    $need('obgyn.pregnancy.manage');
                    ObgynService::closePregnancy($pregId, (string) input('status'), (string) input('outcome_date', '') ?: null, (string) input('notes', ''));
                    flash('success', 'Pregnancy closed.');
                    break;
                case 'anc':
                    $need('obgyn.anc.record');
                    $r = ObgynService::saveAnc($pregId, (int) input('visit_id', 0) ?: null, $_POST);
                    flash($r['flags'] ? 'warning' : 'success', 'ANC visit saved.' . ($r['flags'] ? ' Risk: ' . implode('; ', $r['flags']) . ' — inform the gynecologist.' : ''));
                    break;
                case 'delivery':
                    $need('obgyn.delivery.record');
                    $r = ObgynService::recordDelivery($pregId, $_POST, (bool) input('register_newborn'));
                    flash('success', 'Delivery recorded' . ($r['baby'] ? '; newborn registered as ' . $r['baby']['mrn'] : '') . ($r['draft'] ? ' — draft until the gynecologist confirms.' : '.'));
                    break;
                case 'confirm':
                    $need('obgyn.pregnancy.manage');
                    ObgynService::confirmDelivery((int) input('delivery_id'));
                    flash('success', 'Delivery confirmed.');
                    break;
                case 'pnc':
                    $need('obgyn.anc.record');
                    ObgynService::savePnc($pregId, (int) input('visit_id', 0) ?: null, $_POST);
                    flash('success', 'Postnatal visit saved.');
                    break;
                case 'us':
                    $need('obgyn.us.biometry');
                    ObgynService::saveUltrasound($pregId, $_POST, (int) input('radiology_order_id', 0) ?: null);
                    flash('success', 'Scan findings saved.');
                    break;
                default:
                    throw new \InvalidArgumentException('Unknown action.');
            }
        } catch (\Throwable $e) {
            flash('error', $e->getMessage());
        }
        input('back') === 'anc' ? redirect('obgyn/anc') : redirect('obgyn', ['patient_id' => $pid]);
    }
}
