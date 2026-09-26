<?php
/**
 * @var array $def @var array $rows @var \App\Core\Paginator $pager @var string $q @var string $status
 * @var array $lookups @var bool $canManage @var bool $canDelete
 */
use App\Core\View;

$key = $def['key'];
$actions = '<a class="btn btn-excel" href="' . e(url_with(['export' => 'xlsx', 'page' => null])) . '">' . icon('excel') . ' Export to Excel</a>';
if ($canManage) {
    $actions .= '<a class="btn btn-primary" href="' . e(url('masters/form', ['type' => $key])) . '">' . icon('plus') . ' Add ' . e($def['singular']) . '</a>';
}
echo View::partial('page_header', [
    'title'       => $def['title'],
    'description' => $def['description'],
    'breadcrumbs' => [['Master Data'], [$def['title']]],
    'actions'     => $actions,
]);
$listFields = array_filter($def['fields'], static fn ($f) => !empty($f['list']));
?>
<div class="card card-flush">
    <div class="card-header">
        <form class="filters" method="get" action="<?= e(form_action('masters/' . $key)) ?>" data-autosubmit>
            <?= route_field('masters/' . $key) ?>
            <div class="field grow">
                <label>Search</label>
                <input class="input" type="search" name="q" value="<?= e($q) ?>" placeholder="Search <?= e(strtolower($def['title'])) ?>…">
            </div>
            <?php foreach ($lookups as $field => $options): ?>
                <div class="field">
                    <label><?= e($def['fields'][$field]['label']) ?></label>
                    <select class="input" name="<?= e($field) ?>">
                        <option value="">All</option>
                        <?php foreach ($options as $id => $label): ?>
                            <option value="<?= $id ?>"<?= selected($id, input($field, '')) ?>><?= e($label) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            <?php endforeach; ?>
            <div class="field">
                <label>Status</label>
                <select class="input" name="status">
                    <option value="">All</option>
                    <option value="active"<?= selected('active', $status) ?>>Active</option>
                    <option value="inactive"<?= selected('inactive', $status) ?>>Inactive</option>
                </select>
            </div>
            <div class="btns">
                <button class="btn btn-primary" type="submit"><?= icon('filter') ?> Filter</button>
                <a class="btn btn-ghost" href="<?= e(url('masters/' . $key)) ?>">Reset</a>
            </div>
        </form>
    </div>
    <div class="card-body table-wrap">
        <?php if (!$rows): ?>
            <div class="empty"><?= icon($def['icon']) ?><strong>No <?= e(strtolower($def['title'])) ?> found</strong>
                <?= $canManage ? 'Use “Add ' . e($def['singular']) . '” to create the first one.' : 'Try a different search.' ?></div>
        <?php else: ?>
        <table class="table">
            <thead>
            <tr>
                <th style="width:50px">#</th>
                <th><?= e($def['name_label']) ?></th>
                <?php foreach ($listFields as $f): ?><th<?= in_array($f['type'], ['money', 'decimal', 'int'], true) ? ' class="num"' : '' ?>><?= e($f['label']) ?></th><?php endforeach; ?>
                <th>Status</th>
                <th class="num">Order</th>
                <?php if ($canManage || $canDelete): ?><th style="width:1%">Actions</th><?php endif; ?>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($rows as $i => $r): ?>
                <tr>
                    <td class="muted"><?= $pager->offset() + $i + 1 ?></td>
                    <td><strong><?= e($r['name']) ?></strong>
                        <?php if ($key === 'medicines' && $r['brand_name']): ?><div class="muted small"><?= e($r['brand_name']) ?></div><?php endif; ?>
                    </td>
                    <?php foreach ($listFields as $field => $f): ?>
                        <td<?= in_array($f['type'], ['money', 'decimal', 'int'], true) ? ' class="num"' : '' ?>>
                            <?php if ($f['type'] === 'select'): ?>
                                <?= $r[$field . '_label'] ? e($r[$field . '_label']) : '<span class="muted">—</span>' ?>
                            <?php elseif ($f['type'] === 'money'): ?>
                                <?= e(money($r[$field])) ?>
                            <?php elseif ($f['type'] === 'decimal'): ?>
                                <?= e(num($r[$field])) ?>
                            <?php elseif ($f['type'] === 'options'): ?>
                                <span class="badge badge-primary"><?= e(ucfirst((string) $r[$field])) ?></span>
                            <?php else: ?>
                                <?= e(mb_strimwidth((string) $r[$field], 0, 80, '…')) ?>
                            <?php endif; ?>
                        </td>
                    <?php endforeach; ?>
                    <td><?= status_badge($r['is_active']) ?></td>
                    <td class="num"><?= (int) $r['display_order'] ?></td>
                    <?php if ($canManage || $canDelete): ?>
                    <td>
                        <div class="actions">
                            <?php if ($canManage): ?>
                                <a class="act" title="Edit" href="<?= e(url('masters/form', ['type' => $key, 'id' => $r['id']])) ?>"><?= icon('edit') ?></a>
                                <form method="post" action="<?= e(url('masters/toggle')) ?>">
                                    <?= csrf_field() ?><input type="hidden" name="type" value="<?= e($key) ?>"><input type="hidden" name="id" value="<?= (int) $r['id'] ?>">
                                    <button class="act <?= (int) $r['is_active'] ? '' : 'success' ?>" title="<?= (int) $r['is_active'] ? 'Deactivate' : 'Activate' ?>"><?= icon('power') ?></button>
                                </form>
                            <?php endif; ?>
                            <?php if ($canDelete): ?>
                                <form method="post" action="<?= e(url('masters/delete')) ?>" data-confirm="Delete <?= e(strtolower($def['singular'])) ?> “<?= e($r['name']) ?>”? It will move to the Recycle Bin and can be restored. Past prescriptions keep their copy." data-confirm-ok="Delete">
                                    <?= csrf_field() ?><input type="hidden" name="type" value="<?= e($key) ?>"><input type="hidden" name="id" value="<?= (int) $r['id'] ?>">
                                    <button class="act danger" title="Delete"><?= icon('trash') ?></button>
                                </form>
                            <?php endif; ?>
                        </div>
                    </td>
                    <?php endif; ?>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>
    </div>
    <?= View::partial('pagination', ['pager' => $pager]) ?>
</div>
