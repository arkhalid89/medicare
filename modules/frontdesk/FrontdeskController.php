<?php
declare(strict_types=1);

namespace App\Modules\Frontdesk;

use App\Core\ActivityLog;
use App\Core\Auth;
use App\Core\Controller;
use App\Core\DB;
use App\Core\Scope;
use App\Modules\Billing\BillingService;
use App\Modules\Patients\PatientService;
use App\Modules\Visits\VisitService;

/**
 * Front desk workspaces (Doc 8 §5.1): registration + token issue, token
 * console, nurse vitals / triage station, waiting-room display boards.
 */
final class FrontdeskController extends Controller
{
    /** Registration desk. */
    public function index(): void
    {
        $patient = null;
        if (($pid = (int) input('patient_id', 0)) > 0) {
            $patient = PatientService::find($pid);
        }
        $appointment = null;
        if (($aid = (int) input('appointment_id', 0)) > 0) {
            $appointment = DB::one("SELECT a.*, u.name AS doctor_name FROM appointments a JOIN users u ON u.id = a.doctor_id WHERE a.id = ? AND a.status = 'booked'", [$aid]);
            if ($appointment && !$patient) {
                $patient = PatientService::find((int) $appointment['patient_id']);
            }
        }
        $doctors = DB::all(
            "SELECT u.id, u.name, COALESCE(dp.default_fee, 0) AS fee, dp.specialization,
                    (SELECT GROUP_CONCAT(ud.department_id) FROM user_departments ud WHERE ud.user_id = u.id) AS dept_ids
               FROM users u LEFT JOIN doctor_profiles dp ON dp.user_id = u.id
              WHERE u.role = 'doctor' AND u.is_active = 1 AND u.deleted_at IS NULL ORDER BY u.name"
        );
        $onLeave = array_map('intval', DB::column("SELECT doctor_id FROM doctor_leaves WHERE status = 'approved' AND start_at <= ? AND end_at >= ?", [now(), now()]));
        $this->view('frontdesk/registration', [
            'title'       => 'Registration Desk',
            'patient'     => $patient,
            'appointment' => $appointment,
            'departments' => DB::pairs("SELECT id, name FROM departments WHERE deleted_at IS NULL AND is_active = 1 AND department_type = 'clinical' ORDER BY display_order, name"),
            'doctors'     => $doctors,
            'onLeave'     => $onLeave,
            'visitTypes'  => DB::pairs('SELECT id, name FROM visit_types WHERE deleted_at IS NULL AND is_active = 1 ORDER BY display_order, name'),
            'queues'      => TokenService::summary(),
            'recent'      => DB::all(
                'SELECT t.id, t.token_label, t.priority, t.status, t.issued_at, p.name, p.mrn, q.name AS queue_name, i.invoice_no, i.net_amount, i.waived
                   FROM tokens t JOIN patients p ON p.id = t.patient_id JOIN token_queues q ON q.id = t.queue_id LEFT JOIN invoices i ON i.id = t.invoice_id
                  WHERE t.issue_date = ? AND t.issued_by = ? ORDER BY t.id DESC LIMIT 12',
                [today(), Auth::id()]
            ),
            'priority'    => (string) input('priority', $appointment ? 'normal' : 'normal'),
        ]);
    }

    /** JSON fee preview for the selected doctor / visit type / patient. */
    public function fee(): void
    {
        $doctorId = (int) input('doctor_id', 0);
        if ($doctorId <= 0) {
            $this->json(['ok' => false]);
        }
        $patientId = (int) input('patient_id', 0) ?: null;
        $fee = BillingService::consultationFee($doctorId, (int) input('visit_type_id', 0) ?: null, $patientId);
        $lines = [['label' => $fee['label'], 'amount' => $fee['amount']]];
        $card = self::registrationCard($patientId);
        if ($card) {
            $lines[] = ['label' => $card['name'], 'amount' => $card['price']];
        }
        $waiting = (int) DB::value("SELECT COUNT(*) FROM tokens t JOIN token_queues q ON q.id = t.queue_id WHERE q.doctor_id = ? AND t.issue_date = ? AND t.status = 'waiting'", [$doctorId, today()]);
        $this->json(['ok' => true, 'lines' => $lines, 'total' => array_sum(array_column($lines, 'amount')), 'free_follow_up' => $fee['free_follow_up'], 'waiting' => $waiting]);
    }

