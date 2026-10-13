<?php
declare(strict_types=1);

namespace App\Modules\Specialty;

use App\Core\ActivityLog;
use App\Core\Auth;
use App\Core\Controller;
use App\Core\DB;
use App\Core\Scope;

final class SpecialtyController extends Controller
{
    /** Specialty exams: per-patient history and recording against a recent visit (technicians: ECG, audiometry, optometry). */
    public function index(): void
    {
        $pid = (int) input('patient_id', 0);
        $patient = $pid ? DB::one('SELECT id, name, mrn, gender, dob, phone, allergies FROM patients WHERE id = ? AND deleted_at IS NULL', [$pid]) : null;
        $data = ['title' => 'Specialty Exams', 'patient' => $patient];
        if ($patient) {
            $data += [
                'visits' => DB::all('SELECT v.id, v.visit_no, v.visit_date, d.name AS dept, u.name AS doctor FROM visits v LEFT JOIN departments d ON d.id = v.department_id
                                     LEFT JOIN users u ON u.id = v.doctor_id WHERE v.patient_id = ? AND v.visit_date >= ? ORDER BY v.visit_date DESC LIMIT 10',
                    [$pid, date('Y-m-d', strtotime('-30 days'))]),
                'eye' => DB::all('SELECT e.*, v.visit_no FROM eye_exams e JOIN visits v ON v.id = e.encounter_id WHERE e.patient_id = ? ORDER BY e.recorded_at DESC LIMIT 10', [$pid]),
                'growth' => DB::all('SELECT * FROM growth_measurements WHERE patient_id = ? ORDER BY measured_on', [$pid]),
                'cardiac' => DB::all('SELECT c.*, u.name AS by_name FROM cardiac_investigations c LEFT JOIN users u ON u.id = c.performed_by WHERE c.patient_id = ? ORDER BY c.performed_at DESC LIMIT 20', [$pid]),
                'audio' => DB::all('SELECT * FROM audiograms WHERE patient_id = ? ORDER BY performed_at DESC, ear LIMIT 20', [$pid]),
                'body' => DB::all('SELECT f.*, r.label, v.visit_no FROM encounter_body_map_findings f JOIN body_map_regions r ON r.id = f.region_id LEFT JOIN visits v ON v.id = f.encounter_id
                                    WHERE f.patient_id = ? ORDER BY f.recorded_at DESC LIMIT 50', [$pid]),
                'notes' => SpecialtyService::notes($pid),
                'referrals' => DB::all('SELECT r.*, d.name AS dept, u.name AS from_name FROM referrals r LEFT JOIN departments d ON d.id = r.to_department_id
                                         LEFT JOIN users u ON u.id = r.from_doctor_id WHERE r.patient_id = ? ORDER BY r.created_at DESC LIMIT 20', [$pid]),
                'snellen' => SpecialtyService::SNELLEN, 'cardiacTypes' => SpecialtyService::CARDIAC_TYPES, 'freqs' => SpecialtyService::AUDIO_FREQS,
            ];
        }
        $this->view('specialty/index', $data);
    }

    /** Records an exam against one of the patient's recent visits. */
    public function record(): void
    {
        $vid = (int) input('visit_id');
        $v = SpecialtyContext::visit($vid);
        if (!$v || !Scope::visitAllowed($v, 'specialty.exam.record')) {
            abort(403);
        }
        $pid = (int) $v['patient_id'];
        try {
            match ((string) input('kind')) {
                'eye' => SpecialtyService::saveEye($vid, $pid, $_POST),
                'cardiac' => SpecialtyService::saveCardiac($vid, $pid, $_POST),
                'audiogram' => SpecialtyService::saveAudiogram($vid, $pid, ['L' => (array) ($_POST['L'] ?? []), 'R' => (array) ($_POST['R'] ?? []),
                    'tymp_L' => input('tymp_L'), 'tymp_R' => input('tymp_R')]),
                'growth' => SpecialtyService::saveGrowth($pid, $vid, $_POST),
                default => throw new \InvalidArgumentException('Unknown exam.'),
            };
            ActivityLog::record('Specialty exam recorded', 'specialty', (string) $vid, (string) input('kind') . ' — ' . $v['visit_no']);
            flash('success', 'Saved to visit ' . $v['visit_no'] . '.');
        } catch (\Throwable $e) {
            flash('error', $e->getMessage());
        }
        redirect('specialty', ['patient_id' => $pid]);
    }

    /** Immunization card and recording a dose (vaccinator / pediatrician). */
    public function immunization(): void
    {
        $pid = (int) input('patient_id', 0);
        if (is_post()) {
            try {
                SpecialtyService::immunize($pid, (int) input('vaccine_id'), (int) input('dose_no', 1), (int) input('visit_id', 0) ?: null,
                    (string) input('site', ''), (string) input('notes', ''));
                flash('success', 'Dose recorded and drawn from stock.');
            } catch (\Throwable $e) {
                flash('error', $e->getMessage());
            }
            redirect('specialty/immunization', ['patient_id' => $pid]);
        }
        $patient = $pid ? DB::one('SELECT id, name, mrn, gender, dob, mother_patient_id FROM patients WHERE id = ? AND deleted_at IS NULL', [$pid]) : null;
        $due = DB::all("SELECT p.id, p.name, p.mrn, p.dob, v.name AS vaccine, r.dose_no, r.next_due_date FROM immunization_records r
                          JOIN patients p ON p.id = r.patient_id JOIN vaccines v ON v.id = r.vaccine_id
                         WHERE r.next_due_date BETWEEN ? AND ? AND NOT EXISTS (SELECT 1 FROM immunization_records x WHERE x.patient_id = r.patient_id AND x.vaccine_id = r.vaccine_id AND x.dose_no = r.dose_no + 1)
                         ORDER BY r.next_due_date LIMIT 100", [date('Y-m-d', strtotime('-30 days')), date('Y-m-d', strtotime('+7 days'))]);
        $this->view('specialty/immunization', ['title' => 'Immunization', 'patient' => $patient, 'card' => $patient ? SpecialtyService::immunizationCard($pid) : [], 'due' => $due]);
    }

    /** Tier 1 template designer (SA chain-wide; HA may add branch fields). */
    public function templates(): void
    {
        $sid = (int) input('specialty_id', 0);
        if (is_post()) {
            try {
                $this->saveTemplate($sid);
            } catch (\Throwable $e) {
                flash('error', $e->getMessage());
            }
            redirect('specialty/templates', ['specialty_id' => $sid]);
        }
        $specialties = DB::all('SELECT s.*, (SELECT COUNT(*) FROM clinical_parameter_templates t WHERE t.specialty_id = s.id AND t.is_active = 1) AS fields,
                                       (SELECT GROUP_CONCAT(d.name SEPARATOR \', \') FROM departments d WHERE d.specialty_id = s.id AND d.deleted_at IS NULL) AS departments
                                  FROM specialties s WHERE s.deleted_at IS NULL ORDER BY s.display_order, s.name');
        $sid = $sid ?: (int) ($specialties[0]['id'] ?? 0);
        $fields = DB::all('SELECT t.*, b.name AS branch_name FROM clinical_parameter_templates t LEFT JOIN branches b ON b.id = t.branch_id WHERE t.specialty_id = ? ORDER BY t.display_order, t.id', [$sid]);
        $this->view('specialty/templates', ['title' => 'Specialty Templates', 'specialties' => $specialties, 'sid' => $sid, 'fields' => $fields]);
    }

    private function saveTemplate(int $sid): void
    {
        $do = (string) input('do');
        $id = (int) input('id', 0);
        if ($do === 'toggle') {
            DB::run('UPDATE clinical_parameter_templates SET is_active = 1 - is_active, updated_at = ?, updated_by = ? WHERE id = ?', [now(), Auth::id(), $id]);
            return;
        }
        $label = trim((string) input('label'));
        $type = (string) input('field_type', 'text');
        if ($label === '' || !isset(SpecialtyService::FIELD_TYPES[$type])) {
            throw new \InvalidArgumentException('Label and a valid type are required.');
        }
        $options = array_values(array_filter(array_map('trim', explode(',', (string) input('options', '')))));
        if (in_array($type, ['select', 'multiselect'], true) && count($options) < 2) {
            throw new \InvalidArgumentException('Give at least two options, separated by commas.');
        }
        $key = preg_replace('/[^a-z0-9_]/', '', strtolower(str_replace(' ', '_', (string) input('field_key', '') ?: $label))) ?: 'field';
        $data = ['label' => mb_substr($label, 0, 150), 'field_type' => $type, 'options_json' => $options ? json_encode($options, JSON_UNESCAPED_UNICODE) : null,
            'unit' => trim((string) input('unit', '')) ?: null, 'is_required' => input('is_required') ? 1 : 0, 'display_order' => (int) input('display_order', 0),
            'branch_id' => input('branch_only') || !Auth::hasRole('SA') ? Auth::branchId() : null];
        if ($id) {
            DB::update('clinical_parameter_templates', $data + ['updated_at' => now(), 'updated_by' => Auth::id()], 'id = ? AND specialty_id = ?', [$id, $sid]);
        } else {
            if (DB::value('SELECT 1 FROM clinical_parameter_templates WHERE specialty_id = ? AND field_key = ?', [$sid, $key])) {
                $key .= '_' . substr(bin2hex(random_bytes(2)), 0, 3);
            }
            DB::insert('clinical_parameter_templates', $data + ['specialty_id' => $sid, 'field_key' => mb_substr($key, 0, 50), 'is_active' => 1, 'created_at' => now(), 'created_by' => Auth::id()]);
        }
        ActivityLog::record('Specialty field saved', 'specialty', (string) $sid, $label);
        flash('success', 'Field saved — it appears on the consultation of every department with this specialty.');
    }

    /** Referral inbox (to my departments / me) and sent referrals. */
    public function referrals(): void
    {
        if (is_post()) {
            try {
                if (input('do') === 'create') {
                    SpecialtyService::refer($_POST + ['source' => 'manual']);
                    flash('success', 'Referral sent.');
                    redirect('specialty', ['patient_id' => (int) input('patient_id')]);
                }
                SpecialtyService::updateReferral((int) input('id'), (string) input('status'));
                flash('success', 'Referral updated.');
            } catch (\Throwable $e) {
                flash('error', $e->getMessage());
            }
            back('clinical/referrals');
        }
        $depts = Auth::memberDepartments((int) Auth::id());
        $params = [Auth::id()];
        $where = 'r.to_doctor_id = ?';
        if ($depts) {
            $where .= ' OR (r.to_doctor_id IS NULL AND r.to_department_id IN (' . DB::placeholders($depts) . '))';
            $params = array_merge($params, $depts);
        }
        $sql = 'SELECT r.*, p.name AS patient_name, p.mrn, d.name AS dept, u.name AS from_name, t.name AS to_name, v.visit_no FROM referrals r
                  JOIN patients p ON p.id = r.patient_id LEFT JOIN departments d ON d.id = r.to_department_id LEFT JOIN users u ON u.id = r.from_doctor_id
                  LEFT JOIN users t ON t.id = r.to_doctor_id LEFT JOIN visits v ON v.id = r.encounter_id';
        $this->view('specialty/referrals', ['title' => 'Referrals',
            'inbox' => DB::all($sql . ' WHERE (' . $where . ") ORDER BY r.status = 'open' DESC, FIELD(r.urgency, 'emergency', 'urgent') DESC, r.created_at DESC LIMIT 200", $params),
            'sent' => DB::all($sql . ' WHERE r.from_doctor_id = ? ORDER BY r.created_at DESC LIMIT 100', [Auth::id()])]);
    }

    /** Adds a clinical note from the patient / specialty page. */
    public function note(): void
    {
        $pid = (int) input('patient_id');
        try {
            SpecialtyService::addNote($pid, (int) input('visit_id', 0) ?: null, (string) input('note_text', ''), (bool) input('is_restricted'), (string) input('note_type', 'progress'));
            flash('success', 'Note added.');
        } catch (\Throwable $e) {
            flash('error', $e->getMessage());
        }
        back('specialty');
    }
}
