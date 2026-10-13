<?php
declare(strict_types=1);

namespace App\Modules\Frontdesk;

use App\Core\ActivityLog;
use App\Core\Auth;
use App\Core\DB;

/**
 * OPD token queues (Doc 7 §2 rules 1–3, Doc 8 §5.1):
 *  - token number = next per (queue, day), issued atomically through the
 *    counters table, plus a unique index — two desks never get the same number;
 *  - "call next" takes emergency > pregnant / disabled / senior > VIP > normal,
 *    then the earliest issue time;
 *  - every action writes a token_events row; a transfer closes the token and
 *    opens a linked one in the target queue so the whole wait is reportable.
 */
final class TokenService
{
    public const ACTIVE = ['waiting', 'called', 'serving'];

    /** The doctor's consultation queue, created on first use. */
    public static function queueForDoctor(int $doctorId, ?int $departmentId = null): int
    {
        $id = DB::value("SELECT id FROM token_queues WHERE doctor_id = ? AND queue_type = 'consultation' AND deleted_at IS NULL AND is_active = 1 ORDER BY id LIMIT 1", [$doctorId]);
        if ($id) {
            return (int) $id;
        }
        $doctor = DB::one('SELECT name FROM users WHERE id = ?', [$doctorId]);
        $departmentId = $departmentId ?: ((int) DB::value('SELECT MIN(department_id) FROM user_departments WHERE user_id = ?', [$doctorId]) ?: null);
        $words = preg_split('/\s+/', preg_replace('/^Dr\.?\s+/i', '', (string) ($doctor['name'] ?? 'D'))) ?: ['D'];
        $prefix = strtoupper(substr(implode('', array_map(static fn ($w) => $w[0] ?? '', $words)), 0, 2)) ?: 'D';
        if (DB::value('SELECT 1 FROM token_queues WHERE prefix = ? AND deleted_at IS NULL', [$prefix])) {
            $prefix = substr($prefix, 0, 1) . (int) DB::value('SELECT COUNT(*) + 1 FROM token_queues');
        }
        return DB::insert('token_queues', [
            'branch_id' => Auth::branchId(), 'department_id' => $departmentId, 'doctor_id' => $doctorId, 'queue_type' => 'consultation',
            'name' => ($doctor['name'] ?? 'Doctor') . ' — OPD', 'prefix' => $prefix, 'reset_policy' => 'daily', 'avg_service_minutes' => 10,
            'is_active' => 1, 'display_order' => (int) DB::value('SELECT COALESCE(MAX(display_order), 0) + 1 FROM token_queues'), 'created_at' => now(),
            'created_by' => Auth::id(),
        ]);
    }

    /** Service queue of a type (lab collection, radiology, pharmacy …), created on first use. */
    public static function queueOfType(string $type, string $name, string $prefix): int
    {
        $id = DB::value('SELECT id FROM token_queues WHERE queue_type = ? AND doctor_id IS NULL AND deleted_at IS NULL AND is_active = 1 ORDER BY id LIMIT 1', [$type]);
        if ($id) {
            return (int) $id;
        }
        return DB::insert('token_queues', ['branch_id' => Auth::branchId(), 'queue_type' => $type, 'name' => $name, 'prefix' => $prefix,
            'reset_policy' => 'daily', 'avg_service_minutes' => 5, 'is_active' => 1, 'display_order' => 50, 'created_at' => now()]);
    }

    /** Next number for the queue on the day — atomic and race-free. */
    public static function nextNumber(int $queueId, string $date): int
    {
        $queue = DB::one('SELECT reset_policy FROM token_queues WHERE id = ?', [$queueId]);
        $key = 'token:' . $queueId . ':' . (($queue['reset_policy'] ?? 'daily') === 'never' ? 'all' : $date);
        DB::run('INSERT INTO counters (counter_key, last_value) VALUES (?, LAST_INSERT_ID(1)) ON DUPLICATE KEY UPDATE last_value = LAST_INSERT_ID(last_value + 1)', [$key]);
        return (int) DB::pdo()->lastInsertId();
    }

