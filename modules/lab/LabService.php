<?php
declare(strict_types=1);

namespace App\Modules\Lab;

use App\Core\ActivityLog;
use App\Core\Auth;
use App\Core\CodeGenerator;
use App\Core\DB;
use App\Core\Migrator;
use App\Modules\Billing\BillingService;
use App\Modules\Inventory\StockService;

/**
 * Laboratory workflow (Doc 3 WF 5):
 *   order (billed at order time) → collect & label → Pathologist gate 1
 *   (accept, or reject with exactly one resolution: resample XOR refund) →
 *   result entry (section-restricted, range snapshot, flags) → Pathologist
 *   gate 2 (verify — blocked while the parameter's latest QC run failed) →
 *   release (report versioned; amendments create a new version).
 */
final class LabService
{
    public const REJECT_REASONS = ['Haemolysed', 'Clotted', 'Insufficient quantity', 'Wrong container', 'Unlabelled / mislabelled', 'Delayed transport', 'Contaminated', 'Other'];
    public const OPEN = ['ordered', 'sample_pending', 'resample_pending', 'collected', 'accepted', 'result_entered'];
    public const DONE = ['verified', 'released', 'rejected_refunded', 'cancelled'];

    // ---------------------------------------------------------------- orders

    /**
     * @param array $ctx patient_id, visit_id, token_id, doctor_id, department_id, source, priority, referring_doctor, clinical_notes
     * @param int[] $testIds
     */
    public static function order(array $ctx, array $testIds): int
    {
        $testIds = array_values(array_unique(array_filter(array_map('intval', $testIds))));
        if (!$testIds) {
            throw new \InvalidArgumentException('Select at least one test.');
        }
        $tests = DB::all('SELECT * FROM lab_tests WHERE id IN (' . DB::placeholders($testIds) . ') AND is_active = 1 AND deleted_at IS NULL', $testIds);
        if (count($tests) !== count($testIds)) {
            throw new \RuntimeException('One of the selected tests is not available.');
        }
        $patientId = (int) $ctx['patient_id'];
        $priority = in_array($ctx['priority'] ?? 'routine', ['routine', 'urgent', 'stat'], true) ? ($ctx['priority'] ?? 'routine') : 'routine';
        return DB::transaction(static function () use ($ctx, $tests, $patientId, $priority): int {
            $pregnancy = Migrator::tableExists('pregnancies')
                ? DB::value("SELECT id FROM pregnancies WHERE patient_id = ? AND status = 'ongoing' ORDER BY id DESC LIMIT 1", [$patientId]) : null;
            $orderId = DB::insert('lab_orders', [
                'branch_id' => Auth::branchId(), 'order_number' => self::number('lab_order', 'LO'), 'visit_id' => $ctx['visit_id'] ?? null,
                'patient_id' => $patientId, 'ordered_by' => $ctx['doctor_id'] ?? Auth::id(), 'ordered_at' => $ctx['when'] ?? now(),
                'priority' => $priority, 'status' => 'ordered', 'source' => $ctx['source'] ?? 'consult',
                'referring_doctor' => $ctx['referring_doctor'] ?? null, 'clinical_notes' => isset($ctx['clinical_notes']) ? mb_substr((string) $ctx['clinical_notes'], 0, 500) : null,
                'pregnancy_id' => $pregnancy ?: null,
            ]);
            $invoiceId = null;
            foreach ($tests as $t) {
                $price = $t['service_id'] ? BillingService::price((int) $t['service_id']) : 0.0;
                $itemId = DB::insert('lab_order_items', ['lab_order_id' => $orderId, 'lab_test_id' => $t['id'], 'status' => 'ordered', 'priority' => $priority,
                    'performing_section_id' => $t['section_id'], 'price' => $price]);
                if ($price > 0 || $t['service_id']) {
                    $c = BillingService::charge(['visit_id' => $ctx['visit_id'] ?? null, 'token_id' => $ctx['token_id'] ?? null, 'patient_id' => $patientId,
                        'doctor_id' => $ctx['doctor_id'] ?? null, 'department_id' => $ctx['department_id'] ?? null, 'new_if_settled' => empty($ctx['visit_id'])],
                        ['description' => 'Lab: ' . $t['name'], 'service_id' => $t['service_id'], 'quantity' => 1, 'unit_price' => $price,
                         'source_module' => 'lab', 'source_reference_type' => 'lab_order_item', 'source_reference_id' => $itemId]);
                    DB::update('lab_order_items', ['invoice_item_id' => $c['item_id']], 'id = ?', [$itemId]);
                    $invoiceId = $c['invoice_id'];
                }
            }
            DB::update('lab_orders', ['invoice_id' => $invoiceId], 'id = ?', [$orderId]);
            ActivityLog::record('Lab order created', 'lab', (string) $orderId, count($tests) . ' test(s): ' . implode(', ', array_column($tests, 'code')),
                audit: ['entity' => 'lab_order']);
            return $orderId;
        });
    }

