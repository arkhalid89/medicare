<?php
/** @var array $item @var array $rows @var int $loc @var array $locations @var array $balances */
use App\Core\View;

echo View::partial('page_header', [
    'title' => 'Stock Card — ' . $item['name'], 'description' => ($item['sku'] ?? '') . ' · average cost ' . money($item['avg_cost']) . ' · sale price ' . money($item['sale_price']),
    'breadcrumbs' => [['Store', url('inventory')], ['Stock card']],
    'actions' => can('inventory.items.manage') ? '<a class="btn btn-outline" href="' . e(url('inventory/item', ['id' => $item['id']])) . '">' . icon('edit') . ' Item</a>' : '',
]);
$refLinks = ['grn_items' => null, 'stock_issues' => null, 'stock_transfers' => 'inventory/transfers', 'stock_adjustments' => 'inventory/adjustments'];
?>
<div class="grid grid-main">
    <div class="card card-flush">
        <div class="card-header"><div class="card-title"><?= icon('list') ?> Ledger (newest first)</div>
            <form method="get" action="<?= e(form_action('inventory/card')) ?>"><?= route_field('inventory/card') ?><input type="hidden" name="item_id" value="<?= (int) $item['id'] ?>">
                <select class="input input-sm" name="location_id" onchange="this.form.submit()"><option value="">All locations</option><?php foreach ($locations as $id => $n): ?><option value="<?= $id ?>"<?= selected($id, $loc) ?>><?= e($n) ?></option><?php endforeach; ?></select></form></div>
        <div class="card-body table-wrap"><table class="table table-compact">
            <thead><tr><th>Date</th><th>Location</th><th>Movement</th><th>Batch</th><th>Reference</th><th class="num">In</th><th class="num">Out</th><th class="num">Running</th><th>By</th></tr></thead><tbody>
            <?php foreach ($rows as $r): $q = (float) $r['qty_signed']; ?>
                <tr><td class="small nowrap"><?= e(fmt_datetime($r['occurred_at'])) ?></td><td class="small"><?= e($r['location_name']) ?></td>
                    <td class="small"><?= e(ucwords(str_replace('_', ' ', $r['movement_type']))) ?></td><td class="small"><?= e($r['batch_no']) ?></td>
                    <td class="small"><?= e(str_replace('_', ' ', (string) $r['reference_type'])) ?><?= $r['reference_id'] ? ' #' . (int) $r['reference_id'] : '' ?><?= $r['note'] ? '<div class="muted">' . e($r['note']) . '</div>' : '' ?></td>
                    <td class="num"><?= $q > 0 ? e(num($q)) : '' ?></td><td class="num text-danger"><?= $q < 0 ? e(num(-$q)) : '' ?></td><td class="num"><strong><?= e(num($r['running'])) ?></strong></td>
                    <td class="small"><?= e($r['user_name']) ?></td></tr>
            <?php endforeach; ?>
            <?php if (!$rows): ?><tr><td colspan="9" class="muted">No movements.</td></tr><?php endif; ?>
        </tbody></table></div>
    </div>
    <div class="card card-flush"><div class="card-header"><div class="card-title"><?= icon('box') ?> On hand now</div></div>
        <div class="card-body"><ul class="list">
            <?php foreach ($balances as $b): ?><li><div class="grow"><div class="title"><?= e($b['location_name']) ?></div><div class="meta"><?= e($b['batch_no'] ?: 'no batch') ?><?= $b['expiry_date'] ? ' · exp ' . e(fmt_date($b['expiry_date'])) : '' ?> <?= $b['status'] && $b['status'] !== 'active' ? status_chip($b['status']) : '' ?></div></div>
                <strong><?= e(num($b['qty_on_hand'])) ?></strong></li><?php endforeach; ?>
            <?php if (!$balances): ?><li class="muted small">Out of stock everywhere.</li><?php endif; ?>
        </ul></div></div>
</div>
