<?php
/** @var array $rows @var array $locations */
use App\Core\Auth;
use App\Core\View;

echo View::partial('page_header', [
    'title' => 'Stock Transfers', 'description' => 'Location to location (ward stock, OT, lab, crash cart). Stock leaves on dispatch and arrives when the receiving location confirms.',
    'breadcrumbs' => [['Store', url('inventory')], ['Transfers']],
]);
?>
<form class="card" method="post" action="<?= e(url('inventory/transfers')) ?>">
    <?= csrf_field() ?>
    <div class="card-header"><div class="card-title"><?= icon('send') ?> New transfer</div></div>
    <div class="card-body">
        <div class="form-grid mb-2">
            <div class="field col-4"><label>From</label><select class="input" name="from_location_id" id="tr-from"><?php foreach ($locations as $id => $n): ?><option value="<?= $id ?>"><?= e($n) ?></option><?php endforeach; ?></select></div>
            <div class="field col-4"><label>To</label><select class="input" name="to_location_id"><?php foreach ($locations as $id => $n): ?><option value="<?= $id ?>"><?= e($n) ?></option><?php endforeach; ?></select></div>
            <div class="field col-4"><label>Notes</label><input class="input" name="notes" maxlength="500"></div>
        </div>
        <div class="search-box mb-2"><?= icon('plus') ?><input class="input" data-item-search placeholder="Add item…" autocomplete="off"><div class="search-results"></div></div>
        <table class="table" id="tr-lines"><thead><tr><th>Item</th><th>Qty</th><th>Batch</th><th></th></tr></thead><tbody></tbody></table>
    </div>
    <div class="card-footer"><button class="btn btn-primary"><?= icon('truck') ?> Dispatch</button></div>
</form>
<div class="card card-flush"><div class="card-header"><div class="card-title"><?= icon('list') ?> Transfers</div></div><div class="card-body table-wrap"><table class="table">
    <thead><tr><th>Transfer</th><th>From → To</th><th>Items</th><th>Status</th><th></th></tr></thead><tbody>
    <?php foreach ($rows as $t): ?>
        <tr><td><strong><?= e($t['transfer_number']) ?></strong><div class="muted small"><?= e(fmt_datetime($t['dispatched_at'])) ?> · <?= e($t['dispatched_by_name']) ?></div></td>
            <td><?= e($t['from_name']) ?> → <?= e($t['to_name']) ?></td>
            <td class="small"><?php foreach ($t['lines'] as $l): ?><div><?= e($l['item_name']) ?> <?= e($l['batch_no']) ?> × <?= e(num($l['qty_sent'])) ?><?= $l['qty_received'] !== null && abs((float) $l['qty_received'] - (float) $l['qty_sent']) > 0.0005 ? ' <span class="text-danger">(received ' . e(num($l['qty_received'])) . ')</span>' : '' ?></div><?php endforeach; ?></td>
            <td><?= status_chip($t['status']) ?></td>
            <td><?php if ($t['status'] === 'in_transit' && (int) $t['dispatched_by'] !== Auth::id()): ?>
                <form method="post" action="<?= e(url('inventory/transfers')) ?>"><?= csrf_field() ?><input type="hidden" name="receive" value="<?= (int) $t['id'] ?>">
                    <?php foreach ($t['lines'] as $l): ?><input type="hidden" name="recv[<?= (int) $l['id'] ?>][qty]" value="<?= e(num($l['qty_sent'])) ?>"><?php endforeach; ?>
                    <button class="btn btn-sm btn-success">Confirm received</button></form><?php endif; ?></td></tr>
    <?php endforeach; ?>
    <?php if (!$rows): ?><tr><td colspan="5" class="muted">No transfers.</td></tr><?php endif; ?>
</tbody></table></div></div>
<script>document.addEventListener('DOMContentLoaded', function () {
    APP.itemLines(document.getElementById('tr-lines'), { columns: ['qty', 'batch'], batchSelect: true, location: function () { return document.getElementById('tr-from').value; } });
});</script>