    /** Registration card fee for a patient registered today (setting registration_fee_new). */
    private static function registrationCard(?int $patientId): ?array
    {
        if (setting('registration_fee_new', '1') !== '1') {
            return null;
        }
        if ($patientId && DB::value('SELECT 1 FROM tokens WHERE patient_id = ? LIMIT 1', [$patientId])) {
            return null;
        }
        if ($patientId && DB::value('SELECT 1 FROM visits WHERE patient_id = ? AND deleted_at IS NULL LIMIT 1', [$patientId])) {
            return null;
        }
        $svc = DB::one("SELECT id, name FROM service_catalog WHERE code = 'REG-CARD' AND is_active = 1 AND deleted_at IS NULL");
        if (!$svc) {
            return null;
        }
        $price = BillingService::price((int) $svc['id']);
        return $price > 0 ? ['id' => (int) $svc['id'], 'name' => $svc['name'], 'price' => $price] : null;
    }

    /**
     * Registers the visit at the desk: invoice (card + consultation) →
     * payment or authorised waiver → token. A token is never issued before the
     * receipt or the waiver exists (Doc 3 WF 1 acceptance criteria).
     */
    public function issue(): void
    {
        $patientId = (int) input('patient_id', 0);
        $doctorId = (int) input('doctor_id', 0);
        $visitTypeId = (int) input('visit_type_id', 0) ?: null;
        $priority = (string) input('priority', 'normal');
        $mode = (string) input('payment_mode', 'cash');
        $waive = (bool) input('waive');
        $waiveReason = trim((string) input('waive_reason', ''));
        $appointmentId = (int) input('appointment_id', 0) ?: null;
        $back = static fn () => redirect('frontdesk', array_filter(['patient_id' => $patientId, 'appointment_id' => $appointmentId]));

        $patient = $patientId ? PatientService::find($patientId) : null;
        $doctor = DB::one("SELECT u.id, u.name FROM users u WHERE u.id = ? AND u.role = 'doctor' AND u.is_active = 1 AND u.deleted_at IS NULL", [$doctorId]);
        if (!$patient || !$doctor) {
            flash('error', 'Select the patient and the doctor.');
            $back();
        }
        if ($waive && (!can('frontdesk.fee.waive') || $waiveReason === '')) {
            flash('error', can('frontdesk.fee.waive') ? 'Enter the reason for the fee waiver.' : 'You are not allowed to waive fees. Ask a supervisor.');
            $back();
        }
        $deptId = (int) input('department_id', 0) ?: ((int) DB::value('SELECT MIN(department_id) FROM user_departments WHERE user_id = ?', [$doctorId]) ?: null);
        $fee = BillingService::consultationFee($doctorId, $visitTypeId, $patientId);
        $card = self::registrationCard($patientId);
        $collect = !$waive && input('collect', '1') === '1';

        try {
            $token = DB::transaction(static function () use ($patient, $doctor, $doctorId, $deptId, $visitTypeId, $fee, $card, $collect, $mode, $waive, $waiveReason, $priority, $appointmentId): array {
                $items = [['description' => $fee['label'], 'service_id' => $fee['service_id'], 'quantity' => 1, 'unit_price' => $fee['amount'],
                    'source_module' => 'registration', 'source_reference_type' => 'consultation']];
                if ($card) {
                    $items[] = ['description' => $card['name'], 'service_id' => $card['id'], 'quantity' => 1, 'unit_price' => $card['price'], 'source_module' => 'registration'];
                }
                $invoiceId = BillingService::create(['patient_id' => $patient['id'], 'doctor_id' => $doctorId, 'department_id' => $deptId, 'invoice_type' => 'opd'], $items);
                $inv = DB::one('SELECT * FROM invoices WHERE id = ?', [$invoiceId]);
                if ($waive) {
                    BillingService::waive($invoiceId, $waiveReason);
                } elseif ($collect && (float) $inv['net_amount'] > 0) {
                    BillingService::collect($invoiceId, (float) $inv['net_amount'], $mode, (string) input('payment_ref', '') ?: null);
                }
                $inv = DB::one('SELECT * FROM invoices WHERE id = ?', [$invoiceId]);
                if (setting('token_requires_payment', '1') === '1' && $inv['status'] !== 'settled') {
                    throw new \RuntimeException('A token cannot be issued before the fee is paid or waived by an authorised person.');
                }
                $queueId = TokenService::queueForDoctor($doctorId, $deptId);
                $token = TokenService::issue($queueId, (int) $patient['id'], ['priority' => $priority, 'invoice_id' => $invoiceId,
                    'visit_type_id' => $visitTypeId, 'appointment_id' => $appointmentId]);
                DB::update('invoices', ['token_id' => $token['id']], 'id = ?', [$invoiceId]);
                if ($appointmentId) {
                    DB::update('appointments', ['status' => 'checked_in', 'checked_in_at' => now(), 'token_id' => $token['id']], 'id = ?', [$appointmentId]);
                }
                ActivityLog::record('Token issued', 'frontdesk', (string) $token['id'], $token['token_label'] . ' for ' . $patient['name'] . ' → ' . $doctor['name']
                    . ' · ' . $inv['invoice_no'] . ($waive ? ' (fee waived)' : ''));
                return $token;
            });
        } catch (\RuntimeException $e) {
            flash('error', $e->getMessage());
            $back();
        }
        flash('success', 'Token ' . $token['token_label'] . ' issued for ' . $patient['name'] . '.');
        redirect('frontdesk', ['print' => $token['id']]);
    }

