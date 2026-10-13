<?php
declare(strict_types=1);

namespace App\Modules\Obgyn;

use App\Core\ActivityLog;
use App\Core\Auth;
use App\Core\DB;
use App\Modules\Patients\PatientService;

/**
 * Gynecology / Obstetrics (Doc 6 §6, Doc 7 §3.2, Doc 8 §5.3): patient-level
 * gynae history, pregnancies with deliveries (newborn registered and linked to
 * the mother), ANC / PNC per encounter with rule-based risk flags. Gravida /
 * para / abortions are DERIVED from the pregnancies — never typed by hand.
 */
final class ObgynService
{
    public const OUTCOMES = ['ongoing' => 'Ongoing', 'delivered' => 'Delivered', 'miscarriage' => 'Miscarriage', 'termination' => 'Termination', 'ectopic' => 'Ectopic', 'molar' => 'Molar'];
    public const MODES = ['SVD' => 'Normal vaginal (SVD)', 'assisted' => 'Assisted (vacuum / forceps)', 'C-section_elective' => 'C-section — elective', 'C-section_emergency' => 'C-section — emergency'];
    public const BIRTH_OUTCOMES = ['live_birth' => 'Live birth', 'stillbirth' => 'Stillbirth', 'neonatal_death' => 'Neonatal death'];
    public const CONTRACEPTION = ['None', 'Condom', 'Combined pill', 'Progestin-only pill', 'Injectable', 'Implant', 'IUCD', 'Tubal ligation', 'Partner vasectomy', 'Natural / LAM'];
    public const MENOPAUSE = ['pre' => 'Pre-menopausal', 'peri' => 'Peri-menopausal', 'post' => 'Post-menopausal'];

    // -------------------------------------------------------------- history

    public static function gynHistory(int $pid): ?array
    {
        return DB::one('SELECT g.*, u.name AS updated_by_name FROM gynecologic_history g LEFT JOIN users u ON u.id = g.updated_by WHERE g.patient_id = ?', [$pid]);
    }

