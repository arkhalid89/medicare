<?php
declare(strict_types=1);

namespace App\Modules\Inventory;

use App\Core\ActivityLog;
use App\Core\Auth;
use App\Core\Controller;
use App\Core\DB;
use App\Core\Paginator;

/** Store dashboard, stock on hand, stock cards, items, opening stock, expiry & recalls, assets. */
final class InventoryController extends Controller
{
    public function dashboard(): void
    {
        $loc = (int) input('location_id', 0) ?: null;
        $lw = $loc ? ' AND b.location_id = ' . $loc : '';
        $tiles = [
            ['Pending indents', 'send', (int) DB::value("SELECT COUNT(*) FROM stock_requisitions WHERE status = 'submitted'"), url('inventory/indents', ['status' => 'submitted'])],
            ['Indents to issue', 'box', (int) DB::value("SELECT COUNT(*) FROM stock_requisitions WHERE status IN ('approved','partially_issued')"), url('inventory/indents', ['status' => 'approved'])],
            ['Below reorder level', 'alert', count(self::lowStock($loc)), url('inventory/stock', ['low' => 1, 'location_id' => $loc])],
            ['Expiring in 90 days', 'clock', (int) DB::value("SELECT COUNT(DISTINCT b.batch_id, b.location_id) FROM stock_balances b JOIN stock_batches sb ON sb.id = b.batch_id
                                                              WHERE b.qty_on_hand > 0 AND sb.expiry_date IS NOT NULL AND sb.expiry_date <= ?" . $lw, [date('Y-m-d', strtotime('+90 days'))]), url('inventory/expiry')],
            ['Transfers in transit', 'truck', (int) DB::value("SELECT COUNT(*) FROM stock_transfers WHERE status = 'in_transit'"), url('inventory/transfers')],
            ['Open purchase orders', 'file-text', (int) DB::value("SELECT COUNT(*) FROM purchase_orders WHERE status IN ('submitted','approved','sent','partially_received')"), url('inventory/po')],
            ['Adjustments to approve', 'sliders', (int) DB::value("SELECT COUNT(*) FROM stock_adjustments WHERE status = 'proposed'"), url('inventory/adjustments')],
            ['Stock value', 'money', null, url('reports/stock')],
        ];
        $value = (float) DB::value('SELECT COALESCE(SUM(b.qty_on_hand * COALESCE(NULLIF(sb.unit_cost, 0), i.avg_cost)), 0) FROM stock_balances b JOIN items i ON i.id = b.item_id
                                     LEFT JOIN stock_batches sb ON sb.id = b.batch_id WHERE b.qty_on_hand > 0' . $lw);
        $this->view('inventory/dashboard', [
            'title'     => 'Store Dashboard',
            'tiles'     => $tiles,
            'value'     => $value,
            'locations' => InventoryService::locations(),
            'loc'       => $loc,
            'low'       => array_slice(self::lowStock($loc), 0, 12),
            'recent'    => DB::all('SELECT l.*, i.name AS item_name, sl.name AS location_name, u.name AS user_name FROM stock_ledger l JOIN items i ON i.id = l.item_id
                                     JOIN stock_locations sl ON sl.id = l.location_id LEFT JOIN users u ON u.id = l.performed_by' . ($loc ? ' WHERE l.location_id = ' . $loc : '') . ' ORDER BY l.id DESC LIMIT 15'),
        ]);
    }

    /** Items under their minimum at a location (reorder suggestions). */
    public static function lowStock(?int $locationId = null): array
    {
        $params = [];
        $w = '';
        if ($locationId) {
            $w = ' AND r.location_id = ?';
            $params[] = $locationId;
        }
        return DB::all(
            'SELECT r.*, i.name AS item_name, i.sku, sl.name AS location_name,
                    COALESCE((SELECT SUM(b.qty_on_hand) FROM stock_balances b WHERE b.item_id = r.item_id AND b.location_id = r.location_id), 0) AS on_hand
               FROM item_location_rules r JOIN items i ON i.id = r.item_id JOIN stock_locations sl ON sl.id = r.location_id
              WHERE r.min_qty > 0' . $w . '
             HAVING on_hand < r.min_qty ORDER BY (on_hand / r.min_qty), i.name',
            $params
        );
    }

    public function stock(): void
    {
        $loc = (int) input('location_id', 0);
        $q = trim((string) input('q', ''));
        $where = 'b.qty_on_hand <> 0';
        $params = [];
        if ($loc) {
            $where .= ' AND b.location_id = ?';
            $params[] = $loc;
        }
        if ($q !== '') {
            $where .= ' AND (i.name LIKE ? OR i.sku LIKE ? OR sb.batch_no LIKE ?)';
            $like = '%' . like_escape($q) . '%';
            array_push($params, $like, $like, $like);
        }
        if (input('low')) {
            $low = self::lowStock($loc ?: null);
            $this->view('inventory/low', ['title' => 'Below Reorder Level', 'rows' => $low, 'locations' => InventoryService::locations(), 'loc' => $loc]);
            return;
        }
        $base = ' FROM stock_balances b JOIN items i ON i.id = b.item_id JOIN stock_locations sl ON sl.id = b.location_id LEFT JOIN stock_batches sb ON sb.id = b.batch_id WHERE ' . $where;
        $select = 'SELECT b.*, i.name AS item_name, i.sku, i.item_type, i.is_controlled, sl.name AS location_name, sb.batch_no, sb.expiry_date, sb.status AS batch_status,
                          COALESCE(NULLIF(sb.unit_cost, 0), i.avg_cost) AS cost';
        $order = ' ORDER BY i.name, sl.name, sb.expiry_date';
        if ($this->wantsExport()) {
            $this->export('stock_on_hand', 'Stock on Hand', [
                'item_name' => 'Item', 'sku' => 'SKU', 'location_name' => 'Location', 'batch_no' => 'Batch', 'expiry_date' => ['Expiry', static fn ($r) => fmt_date($r['expiry_date'])],
                'batch_status' => 'Status', 'qty_on_hand' => ['Qty', static fn ($r) => (float) $r['qty_on_hand']], 'cost' => ['Unit cost', static fn ($r) => (float) $r['cost']],
                'value' => ['Value', static fn ($r) => round((float) $r['qty_on_hand'] * (float) $r['cost'], 2)],
            ], DB::run($select . $base . $order, $params), ['Location' => $loc ? (string) DB::value('SELECT name FROM stock_locations WHERE id = ?', [$loc]) : 'All', 'Search' => $q]);
            return;
        }
        $pager = new Paginator((int) DB::value('SELECT COUNT(*)' . $base, $params), 50);
        $this->view('inventory/stock', ['title' => 'Stock on Hand', 'rows' => DB::all($select . $base . $order . $pager->limitSql(), $params), 'pager' => $pager,
            'locations' => InventoryService::locations(), 'loc' => $loc, 'q' => $q]);
    }

    /** Stock card: ledger lines with running balance and links to the source documents. */
    public function card(): void
    {
        $itemId = $this->requireId('item_id');
        $item = DB::one('SELECT * FROM items WHERE id = ?', [$itemId]);
        if (!$item) {
            abort(404);
        }
        $loc = (int) input('location_id', 0);
        $params = [$itemId];
        $w = '';
        if ($loc) {
            $w = ' AND l.location_id = ?';
            $params[] = $loc;
        }
        $rows = DB::all('SELECT l.*, sl.name AS location_name, sb.batch_no, sb.expiry_date, u.name AS user_name FROM stock_ledger l JOIN stock_locations sl ON sl.id = l.location_id
                          LEFT JOIN stock_batches sb ON sb.id = l.batch_id LEFT JOIN users u ON u.id = l.performed_by WHERE l.item_id = ?' . $w . ' ORDER BY l.id', $params);
        $running = 0.0;
        foreach ($rows as &$r) {
            $running += (float) $r['qty_signed'];
            $r['running'] = $running;
        }
        $this->view('inventory/card', [
            'title' => 'Stock Card — ' . $item['name'], 'item' => $item, 'rows' => array_reverse($rows), 'loc' => $loc, 'locations' => InventoryService::locations(),
            'balances' => DB::all('SELECT b.*, sl.name AS location_name, sb.batch_no, sb.expiry_date, sb.status FROM stock_balances b JOIN stock_locations sl ON sl.id = b.location_id
                                   LEFT JOIN stock_batches sb ON sb.id = b.batch_id WHERE b.item_id = ? AND b.qty_on_hand <> 0 ORDER BY sl.name, sb.expiry_date', [$itemId]),
        ]);
    }

    // ---------------------------------------------------------------- items

    public function items(): void
    {
        $q = trim((string) input('q', ''));
        $type = (string) input('type', '');
        $where = 'i.deleted_at IS NULL';
        $params = [];
        if ($q !== '') {
            $where .= ' AND (i.name LIKE ? OR i.sku LIKE ? OR i.barcode = ?)';
            array_push($params, '%' . like_escape($q) . '%', like_escape($q) . '%', $q);
        }
        if ($type !== '') {
            $where .= ' AND i.item_type = ?';
            $params[] = $type;
        }
        $base = ' FROM items i LEFT JOIN item_categories c ON c.id = i.category_id LEFT JOIN uoms u ON u.id = i.base_uom_id WHERE ' . $where;
        $select = 'SELECT i.*, c.name AS category_name, u.code AS uom, (SELECT COALESCE(SUM(qty_on_hand), 0) FROM stock_balances b WHERE b.item_id = i.id) AS on_hand';
        if ($this->wantsExport()) {
            $this->export('items', 'Items', ['sku' => 'SKU', 'name' => 'Item', 'item_type' => 'Type', 'category_name' => 'Category', 'uom' => 'Unit',
                'on_hand' => ['On hand', static fn ($r) => (float) $r['on_hand']], 'avg_cost' => ['Avg cost', static fn ($r) => (float) $r['avg_cost']],
                'sale_price' => ['Sale price', static fn ($r) => (float) $r['sale_price']], 'is_controlled' => ['Controlled', static fn ($r) => $r['is_controlled'] ? 'Yes' : '']],
                DB::run($select . $base . ' ORDER BY i.name', $params));
            return;
        }
        $pager = new Paginator((int) DB::value('SELECT COUNT(*)' . $base, $params), 50);
        $this->view('inventory/items', ['title' => 'Items', 'rows' => DB::all($select . $base . ' ORDER BY i.name' . $pager->limitSql(), $params), 'pager' => $pager,
            'types' => DB::pairs('SELECT DISTINCT item_type, item_type FROM items ORDER BY item_type')]);
    }

    public function itemForm(): void
    {
        $id = (int) input('id', 0);
        $item = $id ? DB::one('SELECT * FROM items WHERE id = ? AND deleted_at IS NULL', [$id]) : null;
        if ($id && !$item) {
            abort(404);
        }
        $errors = [];
        if (is_post()) {
            if (!can('inventory.items.manage')) {
                abort(403);
            }
            $name = trim((string) input('name', ''));
            $sku = strtoupper(trim((string) input('sku', '')));
            if ($name === '') {
                $errors[] = 'Item name is required.';
            }
            if ($sku !== '' && DB::value('SELECT 1 FROM items WHERE sku = ? AND id <> ?', [$sku, $id])) {
                $errors[] = 'SKU ' . $sku . ' is already used.';
            }
            if (!$errors) {
                $data = [
                    'sku' => $sku ?: null, 'name' => mb_substr($name, 0, 200), 'category_id' => (int) input('category_id', 0) ?: null,
                    'item_type' => (string) (DB::value('SELECT type FROM item_categories WHERE id = ?', [(int) input('category_id', 0)]) ?: input('item_type', 'medical_consumable')),
                    'base_uom_id' => (int) input('base_uom_id', 0) ?: null, 'barcode' => mb_substr((string) input('barcode', ''), 0, 60) ?: null,
                    'is_batch_tracked' => input('is_batch_tracked') ? 1 : 0, 'is_expiry_tracked' => input('is_expiry_tracked') ? 1 : 0,
                    'is_controlled' => input('is_controlled') ? 1 : 0, 'is_cold_chain' => input('is_cold_chain') ? 1 : 0,
                    'shelf_life_days' => (int) input('shelf_life_days', 0) ?: null, 'standard_cost' => (float) input('standard_cost', 0),
                    'sale_price' => (float) input('sale_price', 0), 'is_active' => input('is_active') ? 1 : 0,
                ];
                if ($item) {
                    DB::update('items', $data + ['updated_at' => now(), 'updated_by' => Auth::id()], 'id = ?', [$id]);
                    [$o, $n] = ActivityLog::diff($item, $data);
                    ActivityLog::record('Item updated', 'inventory', (string) $id, $name, null, null, $n ? ['entity' => 'items', 'old' => $o, 'new' => $n] : []);
                } else {
                    $id = DB::insert('items', $data + ['avg_cost' => $data['standard_cost'], 'created_at' => now(), 'created_by' => Auth::id()]);
                    if (!$sku) {
                        DB::update('items', ['sku' => 'ITM-' . str_pad((string) $id, 5, '0', STR_PAD_LEFT)], 'id = ?', [$id]);
                    }
                    ActivityLog::record('Item created', 'inventory', (string) $id, $name);
                }
                foreach ((array) ($_POST['rules'] ?? []) as $locId => $r) {
                    $min = (float) ($r['min'] ?? 0);
                    $reorder = (float) ($r['reorder'] ?? 0);
                    $max = (float) ($r['max'] ?? 0);
                    $bin = mb_substr(trim((string) ($r['bin'] ?? '')), 0, 30);
                    if ($min <= 0 && $reorder <= 0 && $max <= 0 && $bin === '') {
                        DB::run('DELETE FROM item_location_rules WHERE item_id = ? AND location_id = ?', [$id, (int) $locId]);
                        continue;
                    }
                    DB::run('INSERT INTO item_location_rules (item_id, location_id, min_qty, reorder_qty, max_qty, bin_code) VALUES (?, ?, ?, ?, ?, ?)
                             ON DUPLICATE KEY UPDATE min_qty = VALUES(min_qty), reorder_qty = VALUES(reorder_qty), max_qty = VALUES(max_qty), bin_code = VALUES(bin_code)',
                        [$id, (int) $locId, $min, $reorder, $max, $bin ?: null]);
                }
                flash('success', 'Item saved.');
                redirect('inventory/item', ['id' => $id]);
            }
        }
        $this->view('inventory/item_form', [
            'title' => $item ? 'Item — ' . $item['name'] : 'New Item', 'item' => $item, 'errors' => $errors,
            'categories' => DB::pairs('SELECT id, name FROM item_categories WHERE deleted_at IS NULL ORDER BY name'),
            'uoms' => DB::pairs('SELECT id, name FROM uoms WHERE deleted_at IS NULL ORDER BY display_order'),
            'locations' => InventoryService::locations(),
            'rules' => $id ? DB::all('SELECT * FROM item_location_rules WHERE item_id = ?', [$id]) : [],
            'vendors' => $id ? DB::all('SELECT vi.*, v.name AS vendor_name FROM vendor_items vi JOIN vendors v ON v.id = vi.vendor_id WHERE vi.item_id = ? ORDER BY vi.last_bought_at DESC', [$id]) : [],
        ]);
    }

    /** Opening balances at go-live (Store Manager): one 'opening' ledger row per batch. */
    public function opening(): void
    {
        if (is_post()) {
            $loc = (int) input('location_id', 0);
            $n = 0;
            try {
                DB::transaction(static function () use ($loc, &$n): void {
                    foreach ((array) ($_POST['lines'] ?? []) as $l) {
                        $itemId = (int) ($l['item_id'] ?? 0);
                        $qty = (float) ($l['qty'] ?? 0);
                        if ($itemId <= 0 || $qty <= 0) {
                            continue;
                        }
                        $cost = (float) ($l['unit_cost'] ?? 0);
                        $batchId = StockService::batchFor($itemId, (string) ($l['batch_no'] ?? ''), ($l['expiry_date'] ?? '') ?: null, ['unit_cost' => $cost]);
                        StockService::move($itemId, $loc, $qty, 'opening', ['batch_id' => $batchId, 'unit_cost' => $cost, 'reference_type' => 'opening', 'note' => 'Opening balance']);
                        StockService::updateAverageCost($itemId, $qty, $cost);
                        $n++;
                    }
                });
                ActivityLog::record('Opening stock', 'inventory', (string) $loc, $n . ' line(s)');
                flash('success', $n . ' opening balance line(s) posted.');
            } catch (\Throwable $e) {
                flash('error', $e->getMessage());
            }
            redirect('inventory/opening', ['location_id' => $loc]);
        }
        $this->view('inventory/opening', ['title' => 'Opening Stock', 'locations' => InventoryService::locations(), 'loc' => (int) input('location_id', 0), 'scripts' => ['js/stock.js']]);
    }

    // ----------------------------------------------------------- JSON APIs

    public function itemSearch(): void
    {
        $q = trim((string) input('q', ''));
        $loc = (int) input('location_id', 0);
        $params = [];
        $where = 'i.deleted_at IS NULL AND i.is_active = 1';
        if ($q !== '') {
            $where .= ' AND (i.name LIKE ? OR i.sku LIKE ? OR i.barcode = ?)';
            array_push($params, '%' . like_escape($q) . '%', like_escape($q) . '%', $q);
        }
        $types = array_filter(explode(',', (string) input('types', '')));
        if ($types) {
            $where .= ' AND i.item_type IN (' . DB::placeholders($types) . ')';
            array_push($params, ...$types);
        }
        $rows = DB::all('SELECT i.id, i.sku, i.name, i.item_type, i.sale_price, i.avg_cost, i.is_batch_tracked, i.is_expiry_tracked, i.is_controlled, i.medicine_id,
                                (SELECT vi.last_price FROM vendor_items vi WHERE vi.item_id = i.id ORDER BY vi.last_bought_at DESC LIMIT 1) AS last_price
                           FROM items i WHERE ' . $where . ' ORDER BY i.name LIMIT 25', $params);
        foreach ($rows as &$r) {
            $r['on_hand'] = $loc ? StockService::available((int) $r['id'], $loc) : null;
            foreach (['sale_price', 'avg_cost', 'last_price'] as $k) {
                $r[$k] = $r[$k] === null ? null : (float) $r[$k];
            }
        }
        $this->json(['ok' => true, 'items' => $rows]);
    }

    public function batches(): void
    {
        $rows = DB::all("SELECT b.batch_id, b.qty_on_hand AS qty, sb.batch_no, sb.expiry_date, sb.status,
                                (sb.status IS NOT NULL AND (sb.status <> 'active' OR (sb.expiry_date IS NOT NULL AND sb.expiry_date < ?))) AS blocked
                           FROM stock_balances b LEFT JOIN stock_batches sb ON sb.id = b.batch_id
                          WHERE b.item_id = ? AND b.location_id = ? AND b.qty_on_hand > 0 ORDER BY sb.expiry_date IS NULL, sb.expiry_date",
            [today(), (int) input('item_id', 0), (int) input('location_id', 0)]);
        foreach ($rows as &$r) {
            $r['qty'] = (float) $r['qty'];
            $r['blocked'] = (bool) $r['blocked'];
        }
        $this->json(['ok' => true, 'items' => $rows]);
    }

    // ---------------------------------------------------- expiry & recalls

    public function expiry(): void
    {
        $this->view('inventory/expiry', [
            'title' => 'Expiry Watchlist & Recalls',
            'rows' => DB::all("SELECT b.location_id, b.batch_id, b.qty_on_hand, sb.batch_no, sb.expiry_date, sb.status, i.name AS item_name, i.id AS item_id, sl.name AS location_name,
                                      DATEDIFF(sb.expiry_date, CURDATE()) AS days
                                 FROM stock_balances b JOIN stock_batches sb ON sb.id = b.batch_id JOIN items i ON i.id = b.item_id JOIN stock_locations sl ON sl.id = b.location_id
                                WHERE b.qty_on_hand > 0 AND sb.expiry_date IS NOT NULL AND sb.expiry_date <= ? ORDER BY sb.expiry_date",
                [date('Y-m-d', strtotime('+' . max(30, (int) setting('stock_expiry_alert_days', 90)) . ' days'))]),
            'recalls' => DB::all('SELECT r.*, i.name AS item_name FROM recall_notices r JOIN items i ON i.id = r.item_id ORDER BY r.id DESC LIMIT 30'),
            'patients' => [],
        ]);
    }

    public function recall(): void
    {
        $itemId = (int) input('item_id', 0);
        $batch = trim((string) input('batch_no', ''));
        if ($itemId <= 0 || $batch === '') {
            flash('error', 'Choose the item and enter the recalled batch number.');
            redirect('inventory/expiry');
        }
        $patients = InventoryService::recall($itemId, $batch, (string) input('source', 'DRAP'), (string) input('reference', ''), (string) input('notes', ''));
        flash('warning', 'Batch ' . $batch . ' recalled and moved to quarantine. ' . count($patients) . ' patient(s) received it.');
        $this->view('inventory/expiry', [
            'title' => 'Expiry Watchlist & Recalls', 'rows' => [], 'patients' => $patients,
            'recalls' => DB::all('SELECT r.*, i.name AS item_name FROM recall_notices r JOIN items i ON i.id = r.item_id ORDER BY r.id DESC LIMIT 30'),
        ]);
    }

    // -------------------------------------------------------------- assets

    public function assets(): void
    {
        if (is_post()) {
            if (input('maintenance_for')) {
                $aid = (int) input('maintenance_for');
                DB::insert('asset_maintenance_log', ['asset_id' => $aid, 'type' => in_array(input('type'), ['preventive', 'repair', 'calibration'], true) ? input('type') : 'preventive',
                    'performed_on' => (string) input('performed_on', today()), 'cost' => (float) input('cost', 0) ?: null, 'next_due' => input('next_due') ?: null,
                    'vendor_id' => (int) input('vendor_id', 0) ?: null, 'notes' => mb_substr((string) input('notes', ''), 0, 500) ?: null, 'created_by' => Auth::id(), 'created_at' => now()]);
                if (input('next_due')) {
                    DB::update('assets', ['next_maintenance' => input('next_due')], 'id = ?', [$aid]);
                }
                flash('success', 'Maintenance recorded.');
            } else {
                $name = trim((string) input('name', ''));
                if ($name !== '') {
                    DB::insert('assets', ['branch_id' => Auth::branchId(), 'name' => mb_substr($name, 0, 150), 'asset_tag' => mb_substr((string) input('asset_tag', ''), 0, 40) ?: null,
                        'serial_no' => mb_substr((string) input('serial_no', ''), 0, 80) ?: null, 'location_id' => (int) input('location_id', 0) ?: null,
                        'vendor_id' => (int) input('vendor_id', 0) ?: null, 'purchase_date' => input('purchase_date') ?: null, 'cost' => (float) input('cost', 0) ?: null,
                        'warranty_until' => input('warranty_until') ?: null, 'next_maintenance' => input('next_maintenance') ?: null, 'status' => 'in_use',
                        'is_active' => 1, 'created_at' => now(), 'created_by' => Auth::id()]);
                    flash('success', 'Asset registered.');
                }
            }
            redirect('inventory/assets');
        }
        $this->view('inventory/assets', [
            'title' => 'Assets & Maintenance',
            'rows' => DB::all('SELECT a.*, sl.name AS location_name, v.name AS vendor_name, (SELECT MAX(performed_on) FROM asset_maintenance_log m WHERE m.asset_id = a.id) AS last_service
                                 FROM assets a LEFT JOIN stock_locations sl ON sl.id = a.location_id LEFT JOIN vendors v ON v.id = a.vendor_id WHERE a.deleted_at IS NULL ORDER BY a.next_maintenance IS NULL, a.next_maintenance'),
            'locations' => InventoryService::locations(),
            'vendors' => DB::pairs('SELECT id, name FROM vendors WHERE deleted_at IS NULL AND is_active = 1 ORDER BY name'),
        ]);
    }
}
