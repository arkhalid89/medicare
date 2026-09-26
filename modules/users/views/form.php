<?php
/**
 * @var array|null $user @var array $userDepartments @var array $departments @var array $fieldErrors @var string $nextCode
 */
use App\Core\Password;
use App\Core\View;

echo View::partial('page_header', [
    'title'       => $user ? 'Edit User — ' . $user['name'] : 'Add User',
    'description' => 'Account, role, departments and (for doctors) the prescription profile and MRN pattern.',
    'breadcrumbs' => [['Users', url('users')], [$user ? 'Edit' : 'Add']],
]);
$val = static fn (string $f, $d = '') => old($f, $user[$f] ?? $d);
$err = static fn (string $f): string => isset($fieldErrors[$f]) ? '<div class="error-text">' . e($fieldErrors[$f]) . '</div>' : '';
$cls = static fn (string $f): string => isset($fieldErrors[$f]) ? ' is-invalid' : '';
$role = (string) $val('role', 'doctor');
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
                <div class="card-header"><div class="card-title"><?= icon('shield') ?> Role &amp; Access</div></div>
                <div class="card-body">
                    <div class="field mb-2"><label>Role <span class="req">*</span></label>
                        <select class="input<?= $cls('role') ?>" name="role" id="role-select">
                            <?php foreach (config('permissions.roles') as $key => $r): ?><option value="<?= e($key) ?>"<?= selected($key, $role) ?>><?= e($r['label']) ?></option><?php endforeach; ?>
                        </select><?= $err('role') ?></div>
                    <div class="field mb-2"><label>Departments</label>
                        <?php if (!$departments): ?>
                            <div class="hint">No departments yet. Create them under Master Data → Departments.</div>
                        <?php else: ?>
                        <div class="check-list" style="flex-direction:column">
                            <?php foreach ($departments as $id => $name): ?>
                                <label class="check"><input type="checkbox" name="departments[]" value="<?= $id ?>"<?= checked(in_array((int) $id, $userDepartments, true)) ?>> <?= e($name) ?></label>
                            <?php endforeach; ?>
                        </div>
                        <?php endif; ?>
                        <?= $err('departments') ?>
                        <div class="hint">A Department Admin sees doctors, visits and reports of every department assigned here.</div>
                    </div>
                    <div class="field"><label>Status</label>
                        <label class="check"><input type="checkbox" name="is_active" value="1"<?= checked(is_post() ? !empty($_POST['is_active']) : (int) ($user['is_active'] ?? 1) === 1) ?>> Active (can log in)</label><?= $err('is_active') ?></div>
                </div>
            </div>
            <div class="card">
                <div class="card-header"><div class="card-title"><?= icon('info') ?> Role permissions</div></div>
                <div class="card-body small" id="perm-list"></div>
            </div>
        </div>
    </div>
    <div class="card mt-2"><div class="card-footer" style="border-top:0">
        <a class="btn btn-outline" href="<?= e(url('users')) ?>">Cancel</a>
        <button type="submit" class="btn btn-primary"><?= icon('save') ?> Save User</button>
    </div></div>
</form>
<?php
$labels = config('permissions.labels');
$matrix = [];
foreach (config('permissions.roles') as $key => $r) {
    $list = [];
    foreach ($labels as $perm => $label) {
        if (\App\Core\Auth::roleCan($key, $perm)) {
            $list[] = $label;
        }
    }
    $matrix[$key] = $list;
}
?>
<script>
(function () {
    var matrix = <?= json_encode($matrix) ?>;
    var sel = document.getElementById('role-select');
    function sync() {
        document.getElementById('doctor-card').style.display = sel.value === 'doctor' ? '' : 'none';
        document.getElementById('perm-list').innerHTML = '<ul style="margin:0;padding-left:1.1rem">' +
            (matrix[sel.value] || []).map(function (p) { return '<li>' + APP.esc(p) + '</li>'; }).join('') + '</ul>';
    }
    sel.addEventListener('change', sync);
    sync();
})();
</script>
