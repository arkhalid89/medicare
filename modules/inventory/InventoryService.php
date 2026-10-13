<?php
declare(strict_types=1);

namespace App\Modules\Inventory;

use App\Core\ActivityLog;
use App\Core\Auth;
use App\Core\DB;

/**
 * Store documents (Doc 7 §6.2, Doc 8 §3.7 / §5.7). Segregation of duties is
 * enforced here, not in the UI (Doc 8 §7 #5):
 *   - the person who created a PO cannot post its GRN, nor approve the PO;
 *   - the person who proposed a stock adjustment cannot approve it;
 *   - the person who counted cannot post the count variance;
 *   - an indent is approved by someone other than the requester.
 */
final class InventoryService
{
    public const ADJUST_REASONS = [
        'damage' => 'Damage / breakage', 'theft' => 'Theft / loss', 'expiry_writeoff' => 'Expiry write-off', 'found' => 'Found / surplus',
        'count_correction' => 'Count correction', 'wastage' => 'Wastage', 'other' => 'Other',
    ];

    // ------------------------------------------------------- purchase orders

    /** @param array<int, array{item_id:int, qty:float, unit_price:float, tax_pct?:float}> $lines */
    public static function createPo(int $vendorId, int $locationId, array $lines, ?string $expected, string $notes = '', ?int $requisitionId = null): int
    {
        $lines = array_values(array_filter($lines, static fn ($l) => (int) $l['item_id'] > 0 && (float) $l['qty'] > 0));
        if (!$lines) {
            throw new \RuntimeException('Add at least one item to the purchase order.');
        }
        return DB::transaction(static function () use ($vendorId, $locationId, $lines, $expected, $notes, $requisitionId): int {
            $total = 0.0;
            foreach ($lines as $l) {
                $total += (float) $l['qty'] * (float) $l['unit_price'] * (1 + (float) ($l['tax_pct'] ?? 0) / 100);
            }
            $id = DB::insert('purchase_orders', [
                'branch_id' => Auth::branchId(), 'po_number' => StockService::nextNumber('po', 'PO'), 'vendor_id' => $vendorId, 'requisition_id' => $requisitionId,
                'location_id' => $locationId, 'status' => 'draft', 'expected_date' => $expected ?: null, 'total_amount' => round($total, 2),
                'notes' => mb_substr($notes, 0, 500) ?: null, 'created_by' => Auth::id(), 'created_at' => now(),
            ]);
            foreach ($lines as $l) {
                DB::insert('purchase_order_items', ['purchase_order_id' => $id, 'item_id' => (int) $l['item_id'], 'qty_ordered' => round((float) $l['qty'], 3),
                    'unit_price' => round((float) $l['unit_price'], 4), 'tax_pct' => round((float) ($l['tax_pct'] ?? 0), 2)]);
            }
            ActivityLog::record('PO created', 'inventory', (string) $id, 'Purchase order for ' . money($total));
            return $id;
        });
    }

    public static function submitPo(int $id): void
    {
        $n = DB::run("UPDATE purchase_orders SET status = 'submitted', submitted_at = ? WHERE id = ? AND status = 'draft'", [now(), $id])->rowCount();
        if (!$n) {
            throw new \RuntimeException('Only draft purchase orders can be submitted.');
        }
        ActivityLog::record('PO submitted', 'inventory', (string) $id, 'Sent for approval');
    }

    public static function approvePo(int $id, bool $approve = true): void
    {
        $po = DB::one("SELECT * FROM purchase_orders WHERE id = ? AND status = 'submitted'", [$id]);
        if (!$po) {
            throw new \RuntimeException('Purchase order is not waiting for approval.');
        }
        if ((int) $po['created_by'] === Auth::id()) {
            throw new \RuntimeException('The person who created a purchase order cannot approve it.');
        }
        $limit = (float) setting('stock_po_approval_limit', 100000);
        if ($approve && (float) $po['total_amount'] > $limit && !Auth::hasRole('HA', 'SA')) {
            throw new \RuntimeException('Purchase orders above ' . money($limit) . ' need the Hospital Admin\'s approval.');
        }
        DB::update('purchase_orders', ['status' => $approve ? 'approved' : 'cancelled', 'approved_by' => Auth::id(), 'approved_at' => now()], 'id = ?', [$id]);
        ActivityLog::record($approve ? 'PO approved' : 'PO rejected', 'inventory', (string) $id, $po['po_number'] . ' ' . money($po['total_amount']));
    }

    // ------------------------------------------------------------------ GRN

