<?php
declare(strict_types=1);

namespace App\Modules\Inventory;

use App\Core\Auth;
use App\Core\Controller;
use App\Core\DB;
use App\Core\Paginator;

/** Indents, purchase orders, GRN, transfers, adjustments and stocktakes. */
final class StoreDocsController extends Controller
{
    private function run(callable $fn, string $success, string $route, array $params = []): void
    {
        try {
            $res = $fn();
            flash('success', $success);
            if (is_int($res) && isset($params['id']) && $params['id'] === '__id__') {
                $params['id'] = $res;
            }
        } catch (\Throwable $e) {
            flash('error', $e->getMessage());
            if (($params['id'] ?? null) === '__id__') {
                // Creation failed: back to the form the user filled in.
                back($route);
            }
        }
        redirect($route, $params);
    }

    // =============================================================== indents

    public function indents(): void
    {
        $status = (string) input('status', '');
        $where = '1 = 1';
        $params = [];
        if ($status !== '') {
            $where .= $status === 'approved' ? " AND r.status IN ('approved','partially_issued')" : ' AND r.status = ?';
            if ($status !== 'approved') {
                $params[] = $status;
            }
        }
        if (Auth::scope('inventory.requisition.create') === 'own' && !can('inventory.issue') && !can('inventory.requisition.approve')) {
            $mine = array_keys(InventoryService::myLocations()) ?: [0];
            $where .= ' AND r.from_location_id IN (' . DB::placeholders($mine) . ')';
            array_push($params, ...$mine);
        }
        $pager = new Paginator((int) DB::value("SELECT COUNT(*) FROM stock_requisitions r WHERE $where", $params));
        $this->view('inventory/indents', [
            'title' => 'Indents / Requisitions',
            'rows' => DB::all("SELECT r.*, f.name AS from_name, t.name AS to_name, u.name AS requested_by_name,
                                      (SELECT COUNT(*) FROM stock_requisition_items x WHERE x.requisition_id = r.id) AS line_count
                                 FROM stock_requisitions r JOIN stock_locations f ON f.id = r.from_location_id JOIN stock_locations t ON t.id = r.to_location_id
                                 LEFT JOIN users u ON u.id = r.requested_by WHERE $where ORDER BY r.id DESC" . $pager->limitSql(), $params),
            'pager' => $pager, 'status' => $status,
        ]);
    }

    public function newIndent(): void
    {
        if (is_post()) {
            $this->run(static fn () => InventoryService::createRequisition((int) input('from_location_id', 0), (int) input('to_location_id', 0),
                array_values((array) ($_POST['lines'] ?? [])), (string) input('priority', 'normal'), (string) input('notes', ''), input('submit') !== 'draft'),
                'Indent sent to the store.', 'inventory/indent', ['id' => '__id__']);
        }
        $this->view('inventory/indent_new', ['title' => 'New Indent', 'mine' => InventoryService::myLocations(),
            'stores' => InventoryService::locations(['central_store', 'sub_store', 'pharmacy_main']), 'scripts' => ['js/stock.js']]);
    }

    public function indent(): void
    {
        $id = $this->requireId();
        $r = DB::one('SELECT r.*, f.name AS from_name, t.name AS to_name, u.name AS requested_by_name, a.name AS approved_by_name FROM stock_requisitions r
                        JOIN stock_locations f ON f.id = r.from_location_id JOIN stock_locations t ON t.id = r.to_location_id
                        LEFT JOIN users u ON u.id = r.requested_by LEFT JOIN users a ON a.id = r.approved_by WHERE r.id = ?', [$id]);
        if (!$r) {
            abort(404);
        }
        $lines = DB::all('SELECT x.*, i.name AS item_name, i.sku FROM stock_requisition_items x JOIN items i ON i.id = x.item_id WHERE x.requisition_id = ?', [$id]);
        foreach ($lines as &$l) {
            $l['store_qty'] = StockService::available((int) $l['item_id'], (int) $r['to_location_id']);
        }
        $this->view('inventory/indent', ['title' => 'Indent ' . $r['req_number'], 'r' => $r, 'lines' => $lines,
            'issues' => DB::all('SELECT s.*, u.name AS issued_by_name, rb.name AS received_by_name FROM stock_issues s LEFT JOIN users u ON u.id = s.issued_by
                                 LEFT JOIN users rb ON rb.id = s.received_by WHERE s.requisition_id = ? ORDER BY s.id', [$id])]);
    }

    public function indentAction(): void
    {
        $id = $this->requireId();
        $do = (string) input('do', '');
        switch ($do) {
            case 'submit':
                $this->run(static function () use ($id): void {
                    if (!DB::run("UPDATE stock_requisitions SET status = 'submitted', submitted_at = ? WHERE id = ? AND status = 'draft' AND requested_by = ?", [now(), $id, Auth::id()])->rowCount()) {
                        throw new \RuntimeException('Only your own draft can be submitted.');
                    }
                }, 'Indent submitted.', 'inventory/indent', ['id' => $id]);
                break;
            case 'approve':
            case 'reject':
                if (!can('inventory.requisition.approve')) {
                    abort(403);
                }
                $this->run(static fn () => InventoryService::approveRequisition($id, array_map('floatval', (array) ($_POST['approved'] ?? [])), $do === 'approve', (string) input('reason', '')),
                    'Indent ' . ($do === 'approve' ? 'approved.' : 'rejected.'), 'inventory/indent', ['id' => $id]);
                break;
            case 'issue':
                if (!can('inventory.issue')) {
                    abort(403);
                }
                $this->run(static fn () => InventoryService::issueRequisition($id, array_map('floatval', (array) ($_POST['issue'] ?? []))),
                    'Stock issued (FEFO batches).', 'inventory/indent', ['id' => $id]);
                break;
            case 'receive':
                $this->run(static fn () => InventoryService::confirmReceipt((int) input('issue_id', 0)), 'Receipt confirmed.', 'inventory/indent', ['id' => $id]);
                break;
            default:
                abort(400);
        }
    }

    // ======================================================= purchase orders

    public function pos(): void
    {
        $status = (string) input('status', '');
        $params = [];
        $where = '1 = 1';
        if ($status !== '') {
            $where .= ' AND p.status = ?';
            $params[] = $status === 'draft' ? 'submitted' : $status;
        }
        $pager = new Paginator((int) DB::value("SELECT COUNT(*) FROM purchase_orders p WHERE $where", $params));
        $this->view('inventory/pos', ['title' => 'Purchase Orders', 'pager' => $pager, 'status' => $status,
            'rows' => DB::all("SELECT p.*, v.name AS vendor_name, u.name AS created_by_name FROM purchase_orders p JOIN vendors v ON v.id = p.vendor_id
                                 LEFT JOIN users u ON u.id = p.created_by WHERE $where ORDER BY p.id DESC" . $pager->limitSql(), $params),
            'suggest' => InventoryController::lowStock()]);
    }

    public function newPo(): void
    {
        if (is_post()) {
            $this->run(static fn () => InventoryService::createPo((int) input('vendor_id', 0), (int) input('location_id', 0), array_map(static fn ($l) => [
                'item_id' => (int) ($l['item_id'] ?? 0), 'qty' => (float) ($l['qty'] ?? 0), 'unit_price' => (float) ($l['unit_price'] ?? 0), 'tax_pct' => (float) ($l['tax_pct'] ?? 0),
            ], array_values((array) ($_POST['lines'] ?? []))), (string) input('expected_date', '') ?: null, (string) input('notes', '')),
                'Purchase order created as draft — submit it for approval.', 'inventory/po-view', ['id' => '__id__']);
        }
        $this->view('inventory/po_new', ['title' => 'New Purchase Order', 'scripts' => ['js/stock.js'],
            'vendors' => DB::pairs("SELECT id, name FROM vendors WHERE deleted_at IS NULL AND is_active = 1 AND vendor_type IN ('supplier','manufacturer') ORDER BY name"),
            'locations' => InventoryService::locations(['central_store', 'sub_store', 'pharmacy_main']),
            'suggest' => InventoryController::lowStock()]);
    }

    public function po(): void
    {
        $id = $this->requireId();
        $po = DB::one('SELECT p.*, v.name AS vendor_name, sl.name AS location_name, u.name AS created_by_name, a.name AS approved_by_name FROM purchase_orders p
                         JOIN vendors v ON v.id = p.vendor_id LEFT JOIN stock_locations sl ON sl.id = p.location_id LEFT JOIN users u ON u.id = p.created_by
                         LEFT JOIN users a ON a.id = p.approved_by WHERE p.id = ?', [$id]);
        if (!$po) {
            abort(404);
        }
        $this->view('inventory/po', ['title' => 'PO ' . $po['po_number'], 'po' => $po,
            'lines' => DB::all('SELECT x.*, i.name AS item_name, i.sku FROM purchase_order_items x JOIN items i ON i.id = x.item_id WHERE x.purchase_order_id = ?', [$id]),
            'grns' => DB::all('SELECT * FROM goods_receipt_notes WHERE purchase_order_id = ? ORDER BY id', [$id])]);
    }

    public function poAction(): void
    {
        $id = $this->requireId();
        $do = (string) input('do', '');
        if ($do === 'submit') {
            $this->run(static fn () => InventoryService::submitPo($id), 'Purchase order submitted for approval.', 'inventory/po-view', ['id' => $id]);
        }
        if (!can('inventory.po.approve')) {
            abort(403);
        }
        $this->run(static fn () => InventoryService::approvePo($id, $do === 'approve'), 'Purchase order ' . ($do === 'approve' ? 'approved.' : 'rejected.'), 'inventory/po-view', ['id' => $id]);
    }

    // ================================================================== GRN

    public function grns(): void
    {
        $pager = new Paginator((int) DB::value('SELECT COUNT(*) FROM goods_receipt_notes'));
        $this->view('inventory/grns', ['title' => 'Goods Receipt Notes', 'pager' => $pager,
            'rows' => DB::all('SELECT g.*, v.name AS vendor_name, sl.name AS location_name, p.po_number, u.name AS received_by_name FROM goods_receipt_notes g
                                 JOIN vendors v ON v.id = g.vendor_id JOIN stock_locations sl ON sl.id = g.receiving_location_id LEFT JOIN purchase_orders p ON p.id = g.purchase_order_id
                                 LEFT JOIN users u ON u.id = g.received_by ORDER BY g.id DESC' . $pager->limitSql()),
            'openPos' => DB::all("SELECT p.id, p.po_number, v.name AS vendor_name FROM purchase_orders p JOIN vendors v ON v.id = p.vendor_id
                                   WHERE p.status IN ('approved','sent','partially_received') ORDER BY p.id DESC")]);
    }

    public function newGrn(): void
    {
        $poId = (int) input('po_id', 0);
        if (is_post()) {
            $lines = array_map(static function ($l) {
                $l['qty_received'] = $l['qty_received'] ?? ($l['qty'] ?? 0);
                return $l;
            }, array_values((array) ($_POST['lines'] ?? [])));
            $this->run(static fn () => InventoryService::saveGrn([
                'purchase_order_id' => $poId ?: null, 'vendor_id' => (int) input('vendor_id', 0), 'location_id' => (int) input('location_id', 0),
                'vendor_invoice_no' => (string) input('vendor_invoice_no', ''), 'vendor_invoice_date' => (string) input('vendor_invoice_date', ''), 'notes' => (string) input('notes', ''),
            ], $lines, input('post') === '1'), input('post') === '1' ? 'Goods received and posted to stock.' : 'GRN saved as draft.', 'inventory/grn-view', ['id' => '__id__']);
        }
        $po = $poId ? DB::one("SELECT p.*, v.name AS vendor_name FROM purchase_orders p JOIN vendors v ON v.id = p.vendor_id WHERE p.id = ? AND p.status IN ('approved','sent','partially_received')", [$poId]) : null;
        $this->view('inventory/grn_new', ['title' => 'Receive Goods (GRN)', 'po' => $po, 'scripts' => ['js/stock.js'],
            'poLines' => $po ? DB::all('SELECT x.*, i.name AS item_name, i.sku, i.is_batch_tracked, i.is_expiry_tracked, i.sale_price FROM purchase_order_items x JOIN items i ON i.id = x.item_id
                                          WHERE x.purchase_order_id = ? AND x.qty_received < x.qty_ordered', [$poId]) : [],
            'vendors' => DB::pairs("SELECT id, name FROM vendors WHERE deleted_at IS NULL AND is_active = 1 ORDER BY name"),
            'locations' => InventoryService::locations()]);
    }

    public function grn(): void
    {
        $id = $this->requireId();
        $g = DB::one('SELECT g.*, v.name AS vendor_name, sl.name AS location_name, p.po_number, p.created_by AS po_created_by, u.name AS received_by_name, pb.name AS posted_by_name
                        FROM goods_receipt_notes g JOIN vendors v ON v.id = g.vendor_id JOIN stock_locations sl ON sl.id = g.receiving_location_id
                        LEFT JOIN purchase_orders p ON p.id = g.purchase_order_id LEFT JOIN users u ON u.id = g.received_by LEFT JOIN users pb ON pb.id = g.posted_by WHERE g.id = ?', [$id]);
        if (!$g) {
            abort(404);
        }
        if (is_post()) {
            if (!can('inventory.grn.post')) {
                abort(403);
            }
            $this->run(static fn () => InventoryService::postGrn($id), 'GRN posted: batches created and stock received.', 'inventory/grn-view', ['id' => $id]);
        }
        $this->view('inventory/grn', ['title' => 'GRN ' . $g['grn_number'], 'g' => $g,
            'lines' => DB::all('SELECT x.*, i.name AS item_name, i.sku FROM grn_items x JOIN items i ON i.id = x.item_id WHERE x.grn_id = ?', [$id])]);
    }

    // ============================================================ transfers

    public function transfers(): void
    {
        if (is_post()) {
            if (input('receive')) {
                $received = [];
                foreach ((array) ($_POST['recv'] ?? []) as $lid => $v) {
                    $received[(int) $lid] = ['qty' => (float) ($v['qty'] ?? 0), 'reason' => (string) ($v['reason'] ?? '')];
                }
                $this->run(static fn () => InventoryService::receiveTransfer((int) input('receive'), $received), 'Transfer received.', 'inventory/transfers');
            }
            $this->run(static fn () => InventoryService::dispatchTransfer((int) input('from_location_id', 0), (int) input('to_location_id', 0), array_map(static fn ($l) => [
                'item_id' => (int) ($l['item_id'] ?? 0), 'batch_id' => (int) ($l['batch_id'] ?? 0), 'qty' => (float) ($l['qty'] ?? 0),
            ], array_values((array) ($_POST['lines'] ?? []))), (string) input('notes', '')), 'Transfer dispatched — the receiving location confirms it.', 'inventory/transfers');
        }
        $rows = DB::all('SELECT t.*, f.name AS from_name, tl.name AS to_name, u.name AS dispatched_by_name FROM stock_transfers t JOIN stock_locations f ON f.id = t.from_location_id
                          JOIN stock_locations tl ON tl.id = t.to_location_id LEFT JOIN users u ON u.id = t.dispatched_by ORDER BY t.status = \'in_transit\' DESC, t.id DESC LIMIT 100');
        foreach ($rows as &$r) {
            $r['lines'] = DB::all('SELECT x.*, i.name AS item_name, sb.batch_no FROM stock_transfer_items x JOIN items i ON i.id = x.item_id LEFT JOIN stock_batches sb ON sb.id = x.batch_id
                                    WHERE x.transfer_id = ?', [$r['id']]);
        }
        $this->view('inventory/transfers', ['title' => 'Stock Transfers', 'rows' => $rows, 'locations' => InventoryService::locations(), 'scripts' => ['js/stock.js']]);
    }

    // ========================================================== adjustments

    public function adjustments(): void
    {
        if (is_post()) {
            if (input('decide')) {
                if (!can('inventory.adjust.approve')) {
                    abort(403);
                }
                $this->run(static fn () => InventoryService::decideAdjustment((int) input('decide'), input('decision') === 'approve'),
                    'Adjustment ' . (input('decision') === 'approve' ? 'approved and posted.' : 'rejected.'), 'inventory/adjustments');
            }
            if (!can('inventory.adjust.propose') && !can('inventory.adjust.approve')) {
                abort(403);
            }
            $this->run(static fn () => InventoryService::proposeAdjustment((int) input('location_id', 0), (string) input('reason_code', ''), array_map(static fn ($l) => [
                'item_id' => (int) ($l['item_id'] ?? 0), 'batch_id' => (int) ($l['batch_id'] ?? 0), 'qty_signed' => (float) ($l['qty_signed'] ?? 0),
            ], array_values((array) ($_POST['lines'] ?? []))), (string) input('notes', '')), 'Adjustment proposed — another person must approve it.', 'inventory/adjustments');
        }
        $rows = DB::all('SELECT a.*, sl.name AS location_name, p.name AS proposed_by_name, ap.name AS approved_by_name FROM stock_adjustments a
                          JOIN stock_locations sl ON sl.id = a.location_id LEFT JOIN users p ON p.id = a.proposed_by LEFT JOIN users ap ON ap.id = a.approved_by
                         ORDER BY a.status = \'proposed\' DESC, a.id DESC LIMIT 100');
        foreach ($rows as &$r) {
            $r['lines'] = DB::all('SELECT x.*, i.name AS item_name, sb.batch_no FROM stock_adjustment_items x JOIN items i ON i.id = x.item_id LEFT JOIN stock_batches sb ON sb.id = x.batch_id
                                    WHERE x.adjustment_id = ?', [$r['id']]);
        }
        $this->view('inventory/adjustments', ['title' => 'Stock Adjustments', 'rows' => $rows, 'locations' => InventoryService::locations(), 'scripts' => ['js/stock.js']]);
    }

    // =============================================================== counts

    public function counts(): void
    {
        if (is_post() && input('location_id')) {
            $this->run(static fn () => InventoryService::startCount((int) input('location_id'), (string) input('count_type', 'full')),
                'Count sheet created (system quantities are hidden from counters).', 'inventory/count', ['id' => '__id__']);
        }
        $this->view('inventory/counts', ['title' => 'Stocktake', 'locations' => InventoryService::locations(),
            'rows' => DB::all('SELECT c.*, sl.name AS location_name, u.name AS created_by_name, (SELECT COUNT(*) FROM stock_count_lines x WHERE x.count_id = c.id) AS line_count,
                                      (SELECT COUNT(*) FROM stock_count_lines x WHERE x.count_id = c.id AND x.counted_qty IS NOT NULL) AS counted
                                 FROM stock_counts c JOIN stock_locations sl ON sl.id = c.location_id LEFT JOIN users u ON u.id = c.created_by ORDER BY c.id DESC LIMIT 60')]);
    }

    public function count(): void
    {
        $id = $this->requireId();
        $c = DB::one('SELECT c.*, sl.name AS location_name FROM stock_counts c JOIN stock_locations sl ON sl.id = c.location_id WHERE c.id = ?', [$id]);
        if (!$c) {
            abort(404);
        }
        if (is_post()) {
            $do = (string) input('do', 'save');
            $this->run(static function () use ($do, $id): void {
                if ($do === 'post') {
                    if (!can('inventory.count.post')) {
                        throw new \RuntimeException('Posting a count variance needs the Store Manager.');
                    }
                    InventoryService::postCount($id);
                    return;
                }
                InventoryService::saveCounts($id, (array) ($_POST['counted'] ?? []), $do === 'recount');
                if ($do === 'submit') {
                    InventoryService::submitCount($id);
                }
            }, $do === 'post' ? 'Variance posted to stock.' : ($do === 'submit' ? 'Count submitted for review.' : 'Counts saved.'), 'inventory/count', ['id' => $id]);
        }
        // Blind count: system quantities are shown only in review to the poster.
        $showSystem = $c['status'] !== 'counting' && can('inventory.count.post');
        $this->view('inventory/count', ['title' => 'Count ' . $c['count_number'], 'c' => $c, 'showSystem' => $showSystem,
            'lines' => DB::all('SELECT x.*, i.name AS item_name, i.sku, sb.batch_no, sb.expiry_date, u.name AS counted_by_name, r.bin_code FROM stock_count_lines x
                                  JOIN items i ON i.id = x.item_id LEFT JOIN stock_batches sb ON sb.id = x.batch_id LEFT JOIN users u ON u.id = x.counted_by
                                  LEFT JOIN item_location_rules r ON r.item_id = x.item_id AND r.location_id = ? WHERE x.count_id = ? ORDER BY r.bin_code, i.name', [$c['location_id'], $id])]);
    }
}
