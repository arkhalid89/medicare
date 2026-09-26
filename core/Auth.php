<?php
declare(strict_types=1);

namespace App\Core;

/**
 * In-app session authentication and role authorisation.
 * The user row is re-read on every request, so deactivating or deleting a
 * user takes effect immediately, even for a session that is already open.
 */
final class Auth
{
    private static ?array $user = null;
    private static bool $loaded = false;

    /** @return array{ok:bool, message:string} */
    public static function attempt(string $username, string $password): array
    {
        $username = trim($username);
        $row = DB::one('SELECT * FROM users WHERE username = ? AND deleted_at IS NULL', [$username]);

        if (!$row || !Password::verify($password, (string) $row['password'])) {
            ActivityLog::record('Login failed', 'auth', null, 'Failed login attempt for username "' . mb_substr($username, 0, 60) . '"', $row ? (int) $row['id'] : null, $username);
            return ['ok' => false, 'message' => 'Invalid username or password.'];
        }
        if ((int) $row['is_active'] !== 1) {
            return ['ok' => false, 'message' => 'Your account is inactive. Please contact the administrator.'];
        }
        if (Password::needsUpgrade((string) $row['password'])) {
            DB::update('users', ['password' => Password::hash($password)], 'id = ?', [$row['id']]);
        }

        Session::regenerate();
        Session::set('user_id', (int) $row['id']);
        Session::set('last_activity', time());
        DB::update('users', ['last_login_at' => now()], 'id = ?', [$row['id']]);
        self::refresh();
        ActivityLog::record('Login', 'auth', (string) $row['id'], $row['name'] . ' logged in');
        return ['ok' => true, 'message' => 'Welcome, ' . $row['name'] . '.'];
    }

    public static function user(): ?array
    {
        if (self::$loaded) {
            return self::$user;
        }
        self::$loaded = true;
        $id = (int) Session::get('user_id', 0);
        if ($id <= 0) {
            return self::$user = null;
        }

        $timeout = (int) config('app.session_timeout', 120) * 60;
        $last = (int) Session::get('last_activity', time());
        if ($timeout > 0 && time() - $last > $timeout) {
            Session::forget('user_id');
            Session::flash('warning', 'Your session expired due to inactivity. Please log in again.');
            return self::$user = null;
        }

        $user = DB::one(
            'SELECT u.*, dp.pmdc_registration, dp.qualification, dp.specialization, dp.mrn_pattern, dp.default_fee, dp.signature_text
               FROM users u LEFT JOIN doctor_profiles dp ON dp.user_id = u.id
              WHERE u.id = ? AND u.deleted_at IS NULL AND u.is_active = 1',
            [$id]
        );
        if (!$user) {
            Session::forget('user_id');
            return self::$user = null;
        }
        unset($user['password']);
        $user['id'] = (int) $user['id'];
        $user['department_ids'] = array_map('intval', DB::column('SELECT department_id FROM user_departments WHERE user_id = ?', [$id]));
        Session::set('last_activity', time());
        return self::$user = $user;
    }

    public static function check(): bool
    {
        return self::user() !== null;
    }

    public static function id(): ?int
    {
        return self::user()['id'] ?? null;
    }

    public static function role(): ?string
    {
        return self::user()['role'] ?? null;
    }

    public static function isDoctor(): bool
    {
        return self::role() === 'doctor';
    }

    public static function isSuperAdmin(): bool
    {
        return self::role() === 'super_admin';
    }

    public static function can(string $permission): bool
    {
        $role = self::role();
        return $role !== null && self::roleCan($role, $permission);
    }

    public static function roleCan(string $role, string $permission): bool
    {
        $permissions = config('permissions.roles.' . $role . '.permissions', []);
        if (in_array($permission, config('permissions.doctor_only', []), true)) {
            return $role === 'doctor' && in_array($permission, $permissions, true);
        }
        return in_array('*', $permissions, true) || in_array($permission, $permissions, true);
    }

    public static function logout(): void
    {
        if (self::check()) {
            ActivityLog::record('Logout', 'auth', (string) self::id(), self::user()['name'] . ' logged out');
        }
        Session::destroy();
        self::$user = null;
        self::$loaded = true;
    }

    public static function refresh(): void
    {
        self::$loaded = false;
        self::$user = null;
    }

    /** Test helper: act as a user without a session. */
    public static function actingAs(?array $user): void
    {
        if ($user !== null) {
            $user['id'] = (int) $user['id'];
            $user['department_ids'] = array_map('intval', DB::column('SELECT department_id FROM user_departments WHERE user_id = ?', [$user['id']]));
        }
        self::$user = $user;
        self::$loaded = true;
    }
}
