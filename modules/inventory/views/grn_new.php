<?php
/** @var array|null $po @var array $poLines @var array $vendors @var array $locations */
use App\Core\View;

echo View::partial('page_header', [
    'title' => $po ? 'Receive PO ' . $po['po_number'] : 'Direct Goods Receipt', 'description' => 'Enter every batch with its expiry. Expiry is mandatory for expiry-tracked items; rejected quantities are not stocked.',
    'breadcrumbs' => [['Store', url('inventory')], ['GRN', url('inventory/grn')], ['New']],
]);
?>
<form class="card" method="post" action="<?= e(url('inventory/grn-new', ['po_id' => $po['id'] ?? null])) ?>">
    <?= csrf_field() ?>
    <div class="card-body">
        <div class="form-grid mb-2">
            <div class="field col-3"><label>Vendor</label>
                <?php if ($po): ?><input class="input" value="<?= e($po['vendor_name']) ?>" readonly><input type="hidden" name="vendor_id" value="<?= (int) $po['vendor_id'] ?>">
                <?php else: ?><select class="input" name="vendor_id" required><option value="">Select…</option><?php foreach ($vendors as $id => $n): ?><option value="<?= $id ?>"><?= e($n) ?></option><?php endforeach; ?></select><?php endif; ?></div>
            <div class="field col-3"><label>Receiving location</label><select class="input" name="location_id" id="grn-loc"><?php foreach ($locations as $id => $n): ?><option value="<?= $id ?>"<?= selected($id, $po['location_id'] ?? '') ?>><?= e($n) ?></option><?php endforeach; ?></select></div>
            <div class="field col-3"><label>Vendor invoice no.</label><input class="input" name="vendor_invoice_no" maxlength="60"></div>
            <div class="field col-3"><label>Invoice date</label><input class="input" type="date" name="vendor_invoice_date"></div>
            <div class="field col-12"><label>Notes / inspection</label><input class="input" name="notes" maxlength="500"></div>
        </div>
        <?php if (!$po): ?><div class="search-box mb-2"><?= icon('plus') ?><input class="input" data-item-search placeholder="Add item…" autocomplete="off"><div class="search-results"></div></div><?php endif; ?>
        <div class="table-wrap"><table class="table" id="grn-lines"><thead><tr><th>Item</th><th>Received</th><th>Bonus</th><th>Rejected</th><th>Batch</th><th>Expiry</th><th>Unit cost</th><th>MRP</th><th>Reject reason</th><th></th></tr></thead><tbody>
            <?php foreach ($poLines as $i => $l): $left = (float) $l['qty_ordered'] - (float) $l['qty_received']; ?>
                <tr><td><strong><?= e($l['item_name']) ?></strong><div class="muted small">ordered <?= e(num($l['qty_ordered'])) ?>, received <?= e(num($l['qty_received'])) ?></div>
                    <input type="hidden" name="lines[p<?= $i ?>][item_id]" value="<?= (int) $l['item_id'] ?>"><input type="hidden" name="lines[p<?= $i ?>][po_item_id]" value="<?= (int) $l['id'] ?>"></td>
                    <td><input class="input" type="number" step="0.001" min="0" max="<?= e((string) $left) ?>" name="lines[p<?= $i ?>][qty_received]" value="<?= e(num($left)) ?>" style="width:100px"></td>
                    <td><input class="input" type="number" step="0.001" min="0" name="lines[p<?= $i ?>][bonus_qty]" value="0" style="width:80px"></td>
                    <td><input class="input" type="number" step="0.001" min="0" name="lines[p<?= $i ?>][qty_rejected]" value="0" style="width:80px"></td>
                    <td><input class="input" name="lines[p<?= $i ?>][batch_no]" maxlength="60"<?= (int) $l['is_batch_tracked'] ? ' required' : '' ?> style="width:120px"></td>
                    <td><input class="input" type="date" name="lines[p<?= $i ?>][expiry_date]"<?= (int) $l['is_expiry_tracked'] ? ' required' : '' ?>></td>
                    <td><input class="input" type="number" step="0.01" min="0" name="lines[p<?= $i ?>][unit_cost]" value="<?= e(num($l['unit_price'])) ?>" style="width:100px"></td>
                    <td><input class="input" type="number" step="0.01" min="0" name="lines[p<?= $i ?>][mrp]" value="<?= e(num($l['sale_price'])) ?>" style="width:90px"></td>
                    <td><input class="input" name="lines[p<?= $i ?>][reject_reason]" maxlength="255"></td><td></td></tr>
            <?php endforeach; ?>
        </tbody></table></div>
    </div>
    <div class="card-footer"><button class="btn btn-outline" name="post" value="0">Save draft</button><button class="btn btn-primary" name="post" value="1"><?= icon('check') ?> Post GRN to stock</button></div>
</form>
<?php if (!$po): ?>
<script>document.addEventListener('DOMContentLoaded', function () {
    APP.itemLines(document.getElementById('grn-lines'), { columns: ['qty', 'bonus', 'rejected', 'batch', 'expiry', 'cost', 'mrp', 'reason'], location: function () { return document.getElementById('grn-loc').value; } });
});</script>
<?php endif; ?>
