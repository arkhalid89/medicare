<?php
/**
 * @var array|null $user @var array $userDepartments @var array $departments @var array $branches
 * @var array $roles @var array $assignments @var array $fieldErrors @var string $nextCode
 */
use App\Core\Password;
use App\Core\Rbac;
use App\Core\View;

echo View::partial('page_header', [
    'title'       => $user ? 'Edit User — ' . $user['name'] : 'Add User',
    'description' => 'Account, roles per branch and department, department membership and (for doctors) the prescription profile.',
    'breadcrumbs' => [['Users', url('users')], [$user ? 'Edit' : 'Add']],
]);
$val = static fn (string $f, $d = '') => old($f, $user[$f] ?? $d);
$err = static fn (string $f): string => isset($fieldErrors[$f]) ? '<div class="error-text">' . e($fieldErrors[$f]) . '</div>' : '';
$cls = static fn (string $f): string => isset($fieldErrors[$f]) ? ' is-invalid' : '';
if (!$assignments) {
    $assignments = [['role_id' => 0, 'branch_id' => null, 'department_id' => null, 'is_primary' => true]];
}
$multiBranch = count($branches) > 1;

// Role preview data: class and permission labels per role id.
$labels = config('permissions.permissions');
$roleInfo = [];
foreach ($roles as $group) {
    foreach ($group as $r) {
        $list = [];
        foreach (Rbac::rolePermissions($r['id']) as $code => $scope) {
            $list[] = ($code === '*' ? 'All permissions' : ($labels[$code][1] ?? $code)) . ($scope !== 'branch' ? ' (' . $scope . ')' : '');
        }
        $roleInfo[$r['id']] = ['class' => $r['class'], 'name' => $r['name'], 'perms' => $list];
    }
}
$roleSelect = static function (int $selected) use ($roles): string {
    $html = '<option value="">— Select role —</option>';
    foreach ($roles as $tier => $group) {
        $html .= '<optgroup label="' . e($tier) . '">';
        foreach ($group as $r) {
            $html .= '<option value="' . $r['id'] . '"' . selected($r['id'], $selected) . '>' . e($r['name']) . ' (' . e($r['code']) . ')</option>';
        }
        $html .= '</optgroup>';
    }
    return $html;
};
$branchSelect = static function (?int $selected) use ($branches): string {
    $html = '<option value="">All branches</option>';
    foreach ($branches as $id => $name) {
        $html .= '<option value="' . $id . '"' . ($selected !== null ? selected($id, $selected) : '') . '>' . e($name) . '</option>';
    }
    return $html;
};
$deptSelect = static function (?int $selected) use ($departments): string {
    $html = '<option value="">Whole branch</option>';
    foreach ($departments as $id => $name) {
        $html .= '<option value="' . $id . '"' . ($selected !== null ? selected($id, $selected) : '') . '>' . e($name) . '</option>';
    }
    return $html;
};
?>
<form method="post" action="<?= e(url('users/form', ['id' => $user['id'] ?? null])) ?>" autocomplete="off">
    <?= csrf_field() ?>
    <div class="grid grid-main">
        <div>
            <div class="card">
                <div class="card-header"><div class="card-title"><?= icon('user') ?> Account</div></div>
                <div class="card-body">
                    <div class="form-grid">
                        <div class="field col-6"><label>Full Name <span class="req">*</span></label>
                            <input class="input<?= $cls('name') ?>" name="name" value="<?= e($val('name')) ?>" required maxlength="150"><?= $err('name') ?></div>
                        <div class="field col-6"><label>Employee Code</label>
                            <input class="input" value="<?= e($user['employee_code'] ?? '') ?>" placeholder="Auto: <?= e($nextCode) ?>" readonly>
                            <div class="hint">Generated from the pattern in Settings → Codes &amp; Patterns.</div></div>
                        <div class="field col-6"><label>Username <span class="req">*</span></label>
                            <input class="input<?= $cls('username') ?>" name="username" value="<?= e($val('username')) ?>" required maxlength="60"><?= $err('username') ?></div>
                        <div class="field col-6"><label>Password <?= $user ? '' : '<span class="req">*</span>' ?></label>
                            <div class="pw-wrap"><input class="input<?= $cls('password') ?>" type="password" name="password" value="" <?= $user ? '' : 'required' ?> minlength="<?= Password::minLength() ?>" maxlength="100" placeholder="<?= $user ? 'Leave blank to keep current' : 'Minimum ' . Password::minLength() . ' characters' ?>">
                            <button type="button" class="icon-btn pw-toggle" data-pw-toggle><?= icon('eye') ?></button></div><?= $err('password') ?></div>
                        <div class="field col-4"><label>Mobile</label>
                            <input class="input<?= $cls('mobile') ?>" name="mobile" value="<?= e($val('mobile')) ?>" maxlength="20" placeholder="03001234567"><?= $err('mobile') ?></div>
                        <div class="field col-4"><label>Email</label>
                            <input class="input<?= $cls('email') ?>" type="email" name="email" value="<?= e($val('email')) ?>" maxlength="150"><?= $err('email') ?></div>
                        <div class="field col-4"><label>CNIC</label>
                            <input class="input<?= $cls('cnic') ?>" name="cnic" value="<?= e($val('cnic')) ?>" maxlength="15" placeholder="35202-1234567-1"><?= $err('cnic') ?></div>
                    </div>
                </div>
            </div>

            <div class="card">
                <div class="card-header"><div class="card-title"><?= icon('shield') ?> Roles &amp; Access</div>
                    <button type="button" class="btn btn-sm btn-outline" id="add-assign"><?= icon('plus') ?> Add Role</button></div>
                <div class="card-body table-wrap">
                    <p class="muted small mt-0">A person may hold several roles (e.g. Head of Medicine + visiting Dentist). A role bound to a department
                        works only in that department; "Whole branch" applies everywhere in the branch. The primary role decides the home screen.</p>
                    <table class="table table-compact" id="assign-table">
                        <thead><tr><th>Role</th><?php if ($multiBranch): ?><th>Branch</th><?php endif; ?><th>Department</th><th class="text-center">Primary</th><th></th></tr></thead>
                        <tbody>
                        <?php foreach ($assignments as $i => $a): ?>
                            <tr>
                                <td><select class="input" name="assign[<?= $i ?>][role_id]" data-role><?= $roleSelect((int) $a['role_id']) ?></select>
                                    <?php if (!$multiBranch): ?><input type="hidden" name="assign[<?= $i ?>][branch_id]" value="<?= e((string) ($a['branch_id'] ?? (array_key_first($branches) ?? ''))) ?>"><?php endif; ?></td>
                                <?php if ($multiBranch): ?><td><select class="input" name="assign[<?= $i ?>][branch_id]"><?= $branchSelect($a['branch_id']) ?></select></td><?php endif; ?>
                                <td><select class="input" name="assign[<?= $i ?>][department_id]"><?= $deptSelect($a['department_id']) ?></select></td>
                                <td class="text-center"><input type="radio" name="primary" value="<?= $i ?>"<?= checked($a['is_primary']) ?>></td>
                                <td><button type="button" class="act danger" data-remove title="Remove"><?= icon('x') ?></button></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                    <?= $err('assign') ?>
                </div>
            </div>

            <div class="card" id="doctor-card">
                <div class="card-header"><div class="card-title"><?= icon('stethoscope') ?> Doctor Profile</div></div>
                <div class="card-body">
                    <div class="form-grid">
                        <div class="field col-4"><label>PMDC Registration</label><input class="input<?= $cls('pmdc_registration') ?>" name="pmdc_registration" value="<?= e($val('pmdc_registration')) ?>" maxlength="50"><?= $err('pmdc_registration') ?></div>
                        <div class="field col-4"><label>Specialization</label><input class="input" name="specialization" value="<?= e($val('specialization')) ?>" maxlength="150" placeholder="General Physician"></div>
                        <div class="field col-4"><label>Default Consultation Fee</label><input class="input<?= $cls('default_fee') ?>" type="number" min="0" step="1" name="default_fee" value="<?= e(num($val('default_fee', 0))) ?>"><?= $err('default_fee') ?></div>
                        <div class="field col-8"><label>Qualification</label><input class="input" name="qualification" value="<?= e($val('qualification')) ?>" maxlength="255" placeholder="MBBS, FCPS"></div>
                        <div class="field col-4"><label>MRN Pattern</label><input class="input<?= $cls('mrn_pattern') ?>" name="mrn_pattern" value="<?= e($val('mrn_pattern')) ?>" maxlength="60" placeholder="<?= e(setting('default_mrn_pattern')) ?>">
                            <?= $err('mrn_pattern') ?><div class="hint">e.g. <code>DR01-{YYYY}-{000001}</code>. Blank = system default.</div></div>
                        <div class="field col-12"><label>Signature Line</label><input class="input" name="signature_text" value="<?= e($val('signature_text')) ?>" maxlength="255" placeholder="Dr. Name, MBBS"></div>
                    </div>
                </div>
            </div>
        </div>

        <div>
            <div class="card">
                <div class="card-header"><div class="card-title"><?= icon('building') ?> Departments &amp; Status</div></div>
                <div class="card-body">
                    <div class="field mb-2"><label>Member of departments</label>
                        <?php if (!$departments): ?>
                            <div class="hint">No departments yet. Create them under Master Data → Departments.</div>
                        <?php else: ?>
                        <div class="check-list" style="flex-direction:column;max-height:260px;overflow:auto">
                            <?php foreach ($departments as $id => $name): ?>
                                <label class="check"><input type="checkbox" name="departments[]" value="<?= $id ?>"<?= checked(in_array((int) $id, $userDepartments, true)) ?>> <?= e($name) ?></label>
                            <?php endforeach; ?>
                        </div>
                        <?php endif; ?>
                        <?= $err('departments') ?>
                        <div class="hint">Department-scope roles (Head of Department, Department Admin, nurses) see the records of these departments.</div>
                    </div>
                    <div class="field"><label>Status</label>
                        <label class="check"><input type="checkbox" name="is_active" value="1"<?= checked(is_post() ? !empty($_POST['is_active']) : (int) ($user['is_active'] ?? 1) === 1) ?>> Active (can log in)</label><?= $err('is_active') ?></div>
                </div>
            </div>
            <div class="card">
                <div class="card-header"><div class="card-title"><?= icon('info') ?> Effective permissions</div></div>
                <div class="card-body small" id="perm-list" style="max-height:420px;overflow:auto"></div>
            </div>
        </div>
    </div>
    <div class="card mt-2"><div class="card-footer" style="border-top:0">
        <a class="btn btn-outline" href="<?= e(url('users')) ?>">Cancel</a>
        <button type="submit" class="btn btn-primary"><?= icon('save') ?> Save User</button>
    </div></div>