    /**
     * @param array{purchase_order_id?:?int, vendor_id:int, location_id:int, vendor_invoice_no?:string, vendor_invoice_date?:?string, notes?:string} $h
     * @param array<int, array> $lines item_id, po_item_id, batch_no, expiry_date, mfg_date, qty_received, qty_rejected, bonus_qty, reject_reason, unit_cost, mrp
     */
    public static function saveGrn(array $h, array $lines, bool $post): int
    {
        $lines = array_values(array_filter($lines, static fn ($l) => (int) ($l['item_id'] ?? 0) > 0 && ((float) ($l['qty_received'] ?? 0) > 0 || (float) ($l['qty_rejected'] ?? 0) > 0)));
        if (!$lines) {
            throw new \RuntimeException('Enter at least one received item.');
        }
        $po = !empty($h['purchase_order_id']) ? DB::one('SELECT * FROM purchase_orders WHERE id = ?', [(int) $h['purchase_order_id']]) : null;
        if ($po && !in_array($po['status'], ['approved', 'sent', 'partially_received'], true)) {
            throw new \RuntimeException('Goods can be received only against an approved purchase order.');
        }
        if ($po && $post && (int) $po['created_by'] === Auth::id()) {
            throw new \RuntimeException('Segregation of duties: you created this purchase order, so you cannot post its goods receipt.');
        }
        return DB::transaction(static function () use ($h, $lines, $post, $po): int {
            $total = 0.0;
            foreach ($lines as $l) {
                $total += (float) ($l['qty_received'] ?? 0) * (float) ($l['unit_cost'] ?? 0);
            }
            $id = DB::insert('goods_receipt_notes', [
                'branch_id' => Auth::branchId(), 'grn_number' => StockService::nextNumber('grn', 'GRN'), 'purchase_order_id' => $po['id'] ?? null,
                'vendor_id' => (int) ($po['vendor_id'] ?? $h['vendor_id']), 'receiving_location_id' => (int) $h['location_id'],
                'vendor_invoice_no' => mb_substr((string) ($h['vendor_invoice_no'] ?? ''), 0, 60) ?: null, 'vendor_invoice_date' => ($h['vendor_invoice_date'] ?? '') ?: null,
                'status' => 'draft', 'total_amount' => round($total, 2), 'notes' => mb_substr((string) ($h['notes'] ?? ''), 0, 500) ?: null,
                'received_by' => Auth::id(), 'received_at' => now(),
            ]);
            foreach ($lines as $l) {
                DB::insert('grn_items', [
                    'grn_id' => $id, 'po_item_id' => ($l['po_item_id'] ?? '') ?: null, 'item_id' => (int) $l['item_id'],
                    'batch_no' => trim((string) ($l['batch_no'] ?? '')) ?: null, 'expiry_date' => ($l['expiry_date'] ?? '') ?: null, 'mfg_date' => ($l['mfg_date'] ?? '') ?: null,
                    'qty_received' => round((float) ($l['qty_received'] ?? 0), 3), 'qty_rejected' => round((float) ($l['qty_rejected'] ?? 0), 3),
                    'bonus_qty' => round((float) ($l['bonus_qty'] ?? 0), 3), 'reject_reason' => mb_substr((string) ($l['reject_reason'] ?? ''), 0, 255) ?: null,
                    'unit_cost' => round((float) ($l['unit_cost'] ?? 0), 4), 'mrp' => ($l['mrp'] ?? '') !== '' ? round((float) $l['mrp'], 2) : null,
                ]);
            }
            if ($post) {
                self::postGrn($id);
            }
            return $id;
        });
    }

