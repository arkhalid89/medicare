<?php
/** @var array $tiles @var float $value @var array $locations @var ?int $loc @var array $low @var array $recent */
use App\Core\View;

$actions = '';
foreach ([['inventory/indent-new', 'inventory.requisition.create', 'send', 'New Indent'], ['inventory/grn-new', 'inventory.grn.post', 'download', 'Receive Goods'],
          ['inventory/po-new', 'inventory.po.create', 'truck', 'New PO'], ['inventory/opening', 'inventory.items.manage', 'plus', 'Opening Stock']] as [$r, $p, $i, $l]) {
    if (can($p)) {
        $actions .= '<a class="btn btn-outline" href="' . e(url($r)) . '">' . icon($i) . ' ' . $l . '</a>';
    }
}
echo View::partial('page_header', [
    'title' => 'Store Dashboard', 'description' => 'Stock across the central store, pharmacies and department locations — every movement goes through one ledger.',
    'breadcrumbs' => [['Store'], ['Dashboard']], 'actions' => $actions,
]);
?>
<form class="card" method="get" action="<?= e(form_action('inventory')) ?>"><div class="card-body"><div class="filters">
    <?= route_field('inventory') ?><div class="field grow"><label>Location</label>
    <select class="input" name="location_id" onchange="this.form.submit()"><option value="">All locations</option><?php foreach ($locations as $id => $n): ?><option value="<?= $id ?>"<?= selected($id, $loc) ?>><?= e($n) ?></option><?php endforeach; ?></select></div>
</div></div></form>
<div class="grid grid-4 mb-2">
    <?php foreach ($tiles as [$label, $ic, $n, $link]): ?>
        <a class="stat" href="<?= e($link) ?>"><div class="stat-icon"><?= icon($ic) ?></div><div><p class="label"><?= e($label) ?></p>
            <div class="value"><?= $n === null ? e(money($value)) : (int) $n ?></div></div></a>
    <?php endforeach; ?>
</div>
<div class="grid grid-main">
    <div class="card card-flush"><div class="card-header"><div class="card-title"><?= icon('list') ?> Latest movements</div><a class="small" href="<?= e(url('inventory/stock', ['location_id' => $loc])) ?>">Stock on hand →</a></div>
        <div class="card-body table-wrap"><table class="table table-compact"><thead><tr><th>When</th><th>Item</th><th>Location</th><th>Movement</th><th class="num">Qty</th><th>By</th></tr></thead><tbody>
            <?php foreach ($recent as $r): ?><tr><td class="small nowrap"><?= e(fmt_datetime($r['occurred_at'])) ?></td><td><a href="<?= e(url('inventory/card', ['item_id' => $r['item_id']])) ?>"><?= e($r['item_name']) ?></a></td>
                <td class="small"><?= e($r['location_name']) ?></td><td class="small"><?= e(str_replace('_', ' ', $r['movement_type'])) ?></td>
                <td class="num <?= (float) $r['qty_signed'] < 0 ? 'text-danger' : '' ?>"><?= e(num($r['qty_signed'])) ?></td><td class="small"><?= e($r['user_name']) ?></td></tr><?php endforeach; ?>
            <?php if (!$recent): ?><tr><td colspan="6" class="muted">No stock movements yet. Start with Opening Stock or a GRN.</td></tr><?php endif; ?>
        </tbody></table></div></div>
    <div class="card card-flush"><div class="card-header"><div class="card-title"><?= icon('alert') ?> Below reorder level</div></div>
        <div class="card-body"><ul class="list">
            <?php foreach ($low as $l): ?><li><div class="grow"><div class="title"><?= e($l['item_name']) ?></div><div class="meta"><?= e($l['location_name']) ?> · min <?= e(num($l['min_qty'])) ?> · reorder <?= e(num($l['reorder_qty'])) ?></div></div>
                <span class="badge badge-danger"><?= e(num($l['on_hand'])) ?></span></li><?php endforeach; ?>
            <?php if (!$low): ?><li class="muted small">All items are above their minimum (set minimum / reorder levels on the item page).</li><?php endif; ?>
        </ul></div></div>
</div>