    /** Cancel an item that has not been collected yet; the bill line is voided (or refunded if paid). */
    public static function cancelItem(int $itemId, string $reason): void
    {
        $it = self::item($itemId);
        if (!in_array($it['status'], ['ordered', 'sample_pending'], true)) {
            throw new \RuntimeException('Only tests not yet collected can be cancelled.');
        }
        DB::transaction(static function () use ($it, $reason): void {
            self::settleBillLine($it, 'Lab test cancelled: ' . $reason);
            DB::update('lab_order_items', ['status' => 'cancelled'], 'id = ?', [$it['id']]);
            self::syncOrder((int) $it['lab_order_id']);
            ActivityLog::record('Lab test cancelled', 'lab', (string) $it['lab_order_id'], $it['test_name'] . ' — ' . $reason);
        });
    }

    // ---------------------------------------------------------- collection

    /**
     * Collects and labels samples for the given order items (one barcode per
     * item; a resample links back to the rejected sample). Consumables of the
     * test (tube, reagent) are drawn from lab stock.
     * @return int[] sample ids
     */
    public static function collect(array $itemIds, ?bool $fasting = null, string $notes = ''): array
    {
        $itemIds = array_values(array_unique(array_filter(array_map('intval', $itemIds))));
        if (!$itemIds) {
            throw new \InvalidArgumentException('Select the samples collected.');
        }
        return DB::transaction(static function () use ($itemIds, $fasting, $notes): array {
            $out = [];
            foreach ($itemIds as $id) {
                $it = self::item($id);
                if (!in_array($it['status'], ['ordered', 'sample_pending', 'resample_pending'], true)) {
                    throw new \RuntimeException($it['test_name'] . ': sample already collected.');
                }
                $prev = $it['status'] === 'resample_pending'
                    ? DB::value("SELECT id FROM lab_samples WHERE lab_order_item_id = ? AND status = 'rejected' ORDER BY id DESC LIMIT 1", [$id]) : null;
                $sid = DB::insert('lab_samples', [
                    'lab_order_id' => $it['lab_order_id'], 'lab_order_item_id' => $id, 'barcode' => self::barcode(),
                    'specimen_type_id' => $it['specimen_type_id'], 'container_id' => $it['container_id'], 'status' => 'collected',
                    'fasting' => $fasting === null ? null : (int) $fasting, 'collected_by' => Auth::id(), 'collected_at' => now(),
                    'resample_of_sample_id' => $prev ?: null, 'notes' => $notes !== '' ? mb_substr($notes, 0, 255) : null,
                ]);
                if ($prev) {
                    DB::update('lab_sample_reviews', ['new_sample_id' => $sid], 'lab_sample_id = ?', [$prev]);
                }
                DB::update('lab_order_items', ['status' => 'collected', 'collected_at' => now()], 'id = ?', [$id]);
                if ($it['service_id']) {
                    StockService::consumeForService((int) $it['service_id'], 1, 'lab_sample', $sid, ['patient_id' => $it['patient_id']]);
                }
                $out[] = $sid;
                self::syncOrder((int) $it['lab_order_id']);
            }
            ActivityLog::record('Lab samples collected', 'lab', null, count($out) . ' sample(s)');
            return $out;
        });
    }

    // -------------------------------------------- Pathologist gate 1: sample

