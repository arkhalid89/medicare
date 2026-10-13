<?php
/** @var array $byLocation @var array $byCategory @var int $loc @var array $locations */
use App\Core\View;

echo View::partial('page_header', [
    'title' => 'Stock Valuation', 'description' => 'Stock on hand today, valued at weighted average cost and at sale price.',
    'breadcrumbs' => [['Reports'], ['Stock valuation']],
    'actions' => '<a class="btn btn-excel" href="' . e(url_with(['export' => 'xlsx'])) . '">' . icon('excel') . ' Export to Excel</a>',
]);
$sum = static fn (array $rows, string $k): float => array_sum(array_map(static fn ($r) => (float) $r[$k], $rows));
?>
<div class="card mb-2"><div class="card-body"><form class="filters" method="get" action="<?= e(form_action('reports/stock')) ?>" data-autosubmit><?= route_field('reports/stock') ?>
    <div class="field"><label>Location</label><select class="input" name="location_id"><option value="">All locations</option>
        <?php foreach ($locations as $id => $n): ?><option value="<?= $id ?>"<?= selected($id, $loc) ?>><?= e($n) ?></option><?php endforeach; ?></select></div></form></div></div>
<div class="grid grid-2">
<?php foreach (['Location' => $byLocation, 'Category' => $byCategory] as $title => $rows): $exp = $title === 'Location'; ?>
    <div class="card card-flush"><div class="card-header"><div class="card-title">By <?= strtolower($title) ?></div></div><div class="card-body"><table class="table table-compact">
        <thead><tr><th><?= $title ?></th><th class="num">Items</th><th class="num">At cost</th><th class="num">At sale price</th><?= $exp ? '<th class="num">Expired</th>' : '' ?></tr></thead><tbody>
        <?php foreach ($rows as $r): ?><tr><td><?= e($r['label']) ?></td><td class="num"><?= (int) $r['items'] ?></td><td class="num"><?= e(money($r['cost_value'])) ?></td><td class="num"><?= e(money($r['sale_value'])) ?></td>
            <?= $exp ? '<td class="num' . ((float) $r['expired_value'] > 0 ? ' text-danger' : '') . '">' . e(money($r['expired_value'])) . '</td>' : '' ?></tr><?php endforeach; ?>
        <?php if (!$rows): ?><tr><td colspan="5" class="muted">No stock on hand.</td></tr><?php else: ?>
        <tr><td><strong>Total</strong></td><td></td><td class="num"><strong><?= e(money($sum($rows, 'cost_value'))) ?></strong></td><td class="num"><strong><?= e(money($sum($rows, 'sale_value'))) ?></strong></td><?= $exp ? '<td></td>' : '' ?></tr><?php endif; ?>
    </tbody></table></div></div>
<?php endforeach; ?>
</div>