    public static function saveGynHistory(int $pid, array $d): void
    {
        $tiny = static fn ($v, int $max): ?int => is_numeric($v) && (int) $v >= 0 && (int) $v <= $max ? (int) $v : null;
        $date = static fn ($v): ?string => is_string($v) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) && $v <= today() ? $v : null;
        $row = ['menarche_age' => $tiny($d['menarche_age'] ?? null, 25), 'cycle_length_days' => $tiny($d['cycle_length_days'] ?? null, 120),
            'cycle_duration_days' => $tiny($d['cycle_duration_days'] ?? null, 30),
            'cycle_regularity' => in_array($d['cycle_regularity'] ?? '', ['regular', 'irregular'], true) ? $d['cycle_regularity'] : null,
            'lmp_date' => $date($d['lmp_date'] ?? null), 'contraceptive_method' => in_array($d['contraceptive_method'] ?? '', self::CONTRACEPTION, true) ? $d['contraceptive_method'] : null,
            'menopause_status' => isset(self::MENOPAUSE[$d['menopause_status'] ?? '']) ? $d['menopause_status'] : null, 'pap_smear_last' => $date($d['pap_smear_last'] ?? null),
            'notes' => mb_substr(trim((string) ($d['notes'] ?? '')), 0, 1000) ?: null, 'updated_by' => Auth::id(), 'updated_at' => now()];
        $old = self::gynHistory($pid);
        if ($old) {
            DB::update('gynecologic_history', $row, 'patient_id = ?', [$pid]);
            [$before, $after] = ActivityLog::diff($old, $row);
            if ($after) {
                ActivityLog::record('Gynae history updated', 'obgyn', (string) $pid, '', null, null, ['entity' => 'gynecologic_history', 'old' => $before, 'new' => $after]);
            }
        } else {
            DB::insert('gynecologic_history', $row + ['patient_id' => $pid]);
            ActivityLog::record('Gynae history recorded', 'obgyn', (string) $pid);
        }
    }

    /** Recomputes the derived obstetric summary from pregnancies + deliveries. */
    public static function recalcObstetric(int $pid): array
    {
        $preg = DB::all('SELECT p.*, d.mode, d.outcome, d.gestational_age_weeks, d.delivery_date FROM pregnancies p LEFT JOIN pregnancy_deliveries d ON d.pregnancy_id = p.id AND d.is_draft = 0
                          WHERE p.patient_id = ? ORDER BY p.pregnancy_no, d.id', [$pid]);
        $g = [];
        $para = $ab = $living = $cs = 0;
        $modes = [];
        foreach ($preg as $r) {
            $g[$r['id']] = true;
            if (in_array($r['status'], ['miscarriage', 'termination', 'ectopic', 'molar'], true)) {
                $ab++;
            }
            if ($r['outcome'] !== null) {
                if ($r['gestational_age_weeks'] === null || (int) $r['gestational_age_weeks'] >= 28) {
                    $para++;
                }
                if ($r['outcome'] === 'live_birth') {
                    $living++;
                }
                if (str_starts_with((string) $r['mode'], 'C-section')) {
                    $cs++;
                }
                $modes[] = ($r['mode'] === 'SVD' ? 'SVD' : str_replace('_', ' ', (string) $r['mode'])) . ' ' . substr((string) $r['delivery_date'], 0, 4);
            }
        }
        $row = ['gravida' => count($g), 'para' => $para, 'abortions' => $ab, 'living_children' => $living, 'previous_cesareans' => $cs,
            'previous_delivery_modes' => $modes ? mb_substr(implode(', ', $modes), 0, 500) : null, 'updated_by' => Auth::id(), 'updated_at' => now()];
        if (DB::value('SELECT 1 FROM obstetric_history WHERE patient_id = ?', [$pid])) {
            DB::update('obstetric_history', $row, 'patient_id = ?', [$pid]);
        } else {
            DB::insert('obstetric_history', $row + ['patient_id' => $pid]);
        }
        return $row;
    }

    public static function gpal(int $pid): string
    {
        $o = DB::one('SELECT * FROM obstetric_history WHERE patient_id = ?', [$pid]);
        return $o ? 'G' . $o['gravida'] . ' P' . $o['para'] . ' A' . $o['abortions'] . ' L' . $o['living_children'] : 'G0';
    }

    // ----------------------------------------------------------- pregnancies

    public static function ongoing(int $pid): ?array
    {
        return DB::one("SELECT * FROM pregnancies WHERE patient_id = ? AND status = 'ongoing' ORDER BY id DESC LIMIT 1", [$pid]);
    }

    /** Gestational age from LMP at a date: [weeks, days, decimal weeks]. */
    public static function ga(?string $lmp, ?string $at = null): ?array
    {
        if (!$lmp) {
            return null;
        }
        $days = (int) floor((strtotime($at ?? today()) - strtotime($lmp)) / 86400);
        if ($days < 0 || $days > 320) {
            return null;
        }
        return [intdiv($days, 7), $days % 7, round($days / 7, 1)];
    }

    public static function openPregnancy(int $pid, array $d, ?int $encounterId = null): int
    {
        if (!can('obgyn.pregnancy.manage')) {
            throw new \RuntimeException('Opening a pregnancy record needs the gynecologist.');
        }
        if (self::ongoing($pid)) {
            throw new \RuntimeException('This patient already has an ongoing pregnancy.');
        }
        $p = DB::one('SELECT gender FROM patients WHERE id = ?', [$pid]);
        if (($p['gender'] ?? '') !== 'Female') {
            throw new \RuntimeException('Pregnancy records are for female patients.');
        }
        $lmp = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) ($d['lmp_date'] ?? '')) ? $d['lmp_date'] : null;
        if ($lmp && ($lmp > today() || $lmp < date('Y-m-d', strtotime('-44 weeks')))) {
            throw new \InvalidArgumentException('LMP must be within the last 44 weeks.');
        }
        $edd = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) ($d['edd_date'] ?? '')) ? $d['edd_date'] : ($lmp ? date('Y-m-d', strtotime($lmp . ' +280 days')) : null);
        if (!$edd) {
            throw new \InvalidArgumentException('Enter the LMP or the EDD.');
        }
        $no = (int) DB::value('SELECT COALESCE(MAX(pregnancy_no), 0) + 1 FROM pregnancies WHERE patient_id = ?', [$pid]);
        $id = DB::insert('pregnancies', ['branch_id' => Auth::branchId(), 'patient_id' => $pid, 'pregnancy_no' => $no, 'lmp_date' => $lmp, 'edd_date' => $edd,
            'edd_method' => in_array($d['edd_method'] ?? '', ['lmp', 'ultrasound', 'ivf'], true) ? $d['edd_method'] : ($lmp && empty($d['edd_date']) ? 'lmp' : 'ultrasound'),
            'status' => 'ongoing', 'booking_encounter_id' => $encounterId, 'blood_group' => mb_substr((string) ($d['blood_group'] ?? ''), 0, 5) ?: null,
            'is_multiple' => empty($d['is_multiple']) ? 0 : 1, 'created_by' => Auth::id(), 'created_at' => now()]);
        if ($lmp) {
            DB::run('UPDATE gynecologic_history SET lmp_date = ? WHERE patient_id = ?', [$lmp, $pid]);
        }
        self::recalcObstetric($pid);
        self::evaluateRisk($id);
        ActivityLog::record('Pregnancy opened', 'obgyn', (string) $pid, 'Pregnancy #' . $no . ', EDD ' . fmt_date($edd));
        return $id;
    }

    /** A past pregnancy from history (not booked here) — for a correct G/P/A/L. */
    public static function addPastPregnancy(int $pid, array $d): int
    {
        $status = isset(self::OUTCOMES[$d['status'] ?? '']) && $d['status'] !== 'ongoing' ? $d['status'] : 'delivered';
        $date = preg_match('/^\d{4}(-\d{2}-\d{2})?$/', (string) ($d['outcome_date'] ?? '')) ? (strlen($d['outcome_date']) === 4 ? $d['outcome_date'] . '-07-01' : $d['outcome_date']) : null;
        return DB::transaction(static function () use ($pid, $d, $status, $date): int {
            $no = (int) DB::value('SELECT COALESCE(MAX(pregnancy_no), 0) + 1 FROM pregnancies WHERE patient_id = ?', [$pid]);
            $id = DB::insert('pregnancies', ['branch_id' => Auth::branchId(), 'patient_id' => $pid, 'pregnancy_no' => $no, 'status' => $status, 'recorded_historical' => 1,
                'outcome_date' => $date, 'outcome_notes' => mb_substr(trim((string) ($d['notes'] ?? '')), 0, 500) ?: null, 'created_by' => Auth::id(), 'created_at' => now()]);
            if ($status === 'delivered') {
                DB::insert('pregnancy_deliveries', ['pregnancy_id' => $id, 'delivery_date' => ($date ?: today()) . ' 00:00:00',
                    'gestational_age_weeks' => is_numeric($d['ga_weeks'] ?? null) ? (int) $d['ga_weeks'] : null,
                    'mode' => isset(self::MODES[$d['mode'] ?? '']) ? $d['mode'] : 'SVD', 'place' => in_array($d['place'] ?? '', ['home', 'other'], true) ? $d['place'] : 'other',
                    'outcome' => isset(self::BIRTH_OUTCOMES[$d['outcome'] ?? '']) ? $d['outcome'] : 'live_birth', 'baby_sex' => in_array($d['baby_sex'] ?? '', ['Male', 'Female'], true) ? $d['baby_sex'] : null,
                    'complications' => mb_substr(trim((string) ($d['complications'] ?? '')), 0, 500) ?: null, 'recorded_by' => Auth::id(), 'created_at' => now()]);
            }
            self::recalcObstetric($pid);
            ActivityLog::record('Past pregnancy recorded', 'obgyn', (string) $pid, $status);
            return $id;
        });
    }

    public static function closePregnancy(int $id, string $status, ?string $date, string $notes = ''): void
    {
        if (!in_array($status, ['miscarriage', 'termination', 'ectopic', 'molar'], true)) {
            throw new \InvalidArgumentException('Choose how the pregnancy ended (a birth is recorded with the delivery form).');
        }
        $p = DB::one("SELECT * FROM pregnancies WHERE id = ? AND status = 'ongoing'", [$id]);
        if (!$p) {
            throw new \RuntimeException('Pregnancy is not ongoing.');
        }
        DB::update('pregnancies', ['status' => $status, 'outcome_date' => $date ?: today(), 'outcome_notes' => mb_substr($notes, 0, 500) ?: null, 'updated_at' => now()], 'id = ?', [$id]);
        self::recalcObstetric((int) $p['patient_id']);
        ActivityLog::record('Pregnancy closed', 'obgyn', (string) $p['patient_id'], $status);
    }

    // ------------------------------------------------------------- ANC / PNC

    /** @return array{id:int, flags:string[]} */
    public static function saveAnc(int $pregnancyId, ?int $encounterId, array $d): array
    {
        $p = DB::one("SELECT * FROM pregnancies WHERE id = ? AND status = 'ongoing'", [$pregnancyId]);
        if (!$p) {
            throw new \RuntimeException('ANC needs an ongoing pregnancy.');
        }
        $num = static fn ($v, float $min, float $max): ?float => is_numeric($v) && (float) $v >= $min && (float) $v <= $max ? (float) $v : null;
        $ga = $num($d['gestational_age_weeks'] ?? null, 0, 45) ?? (self::ga($p['lmp_date'])[2] ?? (($e = self::gaFromEdd($p['edd_date'])) !== null ? $e : null));
        $row = ['encounter_id' => $encounterId, 'pregnancy_id' => $pregnancyId, 'patient_id' => $p['patient_id'], 'visit_date' => today(),
            'gestational_age_weeks' => $ga, 'trimester' => $ga === null ? null : ($ga < 14 ? 1 : ($ga < 28 ? 2 : 3)),
            'fundal_height_cm' => $num($d['fundal_height_cm'] ?? null, 5, 50), 'fetal_heart_rate_bpm' => ($v = $num($d['fetal_heart_rate_bpm'] ?? null, 50, 250)) !== null ? (int) $v : null,
            'fetal_position' => mb_substr(trim((string) ($d['fetal_position'] ?? '')), 0, 30) ?: null,
            'presentation' => in_array($d['presentation'] ?? '', ['cephalic', 'breech', 'transverse', 'oblique'], true) ? $d['presentation'] : null,
            'bp_systolic' => ($v = $num($d['bp_systolic'] ?? null, 50, 260)) !== null ? (int) $v : null, 'bp_diastolic' => ($v = $num($d['bp_diastolic'] ?? null, 30, 180)) !== null ? (int) $v : null,
            'weight_kg' => $num($d['weight_kg'] ?? null, 25, 250), 'edema_flag' => empty($d['edema_flag']) ? 0 : 1, 'urine_protein_flag' => empty($d['urine_protein_flag']) ? 0 : 1,
            'hb_g_dl' => $num($d['hb_g_dl'] ?? null, 2, 20), 'tt_dose_given' => empty($d['tt_dose_given']) ? 0 : 1,
            'notes' => mb_substr(trim((string) ($d['notes'] ?? '')), 0, 1000) ?: null,
            'next_visit_date' => preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) ($d['next_visit_date'] ?? '')) && $d['next_visit_date'] > today() ? $d['next_visit_date'] : null,
            'recorded_by' => Auth::id(), 'created_at' => now()];
        $flags = self::ancFlags($row, $p);
        $row['risk_flags_json'] = $flags ? json_encode($flags, JSON_UNESCAPED_UNICODE) : null;
        if ($encounterId) {
            DB::run('DELETE FROM antenatal_visits WHERE encounter_id = ? AND pregnancy_id = ?', [$encounterId, $pregnancyId]);
        }
        $id = DB::insert('antenatal_visits', $row);
        self::evaluateRisk($pregnancyId);
        ActivityLog::record('ANC visit recorded', 'obgyn', (string) $p['patient_id'], 'GA ' . ($ga ?? '?') . ' wk' . ($flags ? ' — ' . implode('; ', $flags) : ''));
        return ['id' => $id, 'flags' => $flags];
    }

    private static function gaFromEdd(?string $edd): ?float
    {
        return $edd ? round((280 - (strtotime($edd) - strtotime(today())) / 86400) / 7, 1) : null;
    }

    /** Doc 8 §5.3 risk banner rules (thresholds in settings). */
    public static function ancFlags(array $a, array $p): array
    {
        $sys = (int) setting('obgyn_risk_bp_systolic', 140);
        $dia = (int) setting('obgyn_risk_bp_diastolic', 90);
        $hb = (float) setting('obgyn_risk_hb', 10);
        $flags = [];
        $highBp = ($a['bp_systolic'] ?? 0) >= $sys || ($a['bp_diastolic'] ?? 0) >= $dia;
        if ($highBp && !empty($a['urine_protein_flag'])) {
            $flags[] = 'BP ≥ ' . $sys . '/' . $dia . ' with proteinuria — suggests pre-eclampsia';
        } elseif ($highBp) {
            $flags[] = 'Raised blood pressure (≥ ' . $sys . '/' . $dia . ')';
        }
        if (($a['bp_systolic'] ?? 0) >= 160 || ($a['bp_diastolic'] ?? 0) >= 110) {
            $flags[] = 'Severe-range blood pressure';
        }
        if (!empty($a['edema_flag']) && !empty($a['urine_protein_flag']) && !$highBp) {
            $flags[] = 'Oedema with proteinuria';
        }
        if (($a['hb_g_dl'] ?? null) !== null && $a['hb_g_dl'] < $hb) {
            $flags[] = 'Anaemia (Hb ' . $a['hb_g_dl'] . ' g/dL)';
        }
        if (($a['fetal_heart_rate_bpm'] ?? null) !== null && ($a['fetal_heart_rate_bpm'] < 110 || $a['fetal_heart_rate_bpm'] > 160)) {
            $flags[] = 'Fetal heart rate outside 110–160';
        }
        if (($a['gestational_age_weeks'] ?? 0) >= 41) {
            $flags[] = 'Post-dates (≥ 41 weeks)';
        }
        if (($a['gestational_age_weeks'] ?? 0) >= 36 && in_array($a['presentation'] ?? '', ['breech', 'transverse', 'oblique'], true)) {
            $flags[] = 'Malpresentation at term';
        }
        return $flags;
    }

    /** Pregnancy-level risk: ANC flags + history (previous C-section, age, multiple). */
    public static function evaluateRisk(int $pregnancyId): array
    {
        $p = DB::one('SELECT pr.*, pt.dob FROM pregnancies pr JOIN patients pt ON pt.id = pr.patient_id WHERE pr.id = ?', [$pregnancyId]);
        $reasons = [];
        $oh = DB::one('SELECT * FROM obstetric_history WHERE patient_id = ?', [$p['patient_id']]);
        if ($oh && (int) $oh['previous_cesareans'] > 0) {
            $reasons[] = 'Previous C-section (' . (int) $oh['previous_cesareans'] . ')';
        }
        if ($oh && (int) $oh['para'] >= 5) {
            $reasons[] = 'Grand multipara';
        }
        $age = age_years($p['dob']);
        if ($age !== null && ($age < 18 || $age > 35)) {
            $reasons[] = 'Maternal age ' . $age;
        }
        if ($p['is_multiple']) {
            $reasons[] = 'Multiple pregnancy';
        }
        $last = DB::one('SELECT risk_flags_json FROM antenatal_visits WHERE pregnancy_id = ? ORDER BY visit_date DESC, id DESC LIMIT 1', [$pregnancyId]);
        foreach (json_decode((string) ($last['risk_flags_json'] ?? ''), true) ?: [] as $f) {
            $reasons[] = $f;
        }
        DB::update('pregnancies', ['risk_level' => $reasons ? 'high' : 'low', 'risk_reasons_json' => $reasons ? json_encode($reasons, JSON_UNESCAPED_UNICODE) : null, 'updated_at' => now()],
            'id = ?', [$pregnancyId]);
        return $reasons;
    }

    public static function savePnc(int $pregnancyId, ?int $encounterId, array $d): int
    {
        $p = DB::one('SELECT pr.*, (SELECT MAX(delivery_date) FROM pregnancy_deliveries WHERE pregnancy_id = pr.id) AS delivered_at FROM pregnancies pr WHERE pr.id = ?', [$pregnancyId]);
        if (!$p || $p['status'] !== 'delivered') {
            throw new \RuntimeException('Postnatal care needs a delivered pregnancy.');
        }
        $id = DB::insert('postnatal_visits', ['encounter_id' => $encounterId, 'pregnancy_id' => $pregnancyId, 'patient_id' => $p['patient_id'], 'visit_date' => today(),
            'days_postpartum' => $p['delivered_at'] ? max(0, (int) floor((time() - strtotime($p['delivered_at'])) / 86400)) : null,
            'bp_systolic' => is_numeric($d['bp_systolic'] ?? null) ? (int) $d['bp_systolic'] : null, 'bp_diastolic' => is_numeric($d['bp_diastolic'] ?? null) ? (int) $d['bp_diastolic'] : null,
            'lochia' => mb_substr((string) ($d['lochia'] ?? ''), 0, 20) ?: null, 'uterine_involution' => mb_substr((string) ($d['uterine_involution'] ?? ''), 0, 20) ?: null,
            'perineal_wound_status' => mb_substr((string) ($d['perineal_wound_status'] ?? ''), 0, 30) ?: null, 'breastfeeding_status' => mb_substr((string) ($d['breastfeeding_status'] ?? ''), 0, 20) ?: null,
            'contraception_counselled' => empty($d['contraception_counselled']) ? 0 : 1, 'notes' => mb_substr(trim((string) ($d['notes'] ?? '')), 0, 1000) ?: null,
            'recorded_by' => Auth::id(), 'created_at' => now()]);
        ActivityLog::record('PNC visit recorded', 'obgyn', (string) $p['patient_id']);
        return $id;
    }

    // -------------------------------------------------------------- delivery

    /**
     * Records the delivery; a live birth can register the newborn as a
     * patient linked to the mother. A midwife's record is a draft until the
     * gynecologist confirms it.
     */
    public static function recordDelivery(int $pregnancyId, array $d, bool $registerNewborn): array
    {
        $p = DB::one("SELECT pr.*, pt.name AS mother_name, pt.phone, pt.city, pt.address FROM pregnancies pr JOIN patients pt ON pt.id = pr.patient_id WHERE pr.id = ? AND pr.status = 'ongoing'", [$pregnancyId]);
        if (!$p) {
            throw new \RuntimeException('The pregnancy is not ongoing.');
        }
        $mode = isset(self::MODES[$d['mode'] ?? '']) ? $d['mode'] : throw new \InvalidArgumentException('Choose the mode of delivery.');
        $outcome = isset(self::BIRTH_OUTCOMES[$d['outcome'] ?? '']) ? $d['outcome'] : 'live_birth';
        $when = preg_match('/^\d{4}-\d{2}-\d{2}T?\s?\d{2}:\d{2}/', (string) ($d['delivery_date'] ?? '')) ? str_replace('T', ' ', substr($d['delivery_date'], 0, 16)) . ':00' : now();
        if ($when > now()) {
            throw new \InvalidArgumentException('Delivery time cannot be in the future.');
        }
        $sex = in_array($d['baby_sex'] ?? '', ['Male', 'Female', 'Other'], true) ? $d['baby_sex'] : null;
        $draft = !can('obgyn.pregnancy.manage');
        return DB::transaction(static function () use ($p, $d, $mode, $outcome, $when, $sex, $draft, $registerNewborn, $pregnancyId): array {
            $baby = null;
            if ($registerNewborn && $outcome !== 'stillbirth' && can('obgyn.newborn.register')) {
                if (!$sex) {
                    throw new \InvalidArgumentException('Baby sex is needed to register the newborn.');
                }
                $baby = PatientService::create(['name' => 'Baby of ' . $p['mother_name'], 'guardian_name' => $p['mother_name'], 'gender' => $sex === 'Other' ? 'Other' : $sex,
                    'dob' => substr($when, 0, 10), 'phone' => $p['phone'], 'city' => $p['city'], 'address' => $p['address']], null,
                    ['registration_type' => 'newborn', 'mother_patient_id' => $p['patient_id']]);
            }
            $ga = is_numeric($d['gestational_age_weeks'] ?? null) ? (int) $d['gestational_age_weeks'] : (self::ga($p['lmp_date'], substr($when, 0, 10))[0] ?? null);
            $id = DB::insert('pregnancy_deliveries', ['pregnancy_id' => $pregnancyId, 'delivery_date' => $when, 'gestational_age_weeks' => $ga, 'mode' => $mode,
                'place' => in_array($d['place'] ?? '', ['this_hospital', 'home', 'other'], true) ? $d['place'] : 'this_hospital', 'attended_by' => Auth::id(), 'outcome' => $outcome,
                'baby_sex' => $sex, 'birth_weight_g' => is_numeric($d['birth_weight_g'] ?? null) && $d['birth_weight_g'] > 200 && $d['birth_weight_g'] < 7000 ? (int) $d['birth_weight_g'] : null,
                'apgar_1min' => is_numeric($d['apgar_1min'] ?? null) ? min(10, max(0, (int) $d['apgar_1min'])) : null, 'apgar_5min' => is_numeric($d['apgar_5min'] ?? null) ? min(10, max(0, (int) $d['apgar_5min'])) : null,
                'complications' => mb_substr(trim((string) ($d['complications'] ?? '')), 0, 500) ?: null, 'baby_patient_id' => $baby['id'] ?? null,
                'encounter_id' => (int) ($d['encounter_id'] ?? 0) ?: null, 'recorded_by' => Auth::id(), 'is_draft' => $draft ? 1 : 0, 'created_at' => now()]);
            DB::update('pregnancies', ['status' => 'delivered', 'outcome_date' => substr($when, 0, 10), 'updated_at' => now()], 'id = ?', [$pregnancyId]);
            self::recalcObstetric((int) $p['patient_id']);
            ActivityLog::record('Delivery recorded', 'obgyn', (string) $p['patient_id'], self::MODES[$mode] . ', ' . self::BIRTH_OUTCOMES[$outcome] . ($baby ? ' — newborn ' . $baby['mrn'] : '') . ($draft ? ' (draft)' : ''));
            return ['id' => $id, 'baby' => $baby, 'draft' => $draft];
        });
    }

    public static function confirmDelivery(int $deliveryId): void
    {
        $d = DB::one('SELECT d.*, p.patient_id FROM pregnancy_deliveries d JOIN pregnancies p ON p.id = d.pregnancy_id WHERE d.id = ? AND d.is_draft = 1', [$deliveryId]);
        if (!$d) {
            throw new \RuntimeException('Nothing to confirm.');
        }
        if ((int) $d['recorded_by'] === Auth::id()) {
            throw new \RuntimeException('The person who recorded the delivery cannot confirm it.');
        }
        DB::update('pregnancy_deliveries', ['is_draft' => 0, 'cosigned_by' => Auth::id(), 'cosigned_at' => now()], 'id = ?', [$deliveryId]);
        self::recalcObstetric((int) $d['patient_id']);
        ActivityLog::record('Delivery record confirmed', 'obgyn', (string) $d['patient_id']);
    }

    // ------------------------------------------------------------ ultrasound

    /** Obstetric biometry (SON / RAD); EFW by Hadlock (BPD, HC, AC, FL in cm). */
    public static function saveUltrasound(int $pregnancyId, array $d, ?int $radiologyOrderId = null, ?int $reportId = null): int
    {
        if (!DB::value('SELECT 1 FROM pregnancies WHERE id = ?', [$pregnancyId])) {
            throw new \RuntimeException('Pregnancy not found.');
        }
        $bio = [];
        foreach (['bpd', 'hc', 'ac', 'fl', 'crl'] as $k) {
            if (is_numeric($d[$k] ?? null) && (float) $d[$k] > 0) {
                $bio[$k] = round((float) $d[$k], 1); // mm
            }
        }
        $efw = is_numeric($d['efw_g'] ?? null) ? (int) $d['efw_g'] : self::hadlock($bio);
        $id = DB::insert('obstetric_ultrasound_findings', ['radiology_report_id' => $reportId, 'radiology_order_id' => $radiologyOrderId, 'pregnancy_id' => $pregnancyId,
            'scan_date' => today(), 'ga_by_scan_weeks' => is_numeric($d['ga_by_scan_weeks'] ?? null) ? (float) $d['ga_by_scan_weeks'] : null,
            'biometry_json' => $bio ? json_encode($bio) : null, 'efw_g' => $efw, 'afi_cm' => is_numeric($d['afi_cm'] ?? null) ? (float) $d['afi_cm'] : null,
            'fhr_bpm' => is_numeric($d['fhr_bpm'] ?? null) ? (int) $d['fhr_bpm'] : null, 'placenta_location' => mb_substr((string) ($d['placenta_location'] ?? ''), 0, 30) ?: null,
            'fetal_presentation' => mb_substr((string) ($d['fetal_presentation'] ?? ''), 0, 20) ?: null,
            'cervical_length_mm' => is_numeric($d['cervical_length_mm'] ?? null) ? (float) $d['cervical_length_mm'] : null,
            'number_of_fetuses' => max(1, (int) ($d['number_of_fetuses'] ?? 1)), 'notes' => mb_substr((string) ($d['notes'] ?? ''), 0, 500) ?: null,
            'recorded_by' => Auth::id(), 'created_at' => now()]);
        if ((int) ($d['number_of_fetuses'] ?? 1) > 1) {
            DB::update('pregnancies', ['is_multiple' => 1], 'id = ?', [$pregnancyId]);
            self::evaluateRisk($pregnancyId);
        }
        return $id;
    }

    /** Hadlock (1985) EFW from HC, AC, FL (cm); falls back to AC + FL. */
    public static function hadlock(array $mm): ?int
    {
        $hc = isset($mm['hc']) ? $mm['hc'] / 10 : null;
        $ac = isset($mm['ac']) ? $mm['ac'] / 10 : null;
        $fl = isset($mm['fl']) ? $mm['fl'] / 10 : null;
        if ($ac === null || $fl === null) {
            return null;
        }
        $log = $hc !== null
            ? 1.326 - 0.00326 * $ac * $fl + 0.0107 * $hc + 0.0438 * $ac + 0.158 * $fl
            : 1.304 + 0.05281 * $ac + 0.1938 * $fl - 0.004 * $ac * $fl;
        return (int) round(10 ** $log);
    }
}