    /**
     * Accept, or reject with exactly one resolution: 'resample' (patient
     * re-collected, no new charge) XOR 'refund' (the test line is refunded —
     * pre-approved, because the Pathologist's rejection is the approval).
     */
    public static function review(int $sampleId, string $decision, ?string $reason = null, ?string $resolution = null): void
    {
        $s = DB::one('SELECT s.*, li.lab_order_id, li.status AS item_status FROM lab_samples s JOIN lab_order_items li ON li.id = s.lab_order_item_id WHERE s.id = ?', [$sampleId]);
        if (!$s || $s['status'] !== 'collected') {
            throw new \RuntimeException('This sample has already been reviewed.');
        }
        if (!in_array($decision, ['accept', 'reject'], true)) {
            throw new \InvalidArgumentException('Choose accept or reject.');
        }
        if ($decision === 'reject') {
            if (!$reason) {
                throw new \InvalidArgumentException('A rejection needs a reason.');
            }
            if (!in_array($resolution, ['resample', 'refund'], true)) {
                throw new \InvalidArgumentException('A rejected sample must be resolved by exactly one of: resample or refund.');
            }
        }
        DB::transaction(static function () use ($s, $decision, $reason, $resolution): void {
            $it = self::item((int) $s['lab_order_item_id']);
            $review = ['lab_sample_id' => $s['id'], 'reviewed_by' => Auth::id(), 'decision' => $decision, 'reviewed_at' => now()];
            if ($decision === 'accept') {
                DB::update('lab_samples', ['status' => 'accepted'], 'id = ?', [$s['id']]);
                DB::update('lab_order_items', ['status' => 'accepted', 'accepted_at' => now()], 'id = ?', [$it['id']]);
                DB::insert('lab_sample_reviews', $review);
                ActivityLog::record('Lab sample accepted', 'lab', (string) $it['lab_order_id'], $s['barcode'] . ' — ' . $it['test_name']);
            } else {
                DB::update('lab_samples', ['status' => 'rejected'], 'id = ?', [$s['id']]);
                $review += ['rejection_reason' => mb_substr((string) $reason, 0, 100), 'resolution' => $resolution];
                if ($resolution === 'resample') {
                    DB::update('lab_order_items', ['status' => 'resample_pending'], 'id = ?', [$it['id']]);
                } else {
                    [$amount, $refundId] = self::settleBillLine($it, 'Lab sample rejected (' . $reason . ') — ' . $it['test_name']);
                    $review += ['refund_amount' => $amount, 'refund_id' => $refundId];
                    DB::update('lab_order_items', ['status' => 'rejected_refunded'], 'id = ?', [$it['id']]);
                }
                DB::insert('lab_sample_reviews', $review);
                ActivityLog::record('Lab sample rejected', 'lab', (string) $it['lab_order_id'], $s['barcode'] . ' — ' . $it['test_name'] . ': ' . $reason . ' → ' . $resolution,
                    audit: ['entity' => 'lab_sample', 'new' => ['decision' => 'reject', 'resolution' => $resolution]]);
            }
            self::syncOrder((int) $it['lab_order_id']);
        });
    }

    // --------------------------------------------------------- result entry

    /** Sections the user may enter results for; null = unrestricted. */
    public static function mySections(): ?array
    {
        $ids = DB::column('SELECT section_id FROM user_lab_sections WHERE user_id = ?', [Auth::id()]);
        return $ids ? array_map('intval', $ids) : null;
    }

    /** Parameters + applicable range + current values of an order item. */
    public static function worksheet(int $itemId): array
    {
        $it = self::item($itemId);
        $params = DB::all('SELECT * FROM lab_test_parameters WHERE lab_test_id = ? AND is_active = 1 ORDER BY display_order, id', [$it['lab_test_id']]);
        $current = DB::one('SELECT * FROM lab_results WHERE lab_order_item_id = ? AND is_current = 1', [$itemId]);
        $values = $current ? DB::all('SELECT * FROM lab_result_values WHERE lab_result_id = ?', [$current['id']]) : [];
        $byParam = array_column($values, null, 'parameter_id');
        foreach ($params as &$p) {
            $p['range'] = self::rangeFor((int) $p['id'], (string) $it['gender'], $it['dob'], (bool) $it['pregnancy_id']);
            $p['options'] = $p['options_json'] ? (json_decode($p['options_json'], true) ?: []) : [];
            $p['value'] = $byParam[$p['id']] ?? null;
            $p['qc_blocked'] = self::qcBlocked((int) $p['id']);
        }
        unset($p);
        return ['item' => $it, 'params' => $params, 'result' => $current];
    }

