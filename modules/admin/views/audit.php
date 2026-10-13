<?php
/** @var array $rows @var \App\Core\Paginator $pager @var array $entities @var string $from @var string $to */
use App\Core\View;

echo View::partial('page_header', [
    'title'       => 'Audit Trail',
    'description' => 'Every change with its old and new values, approvals and break-glass access. Read-only — no role can edit or delete audit entries.',
    'breadcrumbs' => [['Administration'], ['Audit Trail']],
    'actions'     => '<a class="btn btn-excel" href="' . e(url_with(['export' => 'xlsx', 'page' => null])) . '">' . icon('excel') . ' Export</a>',
]);
$fmt = static function (?string $json): string {
    if ($json === null || $json === '') {
        return '';
    }
    $data = json_decode($json, true);
    if (!is_array($data)) {
        return e((string) $json);
    }
    $out = [];
    foreach ($data as $k => $v) {
        $out[] = '<div><span class="muted">' . e((string) $k) . ':</span> ' . e(is_scalar($v) || $v === null ? (string) $v : json_encode($v, JSON_UNESCAPED_UNICODE)) . '</div>';
    }
    return implode('', $out);
};
?>
<div class="card card-flush">
    <div class="card-header">
        <form class="filters" method="get" action="<?= e(form_action('system/audit')) ?>">
            <?= route_field('system/audit') ?>
            <div class="field grow"><label>Search</label><input class="input" type="search" name="q" value="<?= e(input('q', '')) ?>" placeholder="Action, user, record id…"></div>
            <div class="field"><label>Entity</label><select class="input" name="entity"><option value="">All</option><?php foreach ($entities as $en): ?><option<?= selected($en, input('entity', '')) ?>><?= e($en) ?></option><?php endforeach; ?></select></div>
            <div class="field"><label>From</label><input class="input" type="date" name="from" value="<?= e($from) ?>"></div>
            <div class="field"><label>To</label><input class="input" type="date" name="to" value="<?= e($to) ?>"></div>
            <label class="check"><input type="checkbox" name="all" value="1"<?= checked(input('all')) ?>> Include all activity</label>
            <div class="btns"><button class="btn btn-primary"><?= icon('filter') ?> Filter</button></div>
        </form>
    </div>
    <div class="card-body table-wrap">
        <table class="table table-compact">
            <thead><tr><th>When</th><th>User</th><th>Action</th><th>Record</th><th>Old</th><th>New</th><th>IP</th></tr></thead>
            <tbody>
            <?php foreach ($rows as $r): ?>
                <tr><td class="small nowrap"><?= e(fmt_datetime($r['created_at'])) ?></td><td><?= e($r['username']) ?><div class="muted small"><?= e($r['role']) ?></div></td>
                    <td><strong><?= e($r['action']) ?></strong><div class="muted small"><?= e($r['description']) ?></div></td>
                    <td class="small"><?= e($r['entity_type'] ?: $r['module']) ?> #<?= e($r['record_id']) ?></td>
                    <td class="small audit-old"><?= $fmt($r['old_value']) ?></td><td class="small audit-new"><?= $fmt($r['new_value']) ?></td><td class="small muted"><?= e($r['ip_address']) ?></td></tr>
            <?php endforeach; ?>
            <?php if (!$rows): ?><tr><td colspan="7" class="muted">No audit entries in this range.</td></tr><?php endif; ?>
            </tbody>
        </table>
    </div>
    <?= View::partial('pagination', ['pager' => $pager]) ?>
</div>
