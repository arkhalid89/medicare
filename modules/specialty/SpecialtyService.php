<?php
declare(strict_types=1);

namespace App\Modules\Specialty;

use App\Core\ActivityLog;
use App\Core\Auth;
use App\Core\DB;
use App\Modules\Inventory\StockService;

/**
 * Tier 1 configurable fields, body-map findings and the light first-class
 * specialty tables (Doc 7 §3.3), plus generic clinical notes and referrals.
 */
final class SpecialtyService
{
    public const FIELD_TYPES = ['text' => 'Text', 'number' => 'Number', 'date' => 'Date', 'boolean' => 'Yes / No', 'select' => 'Single choice', 'multiselect' => 'Multiple choice'];
    public const LATERALITY = ['na' => 'N/A', 'left' => 'Left', 'right' => 'Right', 'bilateral' => 'Bilateral'];
    public const FINDING_TYPES = ['Rash', 'Lesion', 'Ulcer', 'Wound', 'Swelling', 'Pain / tenderness', 'Fracture', 'Deformity', 'Burn', 'Scar', 'Mass', 'Other'];
    public const CARDIAC_TYPES = ['ecg' => 'ECG', 'echo' => 'Echocardiogram', 'tmt' => 'Treadmill test (TMT)', 'holter' => 'Holter'];
    public const AUDIO_FREQS = [250, 500, 1000, 2000, 4000, 8000];
    public const SNELLEN = ['6/6', '6/9', '6/12', '6/18', '6/24', '6/36', '6/60', 'CF', 'HM', 'PL', 'NPL'];

    // ------------------------------------------------------------ Tier 1

    /** Active fields of a specialty (branch overrides first). */
    public static function templates(int $specialtyId): array
    {
        $rows = DB::all('SELECT * FROM clinical_parameter_templates WHERE specialty_id = ? AND is_active = 1 AND (branch_id IS NULL OR branch_id = ?)
                          ORDER BY display_order, id', [$specialtyId, Auth::branchId()]);
        foreach ($rows as &$r) {
            $r['options'] = $r['options_json'] ? (json_decode($r['options_json'], true) ?: []) : [];
        }
        return $rows;
    }

    /** @return string[] errors */
    public static function validateParameters(int $specialtyId, array $values): array
    {
        $errors = [];
        foreach (self::templates($specialtyId) as $f) {
            $v = $values[$f['id']] ?? null;
            $empty = $v === null || $v === '' || $v === [] || ($f['field_type'] === 'boolean' && $v === false && $f['is_required']);
            if ($f['is_required'] && $empty) {
                $errors[] = $f['label'] . ' is required.';
                continue;
            }
            if ($empty) {
                continue;
            }
            if ($f['field_type'] === 'number' && !is_numeric($v)) {
                $errors[] = $f['label'] . ' must be a number.';
            } elseif ($f['field_type'] === 'date' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $v)) {
                $errors[] = $f['label'] . ' must be a date.';
            } elseif ($f['field_type'] === 'select' && !in_array((string) $v, $f['options'], true)) {
                $errors[] = $f['label'] . ': choose one of the listed options.';
            } elseif ($f['field_type'] === 'multiselect' && array_diff((array) $v, $f['options'])) {
                $errors[] = $f['label'] . ': choose from the listed options.';
            }
        }
        return $errors;
    }

    public static function saveParameters(int $visitId, int $specialtyId, array $values): int
    {
        $n = 0;
        foreach (self::templates($specialtyId) as $f) {
            $v = $values[$f['id']] ?? null;
            if ($v === null || $v === '' || $v === []) {
                continue;
            }
            $row = ['encounter_id' => $visitId, 'template_field_id' => $f['id'], 'value_text' => null, 'value_number' => null, 'value_json' => null,
                'recorded_by' => Auth::id(), 'recorded_at' => now()];
            match ($f['field_type']) {
                'number' => $row['value_number'] = (float) $v,
                'multiselect' => $row['value_json'] = json_encode(array_values((array) $v), JSON_UNESCAPED_UNICODE),
                'boolean' => $row['value_text'] = $v ? 'Yes' : 'No',
                default => $row['value_text'] = mb_substr((string) $v, 0, 1000),
            };
            DB::run('DELETE FROM encounter_clinical_parameters WHERE encounter_id = ? AND template_field_id = ?', [$visitId, $f['id']]);
            DB::insert('encounter_clinical_parameters', $row);
            $n++;
        }
        return $n;
    }

