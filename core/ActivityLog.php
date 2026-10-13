<?php
declare(strict_types=1);

namespace App\Core;

/**
 * Business activity log (BRD §63) and audit trail (Doc 1 §2.11, Doc 5 §7.7):
 * who did what, to which record, when — optionally with the old → new values.
 * Rows are append-only; no screen can edit them.
 */
final class ActivityLog
{
    /**
     * @param array{entity?:string, old?:mixed, new?:mixed} $audit
     */
    public static function record(
        string $action,
        string $module,
        ?string $recordId = null,
        string $description = '',
        ?int $userId = null,
        ?string $username = null,
        array $audit = []
    ): void {
        try {
            $user = Auth::user();
            $row = [
                'user_id'     => $userId ?? ($user['id'] ?? null),
                'username'    => $username !== null ? mb_substr($username, 0, 60) : ($user['username'] ?? null),
                'role'        => $user ? mb_substr((string) ($user['role_code'] ?? $user['role']), 0, 30) : null,
                'action'      => mb_substr($action, 0, 80),
                'module'      => $module,
                'record_id'   => $recordId,
                'description' => mb_substr($description, 0, 500),
                'ip_address'  => PHP_SAPI === 'cli' ? 'cli' : ($_SERVER['REMOTE_ADDR'] ?? null),
                'user_agent'  => PHP_SAPI === 'cli' ? 'cli' : mb_substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255),
                'created_at'  => now(),
            ];
            if (self::v2()) {
                $row['branch_id'] = $user ? Auth::branchId() : null;
                $row['entity_type'] = $audit['entity'] ?? null;
                $row['old_value'] = array_key_exists('old', $audit) ? json_encode($audit['old'], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE) : null;
                $row['new_value'] = array_key_exists('new', $audit) ? json_encode($audit['new'], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE) : null;
            }
            DB::insert('activity_logs', $row);
        } catch (\Throwable $e) {
            // Logging must never break the business action itself.
            Logger::error('Activity log failed: ' . $e->getMessage());
        }
    }

    /** Changed fields only: [old, new] restricted to keys whose value differs. */
    public static function diff(array $old, array $new): array
    {
        $o = [];
        $n = [];
        foreach ($new as $k => $v) {
            if (!array_key_exists($k, $old) || (string) $old[$k] !== (string) $v) {
                $o[$k] = $old[$k] ?? null;
                $n[$k] = $v;
            }
        }
        return [$o, $n];
    }

    private static function v2(): bool
    {
        static $has = null;
        if ($has === null) {
            try {
                $has = (bool) DB::value(
                    "SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'activity_logs' AND COLUMN_NAME = 'old_value'"
                );
            } catch (\Throwable $e) {
                $has = false;
            }
        }
        return $has;
    }
}
