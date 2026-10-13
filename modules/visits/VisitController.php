<?php
declare(strict_types=1);

namespace App\Modules\Visits;

use App\Core\ActivityLog;
use App\Core\Auth;
use App\Core\Controller;
use App\Core\DB;
use App\Core\Paginator;
use App\Core\Scope;
use App\Core\Urdu;
use App\Modules\Patients\PatientService;
use App\Modules\Templates\TemplateService;
use App\Modules\Users\UserService;

final class VisitController extends Controller
{
    /** Visit listing with filters and Excel export. */
    public function index(): void
    {
        $q = trim((string) input('q', ''));
        $from = (string) input('from', '');
        $to = (string) input('to', '');
        $doctorId = (int) input('doctor_id', 0);
        $payment = (string) input('payment', '');

        $where = 'v.deleted_at IS NULL AND p.deleted_at IS NULL';
        $params = [];
        $where .= Scope::sql('v.doctor_id', $params);
        if ($q !== '') {
            $like = like_escape($q) . '%';
            $cond = ['v.visit_no LIKE ?', 'p.mrn LIKE ?', 'p.name LIKE ?'];
            array_push($params, $like, $like, $like);
            $digits = preg_replace('/\D/', '', $q);
            if (strlen($digits) >= 3) {
                $cond[] = 'p.phone LIKE ?';
                $cond[] = 'p.cnic LIKE ?';
                array_push($params, $digits . '%', like_escape(cnic_prefix($digits)) . '%');
            }
            $where .= ' AND (' . implode(' OR ', $cond) . ')';
        }
        if ($from !== '' && strtotime($from)) {
            $where .= ' AND v.visit_date >= ?';
            $params[] = date('Y-m-d', strtotime($from)) . ' 00:00:00';
        }
        if ($to !== '' && strtotime($to)) {
            $where .= ' AND v.visit_date <= ?';
            $params[] = date('Y-m-d', strtotime($to)) . ' 23:59:59';
        }
        if ($doctorId > 0) {
            $where .= ' AND v.doctor_id = ?';
            $params[] = $doctorId;
        }
        if (in_array($payment, VisitService::PAYMENT_STATUSES, true)) {
            $where .= ' AND v.payment_status = ?';
            $params[] = $payment;
        }

        $base = ' FROM visits v JOIN patients p ON p.id = v.patient_id JOIN users u ON u.id = v.doctor_id WHERE ' . $where;
        $select = "SELECT v.id, v.visit_no, v.visit_date, v.consultation_fee, v.payment_status, v.medicines_total, v.discount_amount,
                          v.medicine_net, v.grand_total, v.follow_up_date, v.print_count, p.id AS patient_id, p.name, p.mrn, p.gender, p.dob, p.phone,
                          u.name AS doctor_name,
                          (SELECT GROUP_CONCAT(i.name ORDER BY i.sort_order SEPARATOR ', ') FROM visit_clinical_items i WHERE i.visit_id = v.id AND i.item_type = 'diagnosis') AS diagnoses,
                          (SELECT COUNT(*) FROM visit_medicines m WHERE m.visit_id = v.id) AS medicine_count";
        $order = ' ORDER BY v.visit_date DESC, v.id DESC';

        if ($this->wantsExport()) {
            $this->export('visits', 'Visits', [
                'visit_no'         => 'Visit No',
                'visit_date'       => ['Date', static fn ($r) => fmt_datetime($r['visit_date'])],
                'mrn'              => 'MRN',
                'name'             => 'Patient',
                'gender'           => 'Gender',
                'phone'            => 'Phone',
                'doctor_name'      => 'Doctor',
                'diagnoses'        => 'Diagnosis',
                'medicine_count'   => 'Medicines',
                'consultation_fee' => ['Consultation Fee', static fn ($r) => (float) $r['consultation_fee']],
                'payment_status'   => 'Payment',
                'medicines_total'  => ['Medicines Total', static fn ($r) => (float) $r['medicines_total']],
                'discount_amount'  => ['Discount', static fn ($r) => (float) $r['discount_amount']],
                'medicine_net'     => ['Medicine Net', static fn ($r) => (float) $r['medicine_net']],
                'grand_total'      => ['Grand Total', static fn ($r) => (float) $r['grand_total']],
                'follow_up_date'   => ['Follow-up', static fn ($r) => fmt_date($r['follow_up_date'])],
            ], DB::run($select . $base . $order, $params), ['Search' => $q, 'From' => $from, 'To' => $to, 'Payment' => $payment]);
            return;
        }

        $pager = new Paginator((int) DB::value('SELECT COUNT(*)' . $base, $params));
        $totals = DB::one('SELECT COALESCE(SUM(v.consultation_fee),0) AS fee, COALESCE(SUM(v.medicine_net),0) AS med, COALESCE(SUM(v.grand_total),0) AS grand' . $base, $params);
        $this->view('visits/index', [
            'title'   => 'Visits',
            'rows'    => DB::all($select . $base . $order . $pager->limitSql(), $params),
            'pager'   => $pager,
            'totals'  => $totals,
            'doctors' => Scope::doctorOptions(),
        ]);
    }