    /** Captured values of an encounter: [label, value, unit]. */
    public static function parameters(int $visitId): array
    {
        $out = [];
        foreach (DB::all('SELECT e.*, t.label, t.unit, t.field_type, s.name AS specialty FROM encounter_clinical_parameters e
                            JOIN clinical_parameter_templates t ON t.id = e.template_field_id JOIN specialties s ON s.id = t.specialty_id
                           WHERE e.encounter_id = ? ORDER BY t.display_order', [$visitId]) as $r) {
            $val = $r['value_number'] !== null ? rtrim(rtrim((string) $r['value_number'], '0'), '.')
                : ($r['value_json'] !== null ? implode(', ', json_decode($r['value_json'], true) ?: []) : (string) $r['value_text']);
            $out[] = ['specialty' => $r['specialty'], 'label' => $r['label'], 'value' => $val, 'unit' => $r['unit']];
        }
        return $out;
    }

    // ------------------------------------------------------------ body map

    public static function bodyMaps(?int $specialtyId = null): array
    {
        $tpl = DB::all('SELECT * FROM body_map_templates WHERE is_active = 1' . ($specialtyId ? ' AND (specialty_id = ? OR specialty_id IS NULL)' : '') . ' ORDER BY id',
            $specialtyId ? [$specialtyId] : []);
        foreach ($tpl as &$t) {
            $t['regions'] = array_map(static function (array $r): array {
                $r['shape'] = json_decode((string) $r['shape_json'], true) ?: [];
                unset($r['shape_json']);
                return $r;
            }, DB::all('SELECT * FROM body_map_regions WHERE template_id = ? ORDER BY display_order, id', [$t['id']]));
        }
        return $tpl;
    }

    public static function saveBodyMap(int $visitId, int $patientId, array $findings): int
    {
        $n = 0;
        foreach ($findings as $f) {
            $region = (int) ($f['region_id'] ?? 0);
            if (!$region || !DB::value('SELECT 1 FROM body_map_regions WHERE id = ?', [$region])) {
                continue;
            }
            DB::insert('encounter_body_map_findings', ['encounter_id' => $visitId, 'patient_id' => $patientId, 'region_id' => $region,
                'laterality' => isset(self::LATERALITY[$f['laterality'] ?? '']) ? $f['laterality'] : 'na',
                'finding_type' => mb_substr(trim((string) ($f['finding_type'] ?? 'Other')) ?: 'Other', 0, 60),
                'severity' => in_array($f['severity'] ?? '', ['mild', 'moderate', 'severe'], true) ? $f['severity'] : null,
                'size_cm' => is_numeric($f['size_cm'] ?? null) ? (float) $f['size_cm'] : null,
                'description' => mb_substr(trim((string) ($f['description'] ?? '')), 0, 500) ?: null, 'recorded_by' => Auth::id(), 'recorded_at' => now()]);
            $n++;
        }
        return $n;
    }

    // ----------------------------------------------------- first-class

    public static function saveEye(int $visitId, int $patientId, array $d): void
    {
        $has = array_filter($d, static fn ($v) => $v !== null && $v !== '' && $v !== []);
        if (!$has) {
            return;
        }
        $num = static fn ($v): ?float => is_numeric($v) ? (float) $v : null;
        $va = static fn ($v): ?string => in_array($v, self::SNELLEN, true) ? $v : null;
        $ref = [];
        foreach (['od', 'os'] as $eye) {
            $ref[$eye] = ['sph' => $num($d['ref_' . $eye . '_sph'] ?? null), 'cyl' => $num($d['ref_' . $eye . '_cyl'] ?? null), 'axis' => $num($d['ref_' . $eye . '_axis'] ?? null)];
        }
        DB::run('DELETE FROM eye_exams WHERE encounter_id = ?', [$visitId]);
        DB::insert('eye_exams', ['encounter_id' => $visitId, 'patient_id' => $patientId,
            'va_od_unaided' => $va($d['va_od_unaided'] ?? null), 'va_os_unaided' => $va($d['va_os_unaided'] ?? null),
            'va_od_corrected' => $va($d['va_od_corrected'] ?? null), 'va_os_corrected' => $va($d['va_os_corrected'] ?? null),
            'iop_od_mmhg' => $num($d['iop_od'] ?? null), 'iop_os_mmhg' => $num($d['iop_os'] ?? null),
            'refraction_json' => json_encode($ref), 'slit_lamp_json' => json_encode(['od' => (string) ($d['slit_od'] ?? ''), 'os' => (string) ($d['slit_os'] ?? '')]),
            'fundus_findings' => mb_substr((string) ($d['fundus'] ?? ''), 0, 1000) ?: null, 'recorded_by' => Auth::id(), 'recorded_at' => now()]);
    }

    public static function saveCardiac(int $visitId, int $patientId, array $d): void
    {
        if (empty($d['type']) || !isset(self::CARDIAC_TYPES[$d['type']])) {
            return;
        }
        DB::insert('cardiac_investigations', ['encounter_id' => $visitId, 'patient_id' => $patientId, 'type' => $d['type'],
            'rhythm' => mb_substr((string) ($d['rhythm'] ?? ''), 0, 60) ?: null, 'heart_rate' => is_numeric($d['heart_rate'] ?? null) ? (int) $d['heart_rate'] : null,
            'ef_percent' => is_numeric($d['ef_percent'] ?? null) ? (float) $d['ef_percent'] : null, 'findings' => mb_substr((string) ($d['findings'] ?? ''), 0, 1000) ?: null,
            'performed_by' => Auth::id(), 'performed_at' => now()]);
    }

    /** @param array $d ['L' => [freq => dB], 'R' => [...], 'tymp_L', 'tymp_R'] */
    public static function saveAudiogram(int $visitId, int $patientId, array $d): void
    {
        foreach (['L', 'R'] as $ear) {
            $air = [];
            foreach (self::AUDIO_FREQS as $f) {
                if (is_numeric($d[$ear][$f] ?? null)) {
                    $air[$f] = (int) $d[$ear][$f];
                }
            }
            if (!$air) {
                continue;
            }
            $pta = array_filter([$air[500] ?? null, $air[1000] ?? null, $air[2000] ?? null], 'is_int');
            DB::run('DELETE FROM audiograms WHERE encounter_id = ? AND ear = ?', [$visitId, $ear]);
            DB::insert('audiograms', ['encounter_id' => $visitId, 'patient_id' => $patientId, 'ear' => $ear, 'air_conduction_json' => json_encode($air),
                'tympanometry_type' => in_array($d['tymp_' . $ear] ?? '', ['A', 'As', 'Ad', 'B', 'C'], true) ? $d['tymp_' . $ear] : null,
                'pta_db' => $pta ? round(array_sum($pta) / count($pta), 1) : null, 'performed_by' => Auth::id(), 'performed_at' => now()]);
        }
    }

    public static function saveGrowth(int $patientId, ?int $visitId, array $d, ?string $date = null): ?int
    {
        $w = is_numeric($d['weight_kg'] ?? null) ? (float) $d['weight_kg'] : null;
        $h = is_numeric($d['height_cm'] ?? null) ? (float) $d['height_cm'] : null;
        $hc = is_numeric($d['head_cm'] ?? null) ? (float) $d['head_cm'] : null;
        if ($w === null && $h === null && $hc === null) {
            return null;
        }
        $p = DB::one('SELECT gender, dob FROM patients WHERE id = ?', [$patientId]);
        $date ??= today();
        $months = $p['dob'] ? round((strtotime($date) - strtotime($p['dob'])) / (86400 * 30.4375), 1) : 0.0;
        $bmi = $w && $h ? round($w / (($h / 100) ** 2), 2) : null;
        $pct = GrowthChart::percentiles((string) $p['gender'], $months, $w, $h);
        if ($visitId) {
            DB::run('DELETE FROM growth_measurements WHERE encounter_id = ?', [$visitId]);
        }
        return DB::insert('growth_measurements', ['patient_id' => $patientId, 'encounter_id' => $visitId, 'measured_on' => $date, 'age_months' => max(0, $months),
            'weight_kg' => $w, 'height_cm' => $h, 'head_circumference_cm' => $hc, 'bmi' => $bmi, 'percentiles_json' => json_encode($pct), 'chart_standard' => 'WHO',
            'recorded_by' => Auth::id()]);
    }

    // ------------------------------------------------------- immunization

    /** Due / given status of every scheduled dose for a child. */
    public static function immunizationCard(int $patientId): array
    {
        $dob = DB::value('SELECT dob FROM patients WHERE id = ?', [$patientId]);
        $given = [];
        foreach (DB::all('SELECT r.*, u.name AS given_by_name FROM immunization_records r LEFT JOIN users u ON u.id = r.given_by WHERE r.patient_id = ?', [$patientId]) as $r) {
            $given[$r['vaccine_id'] . ':' . $r['dose_no']] = $r;
        }
        $card = [];
        foreach (DB::all('SELECT * FROM vaccines WHERE is_active = 1 ORDER BY display_order, id') as $v) {
            foreach (json_decode((string) $v['schedule_json'], true) ?: [['dose' => 1, 'age_weeks' => 0, 'label' => '']] as $s) {
                $due = $dob ? date('Y-m-d', strtotime($dob . ' +' . (int) $s['age_weeks'] . ' weeks')) : null;
                $g = $given[$v['id'] . ':' . $s['dose']] ?? null;
                $card[] = ['vaccine_id' => (int) $v['id'], 'vaccine' => $v['name'], 'dose' => (int) $s['dose'], 'label' => $s['label'] ?? '', 'due' => $due,
                    'given' => $g, 'status' => $g ? 'given' : ($due && $due < today() ? 'overdue' : ($due && $due <= date('Y-m-d', strtotime('+14 days')) ? 'due' : 'upcoming'))];
            }
        }
        return $card;
    }

    /** Records a dose; the vaccine is drawn (FEFO) from ward / OPD stock. */
    public static function immunize(int $patientId, int $vaccineId, int $dose, ?int $visitId = null, string $site = '', string $notes = ''): int
    {
        $v = DB::one('SELECT * FROM vaccines WHERE id = ? AND is_active = 1', [$vaccineId]);
        if (!$v) {
            throw new \RuntimeException('Unknown vaccine.');
        }
        if (DB::value('SELECT 1 FROM immunization_records WHERE patient_id = ? AND vaccine_id = ? AND dose_no = ?', [$patientId, $vaccineId, $dose])) {
            throw new \RuntimeException($v['name'] . ' dose ' . $dose . ' is already recorded.');
        }
        return DB::transaction(static function () use ($v, $patientId, $vaccineId, $dose, $visitId, $site, $notes): int {
            $batch = null;
            if ($v['item_id'] && ($loc = self::vaccineLocation((int) $v['item_id']))) {
                $plan = StockService::takeFefo((int) $v['item_id'], $loc, 1, 'consumption', ['reference_type' => 'immunization', 'patient_id' => $patientId, 'visit_id' => $visitId], true);
                $batch = $plan[0]['batch_id'] ?? null;
            }
            $schedule = json_decode((string) $v['schedule_json'], true) ?: [];
            $next = null;
            $dob = DB::value('SELECT dob FROM patients WHERE id = ?', [$patientId]);
            foreach ($schedule as $s) {
                if ((int) $s['dose'] === $dose + 1 && $dob) {
                    $next = max(date('Y-m-d', strtotime($dob . ' +' . (int) $s['age_weeks'] . ' weeks')), date('Y-m-d', strtotime('+4 weeks')));
                }
            }
            $id = DB::insert('immunization_records', ['patient_id' => $patientId, 'encounter_id' => $visitId, 'vaccine_id' => $vaccineId, 'dose_no' => $dose,
                'given_at' => now(), 'stock_batch_id' => $batch ?: null, 'site' => $site ?: null, 'given_by' => Auth::id(), 'next_due_date' => $next,
                'notes' => $notes ?: null]);
            ActivityLog::record('Vaccine given', 'specialty', (string) $patientId, $v['name'] . ' dose ' . $dose);
            return $id;
        });
    }

    /** Cold-chain location holding the vaccine (EPI fridge in OPD / ward, else the pharmacy). */
    private static function vaccineLocation(int $itemId): ?int
    {
        foreach (['ward_stock', 'pharmacy_opd', 'pharmacy_main', 'central_store'] as $type) {
            foreach (DB::column("SELECT id FROM stock_locations WHERE location_type = ? AND cold_storage = 1 AND deleted_at IS NULL", [$type]) as $loc) {
                if (StockService::available($itemId, (int) $loc) > 0) {
                    return (int) $loc;
                }
            }
        }
        return null;
    }

    // ------------------------------------------------ notes & referrals

    public static function addNote(int $patientId, ?int $visitId, string $text, bool $restricted, string $type = 'progress', array $share = []): int
    {
        $text = trim($text);
        if ($text === '') {
            throw new \InvalidArgumentException('Write the note.');
        }
        $id = DB::insert('clinical_notes', ['encounter_id' => $visitId, 'patient_id' => $patientId, 'author_id' => Auth::id(), 'note_type' => $type,
            'note_text' => $text, 'is_restricted' => $restricted ? 1 : 0, 'shared_user_ids' => $share ? implode(',', array_map('intval', $share)) : null, 'created_at' => now()]);
        ActivityLog::record('Clinical note added', 'clinical', (string) $patientId, ($restricted ? 'Restricted ' : '') . $type . ' note');
        return $id;
    }

    /** Notes the current user may read; restricted ones are masked otherwise. */
    public static function notes(int $patientId): array
    {
        $rows = DB::all('SELECT n.*, u.name AS author_name, v.visit_no FROM clinical_notes n JOIN users u ON u.id = n.author_id LEFT JOIN visits v ON v.id = n.encounter_id
                          WHERE n.patient_id = ? ORDER BY n.created_at DESC', [$patientId]);
        foreach ($rows as &$r) {
            $r['visible'] = !$r['is_restricted'] || SpecialtyContext::canSeeRestricted($r);
            if (!$r['visible']) {
                $r['note_text'] = '';
            }
        }
        return $rows;
    }

    public static function refer(array $d): int
    {
        $reason = trim((string) ($d['reason'] ?? ''));
        if ($reason === '') {
            throw new \InvalidArgumentException('A referral needs a reason.');
        }
        if (empty($d['to_department_id']) && empty($d['to_doctor_id']) && trim((string) ($d['external_to'] ?? '')) === '') {
            throw new \InvalidArgumentException('Choose where the patient is referred to.');
        }
        $id = DB::insert('referrals', ['branch_id' => Auth::branchId(), 'encounter_id' => $d['encounter_id'] ?? null, 'patient_id' => (int) $d['patient_id'],
            'from_doctor_id' => (int) ($d['from_doctor_id'] ?? Auth::id()), 'to_department_id' => (int) ($d['to_department_id'] ?? 0) ?: null,
            'to_doctor_id' => (int) ($d['to_doctor_id'] ?? 0) ?: null, 'external_to' => mb_substr(trim((string) ($d['external_to'] ?? '')), 0, 150) ?: null,
            'urgency' => in_array($d['urgency'] ?? '', ['routine', 'urgent', 'emergency'], true) ? $d['urgency'] : 'routine',
            'reason' => mb_substr($reason, 0, 1000), 'source' => $d['source'] ?? null, 'status' => 'open', 'created_at' => now()]);
        ActivityLog::record('Referral created', 'clinical', (string) $d['patient_id'], mb_substr($reason, 0, 120));
        return $id;
    }

    public static function updateReferral(int $id, string $status): void
    {
        if (!in_array($status, ['accepted', 'completed', 'declined'], true)) {
            throw new \InvalidArgumentException('Invalid status.');
        }
        DB::update('referrals', ['status' => $status, 'accepted_by' => Auth::id(), 'closed_at' => $status === 'accepted' ? null : now()], 'id = ?', [$id]);
    }
}
