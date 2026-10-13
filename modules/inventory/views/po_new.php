<?php
/** @var array $vendors @var array $locations @var array $suggest */
use App\Core\View;

echo View::partial('page_header', [
    'title' => 'New Purchase Order', 'description' => 'Items, quantities and agreed prices. Last purchase prices are suggested.',
    'breadcrumbs' => [['Store', url('inventory')], ['Purchase Orders', url('inventory/po')], ['New']],
]);
?>
<form class="card" method="post" action="<?= e(url('inventory/po-new')) ?>">
    <?= csrf_field() ?>
    <div class="card-body">
        <div class="form-grid mb-2">
            <div class="field col-4"><label>Vendor</label><select class="input" name="vendor_id" required><option value="">Select…</option><?php foreach ($vendors as $id => $n): ?><option value="<?= $id ?>"><?= e($n) ?></option><?php endforeach; ?></select></div>
            <div class="field col-4"><label>Deliver to</label><select class="input" name="location_id" id="po-loc"><?php foreach ($locations as $id => $n): ?><option value="<?= $id ?>"><?= e($n) ?></option><?php endforeach; ?></select></div>
            <div class="field col-4"><label>Expected date</label><input class="input" type="date" name="expected_date"></div>
            <div class="field col-12"><label>Notes / terms</label><input class="input" name="notes" maxlength="500"></div>
        </div>
        <div class="flex mb-2"><div class="search-box" style="flex:1"><?= icon('plus') ?><input class="input" data-item-search placeholder="Add item…" autocomplete="off"><div class="search-results"></div></div>
            <?php if ($suggest): ?><button type="button" class="btn btn-outline" id="po-suggest"><?= icon('alert') ?> Add <?= count($suggest) ?> reorder suggestion(s)</button><?php endif; ?></div>
        <table class="table" id="po-lines"><thead><tr><th>Item</th><th>Qty</th><th>Unit price</th><th>Tax %</th><th class="num">Amount</th><th></th></tr></thead><tbody></tbody>
            <tfoot><tr><td colspan="4" class="text-right"><strong>Total (before tax)</strong></td><td class="num"><strong data-total>0</strong></td><td></td></tr></tfoot></table>
    </div>
    <div class="card-footer"><button class="btn btn-primary"><?= icon('save') ?> Create PO</button></div>
</form>
<script>document.addEventListener('DOMContentLoaded', function () {
    var ed = APP.itemLines(document.getElementById('po-lines'), { columns: ['qty', 'price', 'tax'], amount: true, priceField: 'last_price',
        location: function () { return document.getElementById('po-loc').value; } });
    var s = document.getElementById('po-suggest');
    if (s) s.addEventListener('click', function () {
        <?= json_encode(array_map(static fn ($x) => ['id' => (int) $x['item_id'], 'name' => $x['item_name'], 'sku' => $x['sku'], 'qty' => (float) ($x['reorder_qty'] ?: max(1, (float) $x['min_qty'] - (float) $x['on_hand'])), 'price' => 0], $suggest)) ?>
            .forEach(function (it) { ed.add(it); });
        s.disabled = true;
    });
});</script>