    /** The one-page consultation screen (BRD §33–48). */
    public function create(): void
    {
        $user = Auth::user();
        $isDoctor = $user['role'] === 'doctor';
        $prefill = ['patient' => null, 'medicines' => [], 'complaints' => [], 'symptoms' => [], 'diagnoses' => [], 'investigations' => []];
        $notices = [];

        if (($pid = (int) input('patient_id', 0)) > 0) {
            $patient = PatientService::find($pid);
            if (!$patient) {
                flash('error', 'Patient not found.');
                redirect('visits/new');
            }
            $prefill['patient'] = PatientService::forClient($patient);
        }

        // Started from a token (OPD queue): patient, nurse vitals and the receipt come along.
        if (($tokenId = (int) input('token_id', 0)) > 0) {
            $token = DB::one('SELECT t.*, q.doctor_id, q.department_id FROM tokens t JOIN token_queues q ON q.id = t.queue_id WHERE t.id = ?', [$tokenId]);
            if ($token && in_array($token['status'], ['waiting', 'called', 'serving', 'no_show'], true)) {
                $prefill['token'] = ['id' => (int) $token['id'], 'label' => $token['token_label'], 'department_id' => $token['department_id'] ? (int) $token['department_id'] : null];
                if (!$prefill['patient'] && ($p = PatientService::find((int) $token['patient_id']))) {
                    $prefill['patient'] = PatientService::forClient($p);
                }
                $vit = DB::one('SELECT * FROM vitals WHERE token_id = ? ORDER BY id DESC LIMIT 1', [$tokenId]);
                if ($vit) {
                    $prefill['vitals'] = array_intersect_key($vit, VisitService::VITALS);
                    $notices[] = 'Vitals recorded by the nurse at ' . date('h:i A', strtotime($vit['recorded_at'])) . ' are filled in.';
                }
                if ($token['invoice_id'] && ($pre = VisitService::prepaidFee((int) $token['invoice_id']))) {
                    $prefill['prepaid'] = $pre + ['invoice_no' => (string) DB::value('SELECT invoice_no FROM invoices WHERE id = ?', [$token['invoice_id']])];
                }
                if (!$isDoctor && $token['doctor_id']) {
                    $prefill['doctor_id'] = (int) $token['doctor_id'];
                }
            }
        }

        if (($repeat = (int) input('repeat', 0)) > 0) {
            $visit = VisitService::load($repeat);
            $refill = $visit ? VisitService::refillData($repeat) : null;
            if (!$refill) {
                flash('error', 'Previous visit not found.');
                redirect('visits/new');
            }
            $prefill = array_merge($prefill, $refill);
            $prefill['patient'] = $visit['patient'] && !$visit['patient']['deleted_at'] ? PatientService::forClient($visit['patient']) : null;
            $prefill['repeated_from_visit_id'] = $repeat;
            $notices[] = 'Everything was repeated from visit ' . $visit['visit_no'] . ' (' . fmt_date($visit['visit_date']) . '), medicines at current prices. Change anything you need — the original prescription stays unchanged.';
            if ($refill['skipped']) {
                $notices[] = 'Not repeated (inactive or removed from the medicine list): ' . implode(', ', $refill['skipped']) . '.';
            }
        }

        if (($tid = (int) input('template_id', 0)) > 0 && $isDoctor) {
            $tpl = TemplateService::loadForScreen($tid, $user['id']);
            if ($tpl) {
                $prefill = array_merge($prefill, array_intersect_key($tpl, array_flip(['complaints', 'symptoms', 'diagnoses', 'investigations', 'medicines', 'overall_instructions', 'follow_up_date', 'vitals'])));
                $prefill['template_id'] = $tid;
                $notices[] = 'Template “' . $tpl['name'] . '” loaded. Changes you make here do not alter the template.';
                if ($tpl['skipped']) {
                    $notices[] = 'Skipped inactive medicines: ' . implode(', ', $tpl['skipped']) . '.';
                }
            }
        }

        $config = $this->screenConfig('visit', $prefill);
        $scripts = [];
        foreach ($config['modules'] as $m) {
            foreach ((array) ($m['scripts'] ?? []) as $s) {
                $scripts[] = $s;
            }
        }
        $this->view('visits/new', [
            'title'   => 'New Visit',
            // Module scripts load first so they register their collectors before checkout.
            'scripts' => array_merge(array_values(array_unique($scripts)), ['js/consult.js']),
            'notices' => $notices,
            'config'  => $config,
        ]);
    }