    /** Thermal token slip + receipt (Urdu / English). */
    public function printToken(): void
    {
        $t = DB::one(
            'SELECT t.*, p.name AS patient_name, p.mrn, p.gender, p.dob, q.name AS queue_name, u.name AS doctor_name, sp.name AS room
               FROM tokens t JOIN patients p ON p.id = t.patient_id JOIN token_queues q ON q.id = t.queue_id
               LEFT JOIN users u ON u.id = q.doctor_id LEFT JOIN service_points sp ON sp.id = q.service_point_id WHERE t.id = ?',
            [$this->requireId()]
        );
        if (!$t) {
            abort(404);
        }
        $ahead = (int) DB::value("SELECT COUNT(*) FROM tokens WHERE queue_id = ? AND issue_date = ? AND status = 'waiting' AND id < ?", [$t['queue_id'], $t['issue_date'], $t['id']]);
        $avg = (int) DB::value('SELECT avg_service_minutes FROM token_queues WHERE id = ?', [$t['queue_id']]);
        $this->view('frontdesk/token_print', [
            'title' => 'Token ' . $t['token_label'],
            't' => $t,
            'invoice' => $t['invoice_id'] ? BillingService::find((int) $t['invoice_id']) : null,
            'ahead' => $ahead,
            'wait' => $ahead * max(1, $avg),
        ], null);
    }

    // ------------------------------------------------------------ console

    public function console(): void
    {
        $queues = TokenService::summary();
        $queueId = (int) input('queue_id', 0);
        if (!$queueId && $queues) {
            $mine = array_values(array_filter($queues, static fn ($q) => (int) $q['doctor_id'] === Auth::id()));
            $queueId = (int) ($mine[0]['id'] ?? $queues[0]['id']);
        }
        $this->view('frontdesk/console', [
            'title'   => 'Token Console',
            'queues'  => $queues,
            'queueId' => $queueId,
            'tokens'  => $queueId ? TokenService::today($queueId) : [],
            'points'  => DB::pairs('SELECT id, name FROM service_points WHERE deleted_at IS NULL AND is_active = 1 ORDER BY display_order, name'),
        ]);
    }

    /** JSON token actions: call_next, call, start, skip, requeue, cancel, transfer, complete. */
    public function action(): void
    {
        $b = json_body() + $_POST;
        $action = (string) ($b['action'] ?? '');
        $tokenId = (int) ($b['token_id'] ?? 0);
        $point = (int) ($b['service_point_id'] ?? 0) ?: null;
        try {
            if ($tokenId) {
                $this->authoriseToken($tokenId);
            }
            switch ($action) {
                case 'call_next':
                    $queueId = (int) ($b['queue_id'] ?? 0);
                    $this->authoriseQueue($queueId);
                    $t = TokenService::callNext($queueId, $point);
                    $this->json($t ? ['ok' => true, 'token' => $t, 'message' => 'Calling ' . $t['token_label']] : ['ok' => false, 'message' => 'No one is waiting in this queue.']);
                    break;
                case 'call':
                    $t = TokenService::call($tokenId, $point);
                    $this->json(['ok' => true, 'token' => $t, 'message' => 'Calling ' . $t['token_label']]);
                    break;
                case 'start':
                    TokenService::start($tokenId);
                    break;
                case 'skip':
                    TokenService::skip($tokenId);
                    break;
                case 'requeue':
                    TokenService::requeue($tokenId);
                    break;
                case 'cancel':
                    TokenService::cancel($tokenId, (string) ($b['reason'] ?? ''));
                    break;
                case 'complete':
                    TokenService::complete($tokenId);
                    break;
                case 'transfer':
                    $new = TokenService::transfer($tokenId, (int) ($b['to_queue_id'] ?? 0), (string) ($b['note'] ?? ''));
                    $this->json(['ok' => true, 'token' => $new, 'message' => 'Transferred as ' . $new['token_label']]);
                    break;
                default:
                    $this->json(['ok' => false, 'message' => 'Unknown action.'], 422);
            }
            $this->json(['ok' => true]);
        } catch (\RuntimeException $e) {
            $this->json(['ok' => false, 'message' => $e->getMessage()], 422);
        }
    }

