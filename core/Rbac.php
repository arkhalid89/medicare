<?php
declare(strict_types=1);

namespace App\Core;

/**
 * Role-based access control engine (Docs 1, 5 §5, 7 §1, 8 §1).
 *
 *   effective permissions = ⋃ over the user's user_role_branch rows that match
 *   the current branch / department context of
 *      (role's grants ∪ base-role chain's grants) − the role's own denies
 *   each with the widest scope found (own < department < branch < chain).
 *
 * The result is cached per request and in the session; any change to roles,
 * grants or assignments bumps settings.permissions_version, which invalidates
 * every cached copy on the next request (the Redis cache of the TRD).
 */
final class Rbac
{
    public const SCOPES = ['own' => 1, 'department' => 2, 'branch' => 3, 'chain' => 4];

    /** @var array<string, array> */
    private static array $memo = [];
    /** @var array<int, array>|null */
    private static ?array $roleCache = null;

    public static function version(): string
    {
        return (string) Settings::get('permissions_version', '1');
    }

    /** Invalidates every cached permission set. */
    public static function bump(): void
    {
        Settings::set(['permissions_version' => (string) (((int) self::version()) + 1)]);
        self::$memo = [];
        self::$roleCache = null;
    }

    public static function flush(): void
    {
        self::$memo = [];
        self::$roleCache = null;
    }

    /**
     * @return array{perms: array<string, array{scope:string, departments:int[]}>, roles: string[], role_ids: int[], cosign: bool,
     *               timeout: ?int, requires_2fa: bool, discount_limit: ?float, workspaces: array}
     */
    public static function resolve(int $userId, ?int $branchId, ?int $departmentId): array
    {
        $key = $userId . ':' . (int) $branchId . ':' . (int) $departmentId . ':' . self::version();
        if (isset(self::$memo[$key])) {
            return self::$memo[$key];
        }
        $cached = PHP_SAPI !== 'cli' ? Session::get('rbac') : null;
        if (is_array($cached) && ($cached['key'] ?? '') === $key) {
            return self::$memo[$key] = $cached['data'];
        }

        $rows = self::assignments($userId);
        $memberDepts = array_map('intval', DB::column('SELECT department_id FROM user_departments WHERE user_id = ?', [$userId]));
        $perms = [];
        $roles = [];
        $roleIds = [];
        $cosign = false;
        $timeouts = [];
        $twoFa = false;
        $discount = null;
        foreach ($rows as $row) {
            if ($row['branch_id'] !== null && $branchId !== null && (int) $row['branch_id'] !== $branchId) {
                continue;
            }
            if ($row['department_id'] !== null && $departmentId !== null && (int) $row['department_id'] !== $departmentId) {
                continue;
            }
            $role = self::role((int) $row['role_id']);
            if (!$role || !(int) $role['is_active']) {
                continue;
            }
            $roles[] = $role['code'];
            $roleIds[] = (int) $role['id'];
            $depts = $row['department_id'] !== null ? [(int) $row['department_id']] : $memberDepts;
            foreach (self::rolePermissions((int) $role['id']) as $code => $scope) {
                if (!isset($perms[$code])) {
                    $perms[$code] = ['scope' => $scope, 'departments' => $depts];
                    continue;
                }
                if (self::SCOPES[$scope] > self::SCOPES[$perms[$code]['scope']]) {
                    $perms[$code]['scope'] = $scope;
                }
                $perms[$code]['departments'] = array_values(array_unique(array_merge($perms[$code]['departments'], $depts)));
            }
            foreach (self::chain((int) $role['id']) as $rid) {
                $r = self::role($rid);
                $cosign = $cosign || (int) ($r['requires_cosign'] ?? 0) === 1;
            }
            if ($role['session_timeout_min'] !== null) {
                $timeouts[] = (int) $role['session_timeout_min'];
            }
            $twoFa = $twoFa || (int) $role['requires_2fa'] === 1;
            if ($role['discount_limit'] !== null) {
                $discount = max((float) $discount, (float) $role['discount_limit']);
            }
        }
        // A consultant who also holds a resident role is not forced to co-sign.
        if ($cosign && array_intersect($roles, ['CD', 'DH', 'DEN', 'DSG', 'GYN', 'CAR', 'OPH', 'ENT', 'PED', 'ORT', 'DER', 'PSY', 'ERP'])) {
            $cosign = false;
        }
        $data = [
            'perms'          => $perms,
            'roles'          => array_values(array_unique($roles)),
            'role_ids'       => array_values(array_unique($roleIds)),
            'cosign'         => $cosign,
            'timeout'        => $timeouts ? min($timeouts) : null,
            'requires_2fa'   => $twoFa,
            'discount_limit' => $discount,
        ];
        if (PHP_SAPI !== 'cli' && session_status() === PHP_SESSION_ACTIVE) {
            Session::set('rbac', ['key' => $key, 'data' => $data]);
        }
        return self::$memo[$key] = $data;
    }

