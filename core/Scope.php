<?php
declare(strict_types=1);

namespace App\Core;

/**
 * Data scoping for listings, reports and dashboards.
 *   Doctor           -> only his/her own visits
 *   Department Admin -> visits of doctors in his/her assigned departments
 *   Super Admin / Reporting -> everything
 */
final class Scope
{
    /** @return int[]|null  null = unrestricted */
    public static function doctorIds(): ?array
    {
        $user = Auth::user();
        if (!$user) {
            return [0];
        }
        if ($user['role'] === 'doctor') {
            return [$user['id']];
        }
        if ($user['role'] === 'dept_admin') {
            if (!$user['department_ids']) {
                return [0];
            }
            $ids = DB::column(
                'SELECT DISTINCT u.id FROM users u JOIN user_departments ud ON ud.user_id = u.id
                  WHERE u.role = \'doctor\' AND ud.department_id IN (' . DB::placeholders($user['department_ids']) . ')',
                $user['department_ids']
            );
            return $ids ? array_map('intval', $ids) : [0];
        }
        return null;
    }

    /** Appends "AND {column} IN (...)" when the user is restricted. */
    public static function sql(string $column, array &$params): string
    {
        $ids = self::doctorIds();
        if ($ids === null) {
            return '';
        }
        array_push($params, ...$ids);
        return ' AND ' . $column . ' IN (' . DB::placeholders($ids) . ')';
    }

    /** Doctors the current user may pick in filters. */
    public static function doctorOptions(): array
    {
        $ids = self::doctorIds();
        $sql = "SELECT id, name FROM users WHERE role = 'doctor' AND deleted_at IS NULL";
        $params = [];
        if ($ids !== null) {
            $sql .= ' AND id IN (' . DB::placeholders($ids) . ')';
            $params = $ids;
        }
        return DB::pairs($sql . ' ORDER BY name', $params);
    }
}
