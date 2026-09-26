<?php
declare(strict_types=1);

namespace App\Modules\Patients;

use App\Core\ActivityLog;
use App\Core\Auth;
use App\Core\Controller;
use App\Core\DB;
use App\Core\Paginator;
use App\Core\Scope;

final class PatientController extends Controller
{
    public function index(): void
    {
        $q = trim((string) input('q', ''));
        $gender = (string) input('gender', '');
        $doctorId = (int) input('doctor_id', 0);
        $from = (string) input('from', '');
        $to = (string) input('to', '');

        $where = 'p.deleted_at IS NULL';
        $params = [];
        if ($q !== '') {
            $digits = preg_replace('/\D/', '', $q);
            $like = like_escape($q) . '%';
            $cond = ['p.mrn LIKE ?', 'p.name LIKE ?', 'p.name LIKE ?', 'p.guardian_name LIKE ?'];
            array_push($params, $like, $like, '% ' . like_escape($q) . '%', $like);
            if ($digits !== '' && strlen($digits) >= 3) {
                $cond[] = 'p.phone LIKE ?';
                $params[] = $digits . '%';
                $cond[] = 'p.cnic LIKE ?';
                $params[] = like_escape(cnic_prefix($digits)) . '%';
            }
            $where .= ' AND (' . implode(' OR ', $cond) . ')';
        }
        if (in_array($gender, PatientService::GENDERS, true)) {
            $where .= ' AND p.gender = ?';
            $params[] = $gender;
        }
        if ($doctorId > 0) {
            $where .= ' AND p.doctor_id = ?';
            $params[] = $doctorId;
        }
        if ($from !== '' && strtotime($from)) {
            $where .= ' AND p.created_at >= ?';
            $params[] = date('Y-m-d', strtotime($from)) . ' 00:00:00';
        }
        if ($to !== '' && strtotime($to)) {
            $where .= ' AND p.created_at <= ?';
            $params[] = date('Y-m-d', strtotime($to)) . ' 23:59:59';
        }

        $base = ' FROM patients p LEFT JOIN users u ON u.id = p.doctor_id WHERE ' . $where;
        $select = 'SELECT p.*, u.name AS doctor_name,
                   (SELECT MAX(v.visit_date) FROM visits v WHERE v.patient_id = p.id AND v.deleted_at IS NULL) AS last_visit,
                   (SELECT COUNT(*) FROM visits v WHERE v.patient_id = p.id AND v.deleted_at IS NULL) AS visit_count';
        $order = ' ORDER BY p.id DESC';

        if ($this->wantsExport()) {
            $this->export('patients', 'Patients', [
                'mrn'           => 'MRN',
                'name'          => 'Patient Name',
                'guardian_name' => 'Father / Husband',
                'gender'        => 'Gender',
                'dob'           => ['Age', static fn ($r) => age_text($r['dob'])],
                'cnic'          => 'CNIC',
                'phone'         => 'Phone',
                'city'          => 'City',
                'address'       => 'Address',
                'doctor_name'   => 'Registered By',
                'visit_count'   => 'Visits',
                'last_visit'    => ['Last Visit', static fn ($r) => fmt_date($r['last_visit'])],
                'created_at'    => ['Registered On', static fn ($r) => fmt_date($r['created_at'])],
            ], DB::run($select . $base . $order, $params), [
                'Search' => $q, 'Gender' => $gender, 'From' => $from, 'To' => $to,
                'Doctor' => $doctorId ? (string) DB::value('SELECT name FROM users WHERE id = ?', [$doctorId]) : '',
            ]);
            return;
        }

        $pager = new Paginator((int) DB::value('SELECT COUNT(*)' . $base, $params));
        $this->view('patients/index', [
            'title'   => 'Patients',
            'rows'    => DB::all($select . $base . $order . $pager->limitSql(), $params),
            'pager'   => $pager,
            'doctors' => DB::pairs("SELECT id, name FROM users WHERE role = 'doctor' AND deleted_at IS NULL ORDER BY name"),
        ]);
    }