    /** Posting creates the batches and the ledger receipts in one transaction. */
    public static function postGrn(int $grnId): void
    {
        DB::transaction(static function () use ($grnId): void {
            $grn = DB::one("SELECT * FROM goods_receipt_notes WHERE id = ? AND status = 'draft' FOR UPDATE", [$grnId]);
            if (!$grn) {
                throw new \RuntimeException('GRN is not a draft.');
            }
            if ($grn['purchase_order_id'] && (int) DB::value('SELECT created_by FROM purchase_orders WHERE id = ?', [$grn['purchase_order_id']]) === Auth::id()) {
                throw new \RuntimeException('Segregation of duties: you created the purchase order of this receipt.');
            }
            foreach (DB::all('SELECT * FROM grn_items WHERE grn_id = ?', [$grnId]) as $l) {
                $qty = (float) $l['qty_received'] + (float) $l['bonus_qty'];
                if ($qty <= 0) {
                    continue;
                }
                $effectiveCost = $qty > 0 ? (float) $l['qty_received'] * (float) $l['unit_cost'] / $qty : 0.0;
                $batchId = StockService::batchFor((int) $l['item_id'], $l['batch_no'], $l['expiry_date'],
                    ['mfg_date' => $l['mfg_date'], 'vendor_id' => $grn['vendor_id'], 'unit_cost' => $effectiveCost, 'mrp' => $l['mrp'], 'grn_item_id' => $l['id']]);
                StockService::move((int) $l['item_id'], (int) $grn['receiving_location_id'], $qty, 'grn_receipt',
                    ['batch_id' => $batchId, 'unit_cost' => $effectiveCost, 'reference_type' => 'grn_items', 'reference_id' => (int) $l['id']]);
                StockService::updateAverageCost((int) $l['item_id'], $qty, $effectiveCost);
                DB::update('grn_items', ['batch_id' => $batchId ?: null], 'id = ?', [$l['id']]);
                if ($l['po_item_id']) {
                    DB::run('UPDATE purchase_order_items SET qty_received = qty_received + ? WHERE id = ?', [$l['qty_received'], $l['po_item_id']]);
                }
                DB::run('INSERT INTO vendor_items (vendor_id, item_id, last_price, last_bought_at) VALUES (?, ?, ?, ?)
                         ON DUPLICATE KEY UPDATE last_price = VALUES(last_price), last_bought_at = VALUES(last_bought_at)',
                    [$grn['vendor_id'], $l['item_id'], $l['unit_cost'], now()]);
                if ($l['mrp'] !== null && (float) $l['mrp'] > 0) {
                    DB::run('UPDATE items SET sale_price = ? WHERE id = ? AND sale_price = 0', [$l['mrp'], $l['item_id']]);
                }
            }
            if ($grn['purchase_order_id']) {
                $open = (float) DB::value('SELECT COALESCE(SUM(GREATEST(qty_ordered - qty_received, 0)), 0) FROM purchase_order_items WHERE purchase_order_id = ?', [$grn['purchase_order_id']]);
                DB::update('purchase_orders', ['status' => $open > 0.0005 ? 'partially_received' : 'received'], 'id = ?', [$grn['purchase_order_id']]);
            }
            DB::update('goods_receipt_notes', ['status' => 'posted', 'posted_at' => now(), 'posted_by' => Auth::id()], 'id = ?', [$grnId]);
            ActivityLog::record('GRN posted', 'inventory', (string) $grnId, $grn['grn_number'] . ' ' . money($grn['total_amount']));
        });
    }

    // ------------------------------------------------- indents / requisitions

    public static function createRequisition(int $fromLocation, int $toLocation, array $lines, string $priority = 'normal', string $notes = '', bool $submit = true): int
    {
        if ($fromLocation === $toLocation) {
            throw new \RuntimeException('The requesting location and the store must differ.');
        }
        $lines = array_values(array_filter($lines, static fn ($l) => (int) ($l['item_id'] ?? 0) > 0 && (float) ($l['qty'] ?? 0) > 0));
        if (!$lines) {
            throw new \RuntimeException('Add at least one item.');
        }
        self::assertOwnLocation($fromLocation, 'inventory.requisition.create');
        return DB::transaction(static function () use ($fromLocation, $toLocation, $lines, $priority, $notes, $submit): int {
            $id = DB::insert('stock_requisitions', [
                'branch_id' => Auth::branchId(), 'req_number' => StockService::nextNumber('req', 'IND'), 'from_location_id' => $fromLocation,
                'to_location_id' => $toLocation, 'requested_by' => Auth::id(), 'priority' => in_array($priority, ['normal', 'urgent'], true) ? $priority : 'normal',
                'status' => $submit ? 'submitted' : 'draft', 'notes' => mb_substr($notes, 0, 500) ?: null, 'created_at' => now(), 'submitted_at' => $submit ? now() : null,
            ]);
            foreach ($lines as $l) {
                DB::insert('stock_requisition_items', ['requisition_id' => $id, 'item_id' => (int) $l['item_id'], 'qty_requested' => round((float) $l['qty'], 3),
                    'note' => mb_substr((string) ($l['note'] ?? ''), 0, 255) ?: null]);
            }
            ActivityLog::record('Indent raised', 'inventory', (string) $id, count($lines) . ' item(s)');
            return $id;
        });
    }

    /** @param array<int, float> $approvedQty item line id => qty */
    public static function approveRequisition(int $id, array $approvedQty, bool $approve = true, string $reason = ''): void
    {
        $r = DB::one("SELECT * FROM stock_requisitions WHERE id = ? AND status = 'submitted'", [$id]);
        if (!$r) {
            throw new \RuntimeException('Indent is not waiting for approval.');
        }
        if ((int) $r['requested_by'] === Auth::id()) {
            throw new \RuntimeException('You cannot approve your own indent.');
        }
        DB::transaction(static function () use ($r, $id, $approvedQty, $approve, $reason): void {
            if ($approve) {
                foreach (DB::all('SELECT * FROM stock_requisition_items WHERE requisition_id = ?', [$id]) as $l) {
                    $q = array_key_exists((int) $l['id'], $approvedQty) ? max(0, (float) $approvedQty[(int) $l['id']]) : (float) $l['qty_requested'];
                    DB::update('stock_requisition_items', ['qty_approved' => round($q, 3)], 'id = ?', [$l['id']]);
                }
            }
            DB::update('stock_requisitions', ['status' => $approve ? 'approved' : 'rejected', 'approved_by' => Auth::id(), 'approved_at' => now(),
                'rejected_reason' => $approve ? null : mb_substr($reason, 0, 255)], 'id = ?', [$id]);
            ActivityLog::record($approve ? 'Indent approved' : 'Indent rejected', 'inventory', (string) $id, $r['req_number']);
        });
    }

