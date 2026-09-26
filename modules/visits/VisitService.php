<?php
declare(strict_types=1);

namespace App\Modules\Visits;

use App\Core\ActivityLog;
use App\Core\Auth;
use App\Core\CodeGenerator;
use App\Core\DB;
use App\Core\Validator;
use App\Modules\Patients\PatientService;

/**
 * The checkout of the one-page consultation (BRD §48): one transaction saves
 * or updates the patient, the visit, vitals, complaints, symptoms, diagnoses,
 * investigations, medicines (with price/source snapshots and Rx groups),
 * follow-up, fee and instructions. A checkout token makes a double click or
 * a resubmitted request return the SAME visit instead of creating a second.
 */
final class VisitService
{
    public const PAYMENT_STATUSES = ['Paid', 'Pending', 'Free'];
    public const MAX_MEDICINES = 60;

    /** Vital => [label, min, max, integer?] */
    public const VITALS = [
        'bp_systolic'  => ['BP systolic', 40, 300, true],
        'bp_diastolic' => ['BP diastolic', 20, 200, true],
        'pulse'        => ['Pulse', 20, 250, true],
        'temperature'  => ['Temperature (°F)', 90, 110, false],
        'weight'       => ['Weight (kg)', 0.5, 400, false],
        'height'       => ['Height (cm)', 30, 250, false],
        'spo2'         => ['SpO₂', 50, 100, true],
        'resp_rate'    => ['Respiratory rate', 5, 80, true],
    ];

