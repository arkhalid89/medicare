<?php
declare(strict_types=1);

namespace App\Modules\Appointments;

use App\Core\ActivityLog;
use App\Core\Auth;
use App\Core\DB;

/**
 * Doctor scheduling & OPD appointments (Doc 2 §5.2, Doc 3 WF 9):
 * weekly schedules generate slots; approved leave blocks them (the front desk
 * sees only "unavailable", never the reason); bookings take one slot each.
 */
final class AppointmentService
{
    public const DAYS = [1 => 'Monday', 2 => 'Tuesday', 3 => 'Wednesday', 4 => 'Thursday', 5 => 'Friday', 6 => 'Saturday', 0 => 'Sunday'];

    /**
     * Slots of a doctor on a date.
     * @return array<int, array{start:string, end:string, status:string, appointment:?array}>
     */
    public static function slots(int $doctorId, string $date): array
    {
        $dow = (int) date('w', strtotime($date));
        $schedules = DB::all('SELECT * FROM doctor_schedules WHERE doctor_id = ? AND day_of_week = ? AND is_active = 1 ORDER BY start_time', [$doctorId, $dow]);
        $booked = [];
        foreach (DB::all(
            "SELECT a.*, p.name AS patient_name, p.mrn, p.phone FROM appointments a JOIN patients p ON p.id = a.patient_id
              WHERE a.doctor_id = ? AND a.scheduled_at BETWEEN ? AND ? AND a.status <> 'cancelled'",
            [$doctorId, $date . ' 00:00:00', $date . ' 23:59:59']
        ) as $a) {
            $booked[substr($a['scheduled_at'], 11, 5)] = $a;
        }
        $leaves = DB::all("SELECT start_at, end_at FROM doctor_leaves WHERE doctor_id = ? AND status = 'approved' AND start_at <= ? AND end_at >= ?",
            [$doctorId, $date . ' 23:59:59', $date . ' 00:00:00']);
        $out = [];
        foreach ($schedules as $s) {
            $step = max(5, (int) $s['slot_minutes']) * 60;
            for ($t = strtotime($date . ' ' . $s['start_time']); $t + $step <= strtotime($date . ' ' . $s['end_time']); $t += $step) {
                $hm = date('H:i', $t);
                $start = date('Y-m-d H:i:s', $t);
                $status = 'open';
                foreach ($leaves as $l) {
                    if ($start >= $l['start_at'] && $start < $l['end_at']) {
                        $status = 'blocked';
                    }
                }
                if (isset($booked[$hm])) {
                    $status = 'booked';
                } elseif ($t < time()) {
                    $status = $status === 'open' ? 'past' : $status;
                }
                $out[] = ['start' => $start, 'end' => date('Y-m-d H:i:s', $t + $step), 'status' => $status, 'appointment' => $booked[$hm] ?? null];
                unset($booked[$hm]);
            }
        }
        // Appointments outside the schedule (e.g. booked before a schedule change) stay visible.
        foreach ($booked as $a) {
            $out[] = ['start' => $a['scheduled_at'], 'end' => $a['scheduled_at'], 'status' => 'booked', 'appointment' => $a];
        }
        usort($out, static fn ($a, $b) => strcmp($a['start'], $b['start']));
        return $out;
    }