    /** @return array token row */
    public static function issue(int $queueId, int $patientId, array $opt = []): array
    {
        $priority = isset(hospital('priorities')[$opt['priority'] ?? 'normal']) ? ($opt['priority'] ?? 'normal') : 'normal';
        return DB::transaction(static function () use ($queueId, $patientId, $opt, $priority): array {
            $queue = DB::one('SELECT * FROM token_queues WHERE id = ? AND deleted_at IS NULL', [$queueId]);
            if (!$queue) {
                throw new \RuntimeException('Queue not found.');
            }
            $date = substr($opt['issued_at'] ?? now(), 0, 10);
            $existing = DB::one("SELECT * FROM tokens WHERE queue_id = ? AND patient_id = ? AND issue_date = ? AND status IN ('waiting','called','serving')",
                [$queueId, $patientId, $date]);
            if ($existing && empty($opt['allow_duplicate'])) {
                throw new \RuntimeException('This patient already has token ' . $existing['token_label'] . ' waiting in this queue.');
            }
            $number = self::nextNumber($queueId, $date);
            $id = DB::insert('tokens', [
                'branch_id' => $queue['branch_id'] ?? Auth::branchId(), 'queue_id' => $queueId, 'issue_date' => $date, 'token_number' => $number,
                'token_label' => $queue['prefix'] . '-' . str_pad((string) $number, 3, '0', STR_PAD_LEFT), 'patient_id' => $patientId,
                'appointment_id' => $opt['appointment_id'] ?? null, 'invoice_id' => $opt['invoice_id'] ?? null, 'visit_type_id' => $opt['visit_type_id'] ?? null,
                'priority' => $priority, 'status' => 'waiting', 'previous_token_id' => $opt['previous_token_id'] ?? null,
                'notes' => isset($opt['notes']) ? mb_substr((string) $opt['notes'], 0, 255) : null,
                'issued_by' => Auth::id(), 'issued_at' => $opt['issued_at'] ?? now(),
            ]);
            self::event($id, 'issued', null, $queueId, null, $opt['note'] ?? null);
            return DB::one('SELECT * FROM tokens WHERE id = ?', [$id]);
        });
    }

    /** Calls the next waiting token (priority, then issue time). */
    public static function callNext(int $queueId, ?int $servicePointId = null): ?array
    {
        return DB::transaction(static function () use ($queueId, $servicePointId): ?array {
            $weights = [];
            foreach (hospital('priorities') as $key => $p) {
                $weights[] = 'WHEN ' . DB::pdo()->quote($key) . ' THEN ' . (int) $p[1];
            }
            $token = DB::one(
                "SELECT * FROM tokens WHERE queue_id = ? AND issue_date = ? AND status = 'waiting'
                  ORDER BY CASE priority " . implode(' ', $weights) . ' ELSE 0 END DESC, issued_at, id LIMIT 1 FOR UPDATE',
                [$queueId, today()]
            );
            if (!$token) {
                return null;
            }
            DB::update('tokens', ['status' => 'called', 'called_at' => now(), 'called_by' => Auth::id(), 'service_point_id' => $servicePointId], 'id = ?', [$token['id']]);
            self::event((int) $token['id'], 'called', null, null, $servicePointId);
            return DB::one('SELECT * FROM tokens WHERE id = ?', [$token['id']]);
        });
    }

    /** Calls a specific token (doctor picks a patient from the list). */
    public static function call(int $tokenId, ?int $servicePointId = null): array
    {
        $t = self::find($tokenId);
        if (!in_array($t['status'], ['waiting', 'called', 'no_show'], true)) {
            throw new \RuntimeException('Token ' . $t['token_label'] . ' is ' . $t['status'] . '.');
        }
        $recall = $t['status'] !== 'waiting';
        DB::update('tokens', ['status' => 'called', 'called_at' => now(), 'called_by' => Auth::id(), 'service_point_id' => $servicePointId ?? $t['service_point_id'],
            'recall_count' => (int) $t['recall_count'] + ($recall ? 1 : 0)], 'id = ?', [$tokenId]);
        self::event($tokenId, $recall ? 'recalled' : 'called', null, null, $servicePointId);
        return self::find($tokenId);
    }

    public static function start(int $tokenId): void
    {
        $t = self::find($tokenId);
        if (in_array($t['status'], ['waiting', 'called'], true)) {
            DB::update('tokens', ['status' => 'serving', 'serving_started_at' => now(), 'called_at' => $t['called_at'] ?? now()], 'id = ?', [$tokenId]);
            self::event($tokenId, 'started');
        }
    }

