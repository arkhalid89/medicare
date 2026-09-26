<?php
declare(strict_types=1);

namespace App\Core;

/** Business activity log (BRD §63) — who did what, to which record, when. */
final class ActivityLog
{
    public static function record(
        string $action,
        string $module,
        ?string $recordId = null,
        string $description = '',
        ?int $userId = null,
        ?string $username = null
    ): void {
        try {
            $user = Auth::user();
            DB::insert('activity_logs', [
                'user_id'     => $userId ?? ($user['id'] ?? null),
                'username'    => $username !== null ? mb_substr($username, 0, 60) : ($user['username'] ?? null),
                'role'        => $user['role'] ?? null,
                'action'      => mb_substr($action, 0, 80),
                'module'      => $module,
                'record_id'   => $recordId,
                'description' => mb_substr($description, 0, 500),
                'ip_address'  => PHP_SAPI === 'cli' ? 'cli' : ($_SERVER['REMOTE_ADDR'] ?? null),
                'user_agent'  => PHP_SAPI === 'cli' ? 'cli' : mb_substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255),
                'created_at'  => now(),
            ]);
        } catch (\Throwable $e) {
            // Logging must never break the business action itself.
            Logger::error('Activity log failed: ' . $e->getMessage());
        }
    }
}
