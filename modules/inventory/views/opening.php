<?php
/** @var array $locations @var int $loc */
use App\Core\View;

echo View::partial('page_header', [
    'title' => 'Opening Stock', 'description' => 'Go-live balances per location: item, batch, expiry, quantity and cost. Each line becomes an "opening" ledger entry.',
    'breadcrumbs' => [['Store', url('inventory')], ['Opening stock']],
]);
?>
<form class="card" method="post" action="<?= e(url('inventory/opening')) ?>">
    <?= csrf_field() ?>
    <div class="card-body">
        <div class="form-grid mb-2"><div class="field col-6"><label>Location</label><select class="input" name="location_id" id="op-loc" required>
            <?php foreach ($locations as $id => $n): ?><option value="<?= $id ?>"<?= selected($id, $loc) ?>><?= e($n) ?></option><?php endforeach; ?></select></div></div>
        <div class="search-box mb-2"><?= icon('plus') ?><input class="input" data-item-search placeholder="Add item…" autocomplete="off"><div class="search-results"></div></div>
        <table class="table" id="op-lines"><thead><tr><th>Item</th><th>Qty</th><th>Batch</th><th>Expiry</th><th>Unit cost</th><th></th></tr></thead><tbody></tbody></table>
    </div>
    <div class="card-footer"><button class="btn btn-primary"><?= icon('save') ?> Post Opening Stock</button></div>
</form>
<script>document.addEventListener('DOMContentLoaded', function () {
    APP.itemLines(document.getElementById('op-lines'), { columns: ['qty', 'batch', 'expiry', 'cost'], location: function () { return document.getElementById('op-loc').value; } });
});</script>