    public static function complete(int $tokenId, ?int $visitId = null): void
    {
        $t = self::find($tokenId);
        if (in_array($t['status'], ['completed', 'cancelled', 'transferred'], true)) {
            if ($visitId && !$t['visit_id']) {
                DB::update('tokens', ['visit_id' => $visitId], 'id = ?', [$tokenId]);
            }
            return;
        }
        DB::update('tokens', ['status' => 'completed', 'completed_at' => now(), 'visit_id' => $visitId ?? $t['visit_id'],
            'serving_started_at' => $t['serving_started_at'] ?? now()], 'id = ?', [$tokenId]);
        self::event($tokenId, 'completed');
    }

    /** Skip = patient did not come when called; can be recalled later. */
    public static function skip(int $tokenId): void
    {
        $t = self::find($tokenId);
        if (!in_array($t['status'], ['waiting', 'called'], true)) {
            throw new \RuntimeException('Only waiting or called tokens can be skipped.');
        }
        DB::update('tokens', ['status' => 'no_show'], 'id = ?', [$tokenId]);
        self::event($tokenId, 'skipped');
    }

    /** Puts a skipped patient back into the waiting line. */
    public static function requeue(int $tokenId): void
    {
        $t = self::find($tokenId);
        if ($t['status'] !== 'no_show') {
            throw new \RuntimeException('Only skipped tokens can be put back.');
        }
        DB::update('tokens', ['status' => 'waiting'], 'id = ?', [$tokenId]);
        self::event($tokenId, 'requeued');
    }

    public static function cancel(int $tokenId, string $reason = ''): void
    {
        $t = self::find($tokenId);
        if (!in_array($t['status'], ['waiting', 'called', 'no_show'], true)) {
            throw new \RuntimeException('This token cannot be cancelled.');
        }
        DB::update('tokens', ['status' => 'cancelled', 'notes' => mb_substr(trim(($t['notes'] ?? '') . ' ' . $reason), 0, 255)], 'id = ?', [$tokenId]);
        self::event($tokenId, 'cancelled', null, null, null, $reason);
        ActivityLog::record('Token cancelled', 'frontdesk', (string) $tokenId, $t['token_label'] . ($reason ? ' — ' . $reason : ''));
    }

    /** Closes the token and issues a linked one in the target queue (doctor → lab → pharmacy). */
    public static function transfer(int $tokenId, int $toQueueId, string $note = ''): array
    {
        return DB::transaction(static function () use ($tokenId, $toQueueId, $note): array {
            $t = self::find($tokenId);
            if ((int) $t['queue_id'] === $toQueueId) {
                throw new \RuntimeException('The token is already in that queue.');
            }
            DB::update('tokens', ['status' => 'transferred', 'completed_at' => now()], 'id = ?', [$tokenId]);
            self::event($tokenId, 'transferred', (int) $t['queue_id'], $toQueueId, null, $note);
            $new = self::issue($toQueueId, (int) $t['patient_id'], ['priority' => $t['priority'], 'previous_token_id' => $tokenId,
                'invoice_id' => $t['invoice_id'], 'visit_type_id' => $t['visit_type_id'], 'allow_duplicate' => true, 'note' => 'From ' . $t['token_label']]);
            DB::update('tokens', ['visit_id' => $t['visit_id']], 'id = ?', [$new['id']]);
            return $new;
        });
    }

    public static function find(int $id): array
    {
        $t = DB::one('SELECT t.*, q.name AS queue_name, q.prefix, q.doctor_id, q.department_id, q.queue_type FROM tokens t JOIN token_queues q ON q.id = t.queue_id WHERE t.id = ?', [$id]);
        if (!$t) {
            throw new \RuntimeException('Token not found.');
        }
        return $t;
    }

    public static function event(int $tokenId, string $type, ?int $from = null, ?int $to = null, ?int $servicePoint = null, ?string $note = null): void
    {
        DB::insert('token_events', ['token_id' => $tokenId, 'event_type' => $type, 'from_queue_id' => $from, 'to_queue_id' => $to,
            'service_point_id' => $servicePoint, 'user_id' => Auth::id(), 'note' => $note ? mb_substr($note, 0, 255) : null, 'occurred_at' => now()]);
    }