    public static function book(int $patientId, int $doctorId, string $start, ?int $visitTypeId, string $source = 'reception', string $notes = ''): int
    {
        $start = date('Y-m-d H:i:s', strtotime($start));
        $slot = null;
        foreach (self::slots($doctorId, substr($start, 0, 10)) as $s) {
            if ($s['start'] === $start) {
                $slot = $s;
            }
        }
        if (!$slot) {
            throw new \RuntimeException('That time is not in the doctor\'s published schedule.');
        }
        if ($slot['status'] !== 'open') {
            throw new \RuntimeException($slot['status'] === 'blocked' ? 'The doctor is unavailable at that time.' : 'That slot is not available.');
        }
        return DB::transaction(static function () use ($patientId, $doctorId, $start, $slot, $visitTypeId, $source, $notes): int {
            DB::run('INSERT IGNORE INTO appointment_slots (branch_id, doctor_id, slot_start, slot_end, status) VALUES (?, ?, ?, ?, ?)',
                [Auth::branchId(), $doctorId, $start, $slot['end'], 'open']);
            $s = DB::one('SELECT * FROM appointment_slots WHERE doctor_id = ? AND slot_start = ? FOR UPDATE', [$doctorId, $start]);
            if ($s['status'] === 'booked' && $s['appointment_id'] && DB::value("SELECT 1 FROM appointments WHERE id = ? AND status <> 'cancelled'", [$s['appointment_id']])) {
                throw new \RuntimeException('That slot was just booked by someone else.');
            }
            $dept = (int) DB::value('SELECT MIN(department_id) FROM user_departments WHERE user_id = ?', [$doctorId]) ?: null;
            $id = DB::insert('appointments', [
                'branch_id' => Auth::branchId(), 'patient_id' => $patientId, 'doctor_id' => $doctorId, 'department_id' => $dept, 'slot_id' => $s['id'],
                'visit_type_id' => $visitTypeId, 'scheduled_at' => $start, 'status' => 'booked',
                'source' => in_array($source, ['walk_in', 'phone', 'portal', 'reception'], true) ? $source : 'reception',
                'notes' => mb_substr($notes, 0, 255) ?: null, 'booked_by' => Auth::id(), 'created_at' => now(),
            ]);
            DB::update('appointment_slots', ['status' => 'booked', 'appointment_id' => $id], 'id = ?', [$s['id']]);
            ActivityLog::record('Appointment booked', 'appointments', (string) $id, 'Patient #' . $patientId . ' with doctor #' . $doctorId . ' at ' . fmt_datetime($start));
            return $id;
        });
    }

    public static function cancel(int $id, string $reason): void
    {
        $a = DB::one("SELECT * FROM appointments WHERE id = ? AND status = 'booked'", [$id]);
        if (!$a) {
            throw new \RuntimeException('Only booked appointments can be cancelled.');
        }
        DB::update('appointments', ['status' => 'cancelled', 'cancelled_at' => now(), 'cancelled_reason' => mb_substr($reason, 0, 255)], 'id = ?', [$id]);
        if ($a['slot_id']) {
            DB::update('appointment_slots', ['status' => 'open', 'appointment_id' => null], 'id = ?', [$a['slot_id']]);
        }
        ActivityLog::record('Appointment cancelled', 'appointments', (string) $id, $reason);
    }

    public static function reschedule(int $id, string $newStart): int
    {
        $a = DB::one("SELECT * FROM appointments WHERE id = ? AND status = 'booked'", [$id]);
        if (!$a) {
            throw new \RuntimeException('Only booked appointments can be rescheduled.');
        }
        return DB::transaction(static function () use ($a, $id, $newStart): int {
            self::cancel($id, 'Rescheduled to ' . fmt_datetime($newStart));
            return self::book((int) $a['patient_id'], (int) $a['doctor_id'], $newStart, $a['visit_type_id'] ? (int) $a['visit_type_id'] : null, $a['source'], (string) $a['notes']);
        });
    }

    public static function markNoShow(int $id): void
    {
        DB::run("UPDATE appointments SET status = 'no_show' WHERE id = ? AND status = 'booked'", [$id]);
        ActivityLog::record('Appointment no-show', 'appointments', (string) $id, '');
    }

    /**
     * Doctor unavailable today (emergency): marks the leave and lists the
     * booked patients to call back — a manual trigger, never automatic (WF 9 step 4).
     */
    public static function affectedBy(int $leaveId): array
    {
        $l = DB::one('SELECT * FROM doctor_leaves WHERE id = ?', [$leaveId]);
        if (!$l) {
            return [];
        }
        return DB::all(
            "SELECT a.*, p.name AS patient_name, p.phone, p.mrn FROM appointments a JOIN patients p ON p.id = a.patient_id
              WHERE a.doctor_id = ? AND a.status = 'booked' AND a.scheduled_at BETWEEN ? AND ? ORDER BY a.scheduled_at",
            [$l['doctor_id'], $l['start_at'], $l['end_at']]
        );
    }
}
