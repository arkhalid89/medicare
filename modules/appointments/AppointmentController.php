<?php
declare(strict_types=1);

namespace App\Modules\Appointments;

use App\Core\ActivityLog;
use App\Core\Auth;
use App\Core\Controller;
use App\Core\DB;
use App\Core\Scope;

final class AppointmentController extends Controller
{
    /** Day board: one doctor's slots + the day's list. */
    public function index(): void
    {
        $date = strtotime((string) input('date', '')) ? date('Y-m-d', strtotime((string) input('date'))) : today();
        $doctors = Scope::allDoctors();
        $doctorId = (int) input('doctor_id', 0);
        if (!$doctorId) {
            $doctorId = Auth::isDoctor() ? (int) Auth::id() : (int) (array_key_first($doctors) ?? 0);
        }
        $ownOnly = Auth::isDoctor() && !can('appointments.book') && !can('appointments.slots.manage');
        if ($ownOnly) {
            $doctorId = (int) Auth::id();
        }
        $this->view('appointments/index', [
            'title'      => 'Appointments',
            'date'       => $date,
            'doctors'    => $doctors,
            'doctorId'   => $doctorId,
            'slots'      => $doctorId ? AppointmentService::slots($doctorId, $date) : [],
            'day'        => DB::all(
                "SELECT a.*, p.name AS patient_name, p.mrn, p.phone, u.name AS doctor_name, vt.name AS visit_type FROM appointments a
                   JOIN patients p ON p.id = a.patient_id JOIN users u ON u.id = a.doctor_id LEFT JOIN visit_types vt ON vt.id = a.visit_type_id
                  WHERE a.scheduled_at BETWEEN ? AND ?" . ($ownOnly ? ' AND a.doctor_id = ' . (int) Auth::id() : '') . ' ORDER BY a.scheduled_at',
                [$date . ' 00:00:00', $date . ' 23:59:59']
            ),
            'visitTypes' => DB::pairs('SELECT id, name FROM visit_types WHERE deleted_at IS NULL AND is_active = 1 ORDER BY display_order'),
        ]);
    }

    public function book(): void
    {
        try {
            $id = AppointmentService::book((int) input('patient_id', 0), (int) input('doctor_id', 0), (string) input('start', ''),
                (int) input('visit_type_id', 0) ?: null, (string) input('source', 'reception'), (string) input('notes', ''));
            flash('success', 'Appointment booked.');
            ActivityLog::record('Appointment booked', 'appointments', (string) $id, '');
        } catch (\Throwable $e) {
            flash('error', $e->getMessage());
        }
        redirect('appointments', ['date' => substr((string) input('start', today()), 0, 10), 'doctor_id' => input('doctor_id')]);
    }

    public function update(): void
    {
        $id = $this->requireId();
        $a = DB::one('SELECT * FROM appointments WHERE id = ?', [$id]);
        if (!$a) {
            abort(404);
        }
        try {
            switch ((string) input('do', '')) {
                case 'cancel':
                    AppointmentService::cancel($id, trim((string) input('reason', '')) ?: 'Cancelled');
                    flash('success', 'Appointment cancelled.');
                    break;
                case 'reschedule':
                    AppointmentService::reschedule($id, (string) input('start', ''));
                    flash('success', 'Appointment rescheduled.');
                    break;
                case 'no_show':
                    AppointmentService::markNoShow($id);
                    flash('success', 'Marked as no-show.');
                    break;
                case 'checkin':
                    redirect('frontdesk', ['appointment_id' => $id]);
            }
        } catch (\Throwable $e) {
            flash('error', $e->getMessage());
        }
        redirect('appointments', ['date' => substr($a['scheduled_at'], 0, 10), 'doctor_id' => $a['doctor_id']]);
    }

    // -------------------------------------------------------- schedules

    public function schedules(): void
    {
        $doctorId = $this->scheduleDoctor();
        $this->view('appointments/schedules', [
            'title'     => 'Doctor Schedule',
            'doctorId'  => $doctorId,
            'doctors'   => can('appointments.slots.manage') ? Scope::allDoctors() : [Auth::id() => Auth::user()['name']],
            'rows'      => DB::all('SELECT * FROM doctor_schedules WHERE doctor_id = ? ORDER BY FIELD(day_of_week, 1,2,3,4,5,6,0), start_time', [$doctorId]),
        ]);
    }

    private function scheduleDoctor(): int
    {
        $doctorId = (int) input('doctor_id', 0) ?: (int) Auth::id();
        if ($doctorId !== Auth::id() && !can('appointments.slots.manage')) {
            abort(403);
        }
        if ($doctorId === Auth::id() && !can('appointments.schedule.own') && !can('appointments.slots.manage')) {
            abort(403);
        }
        return $doctorId;
    }