    /**
     * Saves provisional results (re-entry before verification replaces the
     * values). The reference range applied is snapshotted on every value.
     * @param array $values parameter_id => value
     */
    public static function enterResults(int $itemId, array $values, string $comment = ''): int
    {
        $it = self::item($itemId);
        if (!in_array($it['status'], ['accepted', 'result_entered'], true)) {
            throw new \RuntimeException('Results can be entered only for accepted samples that are not yet verified.');
        }
        $mine = self::mySections();
        if ($mine !== null && !in_array((int) $it['performing_section_id'], $mine, true)) {
            throw new \RuntimeException('You are not assigned to the ' . ($it['section_name'] ?: 'performing') . ' section.');
        }
        $ws = self::worksheet($itemId);
        return DB::transaction(static function () use ($it, $ws, $values, $comment): int {
            $current = $ws['result'];
            if ($current && !$current['verified_at']) {
                DB::run('DELETE FROM lab_result_values WHERE lab_result_id = ?', [$current['id']]);
                DB::update('lab_results', ['comment' => $comment ?: null, 'entered_by' => Auth::id(), 'entered_at' => now()], 'id = ?', [$current['id']]);
                $resultId = (int) $current['id'];
            } else {
                $resultId = DB::insert('lab_results', ['lab_order_item_id' => $it['id'], 'version' => 1, 'is_current' => 1,
                    'comment' => $comment ?: null, 'entered_by' => Auth::id(), 'entered_at' => now()]);
            }
            $critical = self::saveValues($resultId, $ws['params'], $values);
            DB::update('lab_results', ['is_critical' => $critical ? 1 : 0], 'id = ?', [$resultId]);
            DB::update('lab_order_items', ['status' => 'result_entered', 'result_entered_at' => now()], 'id = ?', [$it['id']]);
            self::syncOrder((int) $it['lab_order_id']);
            ActivityLog::record('Lab result entered', 'lab', (string) $it['lab_order_id'], $it['test_name'] . ($critical ? ' — CRITICAL value' : ''));
            return $resultId;
        });
    }

    /** @return bool any critical value */
    private static function saveValues(int $resultId, array $params, array $values): bool
    {
        $critical = false;
        $any = false;
        foreach ($params as $p) {
            $raw = trim((string) ($values[$p['id']] ?? ''));
            if ($raw === '') {
                continue;
            }
            $any = true;
            $r = $p['range'];
            $row = ['lab_result_id' => $resultId, 'parameter_id' => $p['id'], 'unit' => $p['unit'], 'ref_low' => $r['low'] ?? null,
                'ref_high' => $r['high'] ?? null, 'ref_text' => $r['text_range'] ?? null, 'source' => 'manual'];
            if ($p['data_type'] === 'numeric') {
                if (!is_numeric($raw)) {
                    throw new \InvalidArgumentException($p['name'] . ': enter a number.');
                }
                $v = (float) $raw;
                $row['value_numeric'] = $v;
                $row['flag'] = self::flag($v, $r, $p);
            } else {
                $row['value_text'] = mb_substr($raw, 0, 500);
                $row['flag'] = isset($r['text_range']) && $r['text_range'] !== null && strcasecmp($raw, (string) $r['text_range']) !== 0 ? 'abnormal' : 'normal';
            }
            $critical = $critical || str_starts_with($row['flag'], 'critical');
            DB::insert('lab_result_values', $row);
        }
        if (!$any) {
            throw new \InvalidArgumentException('Enter at least one result value.');
        }
        return $critical;
    }

    public static function flag(float $v, ?array $range, array $p): string
    {
        if ($p['critical_low'] !== null && $v < (float) $p['critical_low']) {
            return 'critical_low';
        }
        if ($p['critical_high'] !== null && $v > (float) $p['critical_high']) {
            return 'critical_high';
        }
        if ($range && $range['low'] !== null && $v < (float) $range['low']) {
            return 'low';
        }
        if ($range && $range['high'] !== null && $v > (float) $range['high']) {
            return 'high';
        }
        return 'normal';
    }

    /** Most specific matching range: sex-specific before 'A', pregnancy-specific when pregnant. */
    public static function rangeFor(int $parameterId, string $gender, ?string $dob, bool $pregnant = false): ?array
    {
        $sex = $gender === 'Male' ? 'M' : ($gender === 'Female' ? 'F' : 'A');
        $days = $dob ? max(0, (int) ((time() - strtotime($dob)) / 86400)) : 365 * 30;
        return DB::one(
            "SELECT * FROM lab_reference_ranges WHERE parameter_id = ? AND sex IN ('A', ?) AND ? BETWEEN age_min_days AND age_max_days
                AND (pregnant IS NULL OR pregnant = ?)
              ORDER BY (pregnant IS NOT NULL) DESC, (sex <> 'A') DESC, (age_max_days - age_min_days) LIMIT 1",
            [$parameterId, $sex, $days, $pregnant ? 1 : 0]
        );
    }

    // ------------------------------------------- Pathologist gate 2: verify

