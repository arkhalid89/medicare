<?php
declare(strict_types=1);

namespace App\Modules\Pharmacy;

use App\Core\DB;
use App\Core\Migrator;

/** Consultation → pharmacy: the e-Rx reaches the dispensing queue at checkout (Doc 3 WF 1 acceptance). */
final class PharmacyHooks
{
    public static function afterCheckout(int $visitId, array $payload, array $ctx): void
    {
        if (Migrator::tableExists('dispenses')) {
            PharmacyService::queueVisit($visitId, (int) $ctx['patient_id'], $ctx['token_id'] ?? null);
        }
    }

    public static function panel(array $visit): string
    {
        if (!Migrator::tableExists('dispenses')) {
            return '';
        }
        $d = DB::one('SELECT d.*, u.name AS dispensed_by_name FROM dispenses d LEFT JOIN users u ON u.id = d.dispensed_by WHERE d.visit_id = ? ORDER BY d.id DESC LIMIT 1', [$visit['id']]);
        if (!$d) {
            return '';
        }
        $link = can('pharmacy.dispense') ? ' <a href="' . e(url('pharmacy/dispense', ['id' => $d['id']])) . '">Open</a>' : '';
        return '<div class="card mt-2 module-card"><div class="card-body flex">' . icon('pill') . ' <strong>Hospital pharmacy:</strong> ' . status_chip($d['status'])
            . ($d['dispensed_by_name'] ? ' <span class="muted small">by ' . e($d['dispensed_by_name']) . ', ' . e(fmt_datetime($d['dispensed_at'])) . '</span>' : '') . $link . '</div></div>';
    }
}