</form>
<template id="assign-row">
    <tr>
        <td><select class="input" name="assign[__i__][role_id]" data-role><?= $roleSelect(0) ?></select>
            <?php if (!$multiBranch): ?><input type="hidden" name="assign[__i__][branch_id]" value="<?= e((string) (array_key_first($branches) ?? '')) ?>"><?php endif; ?></td>
        <?php if ($multiBranch): ?><td><select class="input" name="assign[__i__][branch_id]"><?= $branchSelect(null) ?></select></td><?php endif; ?>
        <td><select class="input" name="assign[__i__][department_id]"><?= $deptSelect(null) ?></select></td>
        <td class="text-center"><input type="radio" name="primary" value="__i__"></td>
        <td><button type="button" class="act danger" data-remove title="Remove"><?= icon('x') ?></button></td>
    </tr>
</template>
<script>
(function () {
    var roles = <?= json_encode($roleInfo, JSON_UNESCAPED_UNICODE) ?>;
    var table = document.querySelector('#assign-table tbody');
    var next = table.children.length;
    function sync() {
        var ids = Array.prototype.map.call(table.querySelectorAll('[data-role]'), function (s) { return s.value; }).filter(Boolean);
        var doctor = ids.some(function (id) { return roles[id] && roles[id].class === 'doctor'; });
        document.getElementById('doctor-card').style.display = doctor ? '' : 'none';
        var html = ids.map(function (id) {
            var r = roles[id]; if (!r) return '';
            return '<strong>' + APP.esc(r.name) + '</strong><ul style="margin:.25rem 0 .6rem;padding-left:1.1rem">' +
                r.perms.map(function (p) { return '<li>' + APP.esc(p) + '</li>'; }).join('') + '</ul>';
        }).join('');
        document.getElementById('perm-list').innerHTML = html || '<span class="muted">Select a role to see its permissions.</span>';
    }
    document.getElementById('add-assign').addEventListener('click', function () {
        var tpl = document.getElementById('assign-row').innerHTML.replace(/__i__/g, String(next++));
        var tmp = document.createElement('tbody');
        tmp.innerHTML = tpl;
        table.appendChild(tmp.firstElementChild);
        sync();
    });
    table.addEventListener('change', sync);
    table.addEventListener('click', function (e) {
        var b = e.target.closest('[data-remove]');
        if (b && table.children.length > 1) { b.closest('tr').remove(); sync(); }
    });
    sync();
})();
</script>
