<?php
declare(strict_types=1);

namespace App\Modules\Inventory;

use App\Core\ActivityLog;
use App\Core\Auth;
use App\Core\CodeGenerator;
use App\Core\DB;
use App\Core\Logger;

/**
 * The ONLY writer of stock (Doc 7 §6 rules 1–6):
 *  1. every change is exactly one append-only stock_ledger row + the cached
 *     stock_balances row, in one transaction (row-locked);
 *  2. FEFO picks batches; expired / quarantined / recalled batches are never
 *     handed out;
 *  3. negative stock is blocked unless the location allows it;
 *  4. controlled drugs are mirrored into the controlled-drug register;
 *  5. cold-chain items only go to cold-storage locations;
 *  6. weighted-average cost is recomputed on each receipt; the ledger keeps
 *     the cost of the moment for valuation by location and date.
 */
final class StockService
{
    public const IN_TYPES = ['grn_receipt', 'issue_in', 'transfer_in', 'return_from_patient', 'adjustment_gain', 'count_correction', 'opening'];
    public const OUT_TYPES = ['issue_out', 'transfer_out', 'dispense', 'sale', 'return_to_vendor', 'adjustment_loss', 'consumption', 'wastage', 'expiry_writeoff'];
    /** Movements that hand stock to a patient or a ward — never from a blocked batch. */
    public const SUPPLY_TYPES = ['issue_out', 'transfer_out', 'dispense', 'sale', 'consumption'];

    public const LOCATION_TYPES = [
        'central_store' => 'Central store', 'sub_store' => 'Sub-store', 'pharmacy_main' => 'Pharmacy (main)', 'pharmacy_opd' => 'Pharmacy (OPD)',
        'pharmacy_ipd' => 'Pharmacy (IPD)', 'ward_stock' => 'Ward stock', 'ot_stock' => 'OT stock', 'lab_stock' => 'Laboratory stock',
        'radiology_stock' => 'Radiology stock', 'dental_stock' => 'Dental stock', 'emergency_crash_cart' => 'Emergency crash cart',
        'quarantine' => 'Quarantine', 'expired_hold' => 'Expired hold',
    ];