    /** user_role_branch rows of a user. */
    public static function assignments(int $userId): array
    {
        return DB::all(
            'SELECT urb.*, r.code AS role_code, r.name AS role_name, r.user_class, b.name AS branch_name, d.name AS department_name
               FROM user_role_branch urb JOIN roles r ON r.id = urb.role_id AND r.deleted_at IS NULL
               LEFT JOIN branches b ON b.id = urb.branch_id
               LEFT JOIN departments d ON d.id = urb.department_id
              WHERE urb.user_id = ? ORDER BY urb.is_primary DESC, urb.id',
            [$userId]
        );
    }

    public static function role(int $id): ?array
    {
        if (self::$roleCache === null) {
            self::$roleCache = [];
            foreach (DB::all('SELECT * FROM roles WHERE deleted_at IS NULL') as $r) {
                self::$roleCache[(int) $r['id']] = $r;
            }
        }
        return self::$roleCache[$id] ?? null;
    }

    public static function roleByCode(string $code): ?array
    {
        $id = DB::value('SELECT id FROM roles WHERE code = ? AND deleted_at IS NULL', [$code]);
        return $id ? self::role((int) $id) : null;
    }

    /** [role, base, base's base, …] — guarded against cycles. */
    public static function chain(int $roleId): array
    {
        $out = [];
        $id = $roleId;
        while ($id && !in_array($id, $out, true) && count($out) < 10) {
            $out[] = $id;
            $role = self::role($id);
            $id = $role && $role['base_role_id'] ? (int) $role['base_role_id'] : 0;
        }
        return $out;
    }

    /**
     * Effective permissions of ONE role: grants of the base chain applied from
     * the root down, each level able to widen/narrow scope and deny.
     * @return array<string, string> code => scope
     */
    public static function rolePermissions(int $roleId): array
    {
        static $cache = [];
        $ck = $roleId . ':' . self::version();
        if (isset($cache[$ck])) {
            return $cache[$ck];
        }
        $perms = [];
        foreach (array_reverse(self::chain($roleId)) as $rid) {
            $rows = DB::all(
                'SELECT p.code, rp.scope, rp.effect FROM role_permission rp JOIN permissions p ON p.id = rp.permission_id WHERE rp.role_id = ?',
                [$rid]
            );
            foreach ($rows as $r) {
                if ($r['effect'] === 'grant') {
                    $perms[$r['code']] = $r['scope'];
                }
            }
            foreach ($rows as $r) {
                if ($r['effect'] === 'deny') {
                    unset($perms[$r['code']]);
                }
            }
        }
        return $cache[$ck] = $perms;
    }

    /** Workspaces of the user's roles (in context) that the user may open. */
    public static function workspaces(array $roleIds): array
    {
        if (!$roleIds) {
            return [];
        }
        return DB::all(
            'SELECT w.*, MAX(rw.is_default) AS is_default FROM workspaces w JOIN role_workspace rw ON rw.workspace_id = w.id
              WHERE w.is_active = 1 AND rw.role_id IN (' . DB::placeholders($roleIds) . ')
              GROUP BY w.id ORDER BY w.display_order, w.name',
            $roleIds
        );
    }

    /** The widest of two scopes. */
    public static function wider(?string $a, ?string $b): ?string
    {
        if ($a === null) {
            return $b;
        }
        if ($b === null) {
            return $a;
        }
        return self::SCOPES[$a] >= self::SCOPES[$b] ? $a : $b;
    }
}