    /**
     * Store issues against an approved indent (FEFO batches): issue_out at the
     * store, issue_in at the department location.
     * @param array<int, float> $qtys requisition item id => qty to issue now
     */
    public static function issueRequisition(int $id, array $qtys): int
    {
        return DB::transaction(static function () use ($id, $qtys): int {
            $r = DB::one("SELECT * FROM stock_requisitions WHERE id = ? AND status IN ('approved','partially_issued') FOR UPDATE", [$id]);
            if (!$r) {
                throw new \RuntimeException('Indent is not approved for issue.');
            }
            $issueId = DB::insert('stock_issues', ['branch_id' => Auth::branchId(), 'issue_number' => StockService::nextNumber('iss', 'ISS'), 'requisition_id' => $id,
                'from_location_id' => $r['to_location_id'], 'to_location_id' => $r['from_location_id'], 'issued_by' => Auth::id(), 'issued_at' => now()]);
            $any = false;
            foreach (DB::all('SELECT * FROM stock_requisition_items WHERE requisition_id = ?', [$id]) as $l) {
                $remaining = (float) ($l['qty_approved'] ?? $l['qty_requested']) - (float) $l['qty_issued'];
                $q = min($remaining, max(0, (float) ($qtys[(int) $l['id']] ?? $remaining)));
                if ($q <= 0.0005) {
                    continue;
                }
                foreach (StockService::fefo((int) $l['item_id'], (int) $r['to_location_id'], $q) as $p) {
                    StockService::relocate((int) $l['item_id'], $p['batch_id'], (int) $r['to_location_id'], (int) $r['from_location_id'], $p['qty'], 'issue',
                        ['reference_type' => 'stock_issues', 'reference_id' => $issueId]);
                    DB::insert('stock_issue_items', ['issue_id' => $issueId, 'item_id' => $l['item_id'], 'batch_id' => $p['batch_id'], 'qty' => $p['qty'],
                        'unit_cost' => (float) DB::value('SELECT unit_cost FROM stock_batches WHERE id = ?', [$p['batch_id']])]);
                }
                DB::run('UPDATE stock_requisition_items SET qty_issued = qty_issued + ? WHERE id = ?', [$q, $l['id']]);
                $any = true;
            }
            if (!$any) {
                throw new \RuntimeException('Nothing to issue.');
            }
            $open = (float) DB::value('SELECT COALESCE(SUM(GREATEST(COALESCE(qty_approved, qty_requested) - qty_issued, 0)), 0) FROM stock_requisition_items WHERE requisition_id = ?', [$id]);
            DB::update('stock_requisitions', ['status' => $open > 0.0005 ? 'partially_issued' : 'issued'], 'id = ?', [$id]);
            ActivityLog::record('Stock issued', 'inventory', (string) $issueId, $r['req_number']);
            return $issueId;
        });
    }

    public static function confirmReceipt(int $issueId): void
    {
        $i = DB::one('SELECT * FROM stock_issues WHERE id = ? AND received_at IS NULL', [$issueId]);
        if (!$i) {
            throw new \RuntimeException('Issue already confirmed.');
        }
        self::assertOwnLocation((int) $i['to_location_id'], 'inventory.requisition.create');
        DB::update('stock_issues', ['received_by' => Auth::id(), 'received_at' => now()], 'id = ?', [$issueId]);
        if ($i['requisition_id']) {
            DB::update('stock_requisitions', ['received_confirmed_at' => now()], 'id = ?', [$i['requisition_id']]);
        }
    }

    // ------------------------------------------------------------ transfers

