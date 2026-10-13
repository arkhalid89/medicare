<?php
declare(strict_types=1);

namespace App\Modules\Patients;

use App\Core\ActivityLog;
use App\Core\Auth;
use App\Core\CodeGenerator;
use App\Core\DB;
use App\Core\Validator;

/**
 * Patient registration, update, search and duplicate (family) detection.
 * Used by the Patients screens and by the one-page consultation checkout.
 */
final class PatientService
{
    public const GENDERS = ['Male', 'Female', 'Other'];
    public const BLOOD_GROUPS = ['A+', 'A-', 'B+', 'B-', 'AB+', 'AB-', 'O+', 'O-'];

    /**
     * Validates and normalises patient input.
     * @return array{0: array, 1: array} [clean data, errors]
     */
    public static function validate(array $in): array
    {
        $v = new Validator($in);
        $v->required('name', 'Patient name')->max('name', 'Patient name', 150)
            ->max('guardian_name', 'Father / Husband name', 150)
            ->required('gender', 'Gender')->in('gender', 'Gender', self::GENDERS)
            ->cnic('cnic', 'CNIC')
            ->phone('phone', 'Phone')
            ->date('dob', 'Date of birth')
            ->integer('age', 'Age', 0, 130)
            ->integer('age_years', 'Age (years)', 0, 130)
            ->integer('age_months', 'Age (months)', 0, 11)
            ->integer('age_days', 'Age (days)', 0, 31)
            ->max('allergies', 'Allergies', 500)
            ->in('blood_group', 'Blood group', self::BLOOD_GROUPS)
            ->max('address', 'Address', 255)
            ->max('city', 'City', 80)
            ->max('notes', 'Notes', 2000);

        $dob = trim((string) ($in['dob'] ?? ''));
        if ($dob !== '' && !isset($v->errors()['dob']) && $dob > today()) {
            $v->add('dob', 'Date of birth cannot be in the future.');
        }

        $estimated = 0;
        $years = trim((string) ($in['age_years'] ?? $in['age'] ?? ''));
        $months = trim((string) ($in['age_months'] ?? ''));
        $days = trim((string) ($in['age_days'] ?? ''));
        $ageGiven = ($years !== '' || $months !== '' || $days !== '')
            && !array_intersect_key($v->errors(), array_flip(['age', 'age_years', 'age_months', 'age_days']));
        if ($dob !== '') {
            $estimated = !empty($in['dob_estimated']) ? 1 : 0;
        } elseif ($ageGiven) {
            // Only age known (years, months and/or days — infants): store the
            // matching date of birth so the age stays correct over time.
            $dob = self::dobFromAge((int) $years, (int) $months, (int) $days);
            $estimated = 1;
        }

        $clean = [
            'name'          => self::title(trim((string) ($in['name'] ?? ''))),
            'guardian_name' => self::nullIfEmpty(self::title(trim((string) ($in['guardian_name'] ?? '')))),
            'cnic'          => normalize_cnic((string) ($in['cnic'] ?? '')),
            'phone'         => normalize_phone((string) ($in['phone'] ?? '')),
            'gender'        => (string) ($in['gender'] ?? ''),
            'dob'           => $dob !== '' ? $dob : null,
            'dob_estimated' => $dob !== '' ? $estimated : 0,
            'blood_group'   => self::nullIfEmpty(trim((string) ($in['blood_group'] ?? ''))),
            'allergies'     => self::nullIfEmpty(trim((string) ($in['allergies'] ?? ''))),
            'address'       => self::nullIfEmpty(trim((string) ($in['address'] ?? ''))),
            'city'          => self::nullIfEmpty(self::title(trim((string) ($in['city'] ?? '')))),
            'notes'         => self::nullIfEmpty(trim((string) ($in['notes'] ?? ''))),
        ];
        return [$clean, $v->errors()];
    }

