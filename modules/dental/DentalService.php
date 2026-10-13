<?php
declare(strict_types=1);

namespace App\Modules\Dental;

use App\Core\ActivityLog;
use App\Core\Auth;
use App\Core\CodeGenerator;
use App\Core\DB;
use App\Modules\Billing\BillingService;
use App\Modules\Inventory\StockService;

/**
 * Dentistry (Doc 6 §5, Doc 7 §3.1, Doc 8 §5.2): tooth map → findings (+
 * surfaces) → treatment plan items → "done today" posts the procedure's
 * service to the bill and deducts its consumables from dental stock.
 */
final class DentalService
{
    public const CONDITIONS = [
        'healthy' => 'Healthy', 'caries' => 'Caries', 'filled' => 'Filled (restored)', 'crown' => 'Crown', 'bridge_abutment' => 'Bridge abutment', 'implant' => 'Implant',
        'root_canal_needed' => 'Root canal needed', 'root_canal_done' => 'Root canal done', 'fracture' => 'Fracture', 'missing' => 'Missing', 'impacted' => 'Impacted',
        'abscess' => 'Abscess', 'erupting' => 'Erupting', 'retained_deciduous' => 'Retained deciduous', 'worn' => 'Worn / attrition', 'other' => 'Other',
    ];
    public const LAB_STATUSES = ['created' => ['sent', 'cancelled'], 'sent' => ['in_progress', 'received', 'cancelled'], 'in_progress' => ['received', 'cancelled'],
        'received' => ['fitted', 'sent'], 'fitted' => [], 'cancelled' => []];
    public const PERIO_SITES = ['DB', 'B', 'MB', 'DL', 'L', 'ML'];

    /** @return array<string, array> tooth_number => row with surfaces[] */
    public static function teeth(): array
    {
        $out = [];
        foreach (DB::all('SELECT * FROM tooth_reference ORDER BY quadrant, tooth_number') as $t) {
            $t['surfaces'] = json_decode($t['valid_surfaces_json'], true) ?: [];
            unset($t['valid_surfaces_json']);
            $out[$t['tooth_number']] = $t;
        }
        return $out;
    }

    public static function procedures(): array
    {
        $rows = DB::all('SELECT * FROM dental_procedure_catalog WHERE is_active = 1 ORDER BY display_order, name');
        foreach ($rows as &$p) {
            $p['price'] = $p['service_id'] ? BillingService::price((int) $p['service_id']) : 0.0;
        }
        return array_column($rows, null, 'id');
    }

