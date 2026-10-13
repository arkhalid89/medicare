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
<?php if (can('records.delete')): ?>
<details class="card mb-2 clean-box">
    <summary class="card-header" style="cursor:pointer"><div class="card-title"><?= icon('trash') ?> Clean activity logs by date range</div><span class="muted small">Super Admin · a safety backup is taken first</span></summary>
    <form method="post" action="<?= e(url('system/logs/clean')) ?>" class="card-body" id="clean-form" data-confirm="Permanently delete the selected activity log entries? A safety backup is taken first." data-confirm-ok="Delete entries">
        <?= csrf_field() ?>
        <div class="filters">
            <div class="field"><label>From <span class="req">*</span></label><input class="input" type="date" name="from" required max="<?= today() ?>"></div>
            <div class="field"><label>To <span class="req">*</span></label><input class="input" type="date" name="to" required max="<?= today() ?>"></div>
            <div class="field"><label>Module (optional)</label><select class="input" name="module"><option value="">All modules</option>
                <?php foreach ($modules as $m): ?><option><?= e($m) ?></option><?php endforeach; ?></select></div>
            <div class="field"><label>User (optional)</label><select class="input" name="user_id"><option value="">All users</option>
                <?php foreach ($users as $id => $name): ?><option value="<?= $id ?>"><?= e($name) ?></option><?php endforeach; ?></select></div>
            <div class="btns"><span class="badge badge-warning" id="clean-count">Choose dates</span><button type="submit" class="btn btn-danger"><?= icon('trash') ?> Clean</button></div>
        </div>
    </form>
</details>
<script>
(function () {
    var f = document.getElementById('clean-form'), badge = document.getElementById('clean-count');
    var update = APP.debounce(function () {
        var d = new FormData(f);
        if (!d.get('from') || !d.get('to')) { badge.textContent = 'Choose dates'; return; }
        badge.textContent = 'Counting…';
        APP.ajax(APP.url('system/logs/preview', { from: d.get('from'), to: d.get('to'), module: d.get('module'), user_id: d.get('user_id') })).then(function (r) {
            badge.textContent = r.ok ? r.count + ' entr' + (r.count === 1 ? 'y' : 'ies') + ' will be deleted' : (r.message || 'Invalid range');
        });
    }, 300);
    f.addEventListener('change', update);
})();
</script>
<?php endif; ?>

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
