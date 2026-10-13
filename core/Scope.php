<?php
declare(strict_types=1);

namespace App\Core;

/**
 * Data scoping for listings, reports and dashboards (Doc 1 §2.2–2.3).
 * The scope of a permission decides which rows a user reaches:
 *   own        -> records the user is attending (a doctor's own visits)
 *   department -> records of the doctors in the user's departments (HOD,
 *                 classic Department Admin, charge nurse)
 *   branch     -> everything in the current branch
 *   chain      -> everything (Super Admin, Auditor, Reporting)
 * Break-glass grants (emergency_access_grants) open one patient for a few
 * hours, always logged and reviewed.
 */
final class Scope
{
    /** @return int[]|null  null = unrestricted */
    public static function doctorIds(string $permission = 'visits.view'): ?array
    {
        $user = Auth::user();
        if (!$user) {
            return [0];
        }
        $scope = Auth::scope($permission);
        if ($scope === null) {
            return [0];
        }
        if ($scope === 'own') {
            return [$user['id']];
        }
        if ($scope === 'department') {
            $depts = Auth::departmentIdsFor($permission) ?: $user['department_ids'];
            if (!$depts) {
                return $user['role'] === 'doctor' ? [$user['id']] : [0];
            }
            $ids = DB::column(
                "SELECT DISTINCT u.id FROM users u
                  WHERE u.role = 'doctor' AND u.deleted_at IS NULL AND (
                        EXISTS (SELECT 1 FROM user_departments ud WHERE ud.user_id = u.id AND ud.department_id IN (" . DB::placeholders($depts) . '))
                     OR EXISTS (SELECT 1 FROM user_role_branch x WHERE x.user_id = u.id AND x.department_id IN (' . DB::placeholders($depts) . ')))',
                array_merge($depts, $depts)
            );
            $ids = array_map('intval', $ids);
            if ($user['role'] === 'doctor' && !in_array($user['id'], $ids, true)) {
                $ids[] = $user['id'];
            }
            return $ids ?: [0];
        }
        return null;
    }

    /** Appends "AND {column} IN (...)" when the user is restricted. */
    public static function sql(string $column, array &$params, string $permission = 'visits.view'): string
    {
        $ids = self::doctorIds($permission);
        if ($ids === null) {
            return '';
        }
        array_push($params, ...$ids);
        return ' AND ' . $column . ' IN (' . DB::placeholders($ids) . ')';
    }

    /**
     * Restricts a branch-scoped table to the current branch unless the user
     * holds $permission chain-wide. Rows without a branch (v1 data) stay visible.
     */
    public static function branchSql(string $column, array &$params, string $permission): string
    {
        if (Auth::scope($permission) === 'chain' || !self::multiBranch()) {
            return '';
        }
        $params[] = (int) Auth::branchId();
        return ' AND (' . $column . ' = ? OR ' . $column . ' IS NULL)';
    }

    public static function multiBranch(): bool
    {
        static $multi = null;
        if ($multi === null) {
            $multi = (int) DB::value('SELECT COUNT(*) FROM branches WHERE deleted_at IS NULL AND is_active = 1') > 1;
        }
        return $multi;
    }

    /** Doctors the current user may pick in filters. */
    public static function doctorOptions(string $permission = 'visits.view'): array
    {
        $ids = self::doctorIds($permission);
        $sql = "SELECT id, name FROM users WHERE role = 'doctor' AND deleted_at IS NULL";
        $params = [];
        if ($ids !== null) {
            $sql .= ' AND id IN (' . DB::placeholders($ids) . ')';
            $params = $ids;
        }
        return DB::pairs($sql . ' ORDER BY name', $params);
    }

    /** Every active doctor (registration desk, appointments). */
    public static function allDoctors(): array
    {
        return DB::pairs("SELECT id, name FROM users WHERE role = 'doctor' AND is_active = 1 AND deleted_at IS NULL ORDER BY name");
    }

    /**
     * May the current user open this visit's clinical record?
     * Within scope, or a live break-glass grant for the patient.
     */
    public static function visitAllowed(array $visit, string $permission = 'visits.view'): bool
    {
        $ids = self::doctorIds($permission);
        if ($ids === null || in_array((int) $visit['doctor_id'], $ids, true)) {
            return true;
        }
        return self::hasEmergencyAccess((int) $visit['patient_id']);
    }

    public static function hasEmergencyAccess(int $patientId): bool
    {
        $uid = Auth::id();
        return $uid !== null && (bool) DB::value(
            'SELECT 1 FROM emergency_access_grants WHERE user_id = ? AND patient_id = ? AND expires_at > ?',
            [$uid, $patientId, now()]
        );
    }

    /** Masks a CNIC unless the user may see it in full (Doc 1 §6). */
    public static function cnic(?string $cnic): string
    {
        $cnic = (string) $cnic;
        if ($cnic === '' || Auth::can('patients.cnic.full')) {
            return $cnic;
        }
        return strlen($cnic) === 15 ? substr($cnic, 0, 5) . '-XXXXXXX-' . substr($cnic, -1) : 'XXXXX';
    }
}