    /** Current state of the mouth: the latest finding per tooth across all charts. */
    public static function toothState(int $patientId): array
    {
        $rows = DB::all('SELECT f.*, c.chart_date, c.encounter_id, p.name AS procedure_name,
                                (SELECT GROUP_CONCAT(s.surface) FROM dental_finding_surfaces s WHERE s.dental_tooth_finding_id = f.id) AS surfaces
                           FROM dental_tooth_findings f JOIN dental_charts c ON c.id = f.dental_chart_id LEFT JOIN dental_procedure_catalog p ON p.id = f.procedure_id
                          WHERE c.patient_id = ? ORDER BY c.chart_date, f.id', [$patientId]);
        $state = [];
        foreach ($rows as $r) {
            $r['surfaces'] = $r['surfaces'] ? explode(',', $r['surfaces']) : [];
            $state[$r['tooth_number']] = $r;
        }
        // A procedure done later (plan item) updates the tooth's condition.
        foreach (DB::all("SELECT i.tooth_number, p.result_condition, i.performed_at FROM dental_treatment_plan_items i JOIN dental_treatment_plans pl ON pl.id = i.plan_id
                            JOIN dental_procedure_catalog p ON p.id = i.procedure_id
                           WHERE pl.patient_id = ? AND i.status = 'done' AND i.tooth_number IS NOT NULL AND p.result_condition IS NOT NULL ORDER BY i.performed_at", [$patientId]) as $d) {
            if (!isset($state[$d['tooth_number']]) || $state[$d['tooth_number']]['chart_date'] <= substr((string) $d['performed_at'], 0, 10)) {
                $state[$d['tooth_number']] = ['tooth_number' => $d['tooth_number'], 'condition' => $d['result_condition'], 'procedure_status' => 'completed', 'surfaces' => [],
                    'chart_date' => substr((string) $d['performed_at'], 0, 10)];
            }
        }
        return $state;
    }

    /** Findings of one chart (encounter). */
    public static function chartFindings(int $chartId): array
    {
        $rows = DB::all('SELECT f.*, p.name AS procedure_name, u.name AS recorded_by_name, cs.name AS cosigned_by_name,
                                (SELECT GROUP_CONCAT(s.surface) FROM dental_finding_surfaces s WHERE s.dental_tooth_finding_id = f.id) AS surfaces
                           FROM dental_tooth_findings f LEFT JOIN dental_procedure_catalog p ON p.id = f.procedure_id LEFT JOIN users u ON u.id = f.recorded_by
                           LEFT JOIN users cs ON cs.id = f.cosigned_by WHERE f.dental_chart_id = ? ORDER BY f.tooth_number, f.id', [$chartId]);
        foreach ($rows as &$r) {
            $r['surfaces'] = $r['surfaces'] ? explode(',', $r['surfaces']) : [];
        }
        return $rows;
    }

    /**
     * Validates the chart payload of a consultation.
     * @param array $d ['dentition', 'notes', 'findings' => [['teeth' => [..], 'condition', 'surfaces' => [], 'severity', 'mobility', 'procedure_id', 'status', 'notes']]]
     * @return string[]
     */
    public static function validate(array $d): array
    {
        $errors = [];
        $findings = (array) ($d['findings'] ?? []);
        if (!$findings) {
            return [];
        }
        if (!can('dental.chart.edit')) {
            return ['You may view the tooth chart but not record findings.'];
        }
        $teeth = self::teeth();
        $procs = self::procedures();
        foreach ($findings as $i => $f) {
            $n = $i + 1;
            $list = array_values(array_unique(array_map('strval', (array) ($f['teeth'] ?? []))));
            if (!isset(self::CONDITIONS[$f['condition'] ?? ''])) {
                $errors[] = "Dental finding $n: choose a condition.";
            }
            $proc = !empty($f['procedure_id']) ? ($procs[(int) $f['procedure_id']] ?? null) : null;
            if (!empty($f['procedure_id']) && !$proc) {
                $errors[] = "Dental finding $n: unknown procedure.";
            }
            if (!$list && (!$proc || $proc['requires_tooth'])) {
                $errors[] = "Dental finding $n: select at least one tooth.";
            }
            foreach ($list as $t) {
                if (!isset($teeth[$t])) {
                    $errors[] = "Dental finding $n: $t is not a valid FDI tooth number.";
                }
            }
            if ($proc && ($f['status'] ?? 'planned') === 'completed' && !self::mayComplete($proc)) {
                $errors[] = 'You are not allowed to complete ' . $proc['name'] . '.';
            }
            if (isset($f['mobility']) && $f['mobility'] !== '' && $f['mobility'] !== null && ((int) $f['mobility'] < 0 || (int) $f['mobility'] > 3)) {
                $errors[] = "Dental finding $n: mobility grade is 0–3.";
            }
        }
        return $errors;
    }

    public static function mayComplete(array $proc): bool
    {
        return can('dental.procedure.complete') || ($proc['hygienist_allowed'] && can('dental.procedure.hygiene'));
    }

    /** Saves the chart of an encounter (one chart per encounter; previous chart linked for progression). @return int chart id */
    public static function saveChart(int $visitId, int $patientId, array $d, array $ctx = []): int
    {
        $teeth = self::teeth();
        $procs = self::procedures();
        $chartId = (int) DB::value('SELECT id FROM dental_charts WHERE encounter_id = ?', [$visitId]);
        if (!$chartId) {
            $prev = DB::value('SELECT id FROM dental_charts WHERE patient_id = ? ORDER BY chart_date DESC, id DESC LIMIT 1', [$patientId]);
            $chartId = DB::insert('dental_charts', ['branch_id' => Auth::branchId(), 'encounter_id' => $visitId, 'patient_id' => $patientId, 'chart_date' => substr((string) ($ctx['when'] ?? today()), 0, 10),
                'dentition_type' => in_array($d['dentition'] ?? '', ['permanent', 'deciduous', 'mixed'], true) ? $d['dentition'] : 'permanent',
                'chart_type' => $prev ? 'follow_up' : 'initial', 'previous_chart_id' => $prev ?: null,
                'general_notes' => mb_substr(trim((string) ($d['notes'] ?? '')), 0, 1000) ?: null, 'created_by' => Auth::id(), 'created_at' => now()]);
        }
        $done = 0;
        $planned = 0;
        foreach ((array) ($d['findings'] ?? []) as $f) {
            $proc = !empty($f['procedure_id']) ? ($procs[(int) $f['procedure_id']] ?? null) : null;
            $status = $proc ? (($f['status'] ?? 'planned') === 'completed' ? 'completed' : 'planned') : 'n_a';
            $list = array_values(array_unique(array_map('strval', (array) ($f['teeth'] ?? []))));
            if (!$list && $proc && !$proc['requires_tooth']) {
                $list = [null];
            }
            foreach ($list as $tooth) {
                $findingId = null;
                if ($tooth !== null) {
                    $findingId = DB::insert('dental_tooth_findings', ['dental_chart_id' => $chartId, 'tooth_number' => $tooth, 'condition' => $f['condition'],
                        'finding_status' => 'new', 'severity' => in_array($f['severity'] ?? '', ['mild', 'moderate', 'severe'], true) ? $f['severity'] : null,
                        'mobility_grade' => isset($f['mobility']) && $f['mobility'] !== '' ? (int) $f['mobility'] : null,
                        'procedure_id' => $proc['id'] ?? null, 'procedure_status' => $status, 'notes' => mb_substr(trim((string) ($f['notes'] ?? '')), 0, 500) ?: null,
                        'recorded_by' => Auth::id()]);
                    // Multi-surface caries (MOD …): one row per surface valid for THIS tooth.
                    foreach (array_intersect(array_map('strval', (array) ($f['surfaces'] ?? [])), $teeth[$tooth]['surfaces']) as $s) {
                        DB::insert('dental_finding_surfaces', ['dental_tooth_finding_id' => $findingId, 'surface' => $s]);
                    }
                }
                if ($proc) {
                    $itemId = self::addPlanItem($patientId, $visitId, (int) $proc['id'], $tooth, $findingId, array_values(array_intersect((array) ($f['surfaces'] ?? []), $tooth ? $teeth[$tooth]['surfaces'] : [])), (int) ($ctx['doctor_id'] ?? Auth::id()));
                    if ($findingId) {
                        DB::update('dental_tooth_findings', ['plan_item_id' => $itemId], 'id = ?', [$findingId]);
                    }
                    if ($status === 'completed') {
                        self::completeItem($itemId, $visitId, $ctx);
                        $done++;
                    } else {
                        $planned++;
                    }
                }
            }
        }
        ActivityLog::record('Dental chart saved', 'dental', (string) $visitId, count((array) ($d['findings'] ?? [])) . ' finding group(s), ' . $done . ' done today, ' . $planned . ' planned');
        return $chartId;
    }

    /** Adds a procedure to the patient's open plan (creating a proposed plan when none is open). */
    public static function addPlanItem(int $patientId, ?int $visitId, int $procedureId, ?string $tooth, ?int $findingId = null, array $surfaces = [], ?int $dentistId = null): int
    {
        $proc = DB::one('SELECT * FROM dental_procedure_catalog WHERE id = ?', [$procedureId]);
        $planId = (int) DB::value("SELECT id FROM dental_treatment_plans WHERE patient_id = ? AND status IN ('proposed','accepted','in_progress') ORDER BY id DESC LIMIT 1", [$patientId]);
        if (!$planId) {
            $planId = DB::insert('dental_treatment_plans', ['branch_id' => Auth::branchId(), 'patient_id' => $patientId, 'origin_encounter_id' => $visitId,
                'dentist_id' => $dentistId ?: Auth::id(), 'status' => 'proposed', 'created_at' => now()]);
        }
        $seq = (int) DB::value('SELECT COALESCE(MAX(sequence_no), 0) + 1 FROM dental_treatment_plan_items WHERE plan_id = ?', [$planId]);
        $id = DB::insert('dental_treatment_plan_items', ['plan_id' => $planId, 'procedure_id' => $procedureId, 'tooth_number' => $tooth,
            'surfaces' => $surfaces ? implode(',', $surfaces) : null, 'quadrant' => $tooth ? (int) $tooth[0] : null, 'source_finding_id' => $findingId,
            'sequence_no' => $seq, 'planned_visit_no' => $seq, 'status' => 'planned',
            'estimated_price' => $proc['service_id'] ? BillingService::price((int) $proc['service_id']) : 0]);
        self::recalcPlan($planId);
        return $id;
    }

    /**
     * Marks a plan item done: posts the service to the bill (visit invoice
     * when there is a visit, otherwise a new invoice) and deducts consumables.
     */
    public static function completeItem(int $itemId, ?int $visitId = null, array $ctx = []): void
    {
        $it = DB::one('SELECT i.*, p.service_id, p.name AS procedure_name, p.hygienist_allowed, p.result_condition, p.needs_lab_case, pl.patient_id, pl.id AS plan_id
                         FROM dental_treatment_plan_items i JOIN dental_procedure_catalog p ON p.id = i.procedure_id JOIN dental_treatment_plans pl ON pl.id = i.plan_id WHERE i.id = ?', [$itemId]);
        if (!$it || !in_array($it['status'], ['planned', 'scheduled'], true)) {
            throw new \RuntimeException('This treatment step is already completed or skipped.');
        }
        if (!self::mayComplete($it)) {
            throw new \RuntimeException('You are not allowed to complete ' . $it['procedure_name'] . '.');
        }
        DB::transaction(static function () use ($it, $visitId, $ctx): void {
            $invoiceItem = null;
            $price = (float) $it['estimated_price'];
            if (setting('dental_billing_mode', 'per_procedure') === 'per_procedure' && $it['service_id'] && $price > 0) {
                $c = BillingService::charge(['visit_id' => $visitId, 'token_id' => $ctx['token_id'] ?? null, 'patient_id' => (int) $it['patient_id'],
                    'doctor_id' => $ctx['doctor_id'] ?? Auth::id(), 'department_id' => $ctx['department_id'] ?? null, 'new_if_settled' => !$visitId],
                    ['description' => 'Dental: ' . $it['procedure_name'] . ($it['tooth_number'] ? ' — tooth ' . $it['tooth_number'] : ''), 'service_id' => $it['service_id'],
                     'quantity' => 1, 'unit_price' => $price, 'source_module' => 'dental', 'source_reference_type' => 'dental_plan_item', 'source_reference_id' => $it['id']]);
                $invoiceItem = $c['item_id'];
            }
            if ($it['service_id']) {
                StockService::consumeForService((int) $it['service_id'], 1, 'dental_plan_item', (int) $it['id'], ['patient_id' => (int) $it['patient_id'], 'visit_id' => $visitId]);
            }
            DB::update('dental_treatment_plan_items', ['status' => 'done', 'performed_encounter_id' => $visitId, 'performed_by' => Auth::id(), 'performed_at' => now(),
                'invoice_item_id' => $invoiceItem], 'id = ?', [$it['id']]);
            DB::run("UPDATE dental_tooth_findings SET procedure_status = 'completed' WHERE plan_item_id = ?", [$it['id']]);
            self::recalcPlan((int) $it['plan_id']);
            ActivityLog::record('Dental procedure done', 'dental', (string) $it['plan_id'], $it['procedure_name'] . ($it['tooth_number'] ? ' (' . $it['tooth_number'] . ')' : ''));
        });
    }

    public static function skipItem(int $itemId, string $reason): void
    {
        DB::run("UPDATE dental_treatment_plan_items SET status = 'skipped' WHERE id = ? AND status IN ('planned','scheduled')", [$itemId]);
        $plan = (int) DB::value('SELECT plan_id FROM dental_treatment_plan_items WHERE id = ?', [$itemId]);
        self::recalcPlan($plan);
        ActivityLog::record('Dental plan step skipped', 'dental', (string) $plan, $reason);
    }

    public static function acceptPlan(int $planId, string $acceptedBy): void
    {
        if (trim($acceptedBy) === '') {
            throw new \InvalidArgumentException('Enter the name of the patient / guardian accepting the plan.');
        }
        $n = DB::update('dental_treatment_plans', ['status' => 'accepted', 'accepted_at' => now(), 'accepted_by_name' => mb_substr(trim($acceptedBy), 0, 150), 'updated_at' => now()],
            "id = ? AND status = 'proposed'", [$planId]);
        if (!$n) {
            throw new \RuntimeException('Only a proposed plan can be accepted.');
        }
        self::recalcPlan($planId);
        ActivityLog::record('Dental plan accepted', 'dental', (string) $planId, 'Accepted by ' . $acceptedBy);
    }

    public static function cancelPlan(int $planId, string $reason): void
    {
        DB::update('dental_treatment_plans', ['status' => 'cancelled', 'notes' => mb_substr($reason, 0, 500), 'updated_at' => now()], "id = ? AND status <> 'completed'", [$planId]);
        DB::run("UPDATE dental_treatment_plan_items SET status = 'skipped' WHERE plan_id = ? AND status IN ('planned','scheduled')", [$planId]);
        ActivityLog::record('Dental plan cancelled', 'dental', (string) $planId, $reason);
    }

    /** Estimated total and status follow the items. */
    public static function recalcPlan(int $planId): void
    {
        $p = DB::one('SELECT * FROM dental_treatment_plans WHERE id = ?', [$planId]);
        if (!$p) {
            return;
        }
        $agg = DB::one("SELECT COALESCE(SUM(CASE WHEN status <> 'skipped' THEN estimated_price END), 0) AS total, SUM(status = 'done') AS done,
                               SUM(status IN ('planned','scheduled')) AS open FROM dental_treatment_plan_items WHERE plan_id = ?", [$planId]);
        $status = $p['status'];
        if ($status !== 'cancelled') {
            if ((int) $agg['open'] === 0 && (int) $agg['done'] > 0) {
                $status = 'completed';
            } elseif ((int) $agg['done'] > 0) {
                $status = 'in_progress';
            }
        }
        DB::update('dental_treatment_plans', ['estimated_total' => (float) $agg['total'], 'status' => $status, 'updated_at' => now()], 'id = ?', [$planId]);
    }

    // ---------------------------------------------------------------- perio

    /** @param array $m tooth => site => [pd, rec, bop, plaque] */
    public static function savePerio(int $patientId, ?int $visitId, array $m, string $notes = ''): int
    {
        $teeth = self::teeth();
        return DB::transaction(static function () use ($patientId, $visitId, $m, $notes, $teeth): int {
            $id = DB::insert('dental_perio_charts', ['encounter_id' => $visitId, 'patient_id' => $patientId, 'charted_by' => Auth::id(), 'charted_at' => now(), 'notes' => $notes ?: null]);
            $n = 0;
            foreach ($m as $tooth => $sites) {
                if (!isset($teeth[(string) $tooth])) {
                    continue;
                }
                foreach ((array) $sites as $site => $v) {
                    if (!in_array($site, self::PERIO_SITES, true) || (($v['pd'] ?? '') === '' && ($v['rec'] ?? '') === '' && empty($v['bop']) && empty($v['plaque']))) {
                        continue;
                    }
                    DB::insert('dental_perio_measurements', ['perio_chart_id' => $id, 'tooth_number' => (string) $tooth, 'site' => $site,
                        'pocket_depth_mm' => is_numeric($v['pd'] ?? null) ? min(20, max(0, (int) $v['pd'])) : null,
                        'recession_mm' => is_numeric($v['rec'] ?? null) ? min(20, max(0, (int) $v['rec'])) : null,
                        'bleeding_on_probing' => empty($v['bop']) ? 0 : 1, 'plaque' => empty($v['plaque']) ? 0 : 1]);
                    $n++;
                }
            }
            if (!$n) {
                throw new \InvalidArgumentException('Enter at least one measurement.');
            }
            ActivityLog::record('Perio chart recorded', 'dental', (string) $patientId, $n . ' site(s)');
            return $id;
        });
    }

    // ------------------------------------------------------------ lab cases

    public static function createLabCase(array $d): int
    {
        $pid = (int) ($d['patient_id'] ?? 0);
        if (!$pid) {
            throw new \InvalidArgumentException('Patient is required.');
        }
        $id = DB::insert('dental_lab_cases', ['branch_id' => Auth::branchId(), 'case_number' => CodeGenerator::next('dental_lab', 'DLC-{YYYY}-{00001}',
                static fn (string $c): bool => DB::value('SELECT 1 FROM dental_lab_cases WHERE case_number = ?', [$c]) !== null),
            'patient_id' => $pid, 'treatment_plan_item_id' => (int) ($d['treatment_plan_item_id'] ?? 0) ?: null,
            'tooth_numbers' => mb_substr(trim((string) ($d['tooth_numbers'] ?? '')), 0, 60) ?: null,
            'case_type' => in_array($d['case_type'] ?? '', ['crown', 'bridge', 'denture', 'aligner', 'implant', 'other'], true) ? $d['case_type'] : 'other',
            'shade' => mb_substr(trim((string) ($d['shade'] ?? '')), 0, 20) ?: null, 'material' => mb_substr(trim((string) ($d['material'] ?? '')), 0, 60) ?: null,
            'instructions' => mb_substr(trim((string) ($d['instructions'] ?? '')), 0, 500) ?: null, 'technician_user_id' => (int) ($d['technician_user_id'] ?? 0) ?: null,
            'external_vendor_id' => (int) ($d['external_vendor_id'] ?? 0) ?: null, 'due_date' => ($d['due_date'] ?? '') ?: null,
            'created_by' => Auth::id(), 'created_at' => now(), 'status' => 'created']);
        ActivityLog::record('Dental lab case created', 'dental', (string) $id, (string) ($d['case_type'] ?? ''));
        return $id;
    }

    /** Status transitions; a technician may update only cases assigned to them (or unassigned). */
    public static function updateLabCase(int $id, string $to, string $note = ''): void
    {
        $c = DB::one('SELECT * FROM dental_lab_cases WHERE id = ?', [$id]);
        if (!$c) {
            throw new \RuntimeException('Lab case not found.');
        }
        if (!in_array($to, self::LAB_STATUSES[$c['status']] ?? [], true)) {
            throw new \RuntimeException('A case that is ' . str_replace('_', ' ', $c['status']) . ' cannot move to ' . str_replace('_', ' ', $to) . '.');
        }
        $own = Auth::scope('dental.labcase.update') === 'own';
        if ($own && $c['technician_user_id'] && (int) $c['technician_user_id'] !== Auth::id()) {
            throw new \RuntimeException('This case is assigned to another technician.');
        }
        if ($to === 'fitted' && !can('dental.labcase.manage')) {
            throw new \RuntimeException('Only the dentist records the fitting.');
        }
        $data = ['status' => $to, 'status_note' => $note ?: null];
        $data += match ($to) { 'sent' => ['sent_at' => now()], 'received' => ['received_at' => now()], 'fitted' => ['fitted_at' => now()], default => [] };
        if ($own && !$c['technician_user_id']) {
            $data['technician_user_id'] = Auth::id();
        }
        DB::update('dental_lab_cases', $data, 'id = ?', [$id]);
        ActivityLog::record('Dental lab case ' . $to, 'dental', (string) $id, $c['case_number'] . ($note ? ' — ' . $note : ''));
    }
}
