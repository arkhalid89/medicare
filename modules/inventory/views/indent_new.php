<?php
/** @var array $mine @var array $stores */
use App\Core\View;

echo View::partial('page_header', [
    'title' => 'New Indent', 'description' => 'Request items for your department location from the store.',
    'breadcrumbs' => [['Store', url('inventory')], ['Indents', url('inventory/indents')], ['New']],
]);
?>
<?php if (!$mine): ?>
    <div class="alert alert-warning"><?= icon('alert') ?><div>No stock location is assigned to you. Ask the Store Manager to make you custodian of your department's location.</div></div>
<?php else: ?>
<form class="card" method="post" action="<?= e(url('inventory/indent-new')) ?>">
    <?= csrf_field() ?>
    <div class="card-body">
        <div class="form-grid mb-2">
            <div class="field col-4"><label>For location (requesting)</label><select class="input" name="from_location_id" id="in-from"><?php foreach ($mine as $id => $n): ?><option value="<?= $id ?>"><?= e($n) ?></option><?php endforeach; ?></select></div>
            <div class="field col-4"><label>From store</label><select class="input" name="to_location_id" id="in-to"><?php foreach ($stores as $id => $n): ?><option value="<?= $id ?>"><?= e($n) ?></option><?php endforeach; ?></select></div>
            <div class="field col-4"><label>Priority</label><select class="input" name="priority"><option value="normal">Normal</option><option value="urgent">Urgent</option></select></div>
            <div class="field col-12"><label>Notes</label><input class="input" name="notes" maxlength="500"></div>
        </div>
        <div class="search-box mb-2"><?= icon('plus') ?><input class="input" data-item-search placeholder="Add item (shows quantity in the store)…" autocomplete="off"><div class="search-results"></div></div>
        <table class="table" id="in-lines"><thead><tr><th>Item</th><th>Qty</th><th>Note</th><th></th></tr></thead><tbody></tbody></table>
    </div>
    <div class="card-footer"><button class="btn btn-outline" name="submit" value="draft">Save draft</button><button class="btn btn-primary" name="submit" value="1"><?= icon('send') ?> Send to Store</button></div>
</form>
<script>document.addEventListener('DOMContentLoaded', function () {
    APP.itemLines(document.getElementById('in-lines'), { columns: ['qty', 'note'], location: function () { return document.getElementById('in-to').value; } });
});</script>
<?php endif; ?>