    public static function verify(int $itemId): void
    {
        $it = self::item($itemId);
        if ($it['status'] !== 'result_entered') {
            throw new \RuntimeException('Only entered results can be verified.');
        }
        $res = DB::one('SELECT * FROM lab_results WHERE lab_order_item_id = ? AND is_current = 1', [$itemId]);
        if (!$res) {
            throw new \RuntimeException('No result to verify.');
        }
        $values = DB::all('SELECT v.*, p.name, p.data_type FROM lab_result_values v JOIN lab_test_parameters p ON p.id = v.parameter_id WHERE v.lab_result_id = ?', [$res['id']]);
        foreach ($values as $v) {
            if ($v['data_type'] === 'numeric' && self::qcBlocked((int) $v['parameter_id'])) {
                throw new \RuntimeException('Verification blocked: the latest QC run for ' . $v['name'] . ' failed. Re-run QC or have the QC officer release the run.');
            }
        }
        DB::transaction(static function () use ($it, $res, $values): void {
            DB::update('lab_results', ['verified_by' => Auth::id(), 'verified_at' => now()], 'id = ?', [$res['id']]);
            DB::update('lab_order_items', ['status' => 'released', 'verified_at' => now(), 'verified_by' => Auth::id(), 'released_at' => now()], 'id = ?', [$it['id']]);
            $doctor = (int) ($it['ordered_by'] ?: 0);
            foreach ($values as $v) {
                if (str_starts_with((string) $v['flag'], 'critical') && $doctor) {
                    DB::insert('lab_critical_notifications', ['lab_result_value_id' => $v['id'], 'lab_order_item_id' => $it['id'], 'patient_id' => $it['patient_id'],
                        'notified_user_id' => $doctor, 'notified_at' => now()]);
                }
            }
            ActivityLog::record('Lab result verified', 'lab', (string) $it['lab_order_id'], $it['test_name'] . ($res['version'] > 1 ? ' (amended v' . $res['version'] . ')' : '')
                . ($res['is_critical'] ? ' — critical value notified' : ''));
            self::syncOrder((int) $it['lab_order_id']);
            self::maybeReport((int) $it['lab_order_id']);
        });
    }

    /** Pathologist sends a result back to the bench (not verified). */
    public static function sendBack(int $itemId, string $note): void
    {
        $it = self::item($itemId);
        if ($it['status'] !== 'result_entered') {
            throw new \RuntimeException('Only unverified results can be sent back.');
        }
        DB::update('lab_order_items', ['status' => 'accepted'], 'id = ?', [$itemId]);
        DB::run("UPDATE lab_results SET comment = CONCAT(COALESCE(comment, ''), ?) WHERE lab_order_item_id = ? AND is_current = 1", [' [Sent back: ' . $note . ']', $itemId]);
        ActivityLog::record('Lab result sent back', 'lab', (string) $it['lab_order_id'], $it['test_name'] . ' — ' . $note);
    }

    /** Amend a released result: a new version that must be verified again; the next report is a new version. */
    public static function amend(int $itemId, array $values, string $reason, string $comment = ''): int
    {
        $it = self::item($itemId);
        if ($it['status'] !== 'released') {
            throw new \RuntimeException('Only released results can be amended.');
        }
        if (trim($reason) === '') {
            throw new \InvalidArgumentException('An amendment needs a reason.');
        }
        $ws = self::worksheet($itemId);
        return DB::transaction(static function () use ($it, $ws, $values, $reason, $comment): int {
            $old = $ws['result'];
            DB::update('lab_results', ['is_current' => 0], 'lab_order_item_id = ?', [$it['id']]);
            $id = DB::insert('lab_results', ['lab_order_item_id' => $it['id'], 'version' => (int) $old['version'] + 1, 'is_current' => 1,
                'comment' => $comment ?: $old['comment'], 'entered_by' => Auth::id(), 'entered_at' => now(),
                'amended_from_result_id' => $old['id'], 'amend_reason' => mb_substr($reason, 0, 255)]);
            $critical = self::saveValues($id, $ws['params'], $values);
            DB::update('lab_results', ['is_critical' => $critical ? 1 : 0], 'id = ?', [$id]);
            DB::update('lab_order_items', ['status' => 'result_entered', 'result_entered_at' => now(), 'verified_at' => null, 'released_at' => null], 'id = ?', [$it['id']]);
            self::syncOrder((int) $it['lab_order_id']);
            ActivityLog::record('Lab result amended', 'lab', (string) $it['lab_order_id'], $it['test_name'] . ' — ' . $reason,
                audit: ['entity' => 'lab_result', 'old' => ['version' => $old['version']], 'new' => ['version' => (int) $old['version'] + 1]]);
            return $id;
        });
    }