    /** Doctors may act only on their own queue; desk operators on any. */
    private function authoriseQueue(int $queueId): void
    {
        $q = DB::one('SELECT doctor_id FROM token_queues WHERE id = ?', [$queueId]);
        if (!$q) {
            throw new \RuntimeException('Queue not found.');
        }
        if (Auth::isDoctor() && $q['doctor_id'] && (int) $q['doctor_id'] !== Auth::id() && !Auth::hasRole('TKO', 'RC', 'RGS', 'HA', 'SA', 'CN')) {
            throw new \RuntimeException('This is another doctor\'s queue.');
        }
    }

    private function authoriseToken(int $tokenId): void
    {
        $this->authoriseQueue((int) DB::value('SELECT queue_id FROM tokens WHERE id = ?', [$tokenId]));
    }

    /** JSON for polling consoles and the doctor queue. */
    public function queueData(): void
    {
        $queueId = (int) input('queue_id', 0);
        $rows = array_map(static function ($t) {
            return ['id' => (int) $t['id'], 'label' => $t['token_label'], 'patient' => $t['patient_name'], 'mrn' => $t['mrn'], 'status' => $t['status'],
                'priority' => $t['priority'], 'issued' => date('h:i A', strtotime($t['issued_at'])), 'wait' => $t['status'] === 'waiting' ? (int) round((time() - strtotime($t['issued_at'])) / 60) : null,
                'age' => age_text($t['dob']), 'gender' => $t['gender'], 'room' => $t['service_point_name']];
        }, $queueId ? TokenService::today($queueId) : []);
        $this->json(['ok' => true, 'tokens' => $rows, 'summary' => TokenService::summary()]);
    }

    // ------------------------------------------------------------- vitals

    public function vitals(): void
    {
        $params = [today()];
        $where = "t.issue_date = ? AND t.status IN ('waiting','called')";
        $depts = Auth::departmentIdsFor('vitals.record');
        if (Auth::scope('vitals.record') === 'department' && $depts) {
            $where .= ' AND q.department_id IN (' . DB::placeholders($depts) . ')';
            array_push($params, ...$depts);
        }
        $tokens = DB::all(
            "SELECT t.id, t.token_label, t.priority, t.status, t.issued_at, p.id AS patient_id, p.name, p.mrn, p.gender, p.dob, p.allergies, q.name AS queue_name,
                    (SELECT v.id FROM vitals v WHERE v.token_id = t.id ORDER BY v.id DESC LIMIT 1) AS vitals_id
               FROM tokens t JOIN patients p ON p.id = t.patient_id JOIN token_queues q ON q.id = t.queue_id
              WHERE $where ORDER BY t.issued_at",
            $params
        );
        $selected = null;
        $last = null;
        if (($tid = (int) input('token_id', 0)) > 0) {
            foreach ($tokens as $t) {
                if ((int) $t['id'] === $tid) {
                    $selected = $t;
                }
            }
            if ($selected) {
                $last = DB::one('SELECT * FROM vitals WHERE token_id = ? ORDER BY id DESC LIMIT 1', [$tid]);
            }
        }
        $this->view('frontdesk/vitals', ['title' => 'Vitals & Triage', 'tokens' => $tokens, 'selected' => $selected, 'last' => $last,
            'extra' => VisitService::extraVitalLabels()]);
    }

