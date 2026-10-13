<?php
declare(strict_types=1);

namespace App\Modules\Specialty;

use App\Core\Auth;
use App\Core\DB;
use App\Core\Migrator;

/**
 * Which specialty an encounter belongs to (Doc 6 §3): driven by the
 * ENCOUNTER's department → specialty, never by the user. A dentist opening a
 * General-OPD encounter sees the generic consultation only (Doc 8 §7 #1).
 */
final class SpecialtyContext
{
    private static ?array $map = null;

    /** department_id => ['specialty_id', 'module_key', 'specialty'] for clinical departments. */
    public static function departments(): array
    {
        if (self::$map === null) {
            self::$map = [];
            if (Migrator::tableExists('specialties')) {
                foreach (DB::all("SELECT d.id, d.specialty_id, COALESCE(s.module_key, '') AS module_key, s.name
                                    FROM departments d LEFT JOIN specialties s ON s.id = d.specialty_id AND s.deleted_at IS NULL
                                   WHERE d.deleted_at IS NULL") as $r) {
                    self::$map[(int) $r['id']] = ['specialty_id' => $r['specialty_id'] ? (int) $r['specialty_id'] : null, 'module_key' => (string) $r['module_key'], 'specialty' => $r['name']];
                }
            }
        }
        return self::$map;
    }

    public static function forDepartment(?int $deptId): array
    {
        return self::departments()[(int) $deptId] ?? ['specialty_id' => null, 'module_key' => '', 'specialty' => null];
    }

    public static function moduleOf(?int $deptId): string
    {
        return self::forDepartment($deptId)['module_key'];
    }

    /** Department ids whose specialty uses the given module key. */
    public static function departmentsWith(string $moduleKey): array
    {
        return array_keys(array_filter(self::departments(), static fn (array $d): bool => $d['module_key'] === $moduleKey));
    }

    public static function visit(int $visitId): ?array
    {
        $v = DB::one('SELECT v.id, v.patient_id, v.doctor_id, v.department_id, v.visit_date, v.visit_no, p.name AS patient_name, p.gender, p.dob, p.mrn
                        FROM visits v JOIN patients p ON p.id = v.patient_id WHERE v.id = ?', [$visitId]);
        if ($v) {
            $v += self::forDepartment($v['department_id'] ? (int) $v['department_id'] : null);
        }
        return $v;
    }

    /**
     * Restricted clinical notes (Doc 1 §2.8): author, the encounter's doctor,
     * users the note is shared with, a department head, or active break-glass.
     */
    public static function canSeeRestricted(array $note): bool
    {
        $uid = Auth::id();
        if ((int) $note['author_id'] === $uid || Auth::hasRole('SA')) {
            return true;
        }
        if (in_array((string) $uid, array_filter(explode(',', (string) ($note['shared_user_ids'] ?? ''))), true)) {
            return true;
        }
        if (!empty($note['encounter_id']) && (int) DB::value('SELECT doctor_id FROM visits WHERE id = ?', [$note['encounter_id']]) === $uid) {
            return true;
        }
        return Auth::hasRole('DH') || \App\Core\Scope::hasEmergencyAccess((int) $note['patient_id']);
    }
}
