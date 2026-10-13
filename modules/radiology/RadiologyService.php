<?php
declare(strict_types=1);

namespace App\Modules\Radiology;

use App\Core\ActivityLog;
use App\Core\Auth;
use App\Core\CodeGenerator;
use App\Core\DB;
use App\Modules\Billing\BillingService;
use App\Modules\Inventory\StockService;

/**
 * Radiology (Doc 3 WF 5B, Doc 7 §5):
 *   order (billed) → safety screen (pregnancy for ionizing studies in women of
 *   child-bearing age; allergy + creatinine for contrast) → schedule → acquire
 *   (protocol, contrast from radiology stock, dose log) → Radiologist gate
 *   (accept / reprocess / reject + refund) → report → finalize (immutable;
 *   corrections are addenda; critical finding notifies the ordering doctor).
 */
final class RadiologyService
{
    public const PRIORITIES = ['routine' => 'Routine', 'urgent' => 'Urgent', 'stat' => 'STAT'];
    public const PREGNANCY = ['not_applicable' => 'Not applicable', 'unscreened' => 'Not yet asked', 'not_pregnant' => 'Not pregnant', 'possible' => 'Possibly pregnant', 'pregnant' => 'Pregnant'];
    public const REJECT_REASONS = ['Motion artefact', 'Positioning error', 'Wrong exposure', 'Incomplete coverage', 'Wrong patient / side', 'Equipment fault', 'Other'];

    // ------------------------------------------------------------- orders

    /** Does this patient need the pregnancy question for an ionizing study? */
    public static function needsPregnancyScreen(array $proc, array $patient): bool
    {
        if (!(int) $proc['uses_ionizing_radiation'] || ($patient['gender'] ?? '') !== 'Female') {
            return false;
        }
        $age = age_years($patient['dob'] ?? null);
        return $age === null || ($age >= (int) setting('radiology_pregnancy_age_min', 12) && $age <= (int) setting('radiology_pregnancy_age_max', 50));
    }

