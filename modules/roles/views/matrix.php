<?php
/** @var array $role @var array $own @var array $inherited @var array $effective @var array $perms @var array|null $base */
use App\Core\View;

echo View::partial('page_header', [
    'title'       => 'Permissions — ' . $role['name'] . ' (' . $role['code'] . ')',
    'description' => $base ? 'Inherits ' . $base['name'] . ' (' . $base['code'] . '). "Inherit" keeps the base role\'s right; grant to add or change the scope; deny to remove it.'
        : 'Grant each permission with a scope. Approve permissions are separate from create/edit, so a requester cannot approve their own request.',
    'breadcrumbs' => [['Roles & Permissions', url('roles')], [$role['code']]],
    'actions'     => '<a class="btn btn-outline" href="' . e(url('roles/edit', ['id' => $role['id']])) . '">' . icon('edit') . ' Role & Workspaces</a>',
]);
$modules = config('permissions.modules');
$scopes = ['own' => 'Own', 'department' => 'Dept', 'branch' => 'Branch', 'chain' => 'Chain'];
?>
<form method="post" action="<?= e(url('roles/matrix', ['id' => $role['id']])) ?>">
    <?= csrf_field() ?>
    <div class="flex mb-2"><input class="input" id="perm-filter" placeholder="Filter permissions…" style="max-width:320px">
        <span class="spacer"></span><span class="muted small"><?= count($effective) ?> effective permission(s)</span>
        <button class="btn btn-primary"><?= icon('save') ?> Save Permissions</button></div>
    <?php foreach ($perms as $module => $list): ?>
    <div class="card card-flush perm-card">
        <div class="card-header"><div class="card-title"><?= e($modules[$module] ?? ucfirst($module)) ?></div></div>
        <div class="card-body table-wrap">
            <table class="table table-compact perm-table">
                <thead><tr><th>Permission</th><?php if ($base): ?><th class="text-center">Inherit</th><?php endif; ?><th class="text-center">None</th>
                    <?php foreach ($scopes as $s): ?><th class="text-center"><?= $s ?></th><?php endforeach; ?><?php if ($base): ?><th class="text-center">Deny</th><?php endif; ?><th>Effective</th></tr></thead>
                <tbody>
                <?php foreach ($list as $p): $code = $p['code']; $cur = $own[$code] ?? ($base ? 'inherit' : ''); $inh = $inherited[$code] ?? null; ?>
                    <tr data-text="<?= e(strtolower($code . ' ' . $p['label'])) ?>">
                        <td><strong><?= e($p['label']) ?></strong><?= (int) $p['is_approval'] ? ' <span class="badge badge-gold">Approve</span>' : '' ?><div class="muted small"><code><?= e($code) ?></code></div></td>
                        <?php if ($base): ?><td class="text-center"><input type="radio" name="perm[<?= e($code) ?>]" value="inherit"<?= checked($cur === 'inherit') ?>><?= $inh ? '<div class="muted small">' . e($inh) . '</div>' : '' ?></td><?php endif; ?>
                        <td class="text-center"><input type="radio" name="perm[<?= e($code) ?>]" value=""<?= checked($cur === '' && !$base) ?>></td>
                        <?php foreach ($scopes as $k => $s): ?><td class="text-center"><input type="radio" name="perm[<?= e($code) ?>]" value="<?= $k ?>"<?= checked($cur === $k) ?>></td><?php endforeach; ?>
                        <?php if ($base): ?><td class="text-center"><input type="radio" name="perm[<?= e($code) ?>]" value="deny"<?= checked($cur === 'deny') ?>></td><?php endif; ?>
                        <td><?= isset($effective[$code]) ? '<span class="badge badge-success">' . e($effective[$code]) . '</span>' : '<span class="muted">—</span>' ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
    <?php endforeach; ?>
    <div class="card"><div class="card-footer" style="border-top:0"><button class="btn btn-primary"><?= icon('save') ?> Save Permissions</button></div></div>
</form>
<script>
document.getElementById('perm-filter').addEventListener('input', function () {
    var q = this.value.toLowerCase();
    document.querySelectorAll('.perm-table tbody tr').forEach(function (tr) { tr.style.display = !q || tr.dataset.text.indexOf(q) > -1 ? '' : 'none'; });
});
</script>
