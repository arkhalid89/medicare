<?php
declare(strict_types=1);

namespace App\Modules\Radiology;

use App\Core\DB;
use App\Core\Migrator;

/** Consultation → radiology: imaging investigations become billed orders at checkout; the reception does the safety screen. */
final class RadiologyHooks
{
    public static function afterCheckout(int $visitId, array $payload, array $ctx): void
    {
        if (!Migrator::tableExists('radiology_orders')) {
            return;
        }
        $inv = [];
        foreach ((array) ($ctx['items'] ?? []) as $it) {
            if (($it['item_type'] ?? '') === 'investigation' && !empty($it['ref_id'])) {
                $inv[] = (int) $it['ref_id'];
            }
        }
        if (!$inv) {
            return;
        }
        $procs = DB::column('SELECT id FROM radiology_procedure_catalog WHERE investigation_id IN (' . DB::placeholders($inv) . ') AND is_active = 1 AND deleted_at IS NULL', $inv);
        if (!$procs) {
            return;
        }
        $visit = DB::one('SELECT department_id, findings FROM visits WHERE id = ?', [$visitId]);
        $priority = DB::value('SELECT priority FROM tokens WHERE id = ?', [(int) ($ctx['token_id'] ?? 0)]) === 'emergency' ? 'stat' : 'routine';
        $diag = DB::column("SELECT name FROM visit_clinical_items WHERE visit_id = ? AND item_type IN ('diagnosis','complaint') ORDER BY sort_order LIMIT 3", [$visitId]);
        RadiologyService::order(['patient_id' => $ctx['patient_id'], 'visit_id' => $visitId, 'token_id' => $ctx['token_id'] ?? null, 'doctor_id' => $ctx['doctor_id'],
            'department_id' => $visit['department_id'] ?? null, 'source' => 'consult', 'priority' => $priority, 'when' => $ctx['when'] ?? null,
            'clinical_indication' => $diag ? implode(', ', $diag) : null], $procs);
    }

    public static function panel(array $visit): string
    {
        if (!Migrator::tableExists('radiology_orders')) {
            return '';
        }
        $rows = DB::all('SELECT o.id, o.order_number, o.status, c.name FROM radiology_orders o JOIN radiology_procedure_catalog c ON c.id = o.procedure_id WHERE o.encounter_id = ? ORDER BY o.id', [$visit['id']]);
        if (!$rows) {
            return '';
        }
        $h = '<div class="card mt-2 module-card"><div class="card-header"><div class="card-title">' . icon('image') . ' Imaging</div></div><div class="card-body"><div class="flex flex-wrap">';
        foreach ($rows as $r) {
            $label = e($r['name']) . ' ' . status_chip($r['status']);
            $h .= can('radiology.view') || can('radiology.order.create')
                ? '<a class="chip" href="' . e(url('radiology/order', ['id' => $r['id']])) . '">' . $label . '</a> '
                : '<span class="chip">' . $label . '</span> ';
        }
        return $h . '</div></div></div>';
    }
}