    /**
     * @param array $ctx patient_id, visit_id, token_id, doctor_id, department_id, source, referring_doctor, priority, clinical_indication, when
     * @param int[] $procIds
     * @return int[] order ids
     */
    public static function order(array $ctx, array $procIds, array $screen = []): array
    {
        $procIds = array_values(array_unique(array_filter(array_map('intval', $procIds))));
        if (!$procIds) {
            throw new \InvalidArgumentException('Select at least one imaging procedure.');
        }
        $patient = DB::one('SELECT id, gender, dob FROM patients WHERE id = ? AND deleted_at IS NULL', [(int) $ctx['patient_id']]);
        if (!$patient) {
            throw new \RuntimeException('Patient not found.');
        }
        $procs = DB::all('SELECT * FROM radiology_procedure_catalog WHERE id IN (' . DB::placeholders($procIds) . ') AND is_active = 1 AND deleted_at IS NULL', $procIds);
        if (count($procs) !== count($procIds)) {
            throw new \RuntimeException('One of the procedures is not available.');
        }
        $priority = isset(self::PRIORITIES[$ctx['priority'] ?? '']) ? $ctx['priority'] : 'routine';
        return DB::transaction(static function () use ($ctx, $procs, $patient, $priority, $screen): array {
            $ids = [];
            $preg = \App\Core\Migrator::tableExists('pregnancies') ? DB::value("SELECT id FROM pregnancies WHERE patient_id = ? AND status = 'ongoing' LIMIT 1", [$patient['id']]) : null;
            foreach ($procs as $p) {
                $needs = self::needsPregnancyScreen($p, $patient);
                $status = !$needs ? 'not_applicable' : ($preg ? 'pregnant' : (isset(self::PREGNANCY[$screen['pregnancy_status'] ?? '']) && $screen['pregnancy_status'] !== 'not_applicable' ? $screen['pregnancy_status'] : 'unscreened'));
                $price = $p['service_id'] ? BillingService::price((int) $p['service_id']) : 0.0;
                $id = DB::insert('radiology_orders', ['branch_id' => Auth::branchId(), 'order_number' => self::number(), 'encounter_id' => $ctx['visit_id'] ?? null,
                    'patient_id' => $patient['id'], 'procedure_id' => $p['id'], 'modality_id' => $p['modality_id'], 'body_part' => $p['body_part'],
                    'ordered_by' => $ctx['doctor_id'] ?? null, 'ordered_at' => $ctx['when'] ?? now(), 'source' => $ctx['source'] ?? 'consult',
                    'referring_doctor' => $ctx['referring_doctor'] ?? null, 'priority' => $priority,
                    'clinical_indication' => isset($ctx['clinical_indication']) ? mb_substr((string) $ctx['clinical_indication'], 0, 500) : null,
                    'pregnancy_status' => $status, 'contrast_required' => (int) $p['contrast_possible'] && !empty($screen['contrast']) ? 1 : 0,
                    'status' => 'ordered', 'price' => $price, 'service_id' => $p['service_id'], 'pregnancy_id' => $p['is_obstetric'] ? ($preg ?: null) : null]);
                if ($price > 0) {
                    $c = BillingService::charge(['visit_id' => $ctx['visit_id'] ?? null, 'token_id' => $ctx['token_id'] ?? null, 'patient_id' => $patient['id'],
                        'doctor_id' => $ctx['doctor_id'] ?? null, 'department_id' => $ctx['department_id'] ?? null, 'new_if_settled' => empty($ctx['visit_id'])],
                        ['description' => 'Imaging: ' . $p['name'], 'service_id' => $p['service_id'], 'quantity' => 1, 'unit_price' => $price,
                         'source_module' => 'radiology', 'source_reference_type' => 'radiology_order', 'source_reference_id' => $id]);
                    DB::update('radiology_orders', ['invoice_id' => $c['invoice_id'], 'invoice_item_id' => $c['item_id']], 'id = ?', [$id]);
                }
                $ids[] = $id;
            }
            ActivityLog::record('Imaging ordered', 'radiology', (string) $patient['id'], implode(', ', array_column($procs, 'name')));
            return $ids;
        });
    }

    /** Is the order safe to perform? @return string[] blocking issues */
    public static function safetyIssues(array $o): array
    {
        $issues = [];
        if ($o['pregnancy_status'] === 'unscreened') {
            $issues[] = 'Pregnancy screening not done (ionizing study, woman of child-bearing age).';
        }
        if (in_array($o['pregnancy_status'], ['pregnant', 'possible'], true) && (int) $o['uses_ionizing_radiation'] && !$o['screened_by']) {
            $issues[] = 'Patient may be pregnant — the radiologist must justify the study (record it in the screening).';
        }
        if ($o['contrast_required'] && (!$o['allergy_checked'] || $o['creatinine_value'] === null)) {
            $issues[] = 'Contrast study: allergy check and creatinine are required.';
        }
        return $issues;
    }