    public function saveVitals(): void
    {
        $tokenId = (int) input('token_id', 0);
        $t = DB::one('SELECT * FROM tokens WHERE id = ?', [$tokenId]);
        if (!$t) {
            abort(404);
        }
        $in = $_POST;
        $in['other'] = (array) ($_POST['other'] ?? []);
        [$vitals, $errors, $warnings] = VisitService::validateVitals($in);
        if ($errors) {
            flash('error', implode(' ', $errors));
            redirect('frontdesk/vitals', ['token_id' => $tokenId]);
        }
        $triage = (string) input('triage_level', '');
        DB::insert('vitals', $vitals + [
            'branch_id' => Auth::branchId(), 'patient_id' => $t['patient_id'], 'token_id' => $tokenId,
            'triage_level' => in_array($triage, ['red', 'orange', 'yellow', 'green', 'blue'], true) ? $triage : null,
            'notes' => mb_substr(trim((string) input('notes', '')), 0, 255) ?: null, 'recorded_by' => Auth::id(), 'recorded_at' => now(),
        ]);
        if ($triage === 'red' && $t['priority'] !== 'emergency') {
            DB::update('tokens', ['priority' => 'emergency'], 'id = ?', [$tokenId]);
            TokenService::event($tokenId, 'priority', null, null, null, 'Triage red → emergency');
        }
        ActivityLog::record('Vitals recorded', 'vitals', (string) $tokenId, 'Vitals for token ' . $t['token_label']);
        flash($warnings ? 'warning' : 'success', $warnings ? 'Vitals saved with unusual values: ' . implode(' ', $warnings) : 'Vitals saved — the doctor sees them in the consultation.');
        redirect('frontdesk/vitals');
    }

    // ------------------------------------------------------- display boards

    public function boards(): void
    {
        $this->view('frontdesk/boards', [
            'title'  => 'Display Boards',
            'boards' => DB::all('SELECT * FROM display_boards WHERE deleted_at IS NULL ORDER BY display_order, name'),
            'queues' => DB::pairs('SELECT id, name FROM token_queues WHERE deleted_at IS NULL AND is_active = 1 ORDER BY display_order, name'),
            'links'  => DB::all('SELECT * FROM display_board_queues'),
        ]);
    }

    public function saveBoard(): void
    {
        $id = (int) input('id', 0);
        $name = trim((string) input('name', ''));
        if ($name === '') {
            flash('error', 'Board name is required.');
            redirect('frontdesk/boards');
        }
        $data = ['name' => mb_substr($name, 0, 150), 'location_text' => mb_substr((string) input('location_text', ''), 0, 150) ?: null,
            'refresh_seconds' => max(3, min(60, (int) input('refresh_seconds', 5))), 'show_urdu' => input('show_urdu') ? 1 : 0, 'is_active' => input('is_active') ? 1 : 0];
        if ($id) {
            DB::update('display_boards', $data + ['updated_at' => now(), 'updated_by' => Auth::id()], 'id = ?', [$id]);
        } else {
            $id = DB::insert('display_boards', $data + ['branch_id' => Auth::branchId(), 'access_key' => bin2hex(random_bytes(16)), 'created_at' => now(), 'created_by' => Auth::id()]);
        }
        DB::run('DELETE FROM display_board_queues WHERE display_board_id = ?', [$id]);
        foreach (array_values(array_map('intval', (array) ($_POST['queues'] ?? []))) as $i => $q) {
            DB::insert('display_board_queues', ['display_board_id' => $id, 'token_queue_id' => $q, 'display_order' => $i]);
        }
        if (input('new_key')) {
            DB::update('display_boards', ['access_key' => bin2hex(random_bytes(16))], 'id = ?', [$id]);
        }
        ActivityLog::record('Display board saved', 'frontdesk', (string) $id, $name);
        flash('success', 'Display board saved.');
        redirect('frontdesk/boards');
    }

    /** Waiting-room screen: no login, the board's secret key is the access token. */
    public function board(): void
    {
        $b = $this->boardByKey();
        $this->view('frontdesk/board', ['title' => $b['name'], 'b' => $b, 'data' => TokenService::board((int) $b['id'])], null);
    }

    public function boardData(): void
    {
        $b = $this->boardByKey();
        $this->json(['ok' => true, 'time' => date('h:i A'), 'queues' => TokenService::board((int) $b['id'])]);
    }

    private function boardByKey(): array
    {
        $key = (string) input('key', '');
        $b = preg_match('/^[a-f0-9]{32}$/', $key) ? DB::one('SELECT * FROM display_boards WHERE access_key = ? AND deleted_at IS NULL AND is_active = 1', [$key]) : null;
        if (!$b) {
            abort(404, 'Display board not found. Check the link in Front Desk → Display Boards.');
        }
        return $b;
    }
}
