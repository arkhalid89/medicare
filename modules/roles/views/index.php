<?php
/** @var array $rows */
use App\Core\View;

echo View::partial('page_header', [
    'title'       => 'Roles & Permissions',
    'description' => 'Base roles hold the common rights; department roles inherit a base role and add or restrict permissions. Each permission has a scope: own, department, branch or chain.',
    'breadcrumbs' => [['Administration'], ['Roles & Permissions']],
    'actions'     => '<a class="btn btn-primary" href="' . e(url('roles/edit')) . '">' . icon('plus') . ' New Role</a>',
]);
$tiers = ['A' => 'Tier A — System & chain', 'B' => 'Tier B — Branch administration', 'C' => 'Tier C — Clinical', 'D' => 'Tier D — Operational & support'];
$current = null;
?>
<div class="card card-flush"><div class="card-body table-wrap">
    <table class="table">
        <thead><tr><th>Code</th><th>Role</th><th>Inherits</th><th>User type</th><th>Home workspace</th><th class="num">Grants</th><th class="num">Users</th><th>Status</th><th style="width:1%"></th></tr></thead>
        <tbody>
        <?php foreach ($rows as $r): ?>
            <?php if ($r['tier'] !== $current): $current = $r['tier']; ?><tr class="group-row"><td colspan="9"><?= e($tiers[$current] ?? $current) ?></td></tr><?php endif; ?>
            <tr><td><code><?= e($r['code']) ?></code></td>
                <td><strong><?= e($r['name']) ?></strong><?= (int) $r['is_system'] ? '' : ' <span class="badge badge-info">Custom</span>' ?>
                    <div class="muted small"><?= e(trim(($r['specialty_name'] ?? '') . ((int) $r['requires_2fa'] ? ' · 2FA' : '') . ((int) $r['requires_cosign'] ? ' · co-sign' : ''), ' ·')) ?></div></td>
                <td><?= $r['base_code'] ? '<code>' . e($r['base_code']) . '</code> ' . e($r['base_name']) : '<span class="muted">—</span>' ?></td>
                <td class="small"><?= e(role_label($r['user_class'])) ?></td><td class="small"><?= e($r['workspace_name']) ?></td>
                <td class="num"><?= (int) $r['grants'] ?><?= (int) $r['denies'] ? ' <span class="muted small">−' . (int) $r['denies'] . '</span>' : '' ?></td>
                <td class="num"><?= (int) $r['users'] ?></td><td><?= status_badge($r['is_active']) ?></td>
                <td class="nowrap"><a class="act" title="Edit" href="<?= e(url('roles/edit', ['id' => $r['id']])) ?>"><?= icon('edit') ?></a>
                    <a class="act" title="Permissions" href="<?= e(url('roles/matrix', ['id' => $r['id']])) ?>"><?= icon('shield') ?></a>
                    <a class="act" title="Users with this role" href="<?= e(url('users', ['role' => $r['code']])) ?>"><?= icon('users') ?></a></td></tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div></div>
