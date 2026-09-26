<?php
/** @var array $user @var array $departments */
use App\Core\View;

$isDoctor = $user['role'] === 'doctor';
echo View::partial('page_header', [
    'title'       => 'My Profile',
    'description' => 'Your account details' . ($isDoctor ? ' and doctor profile used on prescriptions.' : '.'),
    'breadcrumbs' => [['My Profile']],
    'actions'     => '<a class="btn btn-outline" href="' . e(url('profile/password')) . '">' . icon('key') . ' Change Password</a>',
]);
?>
<div class="grid grid-main">
    <form method="post" class="card" action="<?= e(url('profile')) ?>">
        <?= csrf_field() ?>
        <div class="card-header"><div class="card-title"><?= icon('user') ?> Account</div></div>
        <div class="card-body">
            <div class="form-grid">
                <div class="field col-6">
                    <label>Full Name <span class="req">*</span></label>
                    <input class="input" name="name" value="<?= e(old('name', $user['name'])) ?>" required maxlength="150">
                </div>
                <div class="field col-6">
                    <label>Username</label>
                    <input class="input" value="<?= e($user['username']) ?>" readonly>
                </div>
                <div class="field col-6">
                    <label>Mobile</label>
                    <input class="input" name="mobile" value="<?= e(old('mobile', $user['mobile'])) ?>" maxlength="20" placeholder="03001234567">
                </div>
                <div class="field col-6">
                    <label>Email</label>
                    <input class="input" type="email" name="email" value="<?= e(old('email', $user['email'])) ?>" maxlength="150">
                </div>
                <?php if ($isDoctor): ?>
                    <div class="col-12"><hr class="mt-0 mb-0"></div>
                    <div class="field col-6">
                        <label>PMDC Registration</label>
                        <input class="input" name="pmdc_registration" value="<?= e(old('pmdc_registration', $user['pmdc_registration'])) ?>" maxlength="50">
                    </div>
                    <div class="field col-6">
                        <label>Specialization</label>
                        <input class="input" name="specialization" value="<?= e(old('specialization', $user['specialization'])) ?>" maxlength="150">
                    </div>
                    <div class="field col-8">
                        <label>Qualification</label>
                        <input class="input" name="qualification" value="<?= e(old('qualification', $user['qualification'])) ?>" maxlength="255" placeholder="MBBS, FCPS (Medicine)">
                    </div>
                    <div class="field col-4">
                        <label>Default Consultation Fee</label>
                        <input class="input" type="number" min="0" step="1" name="default_fee" value="<?= e(old('default_fee', num($user['default_fee']))) ?>">
                    </div>
                    <div class="field col-12">
                        <label>Signature Line (printed under signature)</label>
                        <input class="input" name="signature_text" value="<?= e(old('signature_text', $user['signature_text'])) ?>" maxlength="255">
                    </div>
                <?php endif; ?>
            </div>
        </div>
        <div class="card-footer"><button type="submit" class="btn btn-primary"><?= icon('save') ?> Save Profile</button></div>
    </form>

    <div class="card">
        <div class="card-header"><div class="card-title"><?= icon('id-card') ?> Summary</div></div>
        <div class="card-body">
            <dl class="kv">
                <dt>Employee Code</dt><dd><?= e($user['employee_code'] ?: '—') ?></dd>
                <dt>Role</dt><dd><span class="badge badge-primary"><?= e(role_label($user['role'])) ?></span></dd>
                <dt>Departments</dt><dd><?= $departments ? e(implode(', ', $departments)) : '<span class="muted">—</span>' ?></dd>
                <?php if ($isDoctor): ?><dt>MRN Pattern</dt><dd><code><?= e($user['mrn_pattern'] ?: setting('default_mrn_pattern')) ?></code></dd><?php endif; ?>
                <dt>Last Login</dt><dd><?= e(fmt_datetime($user['last_login_at'])) ?: '—' ?></dd>
                <dt>Member Since</dt><dd><?= e(fmt_date($user['created_at'])) ?></dd>
            </dl>
        </div>
    </div>
</div>