    public function saveSchedule(): void
    {
        $doctorId = $this->scheduleDoctor();
        if (input('delete')) {
            DB::run('DELETE FROM doctor_schedules WHERE id = ? AND doctor_id = ?', [(int) input('delete'), $doctorId]);
            flash('success', 'Schedule row removed.');
            redirect('appointments/schedules', ['doctor_id' => $doctorId]);
        }
        $days = array_map('intval', (array) ($_POST['days'] ?? []));
        $start = (string) input('start_time', '');
        $end = (string) input('end_time', '');
        $slot = max(5, min(120, (int) input('slot_minutes', 15)));
        if (!$days || !preg_match('/^\d{2}:\d{2}$/', $start) || !preg_match('/^\d{2}:\d{2}$/', $end) || $start >= $end) {
            flash('error', 'Choose the days and a valid start and end time.');
            redirect('appointments/schedules', ['doctor_id' => $doctorId]);
        }
        foreach ($days as $d) {
            if ($d < 0 || $d > 6) {
                continue;
            }
            $overlap = DB::value('SELECT 1 FROM doctor_schedules WHERE doctor_id = ? AND day_of_week = ? AND is_active = 1 AND start_time < ? AND end_time > ?',
                [$doctorId, $d, $end . ':00', $start . ':00']);
            if ($overlap) {
                flash('warning', AppointmentService::DAYS[$d] . ' overlaps an existing session and was skipped.');
                continue;
            }
            DB::insert('doctor_schedules', ['branch_id' => Auth::branchId(), 'doctor_id' => $doctorId,
                'department_id' => (int) DB::value('SELECT MIN(department_id) FROM user_departments WHERE user_id = ?', [$doctorId]) ?: null,
                'day_of_week' => $d, 'start_time' => $start . ':00', 'end_time' => $end . ':00', 'slot_minutes' => $slot, 'is_active' => 1,
                'created_at' => now(), 'created_by' => Auth::id()]);
        }
        ActivityLog::record('Schedule updated', 'appointments', (string) $doctorId, 'OPD sessions ' . $start . '–' . $end);
        flash('success', 'Schedule saved.');
        redirect('appointments/schedules', ['doctor_id' => $doctorId]);
    }

    // ----------------------------------------------------------- leaves

    public function leaves(): void
    {
        $approver = can('appointments.leave.approve');
        $where = $approver ? '1 = 1' : 'l.doctor_id = ' . (int) Auth::id();
        $rows = DB::all("SELECT l.*, u.name AS doctor_name, a.name AS approved_by_name FROM doctor_leaves l JOIN users u ON u.id = l.doctor_id
                          LEFT JOIN users a ON a.id = l.approved_by WHERE $where ORDER BY l.start_at DESC LIMIT 100");
        foreach ($rows as &$r) {
            $r['affected'] = $r['status'] === 'approved' && $r['end_at'] >= now() ? AppointmentService::affectedBy((int) $r['id']) : [];
        }
        $this->view('appointments/leaves', ['title' => 'Leave & Unavailability', 'rows' => $rows, 'approver' => $approver]);
    }

    public function requestLeave(): void
    {
        $start = strtotime((string) input('start_at', ''));
        $end = strtotime((string) input('end_at', ''));
        if (!$start || !$end || $end <= $start) {
            flash('error', 'Enter a valid start and end.');
            redirect('appointments/leaves');
        }
        $doctorId = can('appointments.leave.approve') && (int) input('doctor_id', 0) ? (int) input('doctor_id') : (int) Auth::id();
        $urgent = (bool) input('urgent') && can('appointments.leave.approve');
        $id = DB::insert('doctor_leaves', ['doctor_id' => $doctorId, 'start_at' => date('Y-m-d H:i:s', $start), 'end_at' => date('Y-m-d H:i:s', $end),
            'reason' => mb_substr((string) input('reason', ''), 0, 255) ?: null, 'status' => $urgent ? 'approved' : 'pending',
            'approved_by' => $urgent ? Auth::id() : null, 'approved_at' => $urgent ? now() : null, 'created_at' => now(), 'created_by' => Auth::id()]);
        ActivityLog::record('Leave requested', 'appointments', (string) $id, fmt_datetime(date('Y-m-d H:i:s', $start)) . ' → ' . fmt_datetime(date('Y-m-d H:i:s', $end)));
        flash('success', $urgent ? 'Unavailability recorded — call back the affected patients listed below.' : 'Leave request sent for approval.');
        redirect('appointments/leaves');
    }

    public function decideLeave(): void
    {
        $id = $this->requireId();
        $l = DB::one("SELECT * FROM doctor_leaves WHERE id = ? AND status = 'pending'", [$id]);
        if (!$l) {
            abort(404);
        }
        if ((int) $l['doctor_id'] === Auth::id()) {
            flash('error', 'You cannot approve your own leave.');
            redirect('appointments/leaves');
        }
        $approve = input('decision') === 'approve';
        DB::update('doctor_leaves', ['status' => $approve ? 'approved' : 'rejected', 'approved_by' => Auth::id(), 'approved_at' => now()], 'id = ?', [$id]);
        ActivityLog::record($approve ? 'Leave approved' : 'Leave rejected', 'appointments', (string) $id, '');
        flash('success', 'Leave ' . ($approve ? 'approved.' : 'rejected.'));
        redirect('appointments/leaves');
    }
}
