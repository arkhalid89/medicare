<?php
/** @var array $rows @var \App\Core\Paginator $pager @var array $types */
use App\Core\View;

echo View::partial('page_header', [
    'title' => 'Items', 'description' => 'One item master for drugs, consumables, reagents, implants and vaccines. Medicines are linked automatically.',
    'breadcrumbs' => [['Store', url('inventory')], ['Items']],
    'actions' => '<a class="btn btn-excel" href="' . e(url_with(['export' => 'xlsx', 'page' => null])) . '">' . icon('excel') . ' Export</a>'
        . (can('inventory.items.manage') ? '<a class="btn btn-primary" href="' . e(url('inventory/item')) . '">' . icon('plus') . ' New Item</a>' : ''),
]);
?>
<div class="card card-flush">
    <div class="card-header"><form class="filters" method="get" action="<?= e(form_action('inventory/items')) ?>" data-autosubmit>
        <?= route_field('inventory/items') ?>
        <div class="field grow"><label>Search</label><input class="input" type="search" name="q" value="<?= e(input('q', '')) ?>" placeholder="Name, SKU, barcode…"></div>
        <div class="field"><label>Type</label><select class="input" name="type"><option value="">All</option><?php foreach ($types as $t): ?><option<?= selected($t, input('type', '')) ?>><?= e($t) ?></option><?php endforeach; ?></select></div>
        <div class="btns"><button class="btn btn-primary"><?= icon('filter') ?> Filter</button></div>
    </form></div>
    <div class="card-body table-wrap"><table class="table">
        <thead><tr><th>SKU</th><th>Item</th><th>Type</th><th>Unit</th><th>Flags</th><th class="num">On hand</th><th class="num">Avg cost</th><th class="num">Sale price</th><th></th></tr></thead><tbody>
        <?php foreach ($rows as $r): ?><tr><td><code><?= e($r['sku']) ?></code></td><td><strong><?= e($r['name']) ?></strong><div class="muted small"><?= e($r['category_name']) ?></div></td>
            <td class="small"><?= e(str_replace('_', ' ', $r['item_type'])) ?></td><td class="small"><?= e($r['uom']) ?></td>
            <td><?= (int) $r['is_controlled'] ? '<span class="badge badge-danger">Controlled</span> ' : '' ?><?= (int) $r['is_cold_chain'] ? '<span class="badge badge-info">Cold chain</span> ' : '' ?><?= (int) $r['is_batch_tracked'] ? '<span class="badge badge-muted">Batch</span>' : '' ?></td>
            <td class="num"><?= e(num($r['on_hand'])) ?></td><td class="num"><?= e(money($r['avg_cost'])) ?></td><td class="num"><?= e(money($r['sale_price'])) ?></td>
            <td class="nowrap"><a class="act" title="Stock card" href="<?= e(url('inventory/card', ['item_id' => $r['id']])) ?>"><?= icon('list') ?></a>
                <a class="act" title="Edit" href="<?= e(url('inventory/item', ['id' => $r['id']])) ?>"><?= icon('edit') ?></a></td></tr><?php endforeach; ?>
    </tbody></table></div>
    <?= View::partial('pagination', ['pager' => $pager]) ?>
</div>
