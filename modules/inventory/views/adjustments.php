<?php
/** @var array $rows @var array $locations */
use App\Core\Auth;
use App\Core\View;
use App\Modules\Inventory\InventoryService;

echo View::partial('page_header', [
    'title' => 'Stock Adjustments', 'description' => 'Damage, breakage, theft, expiry write-off or surplus. The proposer cannot approve; values above the limit need the Hospital Admin.',
    'breadcrumbs' => [['Store', url('inventory')], ['Adjustments']],
]);
?>
<?php if (can('inventory.adjust.propose') || can('inventory.adjust.approve')): ?>
<form class="card" method="post" action="<?= e(url('inventory/adjustments')) ?>">
    <?= csrf_field() ?>
    <div class="card-header"><div class="card-title"><?= icon('sliders') ?> Propose adjustment</div></div>
    <div class="card-body">
        <div class="form-grid mb-2">
            <div class="field col-4"><label>Location</label><select class="input" name="location_id" id="ad-loc"><?php foreach ($locations as $id => $n): ?><option value="<?= $id ?>"><?= e($n) ?></option><?php endforeach; ?></select></div>
            <div class="field col-4"><label>Reason</label><select class="input" name="reason_code"><?php foreach (InventoryService::ADJUST_REASONS as $k => $l): if ($k === 'count_correction') continue; ?><option value="<?= e($k) ?>"><?= e($l) ?></option><?php endforeach; ?></select></div>
            <div class="field col-4"><label>Notes</label><input class="input" name="notes" maxlength="500"></div>
        </div>
        <div class="search-box mb-2"><?= icon('plus') ?><input class="input" data-item-search placeholder="Add item…" autocomplete="off"><div class="search-results"></div></div>
        <table class="table" id="ad-lines"><thead><tr><th>Item</th><th>Qty (+ gain / − loss)</th><th>Batch</th><th></th></tr></thead><tbody></tbody></table>
    </div>
    <div class="card-footer"><button class="btn btn-primary">Propose</button></div>
</form>
<?php endif; ?>
<div class="card card-flush"><div class="card-body table-wrap"><table class="table">
    <thead><tr><th>Adjustment</th><th>Location</th><th>Reason</th><th>Lines</th><th class="num">Value</th><th>Status</th><th></th></tr></thead><tbody>
    <?php foreach ($rows as $a): ?>
        <tr><td><strong><?= e($a['adj_number']) ?></strong><div class="muted small"><?= e(fmt_datetime($a['proposed_at'])) ?> · <?= e($a['proposed_by_name']) ?></div></td>
            <td class="small"><?= e($a['location_name']) ?></td><td class="small"><?= e(InventoryService::ADJUST_REASONS[$a['reason_code']] ?? $a['reason_code']) ?><?= $a['notes'] ? '<div class="muted">' . e($a['notes']) . '</div>' : '' ?></td>
            <td class="small"><?php foreach ($a['lines'] as $l): ?><div><?= e($l['item_name']) ?> <?= e($l['batch_no']) ?> <strong class="<?= (float) $l['qty_signed'] < 0 ? 'text-danger' : '' ?>"><?= e(num($l['qty_signed'])) ?></strong></div><?php endforeach; ?></td>
            <td class="num"><?= e(money($a['total_value'])) ?></td><td><?= status_chip($a['status']) ?><?= $a['approved_by_name'] ? '<div class="muted small">' . e($a['approved_by_name']) . '</div>' : '' ?></td>
            <td><?php if ($a['status'] === 'proposed' && can('inventory.adjust.approve') && (int) $a['proposed_by'] !== Auth::id()): ?>
                <form method="post" action="<?= e(url('inventory/adjustments')) ?>" class="flex"><?= csrf_field() ?><input type="hidden" name="decide" value="<?= (int) $a['id'] ?>">
                    <button class="btn btn-sm btn-success" name="decision" value="approve">Approve</button><button class="btn btn-sm btn-danger" name="decision" value="reject">Reject</button></form><?php endif; ?></td></tr>
    <?php endforeach; ?>
    <?php if (!$rows): ?><tr><td colspan="7" class="muted">No adjustments.</td></tr><?php endif; ?>
</tbody></table></div></div>
<script>document.addEventListener('DOMContentLoaded', function () {
    var t = document.getElementById('ad-lines');
    if (t) APP.itemLines(t, { columns: ['signed', 'batch'], batchSelect: true, allowBlocked: true, location: function () { return document.getElementById('ad-loc').value; } });
});</script>
