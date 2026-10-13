<?php
/** @var array $rows @var \App\Core\Paginator $pager @var array $locations @var int $loc @var string $q */
use App\Core\View;

echo View::partial('page_header', [
    'title' => 'Stock on Hand', 'description' => 'Quantity per location and batch (cache of the ledger). Expired, quarantined and recalled batches can never be issued.',
    'breadcrumbs' => [['Store', url('inventory')], ['Stock on Hand']],
    'actions' => '<a class="btn btn-excel" href="' . e(url_with(['export' => 'xlsx', 'page' => null])) . '">' . icon('excel') . ' Export</a>'
        . '<a class="btn btn-outline" href="' . e(url('inventory/stock', ['low' => 1, 'location_id' => $loc])) . '">' . icon('alert') . ' Below reorder</a>',
]);
?>
<div class="card card-flush">
    <div class="card-header"><form class="filters" method="get" action="<?= e(form_action('inventory/stock')) ?>" data-autosubmit>
        <?= route_field('inventory/stock') ?>
        <div class="field grow"><label>Search</label><input class="input" type="search" name="q" value="<?= e($q) ?>" placeholder="Item, SKU or batch…"></div>
        <div class="field"><label>Location</label><select class="input" name="location_id"><option value="">All</option><?php foreach ($locations as $id => $n): ?><option value="<?= $id ?>"<?= selected($id, $loc) ?>><?= e($n) ?></option><?php endforeach; ?></select></div>
        <div class="btns"><button class="btn btn-primary"><?= icon('filter') ?> Filter</button></div>
    </form></div>
    <div class="card-body table-wrap"><table class="table">
        <thead><tr><th>Item</th><th>Location</th><th>Batch</th><th>Expiry</th><th>Status</th><th class="num">Qty</th><th class="num">Value</th></tr></thead><tbody>
        <?php foreach ($rows as $r): $exp = $r['expiry_date'] && $r['expiry_date'] < today(); ?>
            <tr><td><a href="<?= e(url('inventory/card', ['item_id' => $r['item_id'], 'location_id' => $loc ?: null])) ?>"><strong><?= e($r['item_name']) ?></strong></a>
                <?= (int) $r['is_controlled'] ? ' <span class="badge badge-danger">CD</span>' : '' ?><div class="muted small"><?= e($r['sku']) ?></div></td>
                <td class="small"><?= e($r['location_name']) ?></td><td class="small"><?= e($r['batch_no'] ?: '—') ?></td>
                <td class="small <?= $exp ? 'text-danger' : '' ?>"><?= e(fmt_date($r['expiry_date'])) ?></td><td><?= $r['batch_status'] ? status_chip($exp ? 'expired' : $r['batch_status']) : '' ?></td>
                <td class="num"><strong><?= e(num($r['qty_on_hand'])) ?></strong></td><td class="num"><?= e(money((float) $r['qty_on_hand'] * (float) $r['cost'])) ?></td></tr>
        <?php endforeach; ?>
        <?php if (!$rows): ?><tr><td colspan="7" class="muted">No stock.</td></tr><?php endif; ?>
    </tbody></table></div>
    <?= View::partial('pagination', ['pager' => $pager]) ?>
</div>