    /** Shared bootstrap data for the consultation and template editor screens. */
    public function screenConfig(string $mode, array $prefill): array
    {
        $user = Auth::user();
        $isDoctor = $user['role'] === 'doctor';
        $doctors = [];
        if (!$isDoctor) {
            foreach (DB::all("SELECT u.id, u.name, COALESCE(dp.default_fee, 0) AS default_fee FROM users u LEFT JOIN doctor_profiles dp ON dp.user_id = u.id
                               WHERE u.role = 'doctor' AND u.is_active = 1 AND u.deleted_at IS NULL ORDER BY u.name") as $d) {
                $doctors[] = ['id' => (int) $d['id'], 'name' => $d['name'], 'default_fee' => (float) $d['default_fee']];
            }
        }
        $mainSource = MedicineCatalog::mainSourceId();
        return [
            'mode'           => $mode,
            'token'          => bin2hex(random_bytes(16)),
            'trending'       => ClinicalItems::trending($isDoctor ? (int) $user['id'] : null),
            'doseChoices'    => Urdu::DOSE_CHOICES,
            'units'          => array_map(static fn ($u) => ['en' => $u[0], 'ur' => $u[1], 'factor' => $u[2]], Urdu::UNITS),
            'instructionsUr' => MedicineCatalog::instructionsUrdu(),
            'routesEn'       => Urdu::ROUTES_EN,
            'mainSourceId'   => $mainSource,
            'mainSourceName' => $mainSource ? (string) DB::value('SELECT name FROM medicine_sources WHERE id = ?', [$mainSource]) : '',
            'isDoctor'       => $isDoctor,
            'doctor'         => $isDoctor ? ['id' => $user['id'], 'name' => $user['name'], 'default_fee' => (float) ($user['default_fee'] ?? 0)] : null,
            'doctors'        => $doctors,
            'canEditPatient' => can('patients.edit'),
            'frequencies'    => array_values(array_map(static fn ($f) => [
                'id' => (int) $f['id'], 'name' => $f['name'], 'code' => $f['code'], 'doses_per_day' => (float) $f['doses_per_day'], 'calc_mode' => $f['calc_mode'],
                'ur' => $f['name_ur'] ?: (Urdu::FREQUENCIES[$f['code']] ?? ''),
            ], MedicineCatalog::frequencies())),
            'routes'         => array_values(array_map(static fn ($r) => ['id' => (int) $r['id'], 'name' => $r['name'], 'ur' => $r['name_ur'] ?: (Urdu::ROUTES[$r['name']] ?? '')], MedicineCatalog::routes())),
            'instructions'   => MedicineCatalog::instructions(),
            'extraVitals'    => VisitService::extraVitalLabels(),
            'templates'      => $isDoctor ? array_map(static fn ($t) => ['id' => (int) $t['id'], 'name' => $t['name'], 'fav' => (int) $t['is_favourite']], TemplateService::forDoctor($user['id'])) : [],
            'sourceOrder'    => MedicineCatalog::sourceOrder(),
            'settings'       => [
                'discount'         => setting('discount_enabled', '1') === '1',
                'tax'              => setting('tax_enabled', '0') === '1',
                'split'            => setting('rx_split_enabled', '1') === '1',
                'unassigned'       => (string) setting('rx_unassigned_label', 'General'),
                'followUpDays'     => (int) setting('follow_up_default_days', 7),
                'urdu'             => setting('rx_show_urdu', '1') === '1',
                'defaultPaper'     => (string) setting('default_paper', 'a4'),
            ],
            'papers'         => UserService::PAPERS,
            'prefill'        => $prefill,
            'departments'    => DB::pairs("SELECT id, name FROM departments WHERE deleted_at IS NULL AND is_active = 1 AND department_type = 'clinical' ORDER BY display_order, name"),
            'myDepartments'  => $isDoctor ? $user['department_ids'] : [],
            'visitTypes'     => DB::pairs('SELECT id, name FROM visit_types WHERE deleted_at IS NULL AND is_active = 1 ORDER BY display_order, name'),
            'cosign'         => \App\Core\Auth::requiresCosign(),
            'modules'        => $mode === 'visit' ? VisitHooks::screen(['prefill' => $prefill, 'user' => $user]) : [],
        ];
    }

    /** JSON checkout. */
    public function checkout(): void
    {
        $result = VisitService::checkout(json_body(), Auth::user());
        $this->json($result, $result['ok'] ? 200 : 422);
    }

    public function show(): void
    {
        $visit = VisitService::load($this->requireId());
        if (!$visit) {
            abort(404, 'Visit not found.');
        }
        $this->guard($visit);
        $this->view('visits/view', ['title' => 'Visit ' . $visit['visit_no'], 'v' => $visit, 'panels' => VisitHooks::panels($visit)]);
    }

    /**
     * Clinical records are scoped (own / department / branch). Outside the
     * scope only a logged break-glass grant opens them (Doc 1 §2.7).
     */
    private function guard(array $visit): void
    {
        if (Scope::visitAllowed($visit)) {
            return;
        }
        ActivityLog::record('Access denied', 'visits', (string) $visit['id'], 'Visit outside the user\'s scope');
        if (can('emergency.access')) {
            flash('warning', 'This record belongs to another doctor\'s patient. In an emergency you can open it with break-glass access (logged and reviewed).');
            redirect('emergency', ['patient_id' => $visit['patient_id']]);
        }
        abort(403);
    }

    /** Print preview / print / reprint in A4, A5, Thermal or Legal (BRD §49–53). */
    public function printout(): void
    {
        $visit = VisitService::load($this->requireId());
        if (!$visit) {
            abort(404, 'Visit not found.');
        }
        $this->guard($visit);
        $paper = (string) input('paper', setting('default_paper', 'a4'));
        if (!isset(UserService::PAPERS[$paper])) {
            $paper = 'a4';
        }
        $design = DB::one('SELECT * FROM print_designs WHERE doctor_id = ? AND paper = ?', [$visit['doctor_id'], $paper])
            ?? UserService::defaultDesign((int) $visit['doctor_id'], $paper, $visit['doctor_name'], $visit['doctor']);

        // Where the hospital slip goes: below a cut line on the same page, or on its own page.
        $mode = in_array($visit['rx_split_mode'], ['slip', 'separate_page'], true) ? $visit['rx_split_mode'] : (string) setting('rx_split_print_mode', 'slip');
        $this->view('visits/print', [
            'v'      => $visit,
            'paper'  => $paper,
            'design' => $design,
            'mode'   => $mode,
            'papers' => UserService::PAPERS,
        ], null);
    }

    /** Records a print / reprint (called by the Print button). */
    public function printed(): void
    {
        $body = json_body();
        $id = (int) ($body['id'] ?? 0);
        $paper = (string) ($body['paper'] ?? 'a4');
        if (!DB::value('SELECT 1 FROM visits WHERE id = ? AND deleted_at IS NULL', [$id])) {
            $this->json(['ok' => false, 'message' => 'Visit not found.'], 404);
        }
        VisitService::markPrinted($id, isset(UserService::PAPERS[$paper]) ? $paper : 'a4');
        $this->json(['ok' => true]);
    }

    public function delete(): void
    {
        $id = $this->requireId();
        $visit = DB::one('SELECT id, visit_no FROM visits WHERE id = ? AND deleted_at IS NULL', [$id]);
        if (!$visit) {
            abort(404);
        }
        DB::update('visits', ['deleted_at' => now(), 'deleted_by' => Auth::id()], 'id = ?', [$id]);
        ActivityLog::record('Visit deleted', 'visits', (string) $id, 'Moved to Recycle Bin: ' . $visit['visit_no']);
        flash('success', 'Visit ' . $visit['visit_no'] . ' deleted. It can be restored from the Recycle Bin.');
        redirect('visits');
    }

    public function medicines(): void
    {
        $this->json(['ok' => true, 'items' => MedicineCatalog::search((string) input('q', ''))]);
    }

    /** JSON: previous visits of a patient (popup grid on the consultation screen). */
    public function history(): void
    {
        $patientId = (int) input('patient_id', 0);
        $rows = array_map(static function (array $r): array {
            $r['date_text'] = fmt_datetime($r['visit_date']);
            $r['follow_up_text'] = fmt_date($r['follow_up_date']);
            $r['total_text'] = money($r['grand_total']);
            $r['bp'] = $r['bp_systolic'] && $r['bp_diastolic'] ? $r['bp_systolic'] . '/' . $r['bp_diastolic'] : '';
            return $r;
        }, $patientId > 0 ? VisitService::history($patientId) : []);
        $this->json(['ok' => true, 'items' => $rows]);
    }

    /** JSON: everything of a previous visit, to refill the consultation screen. */
    public function refill(): void
    {
        $data = VisitService::refillData((int) input('id', 0));
        if (!$data) {
            $this->json(['ok' => false, 'message' => 'Visit not found.'], 404);
        }
        $this->json(['ok' => true, 'visit' => $data]);
    }

    public function lookup(): void
    {
        $this->json(['ok' => true, 'items' => ClinicalItems::lookup((string) input('type', ''), (string) input('q', ''))]);
    }
}