    /** @param array<int, array{item_id:int, batch_id:int, qty:float}> $lines */
    public static function dispatchTransfer(int $from, int $to, array $lines, string $notes = ''): int
    {
        if ($from === $to) {
            throw new \RuntimeException('Choose two different locations.');
        }
        return DB::transaction(static function () use ($from, $to, $lines, $notes): int {
            $id = DB::insert('stock_transfers', ['branch_id' => Auth::branchId(), 'transfer_number' => StockService::nextNumber('trf', 'TRF'),
                'from_location_id' => $from, 'to_location_id' => $to, 'status' => 'in_transit', 'notes' => mb_substr($notes, 0, 500) ?: null,
                'dispatched_by' => Auth::id(), 'dispatched_at' => now(), 'created_at' => now()]);
            $n = 0;
            foreach ($lines as $l) {
                $qty = (float) ($l['qty'] ?? 0);
                if ((int) ($l['item_id'] ?? 0) <= 0 || $qty <= 0) {
                    continue;
                }
                $plan = (int) ($l['batch_id'] ?? 0) > 0 ? [['batch_id' => (int) $l['batch_id'], 'qty' => $qty]] : StockService::fefo((int) $l['item_id'], $from, $qty);
                foreach ($plan as $p) {
                    StockService::move((int) $l['item_id'], $from, -$p['qty'], 'transfer_out', ['batch_id' => $p['batch_id'], 'reference_type' => 'stock_transfers', 'reference_id' => $id]);
                    DB::insert('stock_transfer_items', ['transfer_id' => $id, 'item_id' => (int) $l['item_id'], 'batch_id' => $p['batch_id'], 'qty_sent' => $p['qty'],
                        'unit_cost' => (float) DB::value('SELECT unit_cost FROM stock_batches WHERE id = ?', [$p['batch_id']])]);
                    $n++;
                }
            }
            if (!$n) {
                throw new \RuntimeException('Add at least one item to transfer.');
            }
            ActivityLog::record('Transfer dispatched', 'inventory', (string) $id, $n . ' line(s)');
            return $id;
        });
    }

    /** @param array<int, array{qty:float, reason?:string}> $received transfer item id => received */
    public static function receiveTransfer(int $id, array $received): void
    {
        DB::transaction(static function () use ($id, $received): void {
            $t = DB::one("SELECT * FROM stock_transfers WHERE id = ? AND status = 'in_transit' FOR UPDATE", [$id]);
            if (!$t) {
                throw new \RuntimeException('Transfer is not in transit.');
            }
            if ((int) $t['dispatched_by'] === Auth::id() && !Auth::hasRole('SA')) {
                throw new \RuntimeException('The receiving location must confirm the transfer (not the dispatcher).');
            }
            foreach (DB::all('SELECT * FROM stock_transfer_items WHERE transfer_id = ?', [$id]) as $l) {
                $q = min((float) $l['qty_sent'], max(0, (float) ($received[(int) $l['id']]['qty'] ?? $l['qty_sent'])));
                if ($q > 0) {
                    StockService::move((int) $l['item_id'], (int) $t['to_location_id'], $q, 'transfer_in', ['batch_id' => (int) $l['batch_id'],
                        'reference_type' => 'stock_transfers', 'reference_id' => $id]);
                }
                DB::update('stock_transfer_items', ['qty_received' => $q, 'variance_reason' => abs($q - (float) $l['qty_sent']) > 0.0005
                    ? mb_substr((string) ($received[(int) $l['id']]['reason'] ?? 'Short received'), 0, 255) : null], 'id = ?', [$l['id']]);
            }
            DB::update('stock_transfers', ['status' => 'received', 'received_by' => Auth::id(), 'received_at' => now()], 'id = ?', [$id]);
            ActivityLog::record('Transfer received', 'inventory', (string) $id, $t['transfer_number']);
        });
    }

    // ---------------------------------------------------------- adjustments

    /** @param array<int, array{item_id:int, batch_id:int, qty_signed:float}> $lines */
    public static function proposeAdjustment(int $locationId, string $reason, array $lines, string $notes = '', ?int $countId = null): int
    {
        if (!isset(self::ADJUST_REASONS[$reason])) {
            throw new \RuntimeException('Choose the reason of the adjustment.');
        }
        $lines = array_values(array_filter($lines, static fn ($l) => (int) ($l['item_id'] ?? 0) > 0 && abs((float) ($l['qty_signed'] ?? 0)) > 0.0005));
        if (!$lines) {
            throw new \RuntimeException('Add at least one line with a quantity.');
        }
        return DB::transaction(static function () use ($locationId, $reason, $lines, $notes, $countId): int {
            $value = 0.0;
            $id = DB::insert('stock_adjustments', ['branch_id' => Auth::branchId(), 'adj_number' => StockService::nextNumber('adj', 'ADJ'), 'location_id' => $locationId,
                'reason_code' => $reason, 'status' => 'proposed', 'notes' => mb_substr($notes, 0, 500) ?: null, 'proposed_by' => Auth::id(), 'proposed_at' => now(), 'count_id' => $countId]);
            foreach ($lines as $l) {
                $cost = (int) $l['batch_id'] ? (float) DB::value('SELECT unit_cost FROM stock_batches WHERE id = ?', [$l['batch_id']]) : (float) DB::value('SELECT avg_cost FROM items WHERE id = ?', [$l['item_id']]);
                DB::insert('stock_adjustment_items', ['adjustment_id' => $id, 'item_id' => (int) $l['item_id'], 'batch_id' => (int) $l['batch_id'],
                    'qty_signed' => round((float) $l['qty_signed'], 3), 'unit_cost' => $cost]);
                $value += abs((float) $l['qty_signed']) * $cost;
            }
            DB::update('stock_adjustments', ['total_value' => round($value, 2)], 'id = ?', [$id]);
            ActivityLog::record('Adjustment proposed', 'inventory', (string) $id, self::ADJUST_REASONS[$reason] . ' ' . money($value));
            return $id;
        });
    }

