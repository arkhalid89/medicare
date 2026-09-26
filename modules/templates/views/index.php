<?php
/** @var array $rows @var \App\Core\Paginator $pager */
use App\Core\View;

echo View::partial('page_header', [
    'title'       => 'Favourite Templates',
    'description' => 'Your personal prescription templates. Only you can see them. Loading a template never changes it.',
    'breadcrumbs' => [['Favourite Templates']],
    'actions'     => '<a class="btn btn-excel" href="' . e(url_with(['export' => 'xlsx', 'page' => null])) . '">' . icon('excel') . ' Export to Excel</a>'
        . '<a class="btn btn-primary" href="' . e(url('templates/create')) . '">' . icon('plus') . ' New Template</a>',
]);
?>
<div class="card card-flush">
    <div class="card-header">
        <form class="filters" method="get" action="<?= e(form_action('templates')) ?>" data-autosubmit>
            <?= route_field('templates') ?>
            <div class="field grow"><label>Search</label><input class="input" type="search" name="q" value="<?= e(input('q', '')) ?>" placeholder="Template name…"></div>
            <div class="field"><label>Show</label><select class="input" name="fav"><option value="">All templates</option><option value="1"<?= selected('1', input('fav', '')) ?>>Favourites only</option></select></div>
            <div class="btns"><button class="btn btn-primary"><?= icon('search') ?> Search</button></div>
        </form>
    </div>
    <div class="card-body table-wrap">
        <?php if (!$rows): ?>
            <div class="empty"><?= icon('star') ?><strong>No templates yet</strong>Create one here, or use “Save as Template” on the New Visit screen.</div>
        <?php else: ?>
        <table class="table">
            <thead><tr><th style="width:36px"></th><th>Template</th><th>Diagnoses</th><th>Medicines</th><th class="num">Used</th><th>Updated</th><th style="width:1%">Actions</th></tr></thead>
            <tbody>
            <?php foreach ($rows as $r): ?>
                <tr>
                    <td>
                        <form method="post" action="<?= e(url('templates/favourite')) ?>">
                            <?= csrf_field() ?><input type="hidden" name="id" value="<?= (int) $r['id'] ?>">
                            <button class="act" title="<?= (int) $r['is_favourite'] ? 'Unfavourite' : 'Favourite' ?>" style="color:<?= (int) $r['is_favourite'] ? 'var(--secondary)' : 'var(--border-strong)' ?>"><?= icon('star') ?></button>
                        </form>
                    </td>
                    <td><strong><?= e($r['name']) ?></strong><?php if ($r['description']): ?><div class="muted small"><?= e($r['description']) ?></div><?php endif; ?></td>
                    <td class="small"><?= e($r['diagnoses']) ?: '<span class="muted">—</span>' ?></td>
                    <td class="small"><?= (int) $r['medicine_count'] ?> · <?= e(mb_strimwidth((string) $r['medicines'], 0, 70, '…')) ?></td>
                    <td class="num"><?= (int) $r['usage_count'] ?></td>
                    <td class="small nowrap"><?= e(fmt_date($r['updated_at'] ?: $r['created_at'])) ?></td>
                    <td><div class="actions">
                        <a class="btn btn-sm btn-gold" href="<?= e(url('visits/new', ['template_id' => $r['id']])) ?>"><?= icon('stethoscope') ?> Use</a>
                        <a class="act" title="Edit" href="<?= e(url('templates/edit', ['id' => $r['id']])) ?>"><?= icon('edit') ?></a>
                        <form method="post" action="<?= e(url('templates/delete')) ?>" data-confirm="Delete template “<?= e($r['name']) ?>”?" data-confirm-ok="Delete">
                            <?= csrf_field() ?><input type="hidden" name="id" value="<?= (int) $r['id'] ?>"><button class="act danger" title="Delete"><?= icon('trash') ?></button>
                        </form>
                    </div></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>
    </div>
    <?= View::partial('pagination', ['pager' => $pager]) ?>
</div>