    public static function acknowledgeCritical(int $id, string $readBack): void
    {
        $n = DB::one('SELECT * FROM lab_critical_notifications WHERE id = ?', [$id]);
        if (!$n || (int) $n['notified_user_id'] !== Auth::id()) {
            throw new \RuntimeException('Not your notification.');
        }
        DB::update('lab_critical_notifications', ['acknowledged_at' => now(), 'read_back_confirmed' => 1, 'read_back_note' => mb_substr($readBack, 0, 255)], 'id = ?', [$id]);
        ActivityLog::record('Critical lab value acknowledged', 'lab', (string) $n['lab_order_item_id'], $readBack);
    }

    // ------------------------------------------------------------ reports

    /** When every billable item of the order is finished, a report (version) is released. */
    private static function maybeReport(int $orderId): void
    {
        $open = (int) DB::value('SELECT COUNT(*) FROM lab_order_items WHERE lab_order_id = ? AND status IN (' . DB::placeholders(self::OPEN) . ')',
            array_merge([$orderId], self::OPEN));
        $released = (int) DB::value("SELECT COUNT(*) FROM lab_order_items WHERE lab_order_id = ? AND status = 'released'", [$orderId]);
        if ($open > 0 || $released === 0) {
            return;
        }
        $prev = DB::one('SELECT * FROM lab_reports WHERE lab_order_id = ? ORDER BY version DESC LIMIT 1', [$orderId]);
        if ($prev) {
            DB::update('lab_reports', ['status' => 'superseded'], 'id = ?', [$prev['id']]);
        }
        DB::insert('lab_reports', ['branch_id' => Auth::branchId(), 'lab_order_id' => $orderId, 'report_number' => self::number('lab_report', 'LR'),
            'version' => $prev ? (int) $prev['version'] + 1 : 1, 'status' => $prev ? 'amended' : 'released', 'released_by' => Auth::id(),
            'released_at' => now(), 'amended_from_report_id' => $prev['id'] ?? null]);
    }

    public static function handover(int $reportId, string $to): void
    {
        $r = DB::one('SELECT * FROM lab_reports WHERE id = ?', [$reportId]);
        if (!$r) {
            throw new \RuntimeException('Report not found.');
        }
        $inv = DB::one('SELECT i.status FROM lab_orders o JOIN invoices i ON i.id = o.invoice_id WHERE o.id = ?', [$r['lab_order_id']]);
        if ($inv && in_array($inv['status'], ['open', 'partial'], true) && !can('billing.collect')) {
            throw new \RuntimeException('The lab bill is unpaid — send the patient to the billing counter first.');
        }
        DB::run('UPDATE lab_reports SET handed_over_to = ?, handed_over_by = ?, handed_over_at = ?, print_count = print_count + 1 WHERE id = ?',
            [mb_substr($to, 0, 150) ?: 'Patient', Auth::id(), now(), $reportId]);
        ActivityLog::record('Lab report handed over', 'lab', (string) $r['lab_order_id'], $r['report_number'] . ' to ' . ($to ?: 'patient'));
    }

