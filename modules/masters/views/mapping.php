<?php
/**
 * @var array $rows @var \App\Core\Paginator $pager @var array $sources @var array $summary
 * @var int $unmapped @var string $filterSource @var string $q @var bool $canManage
 */
use App\Core\View;

echo View::partial('page_header', [
    'title'       => 'Medicine–Source Mapping',
    'description' => 'Assign each medicine to the source it is purchased from. Prescriptions are split into Rx sections by source.',
    'breadcrumbs' => [['Master Data'], ['Medicine–Source Mapping']],
    'actions'     => '<a class="btn btn-excel" href="' . e(url_with(['export' => 'xlsx', 'page' => null])) . '">' . icon('excel') . ' Export to Excel</a>'
        . (can('masters.manage') ? '<a class="btn btn-outline" href="' . e(url('masters/medicine_sources')) . '">' . icon('truck') . ' Manage Sources</a>' : ''),
]);
?>
<div class="grid" style="grid-template-columns:repeat(auto-fill,minmax(170px,1fr));margin-bottom:1rem">
    <?php foreach ($summary as $s): ?>
        <a class="quick<?= $filterSource === (string) $s['id'] ? ' primary' : '' ?>" href="<?= e(url('masters/mapping', ['source' => $s['id']])) ?>">
            <?= icon('truck') ?><strong><?= e($s['name']) ?></strong><span><?= (int) $s['total'] ?> medicine(s)<?= (int) $s['is_active'] ? '' : ' · inactive' ?></span>
        </a>
    <?php endforeach; ?>
    <a class="quick<?= $filterSource === 'none' ? ' primary' : '' ?>" href="<?= e(url('masters/mapping', ['source' => 'none'])) ?>">
        <?= icon('alert') ?><strong>Not mapped</strong><span><?= $unmapped ?> medicine(s)</span>
    </a>
</div>

<form method="post" action="<?= e(url('masters/mapping')) ?>" class="card card-flush" id="mapping-form">
    <?= csrf_field() ?>
    <input type="hidden" name="return_source" value="<?= e($filterSource) ?>">
    <input type="hidden" name="return_q" value="<?= e($q) ?>">
    <div class="card-header">
        <div class="filters">
            <div class="field grow">
                <label>Search</label>
                <input class="input" type="search" id="map-q" value="<?= e($q) ?>" placeholder="Medicine, generic or brand…">
            </div>
            <div class="field">
                <label>Source</label>
                <select class="input" id="map-source">
                    <option value="">All sources</option>
                    <?php foreach ($sources as $id => $name): ?><option value="<?= $id ?>"<?= selected($id, $filterSource) ?>><?= e($name) ?></option><?php endforeach; ?>
                    <option value="none"<?= selected('none', $filterSource) ?>>Not mapped</option>
                </select>
            </div>
            <div class="btns"><button type="button" class="btn btn-primary" id="map-filter"><?= icon('filter') ?> Filter</button></div>
        </div>
        <?php if ($canManage): ?>
        <div class="flex">
            <select class="input" name="source_id" style="width:200px" aria-label="Assign to source">
                <option value="">Assign selected to…</option>
                <?php foreach ($sources as $id => $name): ?><option value="<?= $id ?>"><?= e($name) ?></option><?php endforeach; ?>
                <option value="none">— Remove source —</option>
            </select>
            <button type="submit" class="btn btn-gold"><?= icon('link') ?> Apply</button>
        </div>
        <?php endif; ?>
    </div>
    <div class="card-body table-wrap">
        <?php if (!$rows): ?>
            <div class="empty"><?= icon('pill') ?><strong>No medicines found</strong></div>
        <?php else: ?>
        <table class="table">
            <thead><tr>
                <?php if ($canManage): ?><th style="width:36px"><input type="checkbox" id="check-all" aria-label="Select all"></th><?php endif; ?>
                <th>Medicine</th><th>Generic</th><th>Strength</th><th class="num">Unit Price</th><th>Source</th><th>Status</th>
            </tr></thead>
            <tbody>
            <?php foreach ($rows as $r): ?>
                <tr>
                    <?php if ($canManage): ?><td><input type="checkbox" name="medicine_ids[]" value="<?= (int) $r['id'] ?>" class="row-check"></td><?php endif; ?>
                    <td><strong><?= e($r['name']) ?></strong></td>
                    <td><?= e($r['generic_name']) ?></td>
                    <td><?= e($r['strength']) ?></td>
                    <td class="num"><?= e(money($r['price'])) ?></td>
                    <td><?= $r['source_name'] ? '<span class="badge badge-gold">' . e($r['source_name']) . '</span>' : '<span class="badge badge-danger">Not mapped</span>' ?></td>
                    <td><?= status_badge($r['is_active']) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>
    </div>
    <?= View::partial('pagination', ['pager' => $pager]) ?>
</form>
<script>
(function () {
    var all = document.getElementById('check-all');
    if (all) all.addEventListener('change', function () {
        document.querySelectorAll('.row-check').forEach(function (c) { c.checked = all.checked; });
    });
    function go() {
        location.href = APP.url('masters/mapping', { source: document.getElementById('map-source').value, q: document.getElementById('map-q').value });
    }
    document.getElementById('map-filter').addEventListener('click', go);
    document.getElementById('map-source').addEventListener('change', go);
    document.getElementById('map-q').addEventListener('keydown', function (e) { if (e.key === 'Enter') { e.preventDefault(); go(); } });
})();
</script>