    public static function decideAdjustment(int $id, bool $approve, bool $skipSod = false): void
    {
        DB::transaction(static function () use ($id, $approve, $skipSod): void {
            $a = DB::one("SELECT * FROM stock_adjustments WHERE id = ? AND status = 'proposed' FOR UPDATE", [$id]);
            if (!$a) {
                throw new \RuntimeException('Adjustment is not waiting for approval.');
            }
            if (!$skipSod && (int) $a['proposed_by'] === Auth::id()) {
                throw new \RuntimeException('Segregation of duties: you proposed this adjustment, so you cannot approve it.');
            }
            $limit = (float) setting('stock_adjust_limit', 5000);
            if ($approve && (float) $a['total_value'] > $limit && !Auth::hasRole('HA', 'SA')) {
                throw new \RuntimeException('Adjustments above ' . money($limit) . ' need the Hospital Admin\'s approval.');
            }
            if ($approve) {
                foreach (DB::all('SELECT * FROM stock_adjustment_items WHERE adjustment_id = ?', [$id]) as $l) {
                    $q = (float) $l['qty_signed'];
                    $type = $a['reason_code'] === 'count_correction' ? 'count_correction'
                        : ($q > 0 ? 'adjustment_gain' : (in_array($a['reason_code'], ['expiry_writeoff', 'wastage'], true) ? $a['reason_code'] : 'adjustment_loss'));
                    StockService::move((int) $l['item_id'], (int) $a['location_id'], $q, $type, ['batch_id' => (int) $l['batch_id'], 'unit_cost' => (float) $l['unit_cost'],
                        'reference_type' => 'stock_adjustments', 'reference_id' => $id, 'allow_negative' => false]);
                }
            }
            DB::update('stock_adjustments', ['status' => $approve ? 'approved' : 'rejected', 'approved_by' => Auth::id(), 'approved_at' => now()], 'id = ?', [$id]);
            ActivityLog::record($approve ? 'Adjustment approved' : 'Adjustment rejected', 'inventory', (string) $id, $a['adj_number'] . ' ' . money($a['total_value']));
        });
    }

    // --------------------------------------------------------------- counts

    /** Snapshot of the location: one line per item + batch with stock (system qty hidden from counters). */
    public static function startCount(int $locationId, string $type = 'full', array $itemIds = []): int
    {
        return DB::transaction(static function () use ($locationId, $type, $itemIds): int {
            $id = DB::insert('stock_counts', ['branch_id' => Auth::branchId(), 'count_number' => StockService::nextNumber('cnt', 'CNT'), 'location_id' => $locationId,
                'count_type' => in_array($type, ['full', 'cycle', 'spot'], true) ? $type : 'full', 'status' => 'counting', 'snapshot_at' => now(), 'created_by' => Auth::id()]);
            $where = 'b.location_id = ? AND b.qty_on_hand <> 0';
            $params = [$locationId];
            if ($itemIds) {
                $where .= ' AND b.item_id IN (' . DB::placeholders($itemIds) . ')';
                array_push($params, ...$itemIds);
            }
            foreach (DB::all('SELECT b.item_id, b.batch_id, b.qty_on_hand FROM stock_balances b JOIN items i ON i.id = b.item_id WHERE ' . $where . ' ORDER BY i.name', $params) as $r) {
                DB::insert('stock_count_lines', ['count_id' => $id, 'item_id' => $r['item_id'], 'batch_id' => $r['batch_id'], 'system_qty' => $r['qty_on_hand']]);
            }
            ActivityLog::record('Stock count started', 'inventory', (string) $id, 'Location #' . $locationId);
            return $id;
        });
    }