    /** Report data: order, patient, released items with current values. */
    public static function report(int $orderId): array
    {
        $o = DB::one('SELECT o.*, p.name AS patient_name, p.mrn, p.gender, p.dob, p.phone, u.name AS doctor_name, v.visit_no
                        FROM lab_orders o JOIN patients p ON p.id = o.patient_id LEFT JOIN users u ON u.id = o.ordered_by LEFT JOIN visits v ON v.id = o.visit_id
                       WHERE o.id = ?', [$orderId]);
        if (!$o) {
            throw new \RuntimeException('Lab order not found.');
        }
        $items = DB::all('SELECT li.*, t.name AS test_name, t.code, t.method, s.name AS section_name, r.id AS result_id, r.version, r.comment, r.amend_reason,
                                 r.verified_at AS r_verified_at, vu.name AS verified_by_name, eu.name AS entered_by_name,
                                 (SELECT MIN(ls.collected_at) FROM lab_samples ls WHERE ls.lab_order_item_id = li.id AND ls.status = \'accepted\') AS sample_at
                            FROM lab_order_items li JOIN lab_tests t ON t.id = li.lab_test_id LEFT JOIN lab_sections s ON s.id = li.performing_section_id
                            LEFT JOIN lab_results r ON r.lab_order_item_id = li.id AND r.is_current = 1
                            LEFT JOIN users vu ON vu.id = r.verified_by LEFT JOIN users eu ON eu.id = r.entered_by
                           WHERE li.lab_order_id = ? ORDER BY s.display_order, t.display_order', [$orderId]);
        foreach ($items as &$i) {
            $i['values'] = $i['result_id'] ? DB::all('SELECT v.*, p.name, p.decimals, p.data_type FROM lab_result_values v JOIN lab_test_parameters p ON p.id = v.parameter_id
                                                       WHERE v.lab_result_id = ? ORDER BY p.display_order', [$i['result_id']]) : [];
        }
        unset($i);
        $o['items'] = $items;
        $o['report'] = DB::one('SELECT r.*, u.name AS released_by_name FROM lab_reports r LEFT JOIN users u ON u.id = r.released_by WHERE r.lab_order_id = ? ORDER BY r.version DESC LIMIT 1', [$orderId]);
        return $o;
    }

    // ------------------------------------------------------------------ QC

    /**
     * Records a QC run with Westgard evaluation:
     *   1-3s → fail; 2-2s (two consecutive beyond 2 SD, same side) → fail;
     *   R-4s (consecutive runs > 4 SD apart) → fail; 1-2s → warning.
     */
    public static function qcRun(int $materialId, float $value, ?int $analyzerId = null): array
    {
        $m = DB::one('SELECT * FROM lab_qc_materials WHERE id = ? AND is_active = 1', [$materialId]);
        if (!$m || !$m['parameter_id'] || (float) $m['target_sd'] <= 0) {
            throw new \RuntimeException('QC material is not configured (parameter, mean and SD).');
        }
        if ($m['expiry_date'] && $m['expiry_date'] < today()) {
            throw new \RuntimeException('This QC lot has expired.');
        }
        $z = round(($value - (float) $m['target_mean']) / (float) $m['target_sd'], 3);
        $prev = DB::one('SELECT z_score FROM lab_qc_runs WHERE qc_material_id = ? ORDER BY run_at DESC, id DESC LIMIT 1', [$materialId]);
        $pz = $prev ? (float) $prev['z_score'] : null;
        [$status, $rule] = ['pass', null];
        if (abs($z) > 3) {
            [$status, $rule] = ['fail', '1-3s'];
        } elseif ($pz !== null && abs($z) > 2 && abs($pz) > 2 && ($z > 0) === ($pz > 0)) {
            [$status, $rule] = ['fail', '2-2s'];
        } elseif ($pz !== null && abs($z - $pz) > 4 && abs($z) > 2) {
            [$status, $rule] = ['fail', 'R-4s'];
        } elseif (abs($z) > 2) {
            [$status, $rule] = ['warn', '1-2s'];
        }
        $id = DB::insert('lab_qc_runs', ['section_id' => $m['section_id'], 'analyzer_id' => $analyzerId, 'parameter_id' => $m['parameter_id'],
            'qc_material_id' => $materialId, 'run_at' => now(), 'measured_value' => $value, 'target_mean' => $m['target_mean'], 'target_sd' => $m['target_sd'],
            'z_score' => $z, 'status' => $status, 'westgard_rule' => $rule, 'recorded_by' => Auth::id()]);
        ActivityLog::record('QC run recorded', 'lab', (string) $id, $m['name'] . ': ' . $value . ' (z ' . $z . ') ' . strtoupper($status) . ($rule ? ' ' . $rule : ''));
        return ['id' => $id, 'z' => $z, 'status' => $status, 'rule' => $rule];
    }

    /** QC officer releases a failed run after corrective action (documented). */
    public static function qcOverride(int $runId, string $note): void
    {
        $r = DB::one("SELECT * FROM lab_qc_runs WHERE id = ? AND status = 'fail'", [$runId]);
        if (!$r) {
            throw new \RuntimeException('Only failed runs can be released.');
        }
        if ((int) $r['recorded_by'] === Auth::id()) {
            throw new \RuntimeException('The person who recorded a failed QC run cannot release it.');
        }
        if (trim($note) === '') {
            throw new \InvalidArgumentException('Document the corrective action.');
        }
        DB::update('lab_qc_runs', ['override_by' => Auth::id(), 'override_note' => mb_substr($note, 0, 255)], 'id = ?', [$runId]);
        ActivityLog::record('QC failure released', 'lab', (string) $runId, $note);
    }

    /** True while the latest QC run of the parameter failed and was not released. */
    public static function qcBlocked(int $parameterId): bool
    {
        $r = DB::one('SELECT status, override_by FROM lab_qc_runs WHERE parameter_id = ? ORDER BY run_at DESC, id DESC LIMIT 1', [$parameterId]);
        return $r && $r['status'] === 'fail' && !$r['override_by'];
    }

    // ----------------------------------------------------------- outsource

    public static function outsource(int $itemId, int $vendorId, string $tracking = '', ?string $expected = null, ?float $cost = null): int
    {
        $it = self::item($itemId);
        if (!in_array($it['status'], ['collected', 'accepted'], true)) {
            throw new \RuntimeException('Only collected / accepted samples can be sent out.');
        }
        $id = DB::insert('lab_outsource_referrals', ['lab_order_item_id' => $itemId, 'vendor_id' => $vendorId, 'sent_at' => now(),
            'tracking_no' => $tracking ?: null, 'expected_at' => $expected ?: null, 'cost' => $cost, 'status' => 'sent', 'created_by' => Auth::id()]);
        DB::update('lab_order_items', ['outsourced_referral_id' => $id], 'id = ?', [$itemId]);
        ActivityLog::record('Lab test outsourced', 'lab', (string) $it['lab_order_id'], $it['test_name']);
        return $id;
    }

    public static function outsourceReceived(int $referralId, ?string $path = null): void
    {
        DB::update('lab_outsource_referrals', ['status' => 'received', 'received_at' => now(), 'external_report_path' => $path], 'id = ?', [$referralId]);
    }

    // ------------------------------------------------------------- helpers

    public static function item(int $itemId): array
    {
        $it = DB::one('SELECT li.*, t.name AS test_name, t.code AS test_code, t.service_id, t.specimen_type_id, t.container_id, t.result_type,
                              o.patient_id, o.visit_id, o.ordered_by, o.order_number, o.invoice_id, o.pregnancy_id, p.name AS patient_name, p.mrn, p.gender, p.dob,
                              s.name AS section_name
                         FROM lab_order_items li JOIN lab_tests t ON t.id = li.lab_test_id JOIN lab_orders o ON o.id = li.lab_order_id
                         JOIN patients p ON p.id = o.patient_id LEFT JOIN lab_sections s ON s.id = li.performing_section_id WHERE li.id = ?', [$itemId]);
        if (!$it) {
            throw new \RuntimeException('Lab order item not found.');
        }
        return $it;
    }

    /** Order status follows its items: ordered → in_progress → completed (or cancelled). */
    public static function syncOrder(int $orderId): void
    {
        $st = DB::column('SELECT status FROM lab_order_items WHERE lab_order_id = ?', [$orderId]);
        $live = array_diff($st, ['cancelled', 'rejected_refunded']);
        $status = !$live ? 'cancelled'
            : (!array_diff($live, ['released']) ? 'completed'
            : (!array_diff($live, ['ordered', 'sample_pending']) ? 'ordered' : 'in_progress'));
        DB::update('lab_orders', ['status' => $status], 'id = ?', [$orderId]);
    }

    /**
     * Takes the test off the bill: an unpaid line is voided; a paid one is
     * refunded (pre-approved — the clinical decision is the approval).
     * @return array{0: float, 1: ?int} amount, refund id
     */
    private static function settleBillLine(array $it, string $reason): array
    {
        if (!$it['invoice_item_id']) {
            return [0.0, null];
        }
        $line = DB::one('SELECT ii.*, i.paid_amount, i.refunded_amount, i.net_amount, i.status FROM invoice_items ii JOIN invoices i ON i.id = ii.invoice_id WHERE ii.id = ?', [$it['invoice_item_id']]);
        if (!$line || $line['is_void']) {
            return [0.0, null];
        }
        $amount = (float) $line['amount'];
        $paidFree = (float) $line['paid_amount'] - (float) $line['refunded_amount'];
        $othersDue = (float) $line['net_amount'] - $amount;
        if ($amount > 0 && $paidFree - $othersDue >= $amount - 0.004) {
            $rid = BillingService::requestRefund((int) $line['invoice_id'], (int) $line['id'], $amount, $reason, 'lab', 'lab_order_item', (int) $it['id'], true);
            return [$amount, $rid];
        }
        DB::update('invoice_items', ['is_void' => 1], 'id = ?', [$line['id']]);
        BillingService::recalc((int) $line['invoice_id']);
        return [$amount, null];
    }

    private static function barcode(): string
    {
        $prefix = preg_replace('/[^A-Z0-9]/', '', strtoupper((string) setting('lab_barcode_prefix', 'L'))) ?: 'L';
        return CodeGenerator::next('lab_barcode', $prefix . '{YY}{MM}{DD}{0001}',
            static fn (string $c): bool => DB::value('SELECT 1 FROM lab_samples WHERE barcode = ?', [$c]) !== null);
    }

    private static function number(string $scope, string $prefix): string
    {
        $table = $scope === 'lab_order' ? ['lab_orders', 'order_number'] : ['lab_reports', 'report_number'];
        return CodeGenerator::next($scope, $prefix . '-{YYYY}-{000001}',
            static fn (string $c): bool => DB::value("SELECT 1 FROM {$table[0]} WHERE {$table[1]} = ?", [$c]) !== null);
    }
}
