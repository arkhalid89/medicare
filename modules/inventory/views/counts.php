<?php
/** @var array $locations @var array $rows */
use App\Core\View;

echo View::partial('page_header', [
    'title' => 'Stocktake', 'description' => 'Blind counts: counters never see the system quantity. A supervisor reviews, orders recounts and posts the variance.',
    'breadcrumbs' => [['Store', url('inventory')], ['Stocktake']],
]);
?>
<form class="card" method="post" action="<?= e(url('inventory/counts')) ?>"><?= csrf_field() ?>
    <div class="card-body"><div class="filters">
        <div class="field grow"><label>Location</label><select class="input" name="location_id"><?php foreach ($locations as $id => $n): ?><option value="<?= $id ?>"><?= e($n) ?></option><?php endforeach; ?></select></div>
        <div class="field"><label>Type</label><select class="input" name="count_type"><option value="full">Full</option><option value="cycle">Cycle</option><option value="spot">Spot</option></select></div>
        <div class="btns"><button class="btn btn-primary"><?= icon('clipboard') ?> Start count</button></div>
    </div></div></form>
<div class="card card-flush"><div class="card-body table-wrap"><table class="table">
    <thead><tr><th>Count</th><th>Location</th><th>Type</th><th>Progress</th><th>Status</th></tr></thead><tbody>
    <?php foreach ($rows as $c): ?><tr><td><a href="<?= e(url('inventory/count', ['id' => $c['id']])) ?>"><strong><?= e($c['count_number']) ?></strong></a><div class="muted small"><?= e(fmt_datetime($c['snapshot_at'])) ?> · <?= e($c['created_by_name']) ?></div></td>
        <td><?= e($c['location_name']) ?></td><td><?= e(ucfirst($c['count_type'])) ?></td><td><?= (int) $c['counted'] ?> / <?= (int) $c['line_count'] ?></td><td><?= status_chip($c['status']) ?></td></tr><?php endforeach; ?>
    <?php if (!$rows): ?><tr><td colspan="5" class="muted">No counts yet.</td></tr><?php endif; ?>
</tbody></table></div></div>
