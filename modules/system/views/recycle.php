<?php
/** @var array $entities @var string $type @var array $counts @var array $rows @var \App\Core\Paginator $pager */
use App\Core\View;

echo View::partial('page_header', [
    'title'       => 'Recycle Bin',
    'description' => 'Soft-deleted records. Restoring brings a record back exactly as it was.',
    'breadcrumbs' => [['Administration'], ['Recycle Bin']],
    'actions'     => '<a class="btn btn-excel" href="' . e(url_with(['export' => 'xlsx', 'page' => null])) . '">' . icon('excel') . ' Export to Excel</a>',
]);
?>
<div class="tabs">
    <?php foreach ($entities as $key => [$label]): ?>
        <a href="<?= e(url('system/recycle', ['type' => $key])) ?>" class="<?= $key === $type ? 'active' : '' ?>"><?= e($label) ?><?= $counts[$key] ? ' <span class="badge badge-danger">' . $counts[$key] . '</span>' : '' ?></a>
    <?php endforeach; ?>
</div>
<div class="card card-flush">
    <div class="card-body table-wrap">
        <?php if (!$rows): ?>
            <div class="empty"><?= icon('trash') ?><strong>Nothing deleted</strong>No <?= e(strtolower($entities[$type][0])) ?> in the Recycle Bin.</div>
        <?php else: ?>
        <table class="table">
            <thead><tr><th>Record</th><th>Deleted On</th><th>Deleted By</th><th style="width:1%"></th></tr></thead>
            <tbody>
            <?php foreach ($rows as $r): ?>
                <tr>
                    <td><strong><?= e($r['label']) ?></strong></td>
                    <td class="nowrap"><?= e(fmt_datetime($r['deleted_at'])) ?></td>
                    <td><?= e($r['deleted_by_name'] ?: '—') ?></td>
                    <td>
                        <form method="post" action="<?= e(url('system/recycle/restore')) ?>">
                            <?= csrf_field() ?><input type="hidden" name="type" value="<?= e($type) ?>"><input type="hidden" name="id" value="<?= (int) $r['id'] ?>">
                            <button class="btn btn-sm btn-success"><?= icon('restore') ?> Restore</button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>
    </div>
    <?= View::partial('pagination', ['pager' => $pager]) ?>
</div>
