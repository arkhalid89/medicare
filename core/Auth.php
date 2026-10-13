<?php
declare(strict_types=1);

namespace App\Core;

/**
 * In-app session authentication and role authorisation.
 * The user row is re-read on every request, so deactivating or deleting a
 * user takes effect immediately, even for a session that is already open.
 *
 * Authorisation is resolved by Rbac from the database: roles (with base-role
 * inheritance and denies) assigned per branch and department. users.role is
 * the user CLASS (super_admin, dept_admin, doctor, reporting, staff) that the
 * rest of the application uses for doctor pickers and profiles.
 */
final class Auth
{
    private static ?array $user = null;
    private static bool $loaded = false;
    private static ?array $context = null;

    /** @return array{ok:bool, message:string, two_factor?:bool, license?:bool} */
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
        if (!License::allowsLogin()) {
            ActivityLog::record('Login blocked', 'license', (string) $row['id'], License::status()['message'], (int) $row['id'], $username);
            return ['ok' => false, 'message' => License::status()['message'], 'license' => true];
        }
        if (!DB::value('SELECT 1 FROM user_role_branch WHERE user_id = ?', [$row['id']])) {
            return ['ok' => false, 'message' => 'No role is assigned to your account yet. Please contact the administrator.'];
        }
        if (Password::needsUpgrade((string) $row['password'])) {
            DB::update('users', ['password' => Password::hash($password)], 'id = ?', [$row['id']]);
        }
        if ((int) ($row['two_factor_enabled'] ?? 0) === 1 && (string) ($row['two_factor_secret'] ?? '') !== '') {
            Session::set('2fa_user', (int) $row['id']);
            Session::set('2fa_started', time());
            return ['ok' => false, 'two_factor' => true, 'message' => 'Enter the 6-digit code from your authenticator app.'];
        }
        return self::completeLogin($row);
    }

    /** Second step of a 2FA login. */
    public static function verifyTwoFactor(string $code): array
    {
        $id = (int) Session::get('2fa_user', 0);
        if ($id <= 0 || time() - (int) Session::get('2fa_started', 0) > 300) {
            Session::forget('2fa_user');
            return ['ok' => false, 'message' => 'The sign-in attempt expired. Please enter your password again.', 'restart' => true];
        }
        $row = DB::one('SELECT * FROM users WHERE id = ? AND deleted_at IS NULL AND is_active = 1', [$id]);
        if (!$row || !Totp::verify((string) $row['two_factor_secret'], $code)) {
            ActivityLog::record('2FA failed', 'auth', (string) $id, 'Wrong authenticator code', $id, $row['username'] ?? null);
            return ['ok' => false, 'message' => 'The code is not correct. Please try again.'];
        }
        Session::forget('2fa_user');
        return self::completeLogin($row);
    }

    public static function pendingTwoFactor(): bool
    {
        return (int) Session::get('2fa_user', 0) > 0;
    }

    private static function completeLogin(array $row): array
    {
        Session::regenerate();
        Session::set('user_id', (int) $row['id']);
        Session::set('last_activity', time());
        Session::forget('ctx_branch');
        Session::forget('ctx_department');
        Session::forget('rbac');
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

        $user = self::load($id);
        if (!$user) {
            Session::forget('user_id');
            return self::$user = null;
        }

        $timeout = (int) config('app.session_timeout', 120);
        $roleTimeout = self::rbac($user)['timeout'];
        if ($roleTimeout !== null && $roleTimeout > 0) {
            $timeout = $timeout > 0 ? min($timeout, $roleTimeout) : $roleTimeout;
        }
        $last = (int) Session::get('last_activity', time());
        if ($timeout > 0 && time() - $last > $timeout * 60) {
            Session::forget('user_id');
            Session::flash('warning', 'Your session expired due to inactivity. Please log in again.');
            return self::$user = null;
        }
        Session::set('last_activity', time());
        return self::$user = $user;
    }

    private static function load(int $id): ?array
    {
        $user = DB::one(
            'SELECT u.*, dp.pmdc_registration, dp.qualification, dp.specialization, dp.mrn_pattern, dp.default_fee, dp.signature_text,
                    r.code AS role_code, r.name AS role_name
               FROM users u LEFT JOIN doctor_profiles dp ON dp.user_id = u.id
               LEFT JOIN roles r ON r.id = u.primary_role_id
              WHERE u.id = ? AND u.deleted_at IS NULL AND u.is_active = 1',
            [$id]
        );
        if (!$user) {
            return null;
        }
        unset($user['password'], $user['two_factor_secret']);
        $user['id'] = (int) $user['id'];
        $user['department_ids'] = self::memberDepartments($user['id']);
        return $user;
    }

    /** Departments the user belongs to (membership + department-bound roles). */
    public static function memberDepartments(int $userId): array
    {
        return array_values(array_unique(array_map('intval', array_merge(
            DB::column('SELECT department_id FROM user_departments WHERE user_id = ?', [$userId]),
            DB::column('SELECT department_id FROM user_role_branch WHERE user_id = ? AND department_id IS NOT NULL', [$userId])
        ))));
    }

    public static function check(): bool
    {
        return self::user() !== null;
    }

    public static function id(): ?int
    {
        return self::user()['id'] ?? null;
    }

    /** User class: super_admin | dept_admin | doctor | reporting | staff. */
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

    // ------------------------------------------------------------------ context

    /** Current branch (session choice, else the user's first / default branch). */
    public static function branchId(): ?int
    {
        return self::context()['branch_id'];
    }

    /** Current department filter (null = all the user's departments). */
    public static function departmentId(): ?int
    {
        return self::context()['department_id'];
    }

    private static function context(): array
    {
        if (self::$context !== null) {
            return self::$context;
        }
        $user = self::user();
        $default = self::defaultBranchId();
        if (!$user) {
            return self::$context = ['branch_id' => $default, 'department_id' => null];
        }
        $options = self::contexts();
        $branch = (int) (PHP_SAPI === 'cli' ? 0 : Session::get('ctx_branch', 0));
        if (!$branch || !isset($options['branches'][$branch])) {
            $branch = $options['branches'] ? (int) array_key_first($options['branches']) : (int) $default;
        }
        $dept = (int) (PHP_SAPI === 'cli' ? 0 : Session::get('ctx_department', 0));
        if ($dept && !isset($options['departments'][$dept])) {
            $dept = 0;
        }
        return self::$context = ['branch_id' => $branch ?: null, 'department_id' => $dept ?: null];
    }

    public static function setContext(?int $branchId, ?int $departmentId): void
    {
        Session::set('ctx_branch', (int) $branchId);
        Session::set('ctx_department', (int) $departmentId);
        Session::forget('rbac');
        self::$context = null;
        Rbac::flush();
    }

    /** Branches and departments the user can switch between. */
    public static function contexts(): array
    {
        $user = self::$user;
        if (!$user) {
            return ['branches' => [], 'departments' => []];
        }
        $rows = Rbac::assignments($user['id']);
        $allBranches = DB::pairs('SELECT id, name FROM branches WHERE deleted_at IS NULL AND is_active = 1 ORDER BY display_order, name');
        $branches = [];
        $departments = [];
        foreach ($rows as $r) {
            if ($r['branch_id'] === null) {
                $branches = $allBranches + $branches;
            } elseif (isset($allBranches[(int) $r['branch_id']])) {
                $branches[(int) $r['branch_id']] = $allBranches[(int) $r['branch_id']];
            }
            if ($r['department_id'] !== null) {
                $departments[(int) $r['department_id']] = (string) $r['department_name'];
            }
        }
        foreach (DB::all('SELECT d.id, d.name FROM user_departments ud JOIN departments d ON d.id = ud.department_id AND d.deleted_at IS NULL WHERE ud.user_id = ?', [$user['id']]) as $d) {
            $departments[(int) $d['id']] = $d['name'];
        }
        return ['branches' => $branches, 'departments' => $departments];
    }

    public static function defaultBranchId(): ?int
    {
        static $id = false;
        if ($id === false) {
            try {
                $set = (int) Settings::get('default_branch_id', 0);
                $id = $set && DB::value('SELECT 1 FROM branches WHERE id = ? AND deleted_at IS NULL', [$set])
                    ? $set
                    : ((int) DB::value('SELECT id FROM branches WHERE deleted_at IS NULL AND is_active = 1 ORDER BY display_order, id LIMIT 1') ?: null);
            } catch (\PDOException $e) {
                $id = null;
            }
        }
        return $id;
    }

    // ------------------------------------------------------------ permissions

    private static function rbac(?array $user = null): array
    {
        $user = $user ?? self::user();
        if (!$user) {
            return ['perms' => [], 'roles' => [], 'role_ids' => [], 'cosign' => false, 'timeout' => null, 'requires_2fa' => false, 'discount_limit' => null];
        }
        $ctx = self::$user ? self::context() : ['branch_id' => self::defaultBranchId(), 'department_id' => null];
        return Rbac::resolve((int) $user['id'], $ctx['branch_id'], $ctx['department_id']);
    }

    public static function can(string $permission): bool
    {
        $user = self::user();
        if (!$user) {
            return false;
        }
        $perms = self::rbac()['perms'];
        if (in_array($permission, config('permissions.doctor_only', []), true)) {
            return $user['role'] === 'doctor' && isset($perms[$permission]);
        }
        return isset($perms['*']) || isset($perms[$permission]);
    }

    /** True when the user holds at least one of the permissions. */
    public static function canAny(array $permissions): bool
    {
        foreach ($permissions as $p) {
            if (self::can($p)) {
                return true;
            }
        }
        return false;
    }

    /** Scope of a permission for the current user: own | department | branch | chain | null. */
    public static function scope(string $permission): ?string
    {
        $perms = self::rbac()['perms'];
        if (isset($perms['*'])) {
            return 'chain';
        }
        return $perms[$permission]['scope'] ?? null;
    }

    /** Department ids where the user may use $permission (department scope). */
    public static function departmentIdsFor(string $permission): array
    {
        $perms = self::rbac()['perms'];
        $depts = $perms[$permission]['departments'] ?? [];
        $ctx = self::departmentId();
        if ($ctx !== null) {
            $depts = in_array($ctx, $depts, true) ? [$ctx] : [];
        }
        return $depts;
    }

    /** Role codes active in the current context. */
    public static function roles(): array
    {
        return self::rbac()['roles'];
    }

    public static function roleIds(): array
    {
        return self::rbac()['role_ids'];
    }

    public static function hasRole(string ...$codes): bool
    {
        return (bool) array_intersect($codes, self::roles());
    }

    /** Residents (RM, DRM, GRM) need a consultant co-sign on clinical entries. */
    public static function requiresCosign(): bool
    {
        return self::rbac()['cosign'];
    }

    /** Highest discount (PKR) the user may give without approval; null = no limit set. */
    public static function discountLimit(): ?float
    {
        if (self::can('billing.discount.approve')) {
            return null;
        }
        return self::rbac()['discount_limit'];
    }

    public static function mustSetupTwoFactor(): bool
    {
        $user = self::user();
        return $user && Settings::get('enforce_2fa', '0') === '1' && self::rbac()['requires_2fa'] && (int) $user['two_factor_enabled'] !== 1;
    }

    /** Workspaces in the user's menu (route must exist, permission must be held). */
    public static function workspaces(): array
    {
        $routes = Router::routes();
        $out = [];
        foreach (Rbac::workspaces(self::roleIds()) as $w) {
            if (!isset($routes[$w['route']])) {
                continue;
            }
            if ($w['required_permission'] && !self::can($w['required_permission'])) {
                continue;
            }
            $out[] = $w;
        }
        return $out;
    }

    /** Landing route after login: the primary role's default workspace, else the dashboard. */
    public static function homeRoute(): string
    {
        $user = self::user();
        if (!$user) {
            return 'login';
        }
        $routes = Router::routes();
        $role = $user['primary_role_id'] ? Rbac::role((int) $user['primary_role_id']) : null;
        if ($role && $role['default_workspace_id']) {
            $route = (string) DB::value('SELECT route FROM workspaces WHERE id = ? AND is_active = 1', [$role['default_workspace_id']]);
            if ($route !== '' && isset($routes[$route]) && $route !== 'consult') {
                return $route;
            }
        }
        return self::can('dashboard.view') ? 'dashboard' : (($w = self::workspaces()) ? $w[0]['route'] : 'profile');
    }

    /**
     * Legacy helper: can a role (code, or v1 class name) do $permission?
     * Used by screens that preview a role's rights.
     */
    public static function roleCan(string $role, string $permission): bool
    {
        $code = config('permissions.legacy_roles.' . $role) ?? $role;
        $r = Rbac::roleByCode((string) $code);
        if (!$r) {
            return false;
        }
        $perms = Rbac::rolePermissions((int) $r['id']);
        if (in_array($permission, config('permissions.doctor_only', []), true)) {
            return $r['user_class'] === 'doctor' && isset($perms[$permission]);
        }
        return isset($perms['*']) || isset($perms[$permission]);
    }

    public static function logout(): void
    {
        if (self::check()) {
            ActivityLog::record('Logout', 'auth', (string) self::id(), self::user()['name'] . ' logged out');
        }
        Session::destroy();
        self::$user = null;
        self::$loaded = true;
        self::$context = null;
    }

    public static function refresh(): void
    {
        self::$loaded = false;
        self::$user = null;
        self::$context = null;
        Rbac::flush();
    }

    /** Test helper: act as a user without a session (optionally in a context). */
    public static function actingAs(?array $user, ?int $branchId = null, ?int $departmentId = null): void
    {
        Rbac::flush();
        self::$context = null;
        if ($user !== null) {
            $user = self::load((int) $user['id']) ?? $user;
            $user['id'] = (int) $user['id'];
            $user['department_ids'] = self::memberDepartments($user['id']);
        }
        self::$user = $user;
        self::$loaded = true;
        if ($user !== null) {
            self::$context = ['branch_id' => $branchId ?? self::defaultBranchId(), 'department_id' => $departmentId];
        }
    }
}