    /**
     * @param array  $payload   decoded JSON from the consultation screen
     * @param array  $actor     the logged-in user
     * @param string|null $visitDate internal override (demo data); never from the client
     * @return array{ok:bool, message?:string, errors?:string[], visit_id?:int, visit_no?:string, patient_id?:int, duplicate?:bool}
     */
    public static function checkout(array $payload, array $actor, ?string $visitDate = null): array
    {
        $token = (string) ($payload['token'] ?? '');
        if (!preg_match('/^[a-f0-9]{32}$/', $token)) {
            return self::fail(['The consultation form is out of date. Please reload the page.']);
        }
        $existing = DB::one('SELECT id, visit_no, patient_id FROM visits WHERE checkout_token = ?', [$token]);
        if ($existing) {
            return ['ok' => true, 'duplicate' => true, 'visit_id' => (int) $existing['id'], 'visit_no' => $existing['visit_no'],
                'patient_id' => (int) $existing['patient_id'], 'message' => 'This visit was already saved.'];
        }

        $errors = [];

        // ---- Doctor -------------------------------------------------------
        $doctorId = $actor['role'] === 'doctor' ? (int) $actor['id'] : (int) ($payload['doctor_id'] ?? 0);
        $doctor = DB::one("SELECT u.id, dp.default_fee FROM users u LEFT JOIN doctor_profiles dp ON dp.user_id = u.id
                            WHERE u.id = ? AND u.role = 'doctor' AND u.deleted_at IS NULL AND u.is_active = 1", [$doctorId]);
        if (!$doctor) {
            $errors[] = 'Please select the consulting doctor.';
        }

        // ---- Patient ------------------------------------------------------
        $patientIn = is_array($payload['patient'] ?? null) ? $payload['patient'] : [];
        $patientId = (int) ($patientIn['id'] ?? 0);
        if ($patientId > 0 && !PatientService::find($patientId)) {
            $errors[] = 'Patient not found. It may have been deleted.';
        }
        [$patientData, $patientErrors] = PatientService::validate($patientIn);
        $errors = array_merge($errors, array_values($patientErrors));

        // ---- Vitals -------------------------------------------------------
        $vitalsIn = is_array($payload['vitals'] ?? null) ? $payload['vitals'] : [];
        [$vitals, $vitalErrors] = self::validateVitals($vitalsIn);
        $errors = array_merge($errors, $vitalErrors);

        // ---- Clinical lists ----------------------------------------------
        [$items, $itemErrors] = ClinicalItems::resolve($payload);
        $errors = array_merge($errors, $itemErrors);

        // ---- Medicines ----------------------------------------------------
        [$lines, $medicineErrors] = self::resolveMedicines(is_array($payload['medicines'] ?? null) ? $payload['medicines'] : []);
        $errors = array_merge($errors, $medicineErrors);

        // ---- Follow-up, fee, billing -------------------------------------
        $v = new Validator($payload);
        $v->date('follow_up_date', 'Follow-up date')
            ->max('follow_up_instructions', 'Follow-up instructions', 500)
            ->numeric('consultation_fee', 'Consultation fee', 0, 10000000)
            ->in('payment_status', 'Payment status', self::PAYMENT_STATUSES)
            ->in('discount_type', 'Discount type', ['amount', 'percent'])
            ->numeric('discount_value', 'Discount', 0, 10000000)
            ->numeric('tax_percent', 'Tax', 0, 100)
            ->max('overall_instructions', 'Overall instructions', 5000)
            ->max('current_history', 'Current history', 5000);
        $followUp = trim((string) ($payload['follow_up_date'] ?? ''));
        $visitDay = substr($visitDate ?? now(), 0, 10);
        if ($followUp !== '' && !isset($v->errors()['follow_up_date']) && $followUp < $visitDay) {
            $v->add('follow_up_date', 'Follow-up date cannot be before the visit date.');
        }
        if (($payload['discount_type'] ?? 'amount') === 'percent' && (float) ($payload['discount_value'] ?? 0) > 100) {
            $v->add('discount_value', 'Discount percentage cannot exceed 100.');
        }
        $errors = array_merge($errors, array_values($v->errors()));

        if ($errors) {
            return self::fail(array_values(array_unique($errors)));
        }

        $paymentStatus = (string) ($payload['payment_status'] ?? 'Paid') ?: 'Paid';
        $discountType = (string) ($payload['discount_type'] ?? 'amount') ?: 'amount';
        $discountValue = setting('discount_enabled', '1') === '1' ? (float) ($payload['discount_value'] ?? 0) : 0.0;
        $taxPercent = setting('tax_enabled', '0') === '1' ? (float) ($payload['tax_percent'] ?? 0) : 0.0;
        $fee = ($payload['consultation_fee'] ?? '') === '' ? (float) ($doctor['default_fee'] ?? 0) : (float) $payload['consultation_fee'];
        $bill = PrescriptionMath::bill($lines, $discountType, $discountValue, $taxPercent, $fee, $paymentStatus);

        $splitEnabled = setting('rx_split_enabled', '1') === '1';
        $lines = PrescriptionMath::assignGroups($lines, $splitEnabled, MedicineCatalog::sourceOrder());

        $repeatedFrom = (int) ($payload['repeated_from_visit_id'] ?? 0);
        $templateId = (int) ($payload['template_id'] ?? 0);
        $canEditPatient = Auth::roleCan($actor['role'], 'patients.edit');

        try {
            return DB::transaction(static function () use (
                $token, $doctorId, $patientId, $patientData, $vitals, $items, $lines, $payload, $bill,
                $paymentStatus, $discountType, $discountValue, $taxPercent, $splitEnabled, $repeatedFrom, $templateId,
                $visitDate, $canEditPatient, $followUp
            ): array {
                // 1. Patient saved
                if ($patientId > 0) {
                    if ($canEditPatient) {
                        PatientService::update($patientId, $patientData);
                    }
                } else {
                    $patientId = (int) PatientService::create($patientData, $doctorId)['id'];
                }

                // 2. Visit created (vitals, follow-up, fee, instructions, bill)
                $visitNo = CodeGenerator::next(
                    'visit',
                    (string) setting('visit_no_pattern', 'V-{YYYY}-{000001}'),
                    static fn (string $c): bool => DB::value('SELECT 1 FROM visits WHERE visit_no = ?', [$c]) !== null
                );
                $when = $visitDate ?? now();
                $visitId = DB::insert('visits', array_merge($vitals, [
                    'visit_no'               => $visitNo,
                    'checkout_token'         => $token,
                    'patient_id'             => $patientId,
                    'doctor_id'              => $doctorId,
                    'visit_date'             => $when,
                    'current_history'        => self::text($payload['current_history'] ?? ''),
                    'follow_up_date'         => $followUp !== '' ? $followUp : null,
                    'follow_up_instructions' => self::text($payload['follow_up_instructions'] ?? ''),
                    'consultation_fee'       => $bill['consultation_fee'],
                    'payment_status'         => $paymentStatus,
                    'medicines_total'        => $bill['medicines_total'],
                    'discount_type'          => $discountType,
                    'discount_value'         => $discountValue,
                    'discount_amount'        => $bill['discount_amount'],
                    'tax_percent'            => $taxPercent,
                    'tax_amount'             => $bill['tax_amount'],
                    'medicine_net'           => $bill['medicine_net'],
                    'grand_total'            => $bill['grand_total'],
                    'overall_instructions'   => self::text($payload['overall_instructions'] ?? ''),
                    'rx_split_enabled'       => $splitEnabled ? 1 : 0,
                    'rx_split_mode'          => (string) setting('rx_split_print_mode', 'same_page'),
                    'repeated_from_visit_id' => $repeatedFrom > 0 && DB::value('SELECT 1 FROM visits WHERE id = ?', [$repeatedFrom]) ? $repeatedFrom : null,
                    'template_id'            => $templateId > 0 && DB::value('SELECT 1 FROM templates WHERE id = ? AND doctor_id = ?', [$templateId, $doctorId]) ? $templateId : null,
                    'created_at'             => $when,
                    'created_by'             => Auth::id(),
                ]));

                // 3–7. Complaints, symptoms, diagnoses, investigations
                ClinicalItems::save('visit_clinical_items', 'visit_id', $visitId, $items);

                // 8–9. Medicines with price + source snapshot and final quantity
                foreach ($lines as $i => $line) {
                    DB::insert('visit_medicines', [
                        'visit_id'       => $visitId,
                        'medicine_id'    => $line['medicine_id'],
                        'medicine_name'  => $line['medicine_name'],
                        'generic_name'   => $line['generic_name'],
                        'strength'       => $line['strength'],
                        'dosage_form'    => $line['dosage_form'],
                        'dose'           => $line['dose'],
                        'dose_unit'      => $line['dose_unit'],
                        'frequency_id'   => $line['frequency_id'],
                        'frequency_name' => $line['frequency_name'],
                        'frequency_code' => $line['frequency_code'],
                        'doses_per_day'  => $line['doses_per_day'],
                        'calc_mode'      => $line['calc_mode'],
                        'route_id'       => $line['route_id'],
                        'route_name'     => $line['route_name'],
                        'duration_days'  => $line['duration_days'],
                        'quantity'       => $line['quantity'],
                        'qty_manual'     => $line['qty_manual'],
                        'unit'           => $line['unit'],
                        'unit_price'     => $line['unit_price'],
                        'line_total'     => $line['line_total'],
                        'source_id'      => $line['source_id'],
                        'source_name'    => $line['source_name'],
                        'rx_group'       => $line['rx_group'],
                        'instructions'   => $line['instructions'],
                        'sort_order'     => $i + 1,
                    ]);
                }

                if ($templateId > 0) {
                    DB::run('UPDATE templates SET usage_count = usage_count + 1 WHERE id = ? AND doctor_id = ?', [$templateId, $doctorId]);
                }

                ActivityLog::record('Visit created', 'visits', (string) $visitId, 'Visit ' . $visitNo . ' for ' . $patientData['name']
                    . ' — ' . count($lines) . ' medicine(s), total ' . money($bill['grand_total']));
                if ($lines) {
                    ActivityLog::record('Prescription created', 'visits', (string) $visitId, 'Prescription ' . $visitNo . ' with ' . count($lines) . ' medicine(s)');
                }

                return ['ok' => true, 'visit_id' => $visitId, 'visit_no' => $visitNo, 'patient_id' => $patientId,
                    'message' => 'Prescription saved successfully.'];
            });
        } catch (\PDOException $e) {
            // A concurrent request with the same token won the race: return that visit.
            if (($e->errorInfo[1] ?? 0) === 1062 && str_contains($e->getMessage(), 'checkout_token')) {
                $row = DB::one('SELECT id, visit_no, patient_id FROM visits WHERE checkout_token = ?', [$token]);
                if ($row) {
                    return ['ok' => true, 'duplicate' => true, 'visit_id' => (int) $row['id'], 'visit_no' => $row['visit_no'],
                        'patient_id' => (int) $row['patient_id'], 'message' => 'This visit was already saved.'];
                }
            }
            throw $e;
        }
    }

    /** @return array{0: array, 1: string[]} */
    public static function validateVitals(array $in): array
    {
        $out = [];
        $errors = [];
        foreach (self::VITALS as $key => [$label, $min, $max, $integer]) {
            $raw = trim((string) ($in[$key] ?? ''));
            if ($raw === '') {
                $out[$key] = null;
                continue;
            }
            if (!is_numeric($raw) || ($integer && !preg_match('/^\d+$/', $raw))) {
                $errors[] = $label . ' must be a ' . ($integer ? 'whole number' : 'number') . '.';
                continue;
            }
            $n = (float) $raw;
            if ($n < $min || $n > $max) {
                $errors[] = $label . ' must be between ' . num($min) . ' and ' . num($max) . '.';
                continue;
            }
            $out[$key] = $integer ? (int) $n : round($n, 1);
        }
        if (($out['bp_systolic'] ?? null) !== null && ($out['bp_diastolic'] ?? null) !== null && $out['bp_diastolic'] >= $out['bp_systolic']) {
            $errors[] = 'BP diastolic must be lower than systolic.';
        }
        $out['bmi'] = null;
        if (!empty($out['weight']) && !empty($out['height'])) {
            $out['bmi'] = round($out['weight'] / (($out['height'] / 100) ** 2), 1);
        }

        $other = [];
        $labels = self::extraVitalLabels();
        $extra = is_array($in['other'] ?? null) ? $in['other'] : [];
        foreach ($labels as $label) {
            $val = trim((string) ($extra[$label] ?? ''));
            if ($val !== '') {
                if (mb_strlen($val) > 50) {
                    $errors[] = $label . ' must not exceed 50 characters.';
                    continue;
                }
                $other[$label] = $val;
            }
        }
        $out['other_vitals'] = $other ? json_encode($other, JSON_UNESCAPED_UNICODE) : null;
        return [$out, $errors];
    }

    /** "Other configured vitals" from settings, e.g. "Blood Sugar (mg/dL)". */
    public static function extraVitalLabels(): array
    {
        $labels = array_map('trim', explode(',', (string) setting('extra_vitals', '')));
        return array_values(array_filter($labels, static fn ($l) => $l !== ''));
    }

    /**
     * Validates prescription lines against the medicine master and snapshots
     * names, source and dosing details. Quantity is recalculated server-side
     * unless the doctor overrode it (qty_manual).
     * @return array{0: array, 1: string[]}
     */
    public static function resolveMedicines(array $input): array
    {
        $errors = [];
        if (count($input) > self::MAX_MEDICINES) {
            return [[], ['A prescription can have at most ' . self::MAX_MEDICINES . ' medicines.']];
        }
        $ids = array_map(static fn ($l) => (int) (is_array($l) ? ($l['medicine_id'] ?? 0) : 0), $input);
        $medicines = MedicineCatalog::activeByIds($ids);
        $frequencies = MedicineCatalog::frequencies(true);
        $routes = MedicineCatalog::routes(true);

        $lines = [];
        $seen = [];
        foreach ($input as $n => $in) {
            if (!is_array($in)) {
                continue;
            }
            $row = $n + 1;
            $id = (int) ($in['medicine_id'] ?? 0);
            $m = $medicines[$id] ?? null;
            $label = $m['name'] ?? trim((string) ($in['name'] ?? 'Medicine #' . $row));
            if (!$m) {
                $errors[] = '"' . $label . '" is inactive or no longer available. Please remove it.';
                continue;
            }
            if (isset($seen[$id])) {
                $errors[] = 'Medicine already added: ' . $m['name'] . '.';
                continue;
            }
            $seen[$id] = true;

            $dose = $in['dose'] ?? '';
            $duration = $in['duration_days'] ?? '';
            $price = $in['unit_price'] ?? $m['price'];
            if (!is_numeric($dose) || (float) $dose <= 0 || (float) $dose > 1000) {
                $errors[] = $m['name'] . ': dose must be greater than 0.';
                continue;
            }
            if ($duration !== '' && $duration !== null && (!preg_match('/^\d+$/', (string) $duration) || (int) $duration > 3650)) {
                $errors[] = $m['name'] . ': duration must be a whole number of days.';
                continue;
            }
            if (!is_numeric($price) || (float) $price < 0 || (float) $price > 10000000) {
                $errors[] = $m['name'] . ': invalid unit price.';
                continue;
            }
            $frequencyId = (int) ($in['frequency_id'] ?? 0);
            $f = $frequencies[$frequencyId] ?? null;
            if ($frequencyId && !$f) {
                $errors[] = $m['name'] . ': frequency is no longer available.';
                continue;
            }
            $routeId = (int) ($in['route_id'] ?? 0);
            $r = $routes[$routeId] ?? null;
            $instructions = trim((string) ($in['instructions'] ?? ''));
            if (mb_strlen($instructions) > 255) {
                $errors[] = $m['name'] . ': instructions must not exceed 255 characters.';
                continue;
            }

            $days = (int) $duration;
            $auto = $f ? PrescriptionMath::quantity((float) $dose, (float) $f['doses_per_day'], (string) $f['calc_mode'], $days, (float) $m['pack_size']) : null;
            $manual = !empty($in['qty_manual']) || $auto === null;
            $quantity = $manual ? ($in['quantity'] ?? '') : $auto;
            if (!is_numeric($quantity) || (float) $quantity <= 0 || (float) $quantity > 100000) {
                $errors[] = 'Invalid quantity for ' . $m['name'] . '.';
                continue;
            }
            $quantity = round((float) $quantity, 2);
            $price = round((float) $price, 2);

            $lines[] = [
                'medicine_id'    => $id,
                'medicine_name'  => $m['name'],
                'generic_name'   => $m['generic_name'],
                'strength'       => $m['strength'],
                'dosage_form'    => $m['dosage_form'],
                'dose'           => round((float) $dose, 2),
                'dose_unit'      => $m['dose_unit'],
                'frequency_id'   => $f ? $frequencyId : null,
                'frequency_name' => $f['name'] ?? null,
                'frequency_code' => $f['code'] ?? null,
                'doses_per_day'  => $f['doses_per_day'] ?? null,
                'calc_mode'      => $f['calc_mode'] ?? null,
                'route_id'       => $r ? $routeId : null,
                'route_name'     => $r['name'] ?? null,
                'duration_days'  => $duration === '' || $duration === null ? null : $days,
                'quantity'       => $quantity,
                'qty_manual'     => $manual ? 1 : 0,
                'unit'           => $m['unit'],
                'unit_price'     => $price,
                'line_total'     => PrescriptionMath::lineTotal($quantity, $price),
                'source_id'      => $m['source_id'] ? (int) $m['source_id'] : null,
                'source_name'    => $m['source_name'],
                'instructions'   => $instructions !== '' ? $instructions : null,
            ];
        }
        return [$lines, $errors];
    }

    /** Full visit with patient, doctor, clinical items, medicines and Rx groups. */
    public static function load(int $id, bool $withDeleted = false): ?array
    {
        $visit = DB::one(
            'SELECT v.*, u.name AS doctor_name FROM visits v JOIN users u ON u.id = v.doctor_id WHERE v.id = ?'
            . ($withDeleted ? '' : ' AND v.deleted_at IS NULL'),
            [$id]
        );
        if (!$visit) {
            return null;
        }
        $visit['patient'] = PatientService::find((int) $visit['patient_id'], true);
        $visit['items'] = ClinicalItems::load('visit_clinical_items', 'visit_id', $id);
        $visit['medicines'] = DB::all('SELECT * FROM visit_medicines WHERE visit_id = ? ORDER BY rx_group, sort_order, id', [$id]);
        $visit['groups'] = PrescriptionMath::groups($visit['medicines'], (string) setting('rx_unassigned_label', 'General'));
        $visit['other_vitals_list'] = json_decode((string) ($visit['other_vitals'] ?? ''), true) ?: [];
        $visit['doctor'] = DB::one('SELECT u.id, u.name, u.mobile, u.email, dp.* FROM users u LEFT JOIN doctor_profiles dp ON dp.user_id = u.id WHERE u.id = ?', [$visit['doctor_id']]);
        return $visit;
    }

    /**
     * Medicines of a previous visit as new prescription lines, at CURRENT
     * master price and source (BRD §80). Inactive medicines are skipped and
     * reported. The original visit is never modified.
     * @return array{medicines: array, skipped: string[]}
     */
    public static function repeatLines(int $visitId): array
    {
        $rows = DB::all('SELECT * FROM visit_medicines WHERE visit_id = ? ORDER BY sort_order, id', [$visitId]);
        return self::linesFrom($rows);
    }

    /** Shared by repeat and template load. */
    public static function linesFrom(array $rows): array
    {
        $masters = MedicineCatalog::activeByIds(array_column($rows, 'medicine_id'));
        $frequencies = MedicineCatalog::frequencies();
        $lines = [];
        $skipped = [];
        foreach ($rows as $r) {
            $m = $masters[(int) $r['medicine_id']] ?? null;
            if (!$m) {
                $skipped[] = $r['medicine_name'];
                continue;
            }
            $lines[] = MedicineCatalog::line($m, [
                'dose'          => $r['dose'],
                'frequency_id'  => $r['frequency_id'],
                'route_id'      => $r['route_id'],
                'duration_days' => $r['duration_days'],
                'quantity'      => $r['quantity'],
                'qty_manual'    => $r['qty_manual'],
                'instructions'  => (string) ($r['instructions'] ?? ''),
            ], $frequencies);
        }
        return ['medicines' => $lines, 'skipped' => $skipped];
    }

    public static function markPrinted(int $visitId, string $paper): void
    {
        $count = (int) DB::value('SELECT print_count FROM visits WHERE id = ?', [$visitId]);
        DB::run('UPDATE visits SET print_count = print_count + 1, last_printed_at = ? WHERE id = ?', [now(), $visitId]);
        ActivityLog::record($count === 0 ? 'Prescription printed' : 'Prescription reprinted', 'visits', (string) $visitId,
            strtoupper($paper) . ' print' . ($count > 0 ? ' (reprint #' . $count . ')' : ''));
    }

    private static function text(mixed $v): ?string
    {
        $v = trim((string) $v);
        return $v === '' ? null : $v;
    }

    private static function fail(array $errors): array
    {
        return ['ok' => false, 'errors' => $errors, 'message' => $errors[0] ?? 'Unable to save prescription.'];
    }
}
