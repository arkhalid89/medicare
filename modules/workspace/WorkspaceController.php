<?php
declare(strict_types=1);

namespace App\Modules\Workspace;

use App\Core\ActivityLog;
use App\Core\Auth;
use App\Core\Controller;
use App\Core\DB;
use App\Core\Scope;
use App\Modules\Frontdesk\TokenService;
use App\Modules\Patients\PatientService;

/**
 * Shared workspace pieces (Doc 8 §4): branch / department switcher, the
 * doctor's OPD queue, break-glass access and the resident co-sign queue.
 */
final class WorkspaceController extends Controller
{
    public function context(): void
    {
        $ctx = Auth::contexts();
        $branch = (int) input('branch_id', 0);
        $dept = (int) input('department_id', 0);
        Auth::setContext(isset($ctx['branches'][$branch]) ? $branch : null, isset($ctx['departments'][$dept]) ? $dept : null);
        ActivityLog::record('Context switched', 'auth', null, 'Branch ' . ($ctx['branches'][$branch] ?? '-') . ' / ' . ($ctx['departments'][$dept] ?? 'all departments'));
        back(Auth::homeRoute());
    }

    /** The doctor's own queue: call next, see vitals, start the consultation. */
    public function consult(): void
    {
        $doctorId = (int) Auth::id();
        if (!Auth::isDoctor()) {
            $doctorId = (int) input('doctor_id', 0);
        }
        $queueId = $doctorId ? (int) (DB::value("SELECT id FROM token_queues WHERE doctor_id = ? AND queue_type = 'consultation' AND deleted_at IS NULL AND is_active = 1 ORDER BY id LIMIT 1", [$doctorId]) ?: 0) : 0;
        $tokens = $queueId ? TokenService::today($queueId) : [];
        foreach ($tokens as &$t) {
            $t['vitals'] = DB::one('SELECT * FROM vitals WHERE token_id = ? ORDER BY id DESC LIMIT 1', [$t['id']]);
        }
        $this->view('workspace/consult', [
            'title'    => 'My OPD Queue',
            'queueId'  => $queueId,
            'tokens'   => $tokens,
            'doctors'  => Auth::isDoctor() ? [] : Scope::allDoctors(),
            'doctorId' => $doctorId,
            'points'   => DB::pairs("SELECT id, name FROM service_points WHERE deleted_at IS NULL AND is_active = 1 AND type IN ('consultation_room','dental_chair') ORDER BY display_order, name"),
            'appointments' => $doctorId ? DB::all("SELECT a.scheduled_at, a.status, p.name, p.mrn FROM appointments a JOIN patients p ON p.id = a.patient_id
                                                     WHERE a.doctor_id = ? AND a.scheduled_at BETWEEN ? AND ? AND a.status <> 'cancelled' ORDER BY a.scheduled_at",
                [$doctorId, today() . ' 00:00:00', today() . ' 23:59:59']) : [],
        ]);
    }

    /** Open the consultation for a called token (marks it "in room"). */
    public function startConsult(): void
    {
        $tokenId = $this->requireId();
        $t = TokenService::find($tokenId);
        if (Auth::isDoctor() && $t['doctor_id'] && (int) $t['doctor_id'] !== Auth::id()) {
            abort(403);
        }
        TokenService::start($tokenId);
        redirect('visits/new', ['patient_id' => $t['patient_id'], 'token_id' => $tokenId]);
    }

    // --------------------------------------------------------- break-glass

