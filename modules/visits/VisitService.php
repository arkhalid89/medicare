<?php
declare(strict_types=1);

namespace App\Modules\Visits;

use App\Core\ActivityLog;
use App\Core\Auth;
use App\Core\CodeGenerator;
use App\Core\DB;
use App\Core\Urdu;
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

    /**
     * Vital => [label, usual-low, usual-high, integer?, storage max].
     * Values outside the usual range are only WARNED about (live on screen and
     * in the checkout response) — the doctor's entry is always saved.
     */
    public const VITALS = [
        'bp_systolic'  => ['BP systolic', 40, 300, true, 65535],
        'bp_diastolic' => ['BP diastolic', 20, 200, true, 65535],
        'pulse'        => ['Pulse', 20, 250, true, 65535],
        'temperature'  => ['Temperature (°F)', 90, 110, false, 999.9],
        'weight'       => ['Weight (kg)', 0.5, 400, false, 9999.9],
        'height'       => ['Height (cm)', 30, 250, false, 9999.9],
        'spo2'         => ['SpO₂', 50, 100, true, 65535],
        'resp_rate'    => ['Respiratory rate', 5, 80, true, 65535],
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

        // ---- Token (front desk) ------------------------------------------
        $qtoken = null;
        $tokenId = (int) ($payload['token_id'] ?? 0);
        if ($tokenId > 0) {
            $qtoken = DB::one('SELECT t.*, q.doctor_id AS queue_doctor_id, q.department_id AS queue_department_id FROM tokens t JOIN token_queues q ON q.id = t.queue_id WHERE t.id = ?', [$tokenId]);
            if (!$qtoken) {
                $errors[] = 'The token of this visit no longer exists.';
            }
        }

        // ---- Doctor -------------------------------------------------------
        $doctorId = $actor['role'] === 'doctor' ? (int) $actor['id'] : (int) ($payload['doctor_id'] ?? ($qtoken['queue_doctor_id'] ?? 0));
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
        if ($qtoken && (int) $qtoken['patient_id'] !== $patientId) {
            $errors[] = 'This token belongs to another patient.';
        }
        [$patientData, $patientErrors] = PatientService::validate($patientIn);
        $errors = array_merge($errors, array_values($patientErrors));

        // ---- Vitals -------------------------------------------------------
        $vitalsIn = is_array($payload['vitals'] ?? null) ? $payload['vitals'] : [];
        [$vitals, $vitalErrors, $vitalWarnings] = self::validateVitals($vitalsIn);
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
            ->max('current_history', 'Current history', 5000)
            ->max('findings', 'Findings', 5000)
            ->max('allergies', 'Allergies', 500);
        $followUp = trim((string) ($payload['follow_up_date'] ?? ''));
        $visitDay = substr($visitDate ?? now(), 0, 10);
        if ($followUp !== '' && !isset($v->errors()['follow_up_date']) && $followUp < $visitDay) {
            $v->add('follow_up_date', 'Follow-up date cannot be before the visit date.');
        }
        if (($payload['discount_type'] ?? 'amount') === 'percent' && (float) ($payload['discount_value'] ?? 0) > 100) {
            $v->add('discount_value', 'Discount percentage cannot exceed 100.');
        }
        $errors = array_merge($errors, array_values($v->errors()));

        // ---- Department modules (lab orders, dental chart, ANC …) --------
        $errors = array_merge($errors, VisitHooks::validate($payload, $doctorId, $patientId));

        if ($errors) {
            return self::fail(array_values(array_unique($errors)));
        }

        $paymentStatus = (string) ($payload['payment_status'] ?? 'Paid') ?: 'Paid';
        $discountType = (string) ($payload['discount_type'] ?? 'amount') ?: 'amount';
        $discountValue = setting('discount_enabled', '1') === '1' ? (float) ($payload['discount_value'] ?? 0) : 0.0;
        $taxPercent = setting('tax_enabled', '0') === '1' ? (float) ($payload['tax_percent'] ?? 0) : 0.0;
        $fee = ($payload['consultation_fee'] ?? '') === '' ? (float) ($doctor['default_fee'] ?? 0) : (float) $payload['consultation_fee'];
        // Fee already collected at the registration desk: the receipt decides it.
        $prepaid = $qtoken && $qtoken['invoice_id'] ? self::prepaidFee((int) $qtoken['invoice_id']) : null;
        if ($prepaid) {
            $fee = $prepaid['amount'];
            $paymentStatus = $prepaid['status'];
        }
        // Main Rx (bought outside) vs hospital-supplied sources; only the latter are billed.
        $splitEnabled = setting('rx_split_enabled', '1') === '1';
        $lines = PrescriptionMath::assignGroups($lines, $splitEnabled, MedicineCatalog::sourceOrder(), MedicineCatalog::mainSourceId());
        $bill = PrescriptionMath::bill($lines, $discountType, $discountValue, $taxPercent, $fee, $paymentStatus);
        $allergies = self::text($payload['allergies'] ?? '');
        $patientData['allergies'] = $allergies;

        $repeatedFrom = (int) ($payload['repeated_from_visit_id'] ?? 0);
        // Refill is counted automatically: each repeat of a prescription adds one.
        $refills = $repeatedFrom > 0 ? (int) (DB::value('SELECT refills FROM visits WHERE id = ?', [$repeatedFrom]) ?? -1) + 1 : 0;
        $templateId = (int) ($payload['template_id'] ?? 0);
        $canEditPatient = $patientId > 0 ? PatientService::canEdit($patientId) : Auth::can('patients.edit');

        try {
            return DB::transaction(static function () use (
                $token, $doctorId, $patientId, $patientData, $vitals, $items, $lines, $payload, $bill,
                $paymentStatus, $discountType, $discountValue, $taxPercent, $splitEnabled, $repeatedFrom, $templateId,
                $visitDate, $canEditPatient, $followUp, $allergies, $vitalWarnings, $refills, $qtoken, $prepaid
            ): array {
                // 1. Patient saved
                if ($patientId > 0) {
                    if ($canEditPatient) {
                        PatientService::update($patientId, $patientData);
                    } else {
                        DB::update('patients', ['allergies' => $allergies], 'id = ?', [$patientId]);
                    }
                } else {
                    $patientId = (int) PatientService::create($patientData, $doctorId)['id'];
                }

                // 2. Visit created (vitals, follow-up, fee, instructions, bill)
                $when = $visitDate ?? now();
                $visitNo = CodeGenerator::next(
                    'visit',
                    (string) setting('visit_no_pattern', 'V-{YYYY}-{000001}'),
                    static fn (string $c): bool => DB::value('SELECT 1 FROM visits WHERE visit_no = ?', [$c]) !== null,
                    [],
                    (int) strtotime($when)
                );
                $visitId = DB::insert('visits', array_merge($vitals, [
                    'visit_no'               => $visitNo,
                    'checkout_token'         => $token,
                    'patient_id'             => $patientId,
                    'doctor_id'              => $doctorId,
                    'visit_date'             => $when,
                    'allergies'              => $allergies,
                    'current_history'        => self::text($payload['current_history'] ?? ''),
                    'findings'               => self::text($payload['findings'] ?? ''),
                    'refills'                => max(0, min(255, $refills)),
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
                    'rx_split_mode'          => (string) setting('rx_split_print_mode', 'slip'),
                    'repeated_from_visit_id' => $repeatedFrom > 0 && DB::value('SELECT 1 FROM visits WHERE id = ?', [$repeatedFrom]) ? $repeatedFrom : null,
                    'template_id'            => $templateId > 0 && DB::value('SELECT 1 FROM templates WHERE id = ? AND doctor_id = ?', [$templateId, $doctorId]) ? $templateId : null,
                    'branch_id'              => Auth::branchId(),
                    'department_id'          => self::departmentFor($doctorId, $qtoken, $payload),
                    'visit_type_id'          => $qtoken['visit_type_id'] ?? (((int) ($payload['visit_type_id'] ?? 0)) ?: null),
                    'token_id'               => $qtoken['id'] ?? null,
                    'appointment_id'         => $qtoken['appointment_id'] ?? null,
                    'service_point_id'       => $qtoken['service_point_id'] ?? null,
                    'cosign_required'        => Auth::requiresCosign() ? 1 : 0,
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
                        'dose_type'      => $line['dose_type'],
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
                        'is_main_rx'     => $line['is_main_rx'],
                        'billable'       => $line['billable'],
                        'instructions'   => $line['instructions'],
                        'sort_order'     => $i + 1,
                    ]);
                }

                if ($templateId > 0) {
                    DB::run('UPDATE templates SET usage_count = usage_count + 1 WHERE id = ? AND doctor_id = ?', [$templateId, $doctorId]);
                }

                // 10. Token closed, nurse vitals linked, bill mirrored into an invoice
                if ($qtoken) {
                    DB::run('UPDATE vitals SET visit_id = ? WHERE token_id = ? AND visit_id IS NULL', [$visitId, $qtoken['id']]);
                    \App\Modules\Frontdesk\TokenService::complete((int) $qtoken['id'], $visitId);
                }
                $invoiceId = self::mirrorInvoice($visitId, $patientId, $doctorId, $qtoken, $prepaid, $lines, $bill, $paymentStatus, $when);
                DB::update('visits', ['invoice_id' => $invoiceId], 'id = ?', [$visitId]);

                // 11. Department modules: lab / imaging orders, pharmacy queue, dental chart, ANC …
                VisitHooks::afterCheckout($visitId, $payload, ['patient_id' => $patientId, 'doctor_id' => $doctorId, 'invoice_id' => $invoiceId,
                    'token_id' => $qtoken['id'] ?? null, 'lines' => $lines, 'items' => $items, 'when' => $when]);

                ActivityLog::record('Visit created', 'visits', (string) $visitId, 'Visit ' . $visitNo . ' for ' . $patientData['name']
                    . ' — ' . count($lines) . ' medicine(s), total ' . money($bill['grand_total']));
                if ($lines) {
                    ActivityLog::record('Prescription created', 'visits', (string) $visitId, 'Prescription ' . $visitNo . ' with ' . count($lines) . ' medicine(s)');
                }

                return ['ok' => true, 'visit_id' => $visitId, 'visit_no' => $visitNo, 'patient_id' => $patientId,
                    'warnings' => $vitalWarnings, 'message' => 'Prescription saved successfully.'];
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

    /**
     * Consultation fee already on the registration receipt.
     * @return array{amount: float, status: string}|null
     */
    public static function prepaidFee(int $invoiceId): ?array
    {
        $inv = DB::one('SELECT * FROM invoices WHERE id = ?', [$invoiceId]);
        if (!$inv || $inv['status'] === 'void') {
            return null;
        }
        $amount = (float) DB::value("SELECT COALESCE(SUM(amount), 0) FROM invoice_items WHERE invoice_id = ? AND is_void = 0 AND source_reference_type = 'consultation'", [$invoiceId]);
        $status = $inv['waived'] ? 'Free' : ($inv['status'] === 'settled' ? 'Paid' : 'Pending');
        return ['amount' => $inv['waived'] ? 0.0 : $amount, 'status' => $status];
    }

    /** Department of the encounter: the token's queue, the screen's choice, else the doctor's first department. */
    private static function departmentFor(int $doctorId, ?array $token, array $payload): ?int
    {
        if ($token && $token['queue_department_id']) {
            return (int) $token['queue_department_id'];
        }
        $chosen = (int) ($payload['department_id'] ?? 0);
        if ($chosen > 0 && DB::value('SELECT 1 FROM departments WHERE id = ? AND deleted_at IS NULL', [$chosen])) {
            return $chosen;
        }
        return (int) DB::value('SELECT MIN(department_id) FROM user_departments WHERE user_id = ?', [$doctorId]) ?: null;
    }

    /**
     * Mirrors the visit bill into the invoice system (Doc 3 WF 8): the
     * consultation fee (unless already on the registration receipt) and —
     * when hospital medicines are billed at checkout — the billable medicine
     * lines, the doctor's discount and tax. "Paid" collects the balance in
     * cash, "Free" waives it, "Pending" leaves it for the cashier.
     */
    private static function mirrorInvoice(int $visitId, int $patientId, int $doctorId, ?array $token, ?array $prepaid, array $lines, array $bill, string $status, string $when): ?int
    {
        $medsAtCheckout = setting('billing_medicines_at', 'checkout') === 'checkout';
        $items = [];
        if (!$prepaid && (float) $bill['consultation_fee'] > 0) {
            $items[] = ['description' => 'Consultation — ' . (string) DB::value('SELECT name FROM users WHERE id = ?', [$doctorId]),
                'service_id' => DB::value("SELECT id FROM service_catalog WHERE code = 'CONS-OPD'") ?: null, 'quantity' => 1,
                'unit_price' => (float) $bill['consultation_fee'], 'source_module' => 'registration', 'source_reference_type' => 'consultation', 'source_reference_id' => $visitId];
        }
        if ($medsAtCheckout) {
            foreach ($lines as $l) {
                if ((int) ($l['billable'] ?? 1) === 1 && (float) $l['line_total'] > 0) {
                    $items[] = ['description' => trim($l['medicine_name'] . ' ' . ($l['strength'] ?? '')), 'quantity' => $l['quantity'], 'unit_price' => $l['unit_price'],
                        'source_module' => 'pharmacy', 'source_reference_type' => 'visit', 'source_reference_id' => $visitId];
                }
            }
            if ((float) $bill['tax_amount'] > 0) {
                $items[] = ['description' => (string) setting('tax_label', 'Tax'), 'quantity' => 1, 'unit_price' => 0, 'tax_amount' => (float) $bill['tax_amount'],
                    'source_module' => 'pharmacy', 'source_reference_type' => 'visit', 'source_reference_id' => $visitId];
            }
        }
        $invoiceId = $token['invoice_id'] ?? null;
        if (!$items) {
            if ($invoiceId) {
                DB::update('invoices', ['visit_id' => $visitId], 'id = ?', [$invoiceId]);
            }
            return $invoiceId ? (int) $invoiceId : null;
        }
        if (!$invoiceId) {
            $invoiceId = \App\Modules\Billing\BillingService::create(['patient_id' => $patientId, 'visit_id' => $visitId, 'doctor_id' => $doctorId,
                'department_id' => DB::value('SELECT department_id FROM visits WHERE id = ?', [$visitId]), 'invoice_type' => 'opd', 'created_at' => $when]);
        }
        DB::update('invoices', ['visit_id' => $visitId], 'id = ?', [$invoiceId]);
        foreach ($items as $it) {
            \App\Modules\Billing\BillingService::addItem((int) $invoiceId, $it, false);
        }
        if ($medsAtCheckout && (float) $bill['discount_amount'] > 0) {
            DB::run("UPDATE invoices SET discount_amount = discount_amount + ?, discount_status = 'approved', discount_reason = 'Prescription discount', discount_by = ?, discount_approved_by = ? WHERE id = ?",
                [(float) $bill['discount_amount'], Auth::id(), Auth::id(), $invoiceId]);
        }
        $inv = \App\Modules\Billing\BillingService::recalc((int) $invoiceId);
        if (!$prepaid) {
            if ($status === 'Free') {
                DB::update('invoices', ['waived' => 1, 'waiver_reason' => 'Marked free by the doctor', 'waived_by' => Auth::id()], 'id = ?', [$invoiceId]);
                \App\Modules\Billing\BillingService::recalc((int) $invoiceId);
            } elseif ($status === 'Paid' && (float) $inv['net_amount'] - (float) $inv['paid_amount'] > 0) {
                DB::insert('payments', ['invoice_id' => $invoiceId, 'branch_id' => Auth::branchId(), 'amount' => round((float) $inv['net_amount'] - (float) $inv['paid_amount'], 2),
                    'mode' => 'cash', 'cash_session_id' => null, 'collected_by' => Auth::id(), 'collected_at' => $when]);
                \App\Modules\Billing\BillingService::recalc((int) $invoiceId);
            }
        }
        return (int) $invoiceId;
    }

    /**
     * Vitals are saved exactly as entered. Only values that cannot be stored
     * (text, negative, absurdly large) are errors; values outside the usual
     * range come back as warnings.
     * @return array{0: array, 1: string[], 2: string[]}  [values, errors, warnings]
     */
    public static function validateVitals(array $in): array
    {
        $out = [];
        $errors = [];
        $warnings = [];
        foreach (self::VITALS as $key => [$label, $min, $max, $integer, $cap]) {
            $raw = trim((string) ($in[$key] ?? ''));
            if ($raw === '') {
                $out[$key] = null;
                continue;
            }
            if (!is_numeric($raw)) {
                $errors[] = $label . ' must be a number.';
                continue;
            }
            $n = (float) $raw;
            if ($n < 0 || $n > $cap) {
                $errors[] = $label . ' value ' . $raw . ' cannot be stored.';
                continue;
            }
            if ($n < $min || $n > $max) {
                $warnings[] = $label . ' ' . num($n) . ' is outside the usual range (' . num($min) . '–' . num($max) . ').';
            }
            $out[$key] = $integer ? (int) round($n) : round($n, 1);
        }
        if (($out['bp_systolic'] ?? null) !== null && ($out['bp_diastolic'] ?? null) !== null && $out['bp_diastolic'] >= $out['bp_systolic']) {
            $warnings[] = 'BP diastolic is not lower than systolic.';
        }
        $out['bmi'] = null;
        if (!empty($out['weight']) && !empty($out['height'])) {
            $bmi = round($out['weight'] / (($out['height'] / 100) ** 2), 1);
            $out['bmi'] = $bmi <= 9999.9 ? $bmi : null;
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
        return [$out, $errors, $warnings];
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
            $doseType = (string) ($m['dose_type'] ?? '') ?: 'other';
            $unitIn = trim((string) ($in['dose_unit'] ?? ''));
            $unit = ($unitIn !== '' ? MedicineCatalog::validUnit(Urdu::unitKey($unitIn), $doseType) : null)
                ?? MedicineCatalog::validUnit(Urdu::unitKey($m['dose_unit'] ?? ''), $doseType)
                ?? (Urdu::DOSE_CHOICES[$doseType] ?? Urdu::DOSE_CHOICES['other'])[0][0];
            $auto = $f ? PrescriptionMath::quantity((float) $dose, (float) $f['doses_per_day'], (string) $f['calc_mode'], $days,
                (float) $m['pack_size'], MedicineCatalog::unitFactor($unit)) : null;
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
                'dose'           => round((float) $dose, 3),
                'dose_unit'      => $unit,
                'dose_type'      => $doseType,
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
        // "No. of visit" on the PHC prescription: this patient's visits up to and including this one.
        $visit['visit_number'] = (int) DB::value(
            'SELECT COUNT(*) FROM visits WHERE patient_id = ? AND deleted_at IS NULL AND (visit_date < ? OR (visit_date = ? AND id <= ?))',
            [$visit['patient_id'], $visit['visit_date'], $visit['visit_date'], $id]
        );
        $day = self::dayStats($visit);
        $visit['day_cumulative'] = $day['amount'];
        $visit['day_count'] = $day['count'];
        return $visit;
    }

    /**
     * The doctor's running figures for the visit's day, up to and including
     * this visit: cumulative bill (patient 1 = 200, patient 2 = 300 → 500 …)
     * and the number of prescriptions written so far that day.
     * @return array{amount: float, count: int}
     */
    public static function dayStats(array $visit): array
    {
        $day = substr((string) $visit['visit_date'], 0, 10);
        $row = DB::one(
            'SELECT COALESCE(SUM(grand_total), 0) AS amount, COUNT(*) AS cnt FROM visits
              WHERE doctor_id = ? AND deleted_at IS NULL AND visit_date >= ? AND visit_date <= ?
                AND (visit_date < ? OR (visit_date = ? AND id <= ?))',
            [$visit['doctor_id'], $day . ' 00:00:00', $day . ' 23:59:59', $visit['visit_date'], $visit['visit_date'], $visit['id']]
        );
        return ['amount' => (float) $row['amount'], 'count' => (int) $row['cnt']];
    }

    public static function dayCumulative(array $visit): float
    {
        return self::dayStats($visit)['amount'];
    }

    /**
     * Everything of a previous visit for the "Previous visits → Repeat" popup:
     * complaints, symptoms, diagnoses, investigations, medicines (current price),
     * history, findings, allergies and instructions. Vitals and fee are not
     * copied (they belong to today's examination). The old visit is unchanged.
     */
    public static function refillData(int $visitId): ?array
    {
        $v = self::load($visitId);
        if (!$v) {
            return null;
        }
        $meds = self::repeatLines($visitId);
        return array_merge($v['items'], [
            'visit_id'               => (int) $v['id'],
            'visit_no'               => $v['visit_no'],
            'visit_date'             => $v['visit_date'],
            'medicines'              => $meds['medicines'],
            'skipped'                => $meds['skipped'],
            'current_history'        => (string) ($v['current_history'] ?? ''),
            'findings'               => (string) ($v['findings'] ?? ''),
            'allergies'              => (string) ($v['allergies'] ?? ''),
            'overall_instructions'   => (string) ($v['overall_instructions'] ?? ''),
            'follow_up_instructions' => (string) ($v['follow_up_instructions'] ?? ''),
            'refills'                => (int) ($v['refills'] ?? 0),
        ]);
    }

    /** Previous visits of a patient for the popup grid (newest first). */
    public static function history(int $patientId, int $limit = 50): array
    {
        return DB::all(
            "SELECT v.id, v.visit_no, v.visit_date, v.grand_total, v.payment_status, v.follow_up_date, u.name AS doctor_name,
                    v.bp_systolic, v.bp_diastolic, v.pulse, v.temperature, v.weight,
                    (SELECT GROUP_CONCAT(i.name ORDER BY i.sort_order SEPARATOR ', ') FROM visit_clinical_items i WHERE i.visit_id = v.id AND i.item_type = 'complaint') AS complaints,
                    (SELECT GROUP_CONCAT(i.name ORDER BY i.sort_order SEPARATOR ', ') FROM visit_clinical_items i WHERE i.visit_id = v.id AND i.item_type = 'diagnosis') AS diagnoses,
                    (SELECT GROUP_CONCAT(i.name ORDER BY i.sort_order SEPARATOR ', ') FROM visit_clinical_items i WHERE i.visit_id = v.id AND i.item_type = 'investigation') AS investigations,
                    (SELECT GROUP_CONCAT(m.medicine_name ORDER BY m.sort_order SEPARATOR ', ') FROM visit_medicines m WHERE m.visit_id = v.id) AS medicines
               FROM visits v JOIN users u ON u.id = v.doctor_id
              WHERE v.patient_id = ? AND v.deleted_at IS NULL ORDER BY v.visit_date DESC, v.id DESC LIMIT " . max(1, min(200, $limit)),
            [$patientId]
        );
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
                'dose_unit'     => (string) ($r['dose_unit'] ?? ''),
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