    /** @param array<int, float|string> $counts line id => counted qty */
    public static function saveCounts(int $countId, array $counts, bool $recount = false): void
    {
        $c = DB::one("SELECT * FROM stock_counts WHERE id = ? AND status IN ('counting','review')", [$countId]);
        if (!$c) {
            throw new \RuntimeException('This count is closed.');
        }
        foreach ($counts as $lineId => $qty) {
            if ($qty === '' || $qty === null || !is_numeric($qty)) {
                continue;
            }
            $line = DB::one('SELECT * FROM stock_count_lines WHERE id = ? AND count_id = ?', [(int) $lineId, $countId]);
            if (!$line) {
                continue;
            }
            $final = round((float) $qty, 3);
            DB::update('stock_count_lines', $recount
                ? ['recount_qty' => $final, 'variance' => round($final - (float) $line['system_qty'], 3), 'counted_by' => Auth::id(), 'counted_at' => now()]
                : ['counted_qty' => $final, 'variance' => round($final - (float) $line['system_qty'], 3), 'counted_by' => Auth::id(), 'counted_at' => now()],
                'id = ?', [$lineId]);
        }
    }

    public static function submitCount(int $countId): void
    {
        DB::run("UPDATE stock_counts SET status = 'review', reviewed_by = NULL WHERE id = ? AND status = 'counting'", [$countId]);
    }

    /**
     * Posting the variance: a count-correction adjustment, approved at once by
     * the poster — who must not be one of the counters.
     */
    public static function postCount(int $countId): ?int
    {
        return DB::transaction(static function () use ($countId): ?int {
            $c = DB::one("SELECT * FROM stock_counts WHERE id = ? AND status = 'review' FOR UPDATE", [$countId]);
            if (!$c) {
                throw new \RuntimeException('Submit the count for review first.');
            }
            if (DB::value('SELECT 1 FROM stock_count_lines WHERE count_id = ? AND counted_by = ?', [$countId, Auth::id()])) {
                throw new \RuntimeException('Segregation of duties: you counted this stock, so a supervisor must post the variance.');
            }
            $lines = [];
            foreach (DB::all('SELECT * FROM stock_count_lines WHERE count_id = ? AND counted_qty IS NOT NULL', [$countId]) as $l) {
                $final = $l['recount_qty'] !== null ? (float) $l['recount_qty'] : (float) $l['counted_qty'];
                $current = (float) DB::value('SELECT COALESCE(qty_on_hand, 0) FROM stock_balances WHERE location_id = ? AND item_id = ? AND batch_id = ?',
                    [$c['location_id'], $l['item_id'], $l['batch_id']]);
                // Movements after the snapshot are respected: only the difference found at the snapshot is corrected.
                $delta = $final - (float) $l['system_qty'];
                if (abs($delta) > 0.0005 && $current + $delta >= -0.0005) {
                    $lines[] = ['item_id' => (int) $l['item_id'], 'batch_id' => (int) $l['batch_id'], 'qty_signed' => $delta];
                }
            }
            $adj = null;
            if ($lines) {
                $adj = self::proposeAdjustment((int) $c['location_id'], 'count_correction', $lines, 'Variance of count ' . $c['count_number'], $countId);
                self::decideAdjustment($adj, true, true);
            }
            DB::update('stock_counts', ['status' => 'posted', 'posted_by' => Auth::id(), 'posted_at' => now()], 'id = ?', [$countId]);
            ActivityLog::record('Stock count posted', 'inventory', (string) $countId, count($lines) . ' variance line(s)');
            return $adj;
        });
    }

    // ------------------------------------------------- recalls & returns

    /**
     * DRAP / manufacturer recall: matching batches are recalled, their stock
     * moved to quarantine, and the patients who received them are listed.
     */
    public static function recall(int $itemId, string $batchNo, string $source, string $reference, string $notes = ''): array
    {
        return DB::transaction(static function () use ($itemId, $batchNo, $source, $reference, $notes): array {
            DB::insert('recall_notices', ['item_id' => $itemId, 'batch_no' => mb_substr($batchNo, 0, 60), 'source' => mb_substr($source, 0, 20),
                'reference' => mb_substr($reference, 0, 100) ?: null, 'issued_at' => today(), 'action_status' => 'quarantined',
                'notes' => mb_substr($notes, 0, 500) ?: null, 'created_by' => Auth::id(), 'created_at' => now()]);
            $quarantine = StockService::locationOfType('quarantine');
            $batches = DB::all('SELECT * FROM stock_batches WHERE item_id = ? AND batch_no = ?', [$itemId, $batchNo]);
            foreach ($batches as $b) {
                DB::update('stock_batches', ['status' => 'recalled'], 'id = ?', [$b['id']]);
                if ($quarantine) {
                    foreach (DB::all('SELECT * FROM stock_balances WHERE batch_id = ? AND qty_on_hand > 0 AND location_id <> ?', [$b['id'], $quarantine]) as $bal) {
                        StockService::relocate($itemId, (int) $b['id'], (int) $bal['location_id'], $quarantine, (float) $bal['qty_on_hand'], 'transfer',
                            ['note' => 'Recall ' . $reference, 'reference_type' => 'recall']);
                    }
                }
            }
            $ids = array_column($batches, 'id') ?: [0];
            $patients = DB::all(
                'SELECT DISTINCT p.id, p.name, p.mrn, p.phone, d.dispensed_at AS given_at, "dispense" AS via FROM dispense_items di JOIN dispenses d ON d.id = di.dispense_id
                   JOIN patients p ON p.id = d.patient_id WHERE di.batch_id IN (' . DB::placeholders($ids) . ')
                 UNION
                 SELECT DISTINCT p.id, p.name, p.mrn, p.phone, s.sold_at AS given_at, "sale" AS via FROM pharmacy_sale_items si JOIN pharmacy_sales s ON s.id = si.sale_id
                   JOIN patients p ON p.id = s.patient_id WHERE si.batch_id IN (' . DB::placeholders($ids) . ')',
                array_merge($ids, $ids)
            );
            ActivityLog::record('Recall registered', 'inventory', (string) $itemId, 'Batch ' . $batchNo . ' (' . $source . ' ' . $reference . '): ' . count($patients) . ' patient(s) to contact');
            return $patients;
        });
    }