    /** Today's tokens of a queue (or all queues) for the console. */
    public static function today(?int $queueId = null, ?array $statuses = null): array
    {
        $params = [today()];
        $where = 't.issue_date = ?';
        if ($queueId) {
            $where .= ' AND t.queue_id = ?';
            $params[] = $queueId;
        }
        if ($statuses) {
            $where .= ' AND t.status IN (' . DB::placeholders($statuses) . ')';
            array_push($params, ...$statuses);
        }
        $weights = [];
        foreach (hospital('priorities') as $key => $p) {
            $weights[] = 'WHEN ' . DB::pdo()->quote($key) . ' THEN ' . (int) $p[1];
        }
        return DB::all(
            "SELECT t.*, p.name AS patient_name, p.mrn, p.gender, p.dob, p.phone, q.name AS queue_name, q.doctor_id, sp.name AS service_point_name,
                    i.invoice_no, i.status AS invoice_status, i.net_amount, i.waived
               FROM tokens t JOIN patients p ON p.id = t.patient_id JOIN token_queues q ON q.id = t.queue_id
               LEFT JOIN service_points sp ON sp.id = t.service_point_id LEFT JOIN invoices i ON i.id = t.invoice_id
              WHERE $where
              ORDER BY FIELD(t.status, 'serving', 'called', 'waiting', 'no_show', 'completed', 'transferred', 'cancelled'),
                       CASE t.priority " . implode(' ', $weights) . ' ELSE 0 END DESC, t.issued_at, t.id',
            $params
        );
    }

    /** Queue summary: waiting count and average wait (minutes) today. */
    public static function summary(): array
    {
        return DB::all(
            "SELECT q.id, q.name, q.prefix, q.queue_type, q.doctor_id, q.avg_service_minutes,
                    SUM(t.status = 'waiting') AS waiting, SUM(t.status IN ('called','serving')) AS in_room, SUM(t.status = 'completed') AS done,
                    ROUND(AVG(CASE WHEN t.called_at IS NOT NULL THEN TIMESTAMPDIFF(MINUTE, t.issued_at, t.called_at) END)) AS avg_wait
               FROM token_queues q LEFT JOIN tokens t ON t.queue_id = q.id AND t.issue_date = ?
              WHERE q.deleted_at IS NULL AND q.is_active = 1
              GROUP BY q.id, q.name, q.prefix, q.queue_type, q.doctor_id, q.avg_service_minutes, q.display_order
              ORDER BY q.display_order, q.name",
            [today()]
        );
    }

    /** Waiting-room board data: now serving + next five per queue. */
    public static function board(int $boardId): array
    {
        $queues = DB::all(
            'SELECT q.id, q.name, q.prefix, u.name AS doctor_name FROM display_board_queues dq JOIN token_queues q ON q.id = dq.token_queue_id AND q.deleted_at IS NULL
               LEFT JOIN users u ON u.id = q.doctor_id WHERE dq.display_board_id = ? ORDER BY dq.display_order, q.name',
            [$boardId]
        );
        if (!$queues) {
            $queues = DB::all("SELECT q.id, q.name, q.prefix, u.name AS doctor_name FROM token_queues q LEFT JOIN users u ON u.id = q.doctor_id
                                WHERE q.deleted_at IS NULL AND q.is_active = 1 ORDER BY q.display_order, q.name");
        }
        $out = [];
        foreach ($queues as $q) {
            $serving = DB::one(
                "SELECT t.token_label, t.called_at, t.recall_count, sp.name AS room FROM tokens t LEFT JOIN service_points sp ON sp.id = t.service_point_id
                  WHERE t.queue_id = ? AND t.issue_date = ? AND t.status IN ('called','serving') ORDER BY t.called_at DESC LIMIT 1",
                [$q['id'], today()]
            );
            $next = DB::column(
                "SELECT token_label FROM tokens WHERE queue_id = ? AND issue_date = ? AND status = 'waiting' ORDER BY
                   CASE priority WHEN 'emergency' THEN 100 WHEN 'pregnant' THEN 60 WHEN 'disabled' THEN 60 WHEN 'senior' THEN 60 WHEN 'vip' THEN 40 ELSE 0 END DESC, issued_at LIMIT 5",
                [$q['id'], today()]
            );
            $out[] = ['id' => (int) $q['id'], 'name' => $q['doctor_name'] ?: $q['name'], 'serving' => $serving['token_label'] ?? null,
                'room' => $serving['room'] ?? null, 'called_at' => $serving['called_at'] ?? null, 'recall' => (int) ($serving['recall_count'] ?? 0), 'next' => $next];
        }
        return $out;
    }
}
