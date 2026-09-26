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
            ->in('blood_group', 'Blood group', self::BLOOD_GROUPS)
            ->max('address', 'Address', 255)
            ->max('city', 'City', 80)
            ->max('notes', 'Notes', 2000);

        $dob = trim((string) ($in['dob'] ?? ''));
        if ($dob !== '' && !isset($v->errors()['dob']) && $dob > today()) {
            $v->add('dob', 'Date of birth cannot be in the future.');
        }

        $estimated = 0;
        if ($dob !== '') {
            $estimated = !empty($in['dob_estimated']) ? 1 : 0;
        } elseif (isset($in['age']) && trim((string) $in['age']) !== '' && !isset($v->errors()['age'])) {
            // Only age known: store an estimated date of birth so age stays correct over time.
            $dob = date('Y-m-d', strtotime('-' . (int) $in['age'] . ' years'));
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
            'address'       => self::nullIfEmpty(trim((string) ($in['address'] ?? ''))),
            'city'          => self::nullIfEmpty(self::title(trim((string) ($in['city'] ?? '')))),
            'notes'         => self::nullIfEmpty(trim((string) ($in['notes'] ?? ''))),
        ];
        return [$clean, $v->errors()];
    }

    /** Registers a patient with an MRN from the doctor's own pattern. */
    public static function create(array $data, int $doctorId): array
    {
        $doctor = DB::one(
            "SELECT u.id, u.name, dp.mrn_pattern FROM users u LEFT JOIN doctor_profiles dp ON dp.user_id = u.id
              WHERE u.id = ? AND u.role = 'doctor' AND u.deleted_at IS NULL",
            [$doctorId]
        );
        if (!$doctor) {
            throw new \InvalidArgumentException('A valid doctor is required to register a patient.');
        }
        $pattern = trim((string) ($doctor['mrn_pattern'] ?? '')) ?: (string) setting('default_mrn_pattern', 'MR-{YYYY}-{000001}');

        return DB::transaction(static function () use ($data, $doctorId, $pattern): array {
            $mrn = CodeGenerator::next(
                'mrn:' . $doctorId,
                $pattern,
                static fn (string $code): bool => DB::value('SELECT 1 FROM patients WHERE mrn = ?', [$code]) !== null
            );
            $id = DB::insert('patients', array_merge($data, [
                'mrn'        => $mrn,
                'doctor_id'  => $doctorId,
                'created_at' => now(),
                'created_by' => Auth::id(),
            ]));
            ActivityLog::record('Patient created', 'patients', (string) $id, 'Registered ' . $data['name'] . ' (' . $mrn . ')');
            return self::find($id);
        });
    }

    public static function update(int $id, array $data): void
    {
        DB::update('patients', array_merge($data, ['updated_at' => now(), 'updated_by' => Auth::id()]), 'id = ?', [$id]);
        ActivityLog::record('Patient updated', 'patients', (string) $id, 'Updated patient ' . $data['name']);
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
            'SELECT id, mrn, name, guardian_name, gender, dob, cnic, phone, city, address, blood_group, dob_estimated FROM patients
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
            'SELECT id, mrn, name, guardian_name, gender, dob, cnic, phone, city, address, blood_group, dob_estimated FROM patients
              WHERE deleted_at IS NULL AND (' . implode(' OR ', $conditions) . ')
              ORDER BY (mrn = ?) DESC, name LIMIT ' . max(1, min(50, $limit)),
            array_merge($params, [$q])
        );
        return array_map([self::class, 'forClient'], $rows);
    }

    /** Shape used by the consultation screen and search dropdowns. */
    public static function forClient(array $p): array
    {
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
            'cnic'          => $p['cnic'] ?? '',
            'phone'         => $p['phone'] ?? '',
            'city'          => $p['city'] ?? '',
            'address'       => $p['address'] ?? '',
            'blood_group'   => $p['blood_group'] ?? '',
        ];
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