    /** @param array<int, array{item_id:int, batch_id:int, qty:float}> $lines */
    public static function returnToVendor(int $vendorId, int $locationId, array $lines, string $reason): int
    {
        return DB::transaction(static function () use ($vendorId, $locationId, $lines, $reason): int {
            $id = DB::insert('vendor_returns', ['branch_id' => Auth::branchId(), 'return_number' => StockService::nextNumber('vr', 'VR'), 'vendor_id' => $vendorId,
                'location_id' => $locationId, 'reason' => mb_substr($reason, 0, 255), 'status' => 'posted', 'created_by' => Auth::id(), 'created_at' => now()]);
            $total = 0.0;
            foreach ($lines as $l) {
                if ((int) ($l['item_id'] ?? 0) <= 0 || (float) ($l['qty'] ?? 0) <= 0) {
                    continue;
                }
                $cost = (float) DB::value('SELECT unit_cost FROM stock_batches WHERE id = ?', [(int) $l['batch_id']]);
                StockService::move((int) $l['item_id'], $locationId, -(float) $l['qty'], 'return_to_vendor', ['batch_id' => (int) $l['batch_id'],
                    'reference_type' => 'vendor_returns', 'reference_id' => $id]);
                DB::insert('vendor_return_items', ['return_id' => $id, 'item_id' => (int) $l['item_id'], 'batch_id' => (int) $l['batch_id'], 'qty' => (float) $l['qty'], 'unit_cost' => $cost]);
                $total += $cost * (float) $l['qty'];
            }
            DB::update('vendor_returns', ['total_amount' => round($total, 2)], 'id = ?', [$id]);
            ActivityLog::record('Returned to vendor', 'inventory', (string) $id, money($total) . ' — ' . $reason);
            return $id;
        });
    }

    // -------------------------------------------------------------- helpers

    /**
     * Department indenters (IND, own scope) act only for the locations they
     * are custodian of or assigned to (user_stock_locations).
     */
    public static function assertOwnLocation(int $locationId, string $permission): void
    {
        if (Auth::scope($permission) !== 'own') {
            return;
        }
        $uid = Auth::id();
        $ok = DB::value('SELECT 1 FROM stock_locations WHERE id = ? AND custodian_user_id = ?', [$locationId, $uid])
            || DB::value('SELECT 1 FROM user_stock_locations WHERE location_id = ? AND user_id = ?', [$locationId, $uid]);
        if (!$ok) {
            throw new \RuntimeException('You can raise and receive indents only for your own department location.');
        }
    }

    /** Locations the current user may pick as "my location". */
    public static function myLocations(string $permission = 'inventory.requisition.create'): array
    {
        if (Auth::scope($permission) === 'own') {
            $uid = Auth::id();
            return DB::pairs('SELECT DISTINCT l.id, l.name FROM stock_locations l LEFT JOIN user_stock_locations u ON u.location_id = l.id AND u.user_id = ?
                               WHERE l.deleted_at IS NULL AND l.is_active = 1 AND (l.custodian_user_id = ? OR u.user_id IS NOT NULL) ORDER BY l.name', [$uid, $uid]);
        }
        return self::locations();
    }

    public static function locations(?array $types = null): array
    {
        $sql = 'SELECT id, name FROM stock_locations WHERE deleted_at IS NULL AND is_active = 1';
        $params = [];
        if ($types) {
            $sql .= ' AND location_type IN (' . DB::placeholders($types) . ')';
            $params = $types;
        }
        return DB::pairs($sql . ' ORDER BY display_order, name', $params);
    }
}