    /** Reception / technician safety questionnaire. */
    public static function screen(int $orderId, array $d): void
    {
        $o = self::find($orderId);
        if (!in_array($o['status'], ['ordered', 'scheduled', 'reprocess_requested'], true)) {
            throw new \RuntimeException('Screening is done before the study.');
        }
        $status = isset(self::PREGNANCY[$d['pregnancy_status'] ?? '']) ? $d['pregnancy_status'] : $o['pregnancy_status'];
        if ($o['pregnancy_status'] !== 'not_applicable' && in_array($status, ['not_applicable', 'unscreened'], true)) {
            throw new \InvalidArgumentException('Record the pregnancy status.');
        }
        if (in_array($status, ['pregnant', 'possible'], true) && (int) $o['uses_ionizing_radiation'] && trim((string) ($d['justification'] ?? '')) === '' ) {
            throw new \InvalidArgumentException('A possibly pregnant patient needs a documented justification (or cancel / change to ultrasound).');
        }
        DB::update('radiology_orders', ['pregnancy_status' => $o['pregnancy_status'] === 'not_applicable' ? 'not_applicable' : $status,
            'lmp_date' => preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) ($d['lmp_date'] ?? '')) ? $d['lmp_date'] : null,
            'allergy_checked' => empty($d['allergy_checked']) ? (int) $o['allergy_checked'] : 1,
            'creatinine_value' => is_numeric($d['creatinine_value'] ?? null) ? (float) $d['creatinine_value'] : $o['creatinine_value'],
            'clinical_indication' => trim((string) ($d['justification'] ?? '')) !== '' ? mb_substr(trim(($o['clinical_indication'] ?? '') . ' | Justification: ' . $d['justification'], ' |'), 0, 500) : $o['clinical_indication'],
            'screened_by' => Auth::id(), 'screened_at' => now()], 'id = ?', [$orderId]);
        ActivityLog::record('Imaging safety screen', 'radiology', (string) $orderId, $o['order_number'] . ': ' . $status);
    }

    public static function schedule(int $orderId, int $roomId, string $start): void
    {
        $o = self::find($orderId);
        if (!in_array($o['status'], ['ordered', 'scheduled', 'reprocess_requested'], true)) {
            throw new \RuntimeException('This study cannot be scheduled now.');
        }
        if ($issues = self::safetyIssues($o)) {
            throw new \RuntimeException(implode(' ', $issues));
        }
        $room = DB::one('SELECT * FROM radiology_rooms WHERE id = ? AND is_active = 1 AND deleted_at IS NULL', [$roomId]);
        if (!$room || (int) $room['modality_id'] !== (int) $o['modality_id']) {
            throw new \RuntimeException('Choose a room of the right modality.');
        }
        $ts = strtotime($start);
        if (!$ts || $ts < time() - 900) {
            throw new \InvalidArgumentException('Choose a time from now on.');
        }
        $end = date('Y-m-d H:i:s', $ts + max((int) $o['est_duration_min'], (int) $room['slot_minutes']) * 60);
        $begin = date('Y-m-d H:i:s', $ts);
        DB::transaction(static function () use ($o, $roomId, $begin, $end): void {
            DB::run('DELETE FROM radiology_schedule_slots WHERE radiology_order_id = ?', [$o['id']]);
            if (DB::value("SELECT 1 FROM radiology_schedule_slots WHERE room_id = ? AND status = 'booked' AND slot_start < ? AND slot_end > ? FOR UPDATE", [$roomId, $end, $begin])) {
                throw new \RuntimeException('That room is already booked at this time.');
            }
            DB::insert('radiology_schedule_slots', ['room_id' => $roomId, 'slot_start' => $begin, 'slot_end' => $end, 'radiology_order_id' => $o['id'], 'status' => 'booked', 'booked_by' => Auth::id()]);
            DB::update('radiology_orders', ['scheduled_at' => $begin, 'room_id' => $roomId, 'status' => $o['status'] === 'reprocess_requested' ? 'reprocess_requested' : 'scheduled'], 'id = ?', [$o['id']]);
        });
        ActivityLog::record('Imaging scheduled', 'radiology', (string) $orderId, $o['order_number'] . ' at ' . fmt_datetime($begin));
    }

    public static function cancel(int $orderId, string $reason): void
    {
        $o = self::find($orderId);
        if (!in_array($o['status'], ['ordered', 'scheduled'], true)) {
            throw new \RuntimeException('Only studies not yet started can be cancelled.');
        }
        DB::transaction(static function () use ($o, $reason): void {
            self::settleBillLine($o, 'Imaging cancelled: ' . $reason);
            DB::run('DELETE FROM radiology_schedule_slots WHERE radiology_order_id = ?', [$o['id']]);
            DB::update('radiology_orders', ['status' => 'cancelled', 'cancel_reason' => mb_substr($reason, 0, 255)], 'id = ?', [$o['id']]);
        });
        ActivityLog::record('Imaging cancelled', 'radiology', (string) $orderId, $o['order_number'] . ' — ' . $reason);
    }

    // -------------------------------------------------------- acquisition

    /** Modalities the technician works on (none assigned = all). */
    public static function myModalities(): ?array
    {
        $ids = DB::column('SELECT modality_id FROM user_radiology_modalities WHERE user_id = ?', [Auth::id()]);
        return $ids ? array_map('intval', $ids) : null;
    }

    public static function start(int $orderId, array $d = []): int
    {
        $o = self::find($orderId);
        if (!in_array($o['status'], ['ordered', 'scheduled', 'reprocess_requested'], true)) {
            throw new \RuntimeException('This study is not waiting for acquisition.');
        }
        $mine = self::myModalities();
        if ($mine !== null && !in_array((int) $o['modality_id'], $mine, true)) {
            throw new \RuntimeException('You are not assigned to ' . $o['modality_name'] . '.');
        }
        if ($issues = self::safetyIssues($o)) {
            throw new \RuntimeException(implode(' ', $issues));
        }
        $prev = $o['status'] === 'reprocess_requested' ? DB::value('SELECT id FROM radiology_procedures WHERE radiology_order_id = ? ORDER BY id DESC LIMIT 1', [$orderId]) : null;
        return DB::transaction(static function () use ($o, $d, $prev): int {
            $id = DB::insert('radiology_procedures', ['radiology_order_id' => $o['id'], 'room_id' => (int) ($d['room_id'] ?? 0) ?: $o['room_id'],
                'equipment_id' => (int) ($d['equipment_id'] ?? 0) ?: null, 'protocol_id' => (int) ($d['protocol_id'] ?? 0) ?: $o['default_protocol_id'],
                'performed_by' => Auth::id(), 'started_at' => now(), 'reprocess_of_procedure_id' => $prev ?: null, 'status' => 'in_progress']);
            if ($prev) {
                DB::update('radiology_procedure_reviews', ['new_procedure_id' => $id], 'radiology_procedure_id = ?', [$prev]);
            }
            DB::update('radiology_orders', ['status' => 'in_progress'], 'id = ?', [$o['id']]);
            return $id;
        });
    }

    /**
     * Finishes the acquisition: images, dose log, contrast (from radiology
     * stock, FEFO / chosen batch) and consumables; the study goes to the
     * radiologist's review queue.
     */
    public static function finish(int $procedureId, array $d, ?string $imagePath = null): void
    {
        $p = DB::one("SELECT rp.*, o.patient_id, o.encounter_id, o.service_id, o.contrast_required, o.order_number, o.id AS order_id
                        FROM radiology_procedures rp JOIN radiology_orders o ON o.id = rp.radiology_order_id WHERE rp.id = ? AND rp.status = 'in_progress'", [$procedureId]);
        if (!$p) {
            throw new \RuntimeException('Acquisition not in progress.');
        }
        $images = max(0, (int) ($d['image_count'] ?? 0));
        if ($images < 1 && trim((string) ($d['image_reference'] ?? '')) === '' && !$imagePath) {
            throw new \InvalidArgumentException('Record the number of images or the image / PACS reference.');
        }
        $contrastItem = (int) ($d['contrast_item_id'] ?? 0);
        if ($p['contrast_required'] && !$contrastItem) {
            throw new \InvalidArgumentException('This is a contrast study — record the contrast used.');
        }
        DB::transaction(static function () use ($p, $d, $images, $imagePath, $contrastItem): void {
            DB::update('radiology_procedures', ['ended_at' => now(), 'performed_at' => now(), 'image_count' => $images,
                'image_reference' => mb_substr(trim((string) ($d['image_reference'] ?? '')), 0, 255) ?: null, 'image_path' => $imagePath,
                'technical_notes' => mb_substr(trim((string) ($d['technical_notes'] ?? '')), 0, 1000) ?: null, 'status' => 'completed'], 'id = ?', [$p['id']]);
            $num = static fn ($v) => is_numeric($v) ? (float) $v : null;
            if (array_filter([$d['kvp'] ?? null, $d['mas'] ?? null, $d['dap_value'] ?? null, $d['ctdi_vol'] ?? null, $d['dlp'] ?? null], 'is_numeric')) {
                DB::insert('radiology_dose_log', ['procedure_id' => $p['id'], 'equipment_id' => $p['equipment_id'], 'kvp' => $num($d['kvp'] ?? null), 'mas' => $num($d['mas'] ?? null),
                    'dap_value' => $num($d['dap_value'] ?? null), 'ctdi_vol' => $num($d['ctdi_vol'] ?? null), 'dlp' => $num($d['dlp'] ?? null),
                    'exposure_count' => max(1, (int) ($d['exposure_count'] ?? 1)), 'recorded_by' => Auth::id(), 'recorded_at' => now()]);
            }
            if ($contrastItem) {
                $vol = (float) ($d['contrast_volume_ml'] ?? 0);
                if ($vol <= 0) {
                    throw new \InvalidArgumentException('Enter the contrast volume.');
                }
                $loc = StockService::locationOfType('radiology_stock');
                $batch = (int) ($d['contrast_batch_id'] ?? 0);
                $units = max(1, (float) ($d['contrast_units'] ?? 1));
                $opt = ['reference_type' => 'radiology_contrast', 'reference_id' => $p['id'], 'patient_id' => $p['patient_id'], 'visit_id' => $p['encounter_id']];
                if ($loc) {
                    $batch ? StockService::move($contrastItem, $loc, -$units, 'consumption', $opt + ['batch_id' => $batch])
                           : ($batch = (int) (StockService::takeFefo($contrastItem, $loc, $units, 'consumption', $opt)[0]['batch_id'] ?? 0));
                }
                DB::insert('radiology_contrast_usage', ['procedure_id' => $p['id'], 'item_id' => $contrastItem, 'stock_batch_id' => $batch, 'volume_ml' => $vol,
                    'route' => mb_substr((string) ($d['contrast_route'] ?? 'IV'), 0, 20), 'reaction' => mb_substr(trim((string) ($d['contrast_reaction'] ?? '')), 0, 255) ?: null,
                    'creatinine_checked' => 1, 'administered_by' => Auth::id(), 'administered_at' => now()]);
            }
            if ($p['service_id'] && !$p['reprocess_of_procedure_id']) {
                StockService::consumeForService((int) $p['service_id'], 1, 'radiology_procedure', (int) $p['id'], ['patient_id' => $p['patient_id'], 'visit_id' => $p['encounter_id']]);
            }
            DB::update('radiology_orders', ['status' => 'under_review'], 'id = ?', [$p['order_id']]);
            ActivityLog::record('Imaging acquired', 'radiology', (string) $p['order_id'], $p['order_number'] . ': ' . $images . ' image(s)');
        });
    }

    // ------------------------------------------------- Radiologist gate

    /** accept / reprocess (repeat, no new charge) / reject (refund). */
    public static function review(int $procedureId, string $decision, string $reason = ''): void
    {
        $p = DB::one("SELECT rp.*, o.order_number, o.id AS order_id FROM radiology_procedures rp JOIN radiology_orders o ON o.id = rp.radiology_order_id
                       WHERE rp.id = ? AND o.status = 'under_review'", [$procedureId]);
        if (!$p) {
            throw new \RuntimeException('This study is not waiting for review.');
        }
        if (DB::value('SELECT 1 FROM radiology_procedure_reviews WHERE radiology_procedure_id = ?', [$procedureId])) {
            throw new \RuntimeException('Already reviewed.');
        }
        if ($decision !== 'accept' && trim($reason) === '') {
            throw new \InvalidArgumentException('Give the reason for reprocessing / rejecting.');
        }
        DB::transaction(static function () use ($p, $decision, $reason): void {
            $o = self::find((int) $p['order_id']);
            $row = ['radiology_procedure_id' => $p['id'], 'reviewed_by' => Auth::id(), 'reason' => $reason ?: null, 'reviewed_at' => now()];
            if ($decision === 'accept') {
                DB::insert('radiology_procedure_reviews', $row + ['decision' => 'accepted']);
                DB::update('radiology_orders', ['status' => 'accepted'], 'id = ?', [$o['id']]);
            } elseif ($decision === 'reprocess') {
                DB::insert('radiology_procedure_reviews', $row + ['decision' => 'reprocess_requested', 'resolution' => 'reprocess']);
                DB::update('radiology_orders', ['status' => 'reprocess_requested'], 'id = ?', [$o['id']]);
            } elseif ($decision === 'reject') {
                [$amount, $refund] = self::settleBillLine($o, 'Imaging rejected by radiologist: ' . $reason);
                DB::insert('radiology_procedure_reviews', $row + ['decision' => 'rejected', 'resolution' => 'refund', 'refund_amount' => $amount, 'refund_id' => $refund]);
                DB::update('radiology_orders', ['status' => 'rejected_refunded'], 'id = ?', [$o['id']]);
            } else {
                throw new \InvalidArgumentException('Unknown decision.');
            }
            ActivityLog::record('Imaging reviewed', 'radiology', (string) $o['id'], $o['order_number'] . ': ' . $decision . ($reason ? ' — ' . $reason : ''));
        });
    }

    // ------------------------------------------------------------ reports

    public static function saveReport(int $orderId, array $d, bool $finalize): int
    {
        $o = self::find($orderId);
        if (!in_array($o['status'], ['accepted', 'reported'], true)) {
            throw new \RuntimeException('Only accepted studies are reported.');
        }
        $rep = DB::one('SELECT * FROM radiology_reports WHERE radiology_order_id = ?', [$orderId]);
        if ($rep && $rep['status'] !== 'draft') {
            throw new \RuntimeException('A finalized report cannot be edited — add an addendum.');
        }
        if ($finalize && trim((string) ($d['impression'] ?? '')) === '') {
            throw new \InvalidArgumentException('The impression is required to finalize.');
        }
        $proc = (int) DB::value("SELECT rp.id FROM radiology_procedures rp JOIN radiology_procedure_reviews r ON r.radiology_procedure_id = rp.id
                                  WHERE rp.radiology_order_id = ? AND r.decision = 'accepted' ORDER BY rp.id DESC LIMIT 1", [$orderId]);
        $critical = !empty($d['is_critical_finding']);
        $row = ['template_id' => (int) ($d['template_id'] ?? 0) ?: null, 'technique' => trim((string) ($d['technique'] ?? '')) ?: null,
            'findings' => trim((string) ($d['findings'] ?? '')) ?: null, 'impression' => trim((string) ($d['impression'] ?? '')) ?: null,
            'is_critical_finding' => $critical ? 1 : 0, 'reported_by' => Auth::id(), 'reported_at' => now()];
        if ($finalize) {
            $row += ['status' => 'final', 'finalized_at' => now(), 'critical_notified_user_id' => $critical ? ($o['ordered_by'] ?: null) : null];
        }
        return DB::transaction(static function () use ($o, $rep, $row, $proc, $finalize, $d): int {
            if ($rep) {
                DB::update('radiology_reports', $row, 'id = ?', [$rep['id']]);
                $id = (int) $rep['id'];
            } else {
                $id = DB::insert('radiology_reports', $row + ['radiology_order_id' => $o['id'], 'radiology_procedure_id' => $proc, 'status' => $row['status'] ?? 'draft']);
            }
            if ($finalize) {
                DB::update('radiology_orders', ['status' => 'reported'], 'id = ?', [$o['id']]);
                if ($o['is_obstetric'] && $o['pregnancy_id'] && array_filter((array) ($d['ob'] ?? []), static fn ($v) => $v !== '' && $v !== null)) {
                    \App\Modules\Obgyn\ObgynService::saveUltrasound((int) $o['pregnancy_id'], (array) $d['ob'], (int) $o['id'], $id);
                }
                ActivityLog::record('Radiology report finalized', 'radiology', (string) $o['id'], $o['order_number'] . ($row['is_critical_finding'] ? ' — CRITICAL finding' : ''));
            }
            return $id;
        });
    }

    public static function addendum(int $reportId, string $text): void
    {
        $r = DB::one("SELECT * FROM radiology_reports WHERE id = ? AND status IN ('final','addended')", [$reportId]);
        if (!$r) {
            throw new \RuntimeException('Addenda are added to finalized reports.');
        }
        if (trim($text) === '') {
            throw new \InvalidArgumentException('Write the addendum.');
        }
        DB::insert('radiology_report_addenda', ['radiology_report_id' => $reportId, 'addendum_text' => trim($text), 'added_by' => Auth::id(), 'added_at' => now()]);
        DB::update('radiology_reports', ['status' => 'addended'], 'id = ?', [$reportId]);
        ActivityLog::record('Radiology addendum', 'radiology', (string) $r['radiology_order_id'], mb_substr($text, 0, 120));
    }

    public static function acknowledgeCritical(int $reportId): void
    {
        $n = DB::update('radiology_reports', ['critical_acknowledged_at' => now()], 'id = ? AND critical_notified_user_id = ? AND critical_acknowledged_at IS NULL', [$reportId, Auth::id()]);
        if (!$n) {
            throw new \RuntimeException('Nothing to acknowledge.');
        }
    }

    // ------------------------------------------------------------ helpers

    public static function find(int $id): array
    {
        $o = DB::one('SELECT o.*, c.name AS procedure_name, c.code AS procedure_code, c.uses_ionizing_radiation, c.contrast_possible, c.is_obstetric, c.prep_instructions,
                             c.est_duration_min, c.default_protocol_id, c.default_report_template_id, m.name AS modality_name, m.code AS modality_code,
                             p.name AS patient_name, p.mrn, p.gender, p.dob, p.allergies, u.name AS doctor_name, r.name AS room_name
                        FROM radiology_orders o JOIN radiology_procedure_catalog c ON c.id = o.procedure_id JOIN radiology_modalities m ON m.id = o.modality_id
                        JOIN patients p ON p.id = o.patient_id LEFT JOIN users u ON u.id = o.ordered_by LEFT JOIN radiology_rooms r ON r.id = o.room_id WHERE o.id = ?', [$id]);
        if (!$o) {
            throw new \RuntimeException('Imaging order not found.');
        }
        return $o;
    }

    /** Unpaid line → voided; paid → pre-approved refund (the radiologist's rejection is the approval). */
    private static function settleBillLine(array $o, string $reason): array
    {
        if (!$o['invoice_item_id']) {
            return [0.0, null];
        }
        $line = DB::one('SELECT ii.*, i.paid_amount, i.refunded_amount, i.net_amount FROM invoice_items ii JOIN invoices i ON i.id = ii.invoice_id WHERE ii.id = ?', [$o['invoice_item_id']]);
        if (!$line || $line['is_void']) {
            return [0.0, null];
        }
        $amount = (float) $line['amount'];
        if ($amount > 0 && (float) $line['paid_amount'] - (float) $line['refunded_amount'] - ((float) $line['net_amount'] - $amount) >= $amount - 0.004) {
            return [$amount, BillingService::requestRefund((int) $line['invoice_id'], (int) $line['id'], $amount, $reason, 'radiology', 'radiology_order', (int) $o['id'], true)];
        }
        DB::update('invoice_items', ['is_void' => 1], 'id = ?', [$line['id']]);
        BillingService::recalc((int) $line['invoice_id']);
        return [$amount, null];
    }

    private static function number(): string
    {
        return CodeGenerator::next('rad', 'RO-{YYYY}-{000001}', static fn (string $c): bool => DB::value('SELECT 1 FROM radiology_orders WHERE order_number = ?', [$c]) !== null);
    }
}