    /**
     * One stock movement. Returns the ledger id.
     * @param array{batch_id?:int, unit_cost?:float, reference_type?:string, reference_id?:int, note?:string, occurred_at?:string,
     *              cosigned_by?:int, allow_negative?:bool, patient_id?:int, visit_id?:int} $opt
     */
    public static function move(int $itemId, int $locationId, float $qtySigned, string $type, array $opt = []): int
    {
        if (abs($qtySigned) < 0.0005) {
            throw new \InvalidArgumentException('Quantity must not be zero.');
        }
        if (!in_array($type, self::IN_TYPES, true) && !in_array($type, self::OUT_TYPES, true)) {
            throw new \InvalidArgumentException('Unknown stock movement ' . $type . '.');
        }
        return DB::transaction(static function () use ($itemId, $locationId, $qtySigned, $type, $opt): int {
            $item = DB::one('SELECT * FROM items WHERE id = ?', [$itemId]);
            $loc = DB::one('SELECT * FROM stock_locations WHERE id = ?', [$locationId]);
            if (!$item || !$loc) {
                throw new \RuntimeException('Item or stock location not found.');
            }
            $batchId = (int) ($opt['batch_id'] ?? 0);
            $batch = $batchId ? DB::one('SELECT * FROM stock_batches WHERE id = ?', [$batchId]) : null;
            if ($batchId && (!$batch || (int) $batch['item_id'] !== $itemId)) {
                throw new \RuntimeException('Batch does not belong to this item.');
            }
            if ((int) $item['is_batch_tracked'] === 1 && !$batchId && $type !== 'opening') {
                throw new \RuntimeException($item['name'] . ' is batch-tracked: a batch is required.');
            }
            if ($qtySigned < 0 && $batch && in_array($type, self::SUPPLY_TYPES, true)) {
                if ($batch['status'] !== 'active') {
                    throw new \RuntimeException('Batch ' . $batch['batch_no'] . ' of ' . $item['name'] . ' is ' . $batch['status'] . ' and cannot be issued.');
                }
                if ($batch['expiry_date'] && $batch['expiry_date'] < today()) {
                    throw new \RuntimeException('Batch ' . $batch['batch_no'] . ' of ' . $item['name'] . ' expired on ' . fmt_date($batch['expiry_date']) . '.');
                }
            }
            if ($qtySigned > 0 && (int) $item['is_cold_chain'] === 1 && !(int) $loc['cold_storage'] && !in_array($loc['location_type'], ['quarantine', 'expired_hold'], true)) {
                throw new \RuntimeException($item['name'] . ' is a cold-chain item; ' . $loc['name'] . ' has no cold storage.');
            }

            DB::run('INSERT IGNORE INTO stock_balances (location_id, item_id, batch_id, qty_on_hand, qty_reserved, updated_at) VALUES (?, ?, ?, 0, 0, ?)',
                [$locationId, $itemId, $batchId, now()]);
            $bal = DB::one('SELECT qty_on_hand FROM stock_balances WHERE location_id = ? AND item_id = ? AND batch_id = ? FOR UPDATE', [$locationId, $itemId, $batchId]);
            $new = round((float) $bal['qty_on_hand'] + $qtySigned, 3);
            $negativeOk = !empty($opt['allow_negative']) || (int) $loc['allow_negative'] === 1
                || in_array((string) $locationId, array_map('trim', explode(',', (string) setting('stock_negative_locations', ''))), true);
            if ($new < -0.0005 && !$negativeOk) {
                throw new \RuntimeException('Not enough stock of ' . $item['name'] . ($batch ? ' (batch ' . $batch['batch_no'] . ')' : '') . ' in ' . $loc['name']
                    . ': ' . num($bal['qty_on_hand']) . ' available, ' . num(abs($qtySigned)) . ' needed.');
            }
            $cost = isset($opt['unit_cost']) ? (float) $opt['unit_cost'] : ($batch ? (float) $batch['unit_cost'] : (float) $item['avg_cost']);
            $when = $opt['occurred_at'] ?? now();
            $ledgerId = DB::insert('stock_ledger', [
                'branch_id' => $loc['branch_id'], 'occurred_at' => $when, 'item_id' => $itemId, 'batch_id' => $batchId, 'location_id' => $locationId,
                'movement_type' => $type, 'qty_signed' => round($qtySigned, 3), 'unit_cost' => round($cost, 4), 'balance_after' => $new,
                'reference_type' => $opt['reference_type'] ?? null, 'reference_id' => $opt['reference_id'] ?? null,
                'note' => isset($opt['note']) ? mb_substr((string) $opt['note'], 0, 255) : null,
                'performed_by' => Auth::id(), 'cosigned_by' => $opt['cosigned_by'] ?? null,
            ]);
            DB::run('UPDATE stock_balances SET qty_on_hand = ?, updated_at = ? WHERE location_id = ? AND item_id = ? AND batch_id = ?',
                [$new, now(), $locationId, $itemId, $batchId]);

            if ((int) $item['is_controlled'] === 1) {
                $itemBalance = (float) DB::value('SELECT COALESCE(SUM(qty_on_hand), 0) FROM stock_balances WHERE location_id = ? AND item_id = ?', [$locationId, $itemId]);
                DB::insert('controlled_drug_register', [
                    'branch_id' => $loc['branch_id'], 'ledger_id' => $ledgerId, 'item_id' => $itemId, 'batch_id' => $batchId, 'location_id' => $locationId,
                    'entry_type' => $type, 'patient_id' => $opt['patient_id'] ?? null, 'visit_id' => $opt['visit_id'] ?? null, 'qty' => round($qtySigned, 3),
                    'balance_after' => $itemBalance, 'issued_by' => Auth::id(), 'witness_by' => $opt['cosigned_by'] ?? null, 'occurred_at' => $when,
                ]);
            }
            return $ledgerId;
        });
    }

