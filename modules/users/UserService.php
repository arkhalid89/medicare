<?php
declare(strict_types=1);

namespace App\Modules\Users;

use App\Core\Auth;
use App\Core\CodeGenerator;
use App\Core\DB;
use App\Core\Rbac;

/** User helpers shared by the Users screens, the installer seed and tests. */
final class UserService
{
    public const ROLE_CODES = ['super_admin' => 'SA', 'dept_admin' => 'DA', 'doctor' => 'DR', 'reporting' => 'RP', 'staff' => 'ST'];
    public const PAPERS = ['a4' => 'A4', 'a5' => 'A5', 'thermal' => 'Thermal (80 mm)', 'legal' => 'Legal (8.5" × 14")'];

    /** Next employee code from the Super Admin's pattern (Settings → Codes). */
    public static function nextEmployeeCode(string $class, array $departmentIds, ?string $roleCode = null): string
    {
        $dept = 'GEN';
        if ($departmentIds) {
            $dept = (string) (DB::value(
                'SELECT code FROM departments WHERE id IN (' . DB::placeholders($departmentIds) . ') AND code IS NOT NULL AND code <> \'\' ORDER BY display_order, id LIMIT 1',
                array_values($departmentIds)
            ) ?: 'GEN');
        }
        return CodeGenerator::next(
            'emp',
            (string) setting('employee_code_pattern', 'EMP-{YYYY}-{0001}'),
            static fn (string $c): bool => DB::value('SELECT 1 FROM users WHERE employee_code = ?', [$c]) !== null,
            ['DEPT' => $dept, 'ROLE' => $roleCode ?: (self::ROLE_CODES[$class] ?? 'US')]
        );
    }

    /**
     * Roles the current user may hand out, grouped by tier.
     * Chain-level roles (tier A) need roles.manage.
     * @return array<string, array<int, array{id:int, code:string, name:string, class:string}>>
     */
    public static function assignableRoles(): array
    {
        $tiers = ['A' => 'System & chain', 'B' => 'Branch administration', 'C' => 'Clinical', 'D' => 'Operational & support'];
        $out = [];
        foreach (DB::all('SELECT id, code, name, tier, user_class FROM roles WHERE deleted_at IS NULL AND is_active = 1 ORDER BY tier, display_order, name') as $r) {
            if ($r['tier'] === 'A' && !Auth::can('roles.manage')) {
                continue;
            }
            $out[$tiers[$r['tier']] ?? 'Other'][] = ['id' => (int) $r['id'], 'code' => $r['code'], 'name' => $r['name'], 'class' => $r['user_class']];
        }
        return $out;
    }

    /**
     * The classic user type from the assigned roles: Super Admin if any role
     * is, doctor if any clinical-doctor role is, otherwise the primary role's.
     */
    public static function classFor(array $roleIds, ?int $primaryRoleId): string
    {
        $classes = [];
        foreach ($roleIds as $id) {
            $r = Rbac::role((int) $id);
            if ($r) {
                $classes[] = $r['user_class'];
            }
        }
        foreach (['super_admin', 'doctor'] as $c) {
            if (in_array($c, $classes, true)) {
                return $c;
            }
        }
        $primary = $primaryRoleId ? Rbac::role($primaryRoleId) : null;
        return $primary['user_class'] ?? ($classes[0] ?? 'staff');
    }

    /**
     * Replaces a user's role assignments.
     * @param array<int, array{role_id:int, branch_id:?int, department_id:?int, is_primary:bool}> $rows
     */
    public static function saveAssignments(int $userId, array $rows): void
    {
        DB::run('DELETE FROM user_role_branch WHERE user_id = ?', [$userId]);
        $primary = null;
        foreach ($rows as $r) {
            DB::insert('user_role_branch', [
                'user_id' => $userId, 'role_id' => $r['role_id'], 'branch_id' => $r['branch_id'], 'department_id' => $r['department_id'],
                'is_primary' => $r['is_primary'] ? 1 : 0, 'created_at' => now(), 'created_by' => Auth::id(),
            ]);
            if ($r['is_primary']) {
                $primary = $r['role_id'];
            }
        }
        $primary = $primary ?? ($rows[0]['role_id'] ?? null);
        DB::update('users', ['primary_role_id' => $primary], 'id = ?', [$userId]);
        Rbac::bump();
    }

    /** Role names of a user (for lists). */
    public static function roleNames(int $userId): array
    {
        return DB::column(
            'SELECT DISTINCT r.name FROM user_role_branch urb JOIN roles r ON r.id = urb.role_id WHERE urb.user_id = ? ORDER BY urb.is_primary DESC, r.name',
            [$userId]
        );
    }

    /** Doctor profile row + one print design per paper size. */
    public static function ensureDoctorSetup(int $userId): void
    {
        $user = DB::one('SELECT id, name FROM users WHERE id = ?', [$userId]);
        if (!$user) {
            return;
        }
        if (!DB::value('SELECT 1 FROM doctor_profiles WHERE user_id = ?', [$userId])) {
            DB::insert('doctor_profiles', ['user_id' => $userId, 'default_fee' => 0, 'updated_at' => now()]);
        }
        $profile = DB::one('SELECT * FROM doctor_profiles WHERE user_id = ?', [$userId]);
        foreach (array_keys(self::PAPERS) as $paper) {
            if (!DB::value('SELECT 1 FROM print_designs WHERE doctor_id = ? AND paper = ?', [$userId, $paper])) {
                DB::insert('print_designs', self::defaultDesign($userId, $paper, $user['name'], $profile));
            }
        }
    }

    public static function defaultDesign(int $doctorId, string $paper, string $doctorName, ?array $profile): array
    {
        $margins = ['a4' => 12, 'a5' => 8, 'thermal' => 3, 'legal' => 14];
        $fonts = ['a4' => 10.5, 'a5' => 9.0, 'thermal' => 8.5, 'legal' => 11.0];
        $lines = array_filter([
            $profile['specialization'] ?? null,
            !empty($profile['pmdc_registration']) ? 'PMDC Reg. No. ' . $profile['pmdc_registration'] : null,
        ]);
        return [
            'doctor_id'          => $doctorId,
            'paper'              => $paper,
            'show_logo'          => 1,
            'header_title'       => $doctorName,
            'header_subtitle'    => $profile['qualification'] ?? null,
            'header_lines'       => $lines ? implode("\n", $lines) : null,
            'clinic_info'        => setting('org_name') . "\n" . setting('org_address'),
            'footer_address'     => (string) setting('org_address'),
            'footer_phone'       => (string) setting('org_phone'),
            'footer_followup'    => 'Please bring this prescription on your next visit.',
            'footer_disclaimer'  => 'Not valid for medico-legal purposes. Consult your doctor before changing any medicine.',
            'footer_appointment' => 'For appointments call ' . setting('org_phone'),
            'margin_mm'          => $margins[$paper],
            'font_size_pt'       => $fonts[$paper],
            'show_prices'        => $paper === 'thermal' ? 1 : 1,
            'show_billing'       => 1,
            'show_signature'     => $paper === 'thermal' ? 0 : 1,
            'updated_at'         => now(),
        ];
    }

    /** Active super admins other than $exceptId — used to protect the last one. */
    public static function otherActiveSuperAdmins(int $exceptId): int
    {
        return (int) DB::value(
            "SELECT COUNT(*) FROM users WHERE role = 'super_admin' AND is_active = 1 AND deleted_at IS NULL AND id <> ?",
            [$exceptId]
        );
    }
}