    public function show(): void
    {
        $id = $this->requireId();
        $patient = PatientService::find($id);
        if (!$patient) {
            abort(404, 'Patient not found.');
        }
        $visits = DB::all(
            "SELECT v.id, v.visit_no, v.visit_date, v.doctor_id, v.follow_up_date, v.consultation_fee, v.payment_status, v.medicines_total,
                    v.medicine_net, v.grand_total, v.print_count, u.name AS doctor_name,
                    (SELECT GROUP_CONCAT(i.name ORDER BY i.sort_order SEPARATOR ', ') FROM visit_clinical_items i WHERE i.visit_id = v.id AND i.item_type = 'complaint') AS complaints,
                    (SELECT GROUP_CONCAT(i.name ORDER BY i.sort_order SEPARATOR ', ') FROM visit_clinical_items i WHERE i.visit_id = v.id AND i.item_type = 'symptom') AS symptoms,
                    (SELECT GROUP_CONCAT(i.name ORDER BY i.sort_order SEPARATOR ', ') FROM visit_clinical_items i WHERE i.visit_id = v.id AND i.item_type = 'diagnosis') AS diagnoses,
                    (SELECT GROUP_CONCAT(i.name ORDER BY i.sort_order SEPARATOR ', ') FROM visit_clinical_items i WHERE i.visit_id = v.id AND i.item_type = 'investigation') AS investigations,
                    (SELECT GROUP_CONCAT(CONCAT(m.medicine_name, ' ×', TRIM(TRAILING '.' FROM TRIM(TRAILING '0' FROM m.quantity))) ORDER BY m.sort_order SEPARATOR ', ') FROM visit_medicines m WHERE m.visit_id = v.id) AS medicines
               FROM visits v JOIN users u ON u.id = v.doctor_id
              WHERE v.patient_id = ? AND v.deleted_at IS NULL ORDER BY v.visit_date DESC, v.id DESC",
            [$id]
        );
        $family = PatientService::duplicates($patient['cnic'], $patient['phone'], $id);
        $this->view('patients/view', ['title' => $patient['name'], 'p' => $patient, 'visits' => $visits, 'family' => $family]);
    }

    public function create(): void
    {
        $errors = [];
        $duplicates = [];
        $isDoctor = Auth::isDoctor();
        $doctors = $isDoctor ? [] : DB::pairs("SELECT id, name FROM users WHERE role = 'doctor' AND is_active = 1 AND deleted_at IS NULL ORDER BY name");

        if (is_post()) {
            [$data, $errors] = PatientService::validate($_POST);
            $doctorId = $isDoctor ? Auth::id() : (int) input('doctor_id', 0);
            if (!$isDoctor && !isset($doctors[$doctorId])) {
                $errors['doctor_id'] = 'Please select the doctor (the MRN follows the doctor\'s pattern).';
            }
            if (!$errors && empty($_POST['confirm_duplicate'])) {
                $duplicates = PatientService::duplicates($data['cnic'], $data['phone']);
            }
            if (!$errors && !$duplicates) {
                $patient = PatientService::create($data, $doctorId);
                flash('success', 'Patient saved successfully. MRN: ' . $patient['mrn']);
                if (input('then') === 'visit' && can('visits.create')) {
                    redirect('visits/new', ['patient_id' => $patient['id']]);
                }
                redirect('patients/view', ['id' => $patient['id']]);
            }
        }
        $this->view('patients/form', [
            'title'       => 'Register Patient',
            'patient'     => null,
            'errors'      => array_values($errors),
            'fieldErrors' => $errors,
            'duplicates'  => $duplicates,
            'doctors'     => $doctors,
        ]);
    }

    public function edit(): void
    {
        $id = $this->requireId();
        $patient = PatientService::find($id);
        if (!$patient) {
            abort(404, 'Patient not found.');
        }
        $errors = [];
        if (is_post()) {
            [$data, $errors] = PatientService::validate($_POST);
            if (!$errors) {
                PatientService::update($id, $data);
                flash('success', 'Patient saved successfully.');
                redirect('patients/view', ['id' => $id]);
            }
        }
        $this->view('patients/form', [
            'title'       => 'Edit Patient',
            'patient'     => $patient,
            'errors'      => array_values($errors),
            'fieldErrors' => $errors,
            'duplicates'  => [],
            'doctors'     => [],
        ]);
    }

    public function delete(): void
    {
        $id = $this->requireId();
        $patient = PatientService::find($id);
        if (!$patient) {
            abort(404);
        }
        DB::update('patients', ['deleted_at' => now(), 'deleted_by' => Auth::id()], 'id = ?', [$id]);
        ActivityLog::record('Patient deleted', 'patients', (string) $id, 'Moved to Recycle Bin: ' . $patient['name'] . ' (' . $patient['mrn'] . ')');
        flash('success', 'Patient "' . $patient['name'] . '" deleted. It can be restored from the Recycle Bin.');
        redirect('patients');
    }

    /** JSON live search (MRN / CNIC / phone / name). */
    public function search(): void
    {
        $this->json(['ok' => true, 'items' => PatientService::search((string) input('q', ''))]);
    }

    /** JSON family / duplicate check by CNIC or phone (BRD §31). */
    public function duplicates(): void
    {
        $this->json(['ok' => true, 'items' => PatientService::duplicates(
            (string) input('cnic', ''),
            (string) input('phone', ''),
            (int) input('exclude', 0)
        )]);
    }
}