    /** Usable quantity (active, not expired) of an item at a location. */
    public static function available(int $itemId, int $locationId): float
    {
        return (float) DB::value(
            "SELECT COALESCE(SUM(b.qty_on_hand), 0) FROM stock_balances b LEFT JOIN stock_batches sb ON sb.id = b.batch_id
              WHERE b.item_id = ? AND b.location_id = ? AND (b.batch_id = 0 OR (sb.status = 'active' AND (sb.expiry_date IS NULL OR sb.expiry_date >= ?)))",
            [$itemId, $locationId, today()]
        );
    }

    /**
     * First-expiry-first-out batch plan.
     * @return array<int, array{batch_id:int, qty:float, batch_no:?string, expiry_date:?string}>
     * @throws \RuntimeException when not enough stock and $partial is false
     */
    public static function fefo(int $itemId, int $locationId, float $qty, bool $partial = false): array
    {
        $rows = DB::all(
            "SELECT b.batch_id, b.qty_on_hand, sb.batch_no, sb.expiry_date FROM stock_balances b LEFT JOIN stock_batches sb ON sb.id = b.batch_id
              WHERE b.item_id = ? AND b.location_id = ? AND b.qty_on_hand > 0
                AND (b.batch_id = 0 OR (sb.status = 'active' AND (sb.expiry_date IS NULL OR sb.expiry_date >= ?)))
              ORDER BY sb.expiry_date IS NULL, sb.expiry_date, b.batch_id",
            [$itemId, $locationId, today()]
        );
        $plan = [];
        $left = $qty;
        foreach ($rows as $r) {
            if ($left <= 0.0005) {
                break;
            }
            $take = min($left, (float) $r['qty_on_hand']);
            $plan[] = ['batch_id' => (int) $r['batch_id'], 'qty' => round($take, 3), 'batch_no' => $r['batch_no'], 'expiry_date' => $r['expiry_date']];
            $left -= $take;
        }
        if ($left > 0.0005 && !$partial) {
            $name = (string) DB::value('SELECT name FROM items WHERE id = ?', [$itemId]);
            throw new \RuntimeException('Not enough usable stock of ' . $name . ': ' . num($qty - $left) . ' available, ' . num($qty) . ' needed.');
        }
        return $plan;
    }

    /** Takes $qty out by FEFO. @return array<int, array{batch_id:int, qty:float, ledger_id:int}> */
    public static function takeFefo(int $itemId, int $locationId, float $qty, string $type, array $opt = [], bool $partial = false): array
    {
        return DB::transaction(static function () use ($itemId, $locationId, $qty, $type, $opt, $partial): array {
            $out = [];
            foreach (self::fefo($itemId, $locationId, $qty, $partial) as $p) {
                $out[] = $p + ['ledger_id' => self::move($itemId, $locationId, -$p['qty'], $type, $opt + ['batch_id' => $p['batch_id']])];
            }
            return $out;
        });
    }

    /** Move between two locations (same batch): transfer_out + transfer_in, or issue_out + issue_in. */
    public static function relocate(int $itemId, int $batchId, int $from, int $to, float $qty, string $kind = 'transfer', array $opt = []): void
    {
        DB::transaction(static function () use ($itemId, $batchId, $from, $to, $qty, $kind, $opt): void {
            self::move($itemId, $from, -$qty, $kind . '_out', $opt + ['batch_id' => $batchId]);
            self::move($itemId, $to, $qty, $kind . '_in', $opt + ['batch_id' => $batchId]);
        });
    }

