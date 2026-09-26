<?php
/** @var array $rows @var \App\Core\Paginator $pager @var array $users @var array $modules */
use App\Core\View;

echo View::partial('page_header', [
    'title'       => 'Activity Logs',
    'description' => 'Who did what and when — logins, patients, visits, prescriptions, prints, masters, settings and backups.',
    'breadcrumbs' => [['Administration'], ['Activity Logs']],
    'actions'     => '<a class="btn btn-excel" href="' . e(url_with(['export' => 'xlsx', 'page' => null])) . '">' . icon('excel') . ' Export to Excel</a>',
]);
?>
<div class="card card-flush">
    <div class="card-header">
        <form class="filters" method="get" action="<?= e(form_action('system/logs')) ?>">
            <?= route_field('system/logs') ?>
            <div class="field grow"><label>Search</label><input class="input" type="search" name="q" value="<?= e(input('q', '')) ?>" placeholder="Action, details or username…"></div>
            <div class="field"><label>User</label><select class="input" name="user_id"><option value="">All users</option>
                <?php foreach ($users as $id => $name): ?><option value="<?= $id ?>"<?= selected($id, input('user_id', '')) ?>><?= e($name) ?></option><?php endforeach; ?></select></div>
            <div class="field"><label>Module</label><select class="input" name="module"><option value="">All</option>
                <?php foreach ($modules as $m): ?><option<?= selected($m, input('module', '')) ?>><?= e($m) ?></option><?php endforeach; ?></select></div>
            <div class="field"><label>From</label><input class="input" type="date" name="from" value="<?= e(input('from', '')) ?>"></div>
            <div class="field"><label>To</label><input class="input" type="date" name="to" value="<?= e(input('to', '')) ?>"></div>
            <div class="btns"><button class="btn btn-primary"><?= icon('filter') ?> Filter</button><a class="btn btn-ghost" href="<?= e(url('system/logs')) ?>">Reset</a></div>
        </form>
    </div>
    <div class="card-body table-wrap">
        <?php if (!$rows): ?>
            <div class="empty"><?= icon('list') ?><strong>No activity found</strong></div>
        <?php else: ?>
        <table class="table table-compact">
            <thead><tr><th>Date / Time</th><th>User</th><th>Action</th><th>Module</th><th>Details</th><th>IP</th></tr></thead>
            <tbody>
            <?php foreach ($rows as $r): ?>
                <tr>
                    <td class="nowrap small"><?= e(fmt_datetime($r['created_at'])) ?></td>
                    <td class="nowrap"><?= e($r['username'] ?: 'system') ?><?php if ($r['role']): ?><div class="muted small"><?= e(role_label($r['role'])) ?></div><?php endif; ?></td>
                    <td class="nowrap"><span class="badge <?= str_contains($r['action'], 'delete') || str_contains($r['action'], 'failed') || str_contains($r['action'], 'reset') || str_contains($r['action'], 'denied') ? 'badge-danger' : 'badge-primary' ?>"><?= e($r['action']) ?></span></td>
                    <td class="small"><?= e($r['module']) ?><?= $r['record_id'] ? ' <span class="muted">#' . e($r['record_id']) . '</span>' : '' ?></td>
                    <td class="small"><?= e($r['description']) ?></td>
                    <td class="small muted nowrap"><?= e($r['ip_address']) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>
    </div>
    <?= View::partial('pagination', ['pager' => $pager]) ?>
</div>
