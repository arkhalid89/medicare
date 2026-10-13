<?php
/** @var array $rows @var array $locations @var int $loc */
use App\Core\View;

echo View::partial('page_header', [
    'title' => 'Below Reorder Level', 'description' => 'Items under their minimum per location — the reorder suggestion for indents and purchase orders.',
    'breadcrumbs' => [['Store', url('inventory')], ['Below reorder']],
    'actions' => (can('inventory.po.create') ? '<a class="btn btn-primary" href="' . e(url('inventory/po-new')) . '">' . icon('truck') . ' Create PO</a>' : '')
        . (can('inventory.requisition.create') ? '<a class="btn btn-outline" href="' . e(url('inventory/indent-new')) . '">' . icon('send') . ' Raise Indent</a>' : ''),
]);
?>
<div class="card card-flush"><div class="card-body table-wrap"><table class="table">
    <thead><tr><th>Item</th><th>Location</th><th class="num">On hand</th><th class="num">Minimum</th><th class="num">Reorder qty</th><th class="num">Maximum</th><th>Bin</th></tr></thead><tbody>
    <?php foreach ($rows as $r): ?><tr><td><a href="<?= e(url('inventory/card', ['item_id' => $r['item_id']])) ?>"><?= e($r['item_name']) ?></a><div class="muted small"><?= e($r['sku']) ?></div></td>
        <td><?= e($r['location_name']) ?></td><td class="num text-danger"><strong><?= e(num($r['on_hand'])) ?></strong></td><td class="num"><?= e(num($r['min_qty'])) ?></td>
        <td class="num"><?= e(num($r['reorder_qty'])) ?></td><td class="num"><?= e(num($r['max_qty'])) ?></td><td><?= e($r['bin_code']) ?></td></tr><?php endforeach; ?>
    <?php if (!$rows): ?><tr><td colspan="7" class="muted">Nothing below its minimum.</td></tr><?php endif; ?>
</tbody></table></div></div>