    /**
     * Registers a patient. The MRN follows the doctor's own pattern (v1 mode)
     * or the hospital-wide pattern (Settings → Hospital: MRN mode "chain", and
     * always when the front desk registers without a doctor).
     */
    public static function create(array $data, ?int $doctorId, array $extra = []): array
    {
        $doctor = null;
        if ($doctorId) {
            $doctor = DB::one(
                "SELECT u.id, u.name, dp.mrn_pattern FROM users u LEFT JOIN doctor_profiles dp ON dp.user_id = u.id
                  WHERE u.id = ? AND u.role = 'doctor' AND u.deleted_at IS NULL",
                [$doctorId]
            );
            if (!$doctor) {
                throw new \InvalidArgumentException('A valid doctor is required to register a patient.');
            }
        }
        $chain = !$doctor || setting('mrn_mode', 'doctor') === 'chain';
        $pattern = $chain
            ? (string) setting('chain_mrn_pattern', 'MC-{YYYY}-{000001}')
            : (trim((string) ($doctor['mrn_pattern'] ?? '')) ?: (string) setting('default_mrn_pattern', 'MR-{YYYY}-{000001}'));
        $scope = $chain ? 'mrn:chain' : 'mrn:' . $doctorId;
        $allowed = array_flip(['registration_type', 'mother_patient_id', 'photo_path', 'occupation', 'marital_status', 'preferred_language']);

        return DB::transaction(static function () use ($data, $doctorId, $pattern, $scope, $extra, $allowed): array {
            $mrn = CodeGenerator::next(
                $scope,
                $pattern,
                static fn (string $code): bool => DB::value('SELECT 1 FROM patients WHERE mrn = ?', [$code]) !== null
            );
            $id = DB::insert('patients', array_merge($data, array_intersect_key($extra, $allowed), [
                'mrn'               => $mrn,
                'doctor_id'         => $doctorId,
                'created_branch_id' => Auth::branchId(),
                'created_at'        => now(),
                'created_by'        => Auth::id(),
            ]));
            if (!empty($data['cnic'])) {
                DB::insert('patient_identifiers', ['patient_id' => $id, 'id_type' => 'cnic', 'id_value' => $data['cnic'], 'is_primary' => 1,
                    'created_at' => now(), 'created_by' => Auth::id()]);
            }
            ActivityLog::record('Patient created', 'patients', (string) $id, 'Registered ' . $data['name'] . ' (' . $mrn . ')');
            return self::find($id);
        });
    }

    /** Extra demographics of v2 (occupation, marital status, language). */
    public static function validateExtra(array $in): array
    {
        $out = [];
        $out['occupation'] = mb_substr(trim((string) ($in['occupation'] ?? '')), 0, 100) ?: null;
        $ms = (string) ($in['marital_status'] ?? '');
        $out['marital_status'] = isset(hospital('marital_statuses')[$ms]) ? $ms : null;
        $lang = (string) ($in['preferred_language'] ?? '');
        $out['preferred_language'] = isset(hospital('languages')[$lang]) ? $lang : null;
        return $out;
    }

    /**
     * May the current user still edit this patient's demographics? Front desk
     * edits only until the first visit; after that patients.edit_any (RGS / HA)
     * is needed — CNIC changes after registration are supervisor work (Doc 8 §3.1).
     */
    public static function canEdit(int $patientId): bool
    {
        if (Auth::can('patients.edit_any')) {
            return true;
        }
        if (!Auth::can('patients.edit')) {
            return false;
        }
        if (Auth::isDoctor()) {
            return true;
        }
        return !DB::value('SELECT 1 FROM visits WHERE patient_id = ? AND deleted_at IS NULL LIMIT 1', [$patientId]);
    }

