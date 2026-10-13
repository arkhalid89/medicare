<?php
/** @var array|null $role @var array $errors @var array $roles @var array $specialties @var array $workspaces @var array $assigned */
use App\Core\View;

echo View::partial('page_header', [
    'title'       => $role ? 'Edit Role — ' . $role['name'] : 'New Role',
    'description' => 'Role properties and the workspaces (menu entries and home screen) users with this role get.',
    'breadcrumbs' => [['Roles & Permissions', url('roles')], [$role ? $role['code'] : 'New']],
]);
$v = static fn (string $f, $d = '') => old($f, $role[$f] ?? $d);
?>
<?php if ($errors): ?><div class="alert alert-danger"><?= icon('alert') ?><div><?= implode('<br>', array_map('e', $errors)) ?></div></div><?php endif; ?>
<form method="post" action="<?= e(url('roles/edit', ['id' => $role['id'] ?? null])) ?>">
    <?= csrf_field() ?>
    <div class="grid grid-main">
        <div class="card">
            <div class="card-header"><div class="card-title"><?= icon('shield') ?> Role</div></div>
            <div class="card-body"><div class="form-grid">
                <div class="field col-3"><label>Code</label><input class="input" name="code" value="<?= e($v('code')) ?>" maxlength="12" required<?= $role && (int) $role['is_system'] ? ' readonly' : '' ?>></div>
                <div class="field col-9"><label>Name</label><input class="input" name="name" value="<?= e($v('name')) ?>" maxlength="100" required></div>
                <div class="field col-12"><label>Description</label><input class="input" name="description" value="<?= e($v('description')) ?>" maxlength="500"></div>
                <div class="field col-6"><label>Inherits permissions of (base role)</label><select class="input" name="base_role_id"><option value="">— none —</option>
                    <?php foreach ($roles as $id => $n): ?><option value="<?= $id ?>"<?= selected($id, $v('base_role_id')) ?>><?= e($n) ?></option><?php endforeach; ?></select></div>
                <div class="field col-3"><label>Tier</label><select class="input" name="tier"><?php foreach (['A', 'B', 'C', 'D'] as $t): ?><option<?= selected($t, $v('tier', 'D')) ?>><?= $t ?></option><?php endforeach; ?></select></div>
                <div class="field col-3"><label>User type</label><select class="input" name="user_class"><?php foreach (config('permissions.classes') as $k => $l): ?><option value="<?= e($k) ?>"<?= selected($k, $v('user_class', 'staff')) ?>><?= e($l) ?></option><?php endforeach; ?></select>
                    <div class="hint">"Doctor" gives a doctor profile, print designs and appears in doctor lists.</div></div>
                <div class="field col-4"><label>Specialty</label><select class="input" name="specialty_id"><option value="">—</option><?php foreach ($specialties as $id => $n): ?><option value="<?= $id ?>"<?= selected($id, $v('specialty_id')) ?>><?= e($n) ?></option><?php endforeach; ?></select></div>
                <div class="field col-4"><label>Department type</label><select class="input" name="department_type"><option value="">—</option><?php foreach (hospital('department_types') as $k => $l): ?><option value="<?= e($k) ?>"<?= selected($k, $v('department_type')) ?>><?= e($l) ?></option><?php endforeach; ?></select></div>
                <div class="field col-4"><label>Idle logout (minutes)</label><input class="input" type="number" min="0" max="600" name="session_timeout_min" value="<?= e((string) $v('session_timeout_min')) ?>" placeholder="System default"></div>
                <div class="field col-4"><label>Discount limit (PKR)</label><input class="input" type="number" min="0" step="1" name="discount_limit" value="<?= e((string) $v('discount_limit')) ?>" placeholder="No limit set"></div>
                <div class="field col-8"><label>&nbsp;</label><div class="flex flex-wrap">
                    <label class="check"><input type="checkbox" name="requires_2fa" value="1"<?= checked((int) $v('requires_2fa', 0)) ?>> Requires 2FA</label>
                    <label class="check"><input type="checkbox" name="requires_cosign" value="1"<?= checked((int) $v('requires_cosign', 0)) ?>> Entries need consultant co-sign</label>
                    <label class="check"><input type="checkbox" name="is_active" value="1"<?= checked((int) $v('is_active', 1)) ?>> Active</label></div></div>
            </div></div>
            <div class="card-footer">
                <?php if ($role): ?>
                    <?php if (isset(config('permissions.roles')[$role['code']])): ?><button class="btn btn-ghost" type="submit" form="reset-form"><?= icon('refresh') ?> Reset to default</button><?php endif; ?>
                    <?php if (!(int) $role['is_system']): ?><button class="btn btn-ghost" type="submit" form="delete-form"><?= icon('trash') ?> Delete</button><?php endif; ?>
                <?php endif; ?>
                <button class="btn btn-primary"><?= icon('save') ?> Save &amp; edit permissions</button>
            </div>
        </div>
        <div class="card">
            <div class="card-header"><div class="card-title"><?= icon('layers') ?> Workspaces</div></div>
            <div class="card-body">
                <p class="muted small mt-0">Tick the screens this role sees in its menu; the radio button marks the home screen after login.</p>
                <?php $section = null; foreach ($workspaces as $w): ?>
                    <?php if ($w['section'] !== $section): $section = $w['section']; ?><div class="nav-section" style="color:var(--muted)"><?= e($section) ?></div><?php endif; ?>
                    <div class="flex" style="justify-content:space-between">
                        <label class="check"><input type="checkbox" name="workspaces[]" value="<?= (int) $w['id'] ?>"<?= checked(in_array((int) $w['id'], $assigned, true)) ?>> <?= e($w['name']) ?></label>
                        <label class="check small"><input type="radio" name="default_workspace_id" value="<?= (int) $w['id'] ?>"<?= checked((int) ($role['default_workspace_id'] ?? 0) === (int) $w['id']) ?>> home</label>
                    </div>
                <?php endforeach; ?>
                <label class="check small mt-1"><input type="radio" name="default_workspace_id" value="0"<?= checked(!($role['default_workspace_id'] ?? 0)) ?>> Dashboard as home</label>
            </div>
        </div>
    </div>
</form>
<?php if ($role): ?>
<form id="reset-form" method="post" action="<?= e(url('roles/reset')) ?>" data-confirm="Reset <?= e($role['name']) ?> to the default permissions and properties? Custom changes are lost."><?= csrf_field() ?><input type="hidden" name="id" value="<?= (int) $role['id'] ?>"></form>
<form id="delete-form" method="post" action="<?= e(url('roles/delete')) ?>" data-confirm="Delete role <?= e($role['name']) ?>?"><?= csrf_field() ?><input type="hidden" name="id" value="<?= (int) $role['id'] ?>"></form>
<?php endif; ?>