    public function emergency(): void
    {
        $reviewer = can('emergency.review');
        $where = $reviewer ? '1 = 1' : 'g.user_id = ' . (int) Auth::id();
        $this->view('workspace/emergency', [
            'title'    => 'Emergency Access',
            'reviewer' => $reviewer,
            'rows'     => DB::all("SELECT g.*, u.name AS user_name, p.name AS patient_name, p.mrn, r.name AS reviewed_by_name FROM emergency_access_grants g
                                    JOIN users u ON u.id = g.user_id JOIN patients p ON p.id = g.patient_id LEFT JOIN users r ON r.id = g.reviewed_by
                                   WHERE $where ORDER BY g.reviewed_at IS NULL DESC, g.id DESC LIMIT 200"),
            'patient'  => ($pid = (int) input('patient_id', 0)) ? PatientService::find($pid) : null,
        ]);
    }

    /** Break-glass: time-boxed (4 h), reason mandatory, always reviewed. */
    public function grant(): void
    {
        $pid = (int) input('patient_id', 0);
        $reason = trim((string) input('reason', ''));
        $patient = PatientService::find($pid);
        if (!$patient || mb_strlen($reason) < 10) {
            flash('error', 'Select the patient and give a clear reason (at least 10 characters).');
            redirect('emergency', ['patient_id' => $pid]);
        }
        $id = DB::insert('emergency_access_grants', ['user_id' => Auth::id(), 'patient_id' => $pid, 'reason' => mb_substr($reason, 0, 500),
            'granted_at' => now(), 'expires_at' => date('Y-m-d H:i:s', time() + 4 * 3600)]);
        ActivityLog::record('Break-glass access', 'emergency', (string) $id, 'Emergency access to ' . $patient['name'] . ' (' . $patient['mrn'] . '): ' . $reason,
            null, null, ['entity' => 'patients', 'new' => ['patient_id' => $pid, 'reason' => $reason, 'hours' => 4]]);
        flash('warning', 'Emergency access granted for 4 hours. This access is logged and will be reviewed.');
        redirect('patients/view', ['id' => $pid]);
    }

    public function review(): void
    {
        $id = $this->requireId();
        DB::update('emergency_access_grants', ['reviewed_by' => Auth::id(), 'reviewed_at' => now(),
            'review_note' => mb_substr((string) input('note', ''), 0, 500) ?: 'Reviewed'], 'id = ? AND reviewed_at IS NULL', [$id]);
        ActivityLog::record('Break-glass reviewed', 'emergency', (string) $id, (string) input('note', ''));
        flash('success', 'Review recorded.');
        redirect('emergency');
    }

    // ------------------------------------------------------------- co-sign

    public function cosign(): void
    {
        $params = [];
        $scope = Scope::sql('v.doctor_id', $params, 'clinical.cosign');
        $this->view('workspace/cosign', [
            'title' => 'Co-sign Queue',
            'rows'  => DB::all("SELECT v.id, v.visit_no, v.visit_date, p.name AS patient_name, p.mrn, u.name AS doctor_name,
                                       (SELECT GROUP_CONCAT(i.name SEPARATOR ', ') FROM visit_clinical_items i WHERE i.visit_id = v.id AND i.item_type = 'diagnosis') AS diagnoses
                                  FROM visits v JOIN patients p ON p.id = v.patient_id JOIN users u ON u.id = v.doctor_id
                                 WHERE v.cosign_required = 1 AND v.cosigned_by IS NULL AND v.deleted_at IS NULL" . $scope . ' ORDER BY v.visit_date', $params),
        ]);
    }

    public function doCosign(): void
    {
        $id = $this->requireId();
        $v = DB::one('SELECT id, visit_no, doctor_id FROM visits WHERE id = ? AND cosign_required = 1 AND cosigned_by IS NULL', [$id]);
        if (!$v) {
            abort(404);
        }
        if ((int) $v['doctor_id'] === Auth::id()) {
            flash('error', 'You cannot co-sign your own entry.');
            redirect('clinical/cosign');
        }
        DB::update('visits', ['cosigned_by' => Auth::id(), 'cosigned_at' => now()], 'id = ?', [$id]);
        foreach (['dental_tooth_findings', 'antenatal_visits', 'pregnancy_deliveries'] as $table) {
            try {
                if ($table === 'dental_tooth_findings') {
                    DB::run('UPDATE dental_tooth_findings f JOIN dental_charts c ON c.id = f.dental_chart_id SET f.cosigned_by = ?, f.cosigned_at = ? WHERE c.encounter_id = ? AND f.cosigned_by IS NULL', [Auth::id(), now(), $id]);
                }
            } catch (\PDOException $e) {
                // module not installed
            }
        }
        ActivityLog::record('Visit co-signed', 'visits', (string) $id, $v['visit_no'] . ' co-signed');
        flash('success', 'Visit ' . $v['visit_no'] . ' co-signed.');
        redirect('clinical/cosign');
    }
}