    /**
     * Fuzzy duplicate check before saving (Doc 8 §5.1): same CNIC, or same
     * phone, or same name with the same date of birth / father's name.
     */
    public static function possibleDuplicates(array $data, int $excludeId = 0): array
    {
        $rows = self::duplicates($data['cnic'] ?? null, $data['phone'] ?? null, $excludeId);
        $seen = array_column($rows, 'id');
        if (!empty($data['name'])) {
            $params = [$data['name'], $excludeId];
            if (!empty($data['dob'])) {
                $extra = ' AND (dob = ? OR guardian_name = ?)';
                $params[] = $data['dob'];
                $params[] = (string) ($data['guardian_name'] ?? '');
            } elseif (!empty($data['guardian_name'])) {
                $extra = ' AND guardian_name = ?';
                $params[] = $data['guardian_name'];
            } else {
                $extra = ' AND 1 = 0';
            }
            foreach (DB::all('SELECT id, mrn, name, guardian_name, gender, dob, cnic, phone, city, address, blood_group, allergies, dob_estimated FROM patients
                               WHERE deleted_at IS NULL AND merged_into_patient_id IS NULL AND name = ? AND id <> ?' . $extra . ' LIMIT 10', $params) as $r) {
                if (!in_array((int) $r['id'], $seen, true)) {
                    $rows[] = self::forClient($r);
                }
            }
        }
        return $rows;
    }

    /**
     * Merges a duplicate into the record that is kept (RGS / HA approval).
     * Every visit, token, invoice and clinical record moves to the kept patient;
     * the duplicate is soft-deleted and points to the kept one.
     */
    public static function merge(int $keepId, int $mergeId, string $reason): void
    {
        if ($keepId === $mergeId) {
            throw new \InvalidArgumentException('Choose two different patients.');
        }
        $keep = self::find($keepId);
        $merge = self::find($mergeId);
        if (!$keep || !$merge) {
            throw new \InvalidArgumentException('Patient not found.');
        }
        DB::transaction(static function () use ($keep, $merge, $keepId, $mergeId, $reason): void {
            $moved = [];
            $tables = DB::column("SELECT DISTINCT TABLE_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND COLUMN_NAME = 'patient_id'");
            foreach ($tables as $table) {
                if ($table === 'patient_merge_log') {
                    continue;
                }
                if (in_array($table, ['gynecologic_history', 'obstetric_history'], true) && DB::value('SELECT 1 FROM `' . $table . '` WHERE patient_id = ?', [$keepId])) {
                    continue;
                }
                $n = DB::run('UPDATE `' . $table . '` SET patient_id = ? WHERE patient_id = ?', [$keepId, $mergeId])->rowCount();
                if ($n) {
                    $moved[$table] = $n;
                }
            }
            DB::run('UPDATE patients SET mother_patient_id = ? WHERE mother_patient_id = ?', [$keepId, $mergeId]);
            $fill = [];
            foreach (['cnic', 'phone', 'dob', 'guardian_name', 'blood_group', 'address', 'city'] as $f) {
                if (empty($keep[$f]) && !empty($merge[$f])) {
                    $fill[$f] = $merge[$f];
                }
            }
            if ($merge['allergies'] && stripos((string) $keep['allergies'], (string) $merge['allergies']) === false) {
                $fill['allergies'] = trim(($keep['allergies'] ? $keep['allergies'] . ', ' : '') . $merge['allergies']);
            }
            if ($fill) {
                DB::update('patients', $fill + ['updated_at' => now(), 'updated_by' => Auth::id()], 'id = ?', [$keepId]);
            }
            DB::update('patients', ['merged_into_patient_id' => $keepId, 'deleted_at' => now(), 'deleted_by' => Auth::id()], 'id = ?', [$mergeId]);
            DB::insert('patient_merge_log', ['kept_patient_id' => $keepId, 'merged_patient_id' => $mergeId, 'merged_by' => Auth::id(),
                'reason' => mb_substr($reason, 0, 500), 'snapshot_json' => json_encode(['merged' => $merge, 'moved' => $moved], JSON_UNESCAPED_UNICODE), 'merged_at' => now()]);
            ActivityLog::record('Patients merged', 'patients', (string) $keepId, $merge['mrn'] . ' merged into ' . $keep['mrn'] . ' — ' . $reason,
                null, null, ['entity' => 'patients', 'old' => ['merged' => $merge['mrn']], 'new' => ['kept' => $keep['mrn'], 'moved' => $moved]]);
        });
    }

    /**
     * Emergency / unidentified registration (Doc 3 WF 7, Doc 7 §2 rule 4):
     * a temporary ER ID now, CNIC and real name captured later (then merged
     * or simply edited).
     */
    public static function createEmergency(string $description, string $gender, ?int $approxAge): array
    {
        $tempId = 'ER-' . date('ymd-His') . '-' . random_int(10, 99);
        $data = [
            'name' => 'Unidentified (' . mb_substr(trim($description) ?: 'ER', 0, 100) . ')', 'guardian_name' => null, 'cnic' => null, 'phone' => null,
            'gender' => in_array($gender, self::GENDERS, true) ? $gender : 'Other', 'dob' => $approxAge !== null ? self::dobFromAge($approxAge, 0, 0) : null,
            'dob_estimated' => 1, 'blood_group' => null, 'allergies' => null, 'address' => null, 'city' => null, 'notes' => 'Emergency registration — identify and update.',
        ];
        $p = self::create($data, null, ['registration_type' => 'emergency_unidentified']);
        DB::insert('patient_identifiers', ['patient_id' => $p['id'], 'id_type' => 'temp_er_id', 'id_value' => $tempId, 'is_primary' => 0,
            'created_at' => now(), 'created_by' => Auth::id()]);
        return $p;
    }

    public static function update(int $id, array $data): void
    {
        $old = DB::one('SELECT * FROM patients WHERE id = ?', [$id]) ?? [];
        DB::update('patients', array_merge($data, ['updated_at' => now(), 'updated_by' => Auth::id()]), 'id = ?', [$id]);
        [$before, $after] = ActivityLog::diff($old, $data);
        ActivityLog::record('Patient updated', 'patients', (string) $id, 'Updated patient ' . $data['name'], null, null,
            $after ? ['entity' => 'patients', 'old' => $before, 'new' => $after] : []);
        if (!empty($data['cnic']) && ($old['cnic'] ?? null) !== $data['cnic']
            && !DB::value("SELECT 1 FROM patient_identifiers WHERE patient_id = ? AND id_type = 'cnic' AND id_value = ?", [$id, $data['cnic']])) {
            DB::run("UPDATE patient_identifiers SET is_primary = 0 WHERE patient_id = ? AND id_type = 'cnic'", [$id]);
            DB::insert('patient_identifiers', ['patient_id' => $id, 'id_type' => 'cnic', 'id_value' => $data['cnic'], 'is_primary' => 1,
                'created_at' => now(), 'created_by' => Auth::id()]);
        }
    }

    public static function find(int $id, bool $withDeleted = false): ?array
    {
        $row = DB::one(
            'SELECT p.*, u.name AS doctor_name FROM patients p LEFT JOIN users u ON u.id = p.doctor_id WHERE p.id = ?'
            . ($withDeleted ? '' : ' AND p.deleted_at IS NULL'),
            [$id]
        );
        if ($row) {
            $row['age'] = age_text($row['dob']);
        }
        return $row;
    }

    /**
     * Patients sharing a CNIC or phone (family members) — BRD §31.
     */
    public static function duplicates(?string $cnic, ?string $phone, int $excludeId = 0): array
    {
        $cnic = normalize_cnic($cnic);
        $phone = normalize_phone($phone);
        if (!$cnic && !$phone) {
            return [];
        }
        $where = [];
        $params = [];
        if ($cnic) {
            $where[] = 'cnic = ?';
            $params[] = $cnic;
        }
        if ($phone) {
            $where[] = 'phone = ?';
            $params[] = $phone;
        }
        $params[] = $excludeId;
        $rows = DB::all(
            'SELECT id, mrn, name, guardian_name, gender, dob, cnic, phone, city, address, blood_group, allergies, dob_estimated FROM patients
              WHERE deleted_at IS NULL AND (' . implode(' OR ', $where) . ') AND id <> ? ORDER BY name LIMIT 20',
            $params
        );
        return array_map([self::class, 'forClient'], $rows);
    }

    /**
     * Live search by MRN, CNIC, phone or name (BRD §32). Uses index-friendly
     * prefix matches so it stays fast with 500,000+ patients.
     */
    public static function search(string $q, int $limit = 15): array
    {
        $q = trim($q);
        if (mb_strlen($q) < 2) {
            return [];
        }
        $digits = preg_replace('/\D/', '', $q);
        $like = like_escape($q) . '%';
        $conditions = ['mrn LIKE ?', 'name LIKE ?', 'name LIKE ?'];
        $params = [$like, $like, '% ' . like_escape($q) . '%'];
        if ($digits !== '' && strlen($digits) >= 3 && strlen($digits) === strlen(preg_replace('/[\s\-]/', '', $q))) {
            $conditions[] = 'phone LIKE ?';
            $params[] = $digits . '%';
            $conditions[] = 'cnic LIKE ?';
            $params[] = like_escape(cnic_prefix($digits)) . '%';
        }
        $rows = DB::all(
            'SELECT id, mrn, name, guardian_name, gender, dob, cnic, phone, city, address, blood_group, allergies, dob_estimated FROM patients
              WHERE deleted_at IS NULL AND (' . implode(' OR ', $conditions) . ')
              ORDER BY (mrn = ?) DESC, name LIMIT ' . max(1, min(50, $limit)),
            array_merge($params, [$q])
        );
        return array_map([self::class, 'forClient'], $rows);
    }

    /** Shape used by the consultation screen and search dropdowns. */
    public static function forClient(array $p): array
    {
        [$y, $m, $d] = self::ageParts($p['dob'] ?? null);
        return [
            'id'            => (int) $p['id'],
            'mrn'           => $p['mrn'],
            'name'          => $p['name'],
            'guardian_name' => $p['guardian_name'] ?? '',
            'gender'        => $p['gender'],
            'dob'           => $p['dob'] ?? '',
            'dob_estimated' => (int) ($p['dob_estimated'] ?? 0),
            'age'           => age_text($p['dob'] ?? null),
            'age_years'     => age_years($p['dob'] ?? null),
            'age_y'         => $y,
            'age_m'         => $m,
            'age_d'         => $d,
            'allergies'     => $p['allergies'] ?? '',
            'visits'        => isset($p['id']) ? (int) DB::value('SELECT COUNT(*) FROM visits WHERE patient_id = ? AND deleted_at IS NULL', [$p['id']]) : 0,
            'cnic'          => $p['cnic'] ?? '',
            'phone'         => $p['phone'] ?? '',
            'city'          => $p['city'] ?? '',
            'address'       => $p['address'] ?? '',
            'blood_group'   => $p['blood_group'] ?? '',
        ];
    }

    /** Date of birth from an age in years + months + days. */
    public static function dobFromAge(int $years, int $months, int $days, ?string $today = null): string
    {
        $d = new \DateTime($today ?? 'today');
        $d->modify('-' . max(0, $years) . ' years')->modify('-' . max(0, $months) . ' months')->modify('-' . max(0, $days) . ' days');
        return $d->format('Y-m-d');
    }

    /** @return array{0: ?int, 1: ?int, 2: ?int} years, months, days */
    public static function ageParts(?string $dob, ?string $today = null): array
    {
        if (!$dob) {
            return [null, null, null];
        }
        try {
            $diff = (new \DateTime($dob))->diff(new \DateTime($today ?? 'today'));
        } catch (\Exception $e) {
            return [null, null, null];
        }
        return $diff->invert ? [0, 0, 0] : [$diff->y, $diff->m, $diff->d];
    }

    private static function nullIfEmpty(?string $v): ?string
    {
        return $v === null || $v === '' ? null : $v;
    }

    /** "muhammad ali" => "Muhammad Ali"; keeps existing capitals (e.g. "McDonald"). */
    private static function title(string $s): string
    {
        return preg_replace_callback('/\b([a-z])/u', static fn ($m) => mb_strtoupper($m[1]), $s) ?? $s;
    }
}
