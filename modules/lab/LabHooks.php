<?php
declare(strict_types=1);

namespace App\Modules\Lab;

use App\Core\DB;
use App\Core\Migrator;

/** Consultation → laboratory: investigations ordered by the doctor become a billed lab order at checkout (Doc 3 WF 1 → WF 5). */
final class LabHooks
{
    public static function afterCheckout(int $visitId, array $payload, array $ctx): void
    {
        if (!Migrator::tableExists('lab_orders')) {
            return;
        }
        $invIds = [];
        foreach ((array) ($ctx['items'] ?? []) as $it) {
            if (($it['item_type'] ?? '') === 'investigation' && !empty($it['ref_id'])) {
                $invIds[] = (int) $it['ref_id'];
            }
        }
        if (!$invIds) {
            return;
        }
        $tests = DB::column('SELECT id FROM lab_tests WHERE investigation_id IN (' . DB::placeholders($invIds) . ') AND is_active = 1 AND deleted_at IS NULL', $invIds);
        if (!$tests) {
            return;
        }
        $visit = DB::one('SELECT department_id FROM visits WHERE id = ?', [$visitId]);
        $priority = DB::value('SELECT priority FROM tokens WHERE id = ?', [(int) ($ctx['token_id'] ?? 0)]) === 'emergency' ? 'stat' : 'routine';
        LabService::order(['patient_id' => $ctx['patient_id'], 'visit_id' => $visitId, 'token_id' => $ctx['token_id'] ?? null, 'doctor_id' => $ctx['doctor_id'],
            'department_id' => $visit['department_id'] ?? null, 'source' => 'consult', 'priority' => $priority, 'when' => $ctx['when'] ?? null], $tests);
    }

    public static function panel(array $visit): string
    {
        if (!Migrator::tableExists('lab_orders')) {
            return '';
        }
        $rows = DB::all('SELECT li.status, t.name, o.id AS order_id, o.order_number FROM lab_order_items li JOIN lab_orders o ON o.id = li.lab_order_id
                           JOIN lab_tests t ON t.id = li.lab_test_id WHERE o.visit_id = ? ORDER BY li.id', [$visit['id']]);
        if (!$rows) {
            return '';
        }
        $h = '<div class="card mt-2 module-card"><div class="card-header"><div class="card-title">' . icon('flask') . ' Laboratory — ' . e($rows[0]['order_number']) . '</div>';
        if (can('lab.view')) {
            $h .= '<a class="btn btn-sm btn-outline" href="' . e(url('lab/order', ['id' => $rows[0]['order_id']])) . '">Open</a>';
        }
        $h .= '</div><div class="card-body"><div class="flex flex-wrap">';
        foreach ($rows as $r) {
            $h .= '<span class="chip">' . e($r['name']) . ' ' . status_chip($r['status']) . '</span> ';
        }
        return $h . '</div></div></div>';
    }
}
