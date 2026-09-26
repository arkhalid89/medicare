<?php
/** @var array $rows @var \App\Core\Paginator $pager @var array $departments @var bool $canManage @var bool $canDelete */
use App\Core\Password;
use App\Core\View;

$actions = '<a class="btn btn-excel" href="' . e(url_with(['export' => 'xlsx', 'page' => null])) . '">' . icon('excel') . ' Export to Excel</a>';
if ($canManage) {
    $actions .= '<a class="btn btn-primary" href="' . e(url('users/form')) . '">' . icon('user-plus') . ' Add User</a>';
}
echo View::partial('page_header', [
    'title'       => 'Users',
    'description' => 'Super Admins, Department Admins, Doctors and Reporting users.',
    'breadcrumbs' => [['Administration'], ['Users']],
    'actions'     => $actions,
]);
$roleBadge = ['super_admin' => 'badge-danger', 'dept_admin' => 'badge-gold', 'doctor' => 'badge-primary', 'reporting' => 'badge-info'];
?>
<div class="card card-flush">
    <div class="card-header">
        <form class="filters" method="get" action="<?= e(form_action('users')) ?>" data-autosubmit>
            <?= route_field('users') ?>
            <div class="field grow"><label>Search</label><input class="input" type="search" name="q" value="<?= e(input('q', '')) ?>" placeholder="Name, username, code, mobile, CNIC…"></div>
            <div class="field"><label>Role</label>
                <select class="input" name="role"><option value="">All roles</option>
                    <?php foreach (config('permissions.roles') as $key => $r): ?><option value="<?= e($key) ?>"<?= selected($key, input('role', '')) ?>><?= e($r['label']) ?></option><?php endforeach; ?>
                </select>
            </div>
            <div class="field"><label>Department</label>
                <select class="input" name="department"><option value="">All</option>
                    <?php foreach ($departments as $id => $name): ?><option value="<?= $id ?>"<?= selected($id, input('department', '')) ?>><?= e($name) ?></option><?php endforeach; ?>
                </select>
            </div>
            <div class="field"><label>Status</label>
                <select class="input" name="status"><option value="">All</option><option value="active"<?= selected('active', input('status', '')) ?>>Active</option><option value="inactive"<?= selected('inactive', input('status', '')) ?>>Inactive</option></select>
            </div>
            <div class="btns"><button class="btn btn-primary"><?= icon('filter') ?> Filter</button><a class="btn btn-ghost" href="<?= e(url('users')) ?>">Reset</a></div>
        </form>
    </div>
    <div class="card-body table-wrap">
        <?php if (!$rows): ?>
            <div class="empty"><?= icon('users') ?><strong>No users found</strong></div>
        <?php else: ?>
        <table class="table">
            <thead><tr><th>Code</th><th>Name</th><th>Username</th><th>Role</th><th>Departments</th><th>Mobile</th><th>Status</th><th>Last Login</th><th style="width:1%">Actions</th></tr></thead>
            <tbody>
            <?php foreach ($rows as $r): ?>
                <tr>
                    <td class="nowrap"><code><?= e($r['employee_code']) ?></code></td>
                    <td><a href="<?= e(url('users/view', ['id' => $r['id']])) ?>"><strong><?= e($r['name']) ?></strong></a>
                        <?php if ($r['specialization']): ?><div class="muted small"><?= e($r['specialization']) ?></div><?php endif; ?></td>
                    <td><?= e($r['username']) ?></td>
                    <td><span class="badge <?= $roleBadge[$r['role']] ?? '' ?>"><?= e(role_label($r['role'])) ?></span></td>
                    <td class="small"><?= $r['departments'] ? e($r['departments']) : '<span class="muted">—</span>' ?></td>
                    <td class="nowrap"><?= e($r['mobile']) ?></td>
                    <td><?= status_badge($r['is_active']) ?></td>
                    <td class="nowrap small"><?= e(fmt_datetime($r['last_login_at'])) ?: '<span class="muted">Never</span>' ?></td>
                    <td>
                        <div class="actions">
                            <a class="act" title="View" href="<?= e(url('users/view', ['id' => $r['id']])) ?>"><?= icon('eye') ?></a>
                            <?php if ($canManage): ?>
                                <a class="act" title="Edit" href="<?= e(url('users/form', ['id' => $r['id']])) ?>"><?= icon('edit') ?></a>
                                <button type="button" class="act" title="Reset password" data-reset="<?= (int) $r['id'] ?>" data-name="<?= e($r['name']) ?>"><?= icon('key') ?></button>
                                <?php if ((int) $r['id'] !== \App\Core\Auth::id()): ?>
                                <form method="post" action="<?= e(url('users/toggle')) ?>"
                                    <?= (int) $r['is_active'] ? 'data-confirm="Deactivate ' . e($r['name']) . '? The user will not be able to log in." data-confirm-ok="Deactivate"' : '' ?>>
                                    <?= csrf_field() ?><input type="hidden" name="id" value="<?= (int) $r['id'] ?>">
                                    <button class="act <?= (int) $r['is_active'] ? '' : 'success' ?>" title="<?= (int) $r['is_active'] ? 'Deactivate' : 'Activate' ?>"><?= icon('power') ?></button>
                                </form>
                                <?php endif; ?>
                            <?php endif; ?>
                            <?php if ($canDelete && (int) $r['id'] !== \App\Core\Auth::id()): ?>
                                <form method="post" action="<?= e(url('users/delete')) ?>" data-confirm="Delete user <?= e($r['name']) ?>? The account moves to the Recycle Bin." data-confirm-ok="Delete">
                                    <?= csrf_field() ?><input type="hidden" name="id" value="<?= (int) $r['id'] ?>">
                                    <button class="act danger" title="Delete"><?= icon('trash') ?></button>
                                </form>
                            <?php endif; ?>
                        </div>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>
    </div>
    <?= View::partial('pagination', ['pager' => $pager]) ?>
</div>

<?php if ($canManage): ?>
<div class="modal-backdrop" id="reset-modal">
    <form class="modal" method="post" action="<?= e(url('users/password')) ?>">
        <?= csrf_field() ?>
        <input type="hidden" name="id" id="reset-id">
        <div class="modal-head"><?= icon('key') ?><h3>Reset Password</h3><button type="button" class="icon-btn close" data-close><?= icon('x') ?></button></div>
        <div class="modal-body">
            <p>Set a new password for <strong id="reset-name"></strong>.</p>
            <div class="field">
                <label>New Password</label>
                <div class="pw-wrap"><input class="input" type="password" name="new_password" required minlength="<?= Password::minLength() ?>" maxlength="100"><button type="button" class="icon-btn pw-toggle" data-pw-toggle><?= icon('eye') ?></button></div>
                <div class="hint">Minimum <?= Password::minLength() ?> characters.</div>
            </div>
        </div>
        <div class="modal-foot"><button type="button" class="btn btn-outline" data-close>Cancel</button><button type="submit" class="btn btn-primary">Reset Password</button></div>
    </form>
</div>
<script>
document.querySelectorAll('[data-reset]').forEach(function (b) {
    b.addEventListener('click', function () {
        document.getElementById('reset-id').value = b.dataset.reset;
        document.getElementById('reset-name').textContent = b.dataset.name;
        APP.openModal('reset-modal');
    });
});
document.querySelectorAll('#reset-modal [data-close]').forEach(function (b) { b.addEventListener('click', function () { APP.closeModal('reset-modal'); }); });
</script>
<?php endif; ?>