    /**
     * The batch of a receipt (created on first receipt of that batch number).
     * Expiry is mandatory for expiry-tracked items (Doc 8 §7 #6).
     */
    public static function batchFor(int $itemId, ?string $batchNo, ?string $expiry, array $extra = []): int
    {
        $item = DB::one('SELECT * FROM items WHERE id = ?', [$itemId]);
        if (!(int) $item['is_batch_tracked']) {
            return 0;
        }
        $batchNo = trim((string) $batchNo);
        if ($batchNo === '') {
            throw new \RuntimeException('Batch number is required for ' . $item['name'] . '.');
        }
        if ((int) $item['is_expiry_tracked'] === 1 && !$expiry) {
            throw new \RuntimeException('Expiry date is required for ' . $item['name'] . ' (batch ' . $batchNo . ').');
        }
        $branch = Auth::branchId();
        $b = DB::one('SELECT * FROM stock_batches WHERE item_id = ? AND batch_no = ? AND branch_id <=> ?', [$itemId, $batchNo, $branch]);
        if ($b) {
            if ($expiry && $b['expiry_date'] && $b['expiry_date'] !== $expiry) {
                throw new \RuntimeException('Batch ' . $batchNo . ' of ' . $item['name'] . ' is already recorded with expiry ' . fmt_date($b['expiry_date']) . '.');
            }
            return (int) $b['id'];
        }
        return DB::insert('stock_batches', [
            'branch_id' => $branch, 'item_id' => $itemId, 'batch_no' => mb_substr($batchNo, 0, 60), 'expiry_date' => $expiry ?: null,
            'mfg_date' => $extra['mfg_date'] ?? null, 'vendor_id' => $extra['vendor_id'] ?? null, 'unit_cost' => round((float) ($extra['unit_cost'] ?? 0), 4),
            'mrp' => $extra['mrp'] ?? null, 'grn_item_id' => $extra['grn_item_id'] ?? null,
            'status' => $expiry && $expiry < today() ? 'expired' : 'active', 'created_at' => now(),
        ]);
    }

    /** Weighted-average cost after a receipt of $qty at $unitCost. */
    public static function updateAverageCost(int $itemId, float $qty, float $unitCost): void
    {
        $onHand = (float) DB::value('SELECT COALESCE(SUM(qty_on_hand), 0) FROM stock_balances WHERE item_id = ?', [$itemId]) - $qty;
        $avg = (float) DB::value('SELECT avg_cost FROM items WHERE id = ?', [$itemId]);
        $newAvg = ($onHand + $qty) > 0 ? (max(0, $onHand) * $avg + $qty * $unitCost) / (max(0, $onHand) + $qty) : $unitCost;
        DB::update('items', ['avg_cost' => round($newAvg, 4)], 'id = ?', [$itemId]);
    }

    /** First active location of a type (in the current branch). */
    public static function locationOfType(string $type): ?int
    {
        $id = DB::value('SELECT id FROM stock_locations WHERE location_type = ? AND is_active = 1 AND deleted_at IS NULL AND (branch_id = ? OR branch_id IS NULL) ORDER BY display_order, id LIMIT 1',
            [$type, Auth::branchId()]);
        return $id ? (int) $id : null;
    }

    public static function nextNumber(string $scope, string $prefix): string
    {
        return CodeGenerator::next($scope, $prefix . '-{YYYY}-{00001}', static fn (string $c): bool => false);
    }

    /**
     * Auto-consumption of a performed service (CBC → EDTA tube + reagent,
     * composite filling → composite + bonding agent …). Clinical work is never
     * blocked by a stock shortage: what is missing is logged for the store.
     */
    public static function consumeForService(int $serviceId, float $times, string $refType, int $refId, array $opt = []): array
    {
        $done = [];
        foreach (DB::all('SELECT * FROM service_consumption_templates WHERE service_id = ?', [$serviceId]) as $t) {
            $loc = self::locationOfType((string) $t['from_location_type']);
            if (!$loc) {
                continue;
            }
            $qty = round((float) $t['qty'] * $times, 3);
            try {
                $plan = self::takeFefo((int) $t['item_id'], $loc, $qty, 'consumption', $opt + ['reference_type' => $refType, 'reference_id' => $refId], true);
                $taken = array_sum(array_column($plan, 'qty'));
                if ($taken + 0.0005 < $qty) {
                    Logger::info('Consumption shortage: item #' . $t['item_id'] . ' needed ' . $qty . ', took ' . $taken . ' (' . $refType . ' #' . $refId . ')');
                }
                $done[] = ['item_id' => (int) $t['item_id'], 'qty' => $taken];
            } catch (\Throwable $e) {
                Logger::error('Consumption failed for service #' . $serviceId . ': ' . $e->getMessage());
            }
        }
        return $done;
    }

    // ---------------------------------------------------------- daily jobs

