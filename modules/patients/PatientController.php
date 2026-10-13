<?php
declare(strict_types=1);

namespace App\Modules\Patients;

use App\Core\ActivityLog;
use App\Core\Auth;
use App\Core\Controller;
use App\Core\DB;
use App\Core\Paginator;
use App\Core\Scope;
use App\Core\Upload;

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
                'cnic'          => ['CNIC', static fn ($r) => cnic_mask($r['cnic'])],
                'phone'         => 'Phone',
                'city'          => 'City',
                'address'       => 'Address',
                'doctor_name'   => 'Registered For',
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
            $merged = DB::value('SELECT merged_into_patient_id FROM patients WHERE id = ?', [$id]);
            if ($merged) {
                flash('info', 'This record was merged into another patient.');
                redirect('patients/view', ['id' => $merged]);
            }
            abort(404, 'Patient not found.');
        }
        $visits = [];
        if (can('visits.view') || can('visits.create')) {
            $params = [$id];
            $scope = can('visits.view') && !Scope::hasEmergencyAccess($id) ? Scope::sql('v.doctor_id', $params) : '';
            $visits = DB::all(
                "SELECT v.id, v.visit_no, v.visit_date, v.doctor_id, v.follow_up_date, v.consultation_fee, v.payment_status, v.medicines_total,
                        v.medicine_net, v.grand_total, v.print_count, u.name AS doctor_name, d.name AS department_name,
                        (SELECT GROUP_CONCAT(i.name ORDER BY i.sort_order SEPARATOR ', ') FROM visit_clinical_items i WHERE i.visit_id = v.id AND i.item_type = 'complaint') AS complaints,
                        (SELECT GROUP_CONCAT(i.name ORDER BY i.sort_order SEPARATOR ', ') FROM visit_clinical_items i WHERE i.visit_id = v.id AND i.item_type = 'symptom') AS symptoms,
                        (SELECT GROUP_CONCAT(i.name ORDER BY i.sort_order SEPARATOR ', ') FROM visit_clinical_items i WHERE i.visit_id = v.id AND i.item_type = 'diagnosis') AS diagnoses,
                        (SELECT GROUP_CONCAT(i.name ORDER BY i.sort_order SEPARATOR ', ') FROM visit_clinical_items i WHERE i.visit_id = v.id AND i.item_type = 'investigation') AS investigations,
                        (SELECT GROUP_CONCAT(CONCAT(m.medicine_name, ' ×', TRIM(TRAILING '.' FROM TRIM(TRAILING '0' FROM m.quantity))) ORDER BY m.sort_order SEPARATOR ', ') FROM visit_medicines m WHERE m.visit_id = v.id) AS medicines
                   FROM visits v JOIN users u ON u.id = v.doctor_id LEFT JOIN departments d ON d.id = v.department_id
                  WHERE v.patient_id = ? AND v.deleted_at IS NULL" . $scope . ' ORDER BY v.visit_date DESC, v.id DESC',
                $params
            );
        }
        $family = PatientService::duplicates($patient['cnic'], $patient['phone'], $id);
        $this->view('patients/view', [
            'title'       => $patient['name'],
            'p'           => $patient,
            'visits'      => $visits,
            'family'      => $family,
            'identifiers' => DB::all('SELECT * FROM patient_identifiers WHERE patient_id = ? ORDER BY is_primary DESC, id', [$id]),
            'contacts'    => DB::all('SELECT * FROM patient_contacts WHERE patient_id = ? ORDER BY is_guardian DESC, id', [$id]),
            'documents'   => can('patients.documents') ? DB::all('SELECT d.*, u.name AS uploaded_by_name FROM patient_documents d LEFT JOIN users u ON u.id = d.uploaded_by
                                                                     WHERE d.patient_id = ? AND d.deleted_at IS NULL ORDER BY d.id DESC', [$id]) : [],
            'invoices'    => can('billing.view') ? DB::all('SELECT id, invoice_no, invoice_type, status, net_amount, paid_amount, refunded_amount, created_at FROM invoices
                                                             WHERE patient_id = ? ORDER BY id DESC LIMIT 20', [$id]) : [],
            'tokens'      => DB::all('SELECT t.token_label, t.issue_date, t.status, q.name AS queue_name FROM tokens t JOIN token_queues q ON q.id = t.queue_id
                                       WHERE t.patient_id = ? ORDER BY t.id DESC LIMIT 10', [$id]),
            'mother'      => $patient['mother_patient_id'] ? PatientService::find((int) $patient['mother_patient_id']) : null,
            'children'    => DB::all('SELECT id, mrn, name, dob FROM patients WHERE mother_patient_id = ? AND deleted_at IS NULL', [$id]),
            'canEdit'     => PatientService::canEdit($id),
        ]);
    }

    public function create(): void
    {
        $errors = [];
        $duplicates = [];
        $isDoctor = Auth::isDoctor();
        $doctors = $isDoctor ? [] : Scope::allDoctors();

        if (is_post()) {
            [$data, $errors] = PatientService::validate($_POST);
            $extra = PatientService::validateExtra($_POST);
            $doctorId = $isDoctor ? Auth::id() : ((int) input('doctor_id', 0) ?: null);
            if ($doctorId !== null && !$isDoctor && !isset($doctors[$doctorId])) {
                $errors['doctor_id'] = 'Please select a valid doctor.';
            }
            if (!$errors && empty($_POST['confirm_duplicate'])) {
                $duplicates = PatientService::possibleDuplicates($data);
            }
            if (!$errors && !$duplicates) {
                $patient = PatientService::create($data, $doctorId, $extra);
                flash('success', 'Patient saved successfully. MRN: ' . $patient['mrn']);
                if (input('then') === 'visit' && can('visits.create')) {
                    redirect('visits/new', ['patient_id' => $patient['id']]);
                }
                if (input('then') === 'token' && can('frontdesk.token.issue')) {
                    redirect('frontdesk', ['patient_id' => $patient['id']]);
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
        if (!PatientService::canEdit($id)) {
            flash('warning', 'This patient already has visits. Demographics and CNIC can now be changed only by a Registration Supervisor or Branch Admin.');
            redirect('patients/view', ['id' => $id]);
        }
        $errors = [];
        if (is_post()) {
            [$data, $errors] = PatientService::validate($_POST);
            if (!$errors) {
                PatientService::update($id, $data + PatientService::validateExtra($_POST));
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
        $items = PatientService::search((string) input('q', ''));
        foreach ($items as &$i) {
            $i['cnic'] = cnic_mask($i['cnic']);
        }
        $this->json(['ok' => true, 'items' => $items]);
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

    // ------------------------------------------------------- v2 additions

    public function addContact(): void
    {
        $id = $this->requireId('patient_id');
        if (!PatientService::find($id) || !(can('patients.edit') || can('patients.create'))) {
            abort(403);
        }
        $name = trim((string) input('name', ''));
        if ($name === '') {
            flash('error', 'Contact name is required.');
            redirect('patients/view', ['id' => $id]);
        }
        DB::insert('patient_contacts', [
            'patient_id' => $id, 'name' => mb_substr($name, 0, 150), 'relation' => mb_substr((string) input('relation', ''), 0, 50) ?: null,
            'phone' => normalize_phone((string) input('phone', '')), 'cnic' => normalize_cnic((string) input('cnic', '')),
            'is_guardian' => input('is_guardian') ? 1 : 0, 'is_emergency_contact' => input('is_emergency_contact') ? 1 : 0,
            'is_next_of_kin' => input('is_next_of_kin') ? 1 : 0, 'created_at' => now(), 'created_by' => Auth::id(),
        ]);
        ActivityLog::record('Contact added', 'patients', (string) $id, 'Next of kin / guardian: ' . $name);
        flash('success', 'Contact added.');
        redirect('patients/view', ['id' => $id]);
    }

    public function removeContact(): void
    {
        $cid = $this->requireId();
        $c = DB::one('SELECT * FROM patient_contacts WHERE id = ?', [$cid]);
        if (!$c || !can('patients.edit')) {
            abort(403);
        }
        DB::run('DELETE FROM patient_contacts WHERE id = ?', [$cid]);
        ActivityLog::record('Contact removed', 'patients', (string) $c['patient_id'], $c['name']);
        redirect('patients/view', ['id' => $c['patient_id']]);
    }

    public function addIdentifier(): void
    {
        $id = $this->requireId('patient_id');
        if (!PatientService::find($id) || !PatientService::canEdit($id)) {
            abort(403);
        }
        $type = (string) input('id_type', '');
        $value = trim((string) input('id_value', ''));
        if (!isset(hospital('patient_id_types')[$type]) || $value === '' || mb_strlen($value) > 60) {
            flash('error', 'Choose an identifier type and enter its number.');
            redirect('patients/view', ['id' => $id]);
        }
        if ($type === 'cnic' || $type === 'b_form' || $type === 'nicop') {
            $value = normalize_cnic($value) ?? $value;
        }
        DB::insert('patient_identifiers', ['patient_id' => $id, 'id_type' => $type, 'id_value' => $value, 'is_primary' => 0,
            'verified_at' => input('verified') ? now() : null, 'created_at' => now(), 'created_by' => Auth::id()]);
        ActivityLog::record('Identifier added', 'patients', (string) $id, hospital('patient_id_types')[$type] . ' added');
        flash('success', 'Identifier added.');
        redirect('patients/view', ['id' => $id]);
    }

    public function uploadDocument(): void
    {
        $id = $this->requireId('patient_id');
        if (!PatientService::find($id)) {
            abort(404);
        }
        $type = (string) input('doc_type', 'other');
        $res = Upload::document($_FILES['file'] ?? null, 'patients/' . $id);
        if (!$res['ok']) {
            flash('error', $res['message']);
            redirect('patients/view', ['id' => $id]);
        }
        DB::insert('patient_documents', [
            'patient_id' => $id, 'doc_type' => isset(hospital('document_types')[$type]) ? $type : 'other',
            'title' => mb_substr(trim((string) input('title', '')), 0, 150) ?: null, 'file_path' => $res['path'], 'mime' => $res['mime'],
            'size_bytes' => $res['size'], 'uploaded_by' => Auth::id(), 'uploaded_at' => now(),
        ]);
        ActivityLog::record('Document uploaded', 'patients', (string) $id, hospital('document_types')[$type] ?? 'Document');
        flash('success', 'Document uploaded.');
        redirect('patients/view', ['id' => $id]);
    }

    public function document(): void
    {
        $doc = DB::one('SELECT * FROM patient_documents WHERE id = ? AND deleted_at IS NULL', [$this->requireId()]);
        if (!$doc) {
            abort(404);
        }
        ActivityLog::record('Document viewed', 'patients', (string) $doc['patient_id'], (string) ($doc['title'] ?: $doc['doc_type']));
        Upload::send($doc['file_path'], ($doc['title'] ?: $doc['doc_type']) . '.' . pathinfo($doc['file_path'], PATHINFO_EXTENSION));
    }

    public function deleteDocument(): void
    {
        $doc = DB::one('SELECT * FROM patient_documents WHERE id = ? AND deleted_at IS NULL', [$this->requireId()]);
        if (!$doc) {
            abort(404);
        }
        DB::update('patient_documents', ['deleted_at' => now(), 'deleted_by' => Auth::id()], 'id = ?', [$doc['id']]);
        ActivityLog::record('Document deleted', 'patients', (string) $doc['patient_id'], (string) ($doc['title'] ?: $doc['doc_type']));
        flash('success', 'Document removed.');
        redirect('patients/view', ['id' => $doc['patient_id']]);
    }

    /** Merge duplicate patients (RGS / HA). */
    public function merge(): void
    {
        $keep = (int) input('keep', 0);
        $mergeId = (int) input('merge', 0);
        if (is_post()) {
            $reason = trim((string) input('reason', ''));
            if ($reason === '') {
                flash('error', 'A reason is required to merge patients.');
                redirect('patients/merge', ['keep' => $keep, 'merge' => $mergeId]);
            }
            try {
                PatientService::merge($keep, $mergeId, $reason);
                flash('success', 'Patients merged. All visits, tokens, bills and records now belong to the kept MRN.');
                redirect('patients/view', ['id' => $keep]);
            } catch (\Throwable $e) {
                flash('error', $e->getMessage());
                redirect('patients/merge', ['keep' => $keep, 'merge' => $mergeId]);
            }
        }
        $this->view('patients/merge', [
            'title' => 'Merge Duplicate Patients',
            'keep'  => $keep ? PatientService::find($keep) : null,
            'other' => $mergeId ? PatientService::find($mergeId) : null,
            'count' => static fn (int $pid): int => (int) DB::value('SELECT COUNT(*) FROM visits WHERE patient_id = ? AND deleted_at IS NULL', [$pid]),
        ]);
    }

    /** Emergency / unidentified registration → token in the ER queue. */
    public function emergency(): void
    {
        $p = PatientService::createEmergency((string) input('description', ''), (string) input('gender', 'Other'),
            is_numeric(input('age')) ? (int) input('age') : null);
        flash('success', 'Emergency patient registered as ' . $p['mrn'] . '. Complete identification later.');
        if (can('frontdesk.token.issue')) {
            redirect('frontdesk', ['patient_id' => $p['id'], 'priority' => 'emergency']);
        }
        redirect('patients/view', ['id' => $p['id']]);
    }
}
