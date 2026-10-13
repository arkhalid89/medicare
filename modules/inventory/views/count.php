<?php
/** @var array $c @var array $lines @var bool $showSystem */
use App\Core\View;

echo View::partial('page_header', [
    'title' => 'Count ' . $c['count_number'], 'description' => $c['location_name'] . ' · snapshot ' . fmt_datetime($c['snapshot_at']) . ($showSystem ? '' : ' · blind count (system quantity hidden)'),
    'breadcrumbs' => [['Store', url('inventory')], ['Stocktake', url('inventory/counts')], [$c['count_number']]],
]);
$editable = in_array($c['status'], ['counting', 'review'], true);
?>
<div class="flex mb-2"><?= status_chip($c['status']) ?></div>
<form class="card card-flush" method="post" action="<?= e(url('inventory/count', ['id' => $c['id']])) ?>">
    <?= csrf_field() ?>
    <div class="card-body table-wrap"><table class="table">
        <thead><tr><th>Bin</th><th>Item</th><th>Batch</th><th>Expiry</th><?php if ($showSystem): ?><th class="num">System</th><?php endif; ?><th>Counted</th><?php if ($showSystem): ?><th>Recount</th><th class="num">Variance</th><?php endif; ?><th>By</th></tr></thead><tbody>
        <?php foreach ($lines as $l): ?>
            <tr><td class="small"><?= e($l['bin_code']) ?></td><td><?= e($l['item_name']) ?><div class="muted small"><?= e($l['sku']) ?></div></td><td class="small"><?= e($l['batch_no']) ?></td><td class="small"><?= e(fmt_date($l['expiry_date'])) ?></td>
                <?php if ($showSystem): ?><td class="num"><?= e(num($l['system_qty'])) ?></td><?php endif; ?>
                <td><?php if ($c['status'] === 'counting'): ?><input class="input input-sm" type="number" step="0.001" min="0" name="counted[<?= (int) $l['id'] ?>]" value="<?= e($l['counted_qty'] !== null ? num($l['counted_qty']) : '') ?>" style="width:110px">
                    <?php else: ?><?= e(num($l['counted_qty'])) ?><?php endif; ?></td>
                <?php if ($showSystem): ?>
                    <td><?php if ($c['status'] === 'review'): ?><input class="input input-sm" type="number" step="0.001" min="0" name="counted[<?= (int) $l['id'] ?>]" value="<?= e($l['recount_qty'] !== null ? num($l['recount_qty']) : '') ?>" style="width:110px"><?php else: ?><?= e(num($l['recount_qty'])) ?><?php endif; ?></td>
                    <td class="num <?= abs((float) $l['variance']) > 0.0005 ? 'text-danger' : '' ?>"><?= $l['variance'] !== null ? e(num($l['variance'])) : '' ?></td>
                <?php endif; ?>
                <td class="small"><?= e($l['counted_by_name']) ?></td></tr>
        <?php endforeach; ?>
    </tbody></table></div>
    <?php if ($editable): ?><div class="card-footer">
        <?php if ($c['status'] === 'counting'): ?><button class="btn btn-outline" name="do" value="save">Save</button><button class="btn btn-primary" name="do" value="submit">Submit for review</button><?php endif; ?>
        <?php if ($c['status'] === 'review' && $showSystem): ?><button class="btn btn-outline" name="do" value="recount">Save recounts</button><button class="btn btn-primary" name="do" value="post"><?= icon('check') ?> Post variance</button><?php endif; ?>
    </div><?php endif; ?>
</form>