    /**
     * Nightly: rebuild the expiry watchlist (90 / 60 / 30 days / expired),
     * mark expired batches and move their stock to the expired-hold location.
     */
    public static function dailyExpiryWatch(): void
    {
        $alertDays = max(30, (int) setting('stock_expiry_alert_days', 90));
        DB::run("UPDATE stock_batches SET status = 'expired' WHERE status = 'active' AND expiry_date IS NOT NULL AND expiry_date < ?", [today()]);
        DB::run('DELETE FROM expiry_watchlist');
        $rows = DB::all(
            "SELECT b.location_id, b.item_id, b.batch_id, b.qty_on_hand, sb.expiry_date, sl.branch_id, sl.location_type
               FROM stock_balances b JOIN stock_batches sb ON sb.id = b.batch_id JOIN stock_locations sl ON sl.id = b.location_id
              WHERE b.qty_on_hand > 0 AND sb.expiry_date IS NOT NULL AND sb.expiry_date <= ?",
            [date('Y-m-d', strtotime('+' . $alertDays . ' days'))]
        );
        $hold = self::locationOfType('expired_hold');
        foreach ($rows as $r) {
            $days = (int) floor((strtotime($r['expiry_date']) - strtotime(today())) / 86400);
            $level = $days < 0 ? 'expired' : ($days <= 30 ? '30' : ($days <= 60 ? '60' : '90'));
            $action = null;
            if ($days < 0 && $hold && (int) $r['location_id'] !== $hold && $r['location_type'] !== 'quarantine') {
                try {
                    self::relocate((int) $r['item_id'], (int) $r['batch_id'], (int) $r['location_id'], $hold, (float) $r['qty_on_hand'], 'transfer',
                        ['note' => 'Expired — moved to expired hold', 'reference_type' => 'expiry_job']);
                    $action = 'Moved to expired hold';
                    $r['location_id'] = $hold;
                } catch (\Throwable $e) {
                    $action = 'Move failed: ' . $e->getMessage();
                }
            }
            DB::run('REPLACE INTO expiry_watchlist (branch_id, batch_id, location_id, expiry_date, days_to_expiry, alert_level, qty, action_taken, updated_at) VALUES (?,?,?,?,?,?,?,?,?)',
                [$r['branch_id'], $r['batch_id'], $r['location_id'], $r['expiry_date'], $days, $level, $r['qty_on_hand'], $action, now()]);
        }
    }

    /** Nightly: balances must equal SUM(ledger); differences are corrected and logged. */
    public static function reconcile(): int
    {
        $fixed = 0;
        $rows = DB::all(
            'SELECT l.location_id, l.item_id, l.batch_id, SUM(l.qty_signed) AS ledger_qty, COALESCE(b.qty_on_hand, 0) AS balance_qty
               FROM stock_ledger l LEFT JOIN stock_balances b ON b.location_id = l.location_id AND b.item_id = l.item_id AND b.batch_id = l.batch_id
              GROUP BY l.location_id, l.item_id, l.batch_id, b.qty_on_hand
             HAVING ABS(SUM(l.qty_signed) - COALESCE(b.qty_on_hand, 0)) > 0.0005'
        );
        foreach ($rows as $r) {
            DB::run('INSERT INTO stock_balances (location_id, item_id, batch_id, qty_on_hand, qty_reserved, updated_at) VALUES (?, ?, ?, ?, 0, ?)
                     ON DUPLICATE KEY UPDATE qty_on_hand = VALUES(qty_on_hand), updated_at = VALUES(updated_at)',
                [$r['location_id'], $r['item_id'], $r['batch_id'], $r['ledger_qty'], now()]);
            $fixed++;
            Logger::error('Stock reconcile: location ' . $r['location_id'] . ' item ' . $r['item_id'] . ' batch ' . $r['batch_id']
                . ' balance ' . $r['balance_qty'] . ' → ledger ' . $r['ledger_qty']);
        }
        if ($fixed) {
            ActivityLog::record('Stock reconciled', 'inventory', null, $fixed . ' balance(s) corrected to the ledger');
        }
        return $fixed;
    }
}
