<?php
use App\Core\Password;
use App\Core\View;

echo View::partial('page_header', [
    'title'       => 'Change Password',
    'description' => 'Choose a new password of at least ' . Password::minLength() . ' characters.',
    'breadcrumbs' => [['My Profile', url('profile')], ['Change Password']],
]);
?>
<form method="post" class="card" style="max-width:520px" action="<?= e(url('profile/password')) ?>">
    <?= csrf_field() ?>
    <div class="card-body">
        <div class="field mb-2">
            <label>Current Password <span class="req">*</span></label>
            <div class="pw-wrap"><input class="input" type="password" name="current_password" required maxlength="100"><button type="button" class="icon-btn pw-toggle" data-pw-toggle><?= icon('eye') ?></button></div>
        </div>
        <div class="field mb-2">
            <label>New Password <span class="req">*</span></label>
            <div class="pw-wrap"><input class="input" type="password" name="new_password" required minlength="<?= Password::minLength() ?>" maxlength="100"><button type="button" class="icon-btn pw-toggle" data-pw-toggle><?= icon('eye') ?></button></div>
        </div>
        <div class="field">
            <label>Confirm New Password <span class="req">*</span></label>
            <div class="pw-wrap"><input class="input" type="password" name="confirm_password" required maxlength="100"><button type="button" class="icon-btn pw-toggle" data-pw-toggle><?= icon('eye') ?></button></div>
        </div>
    </div>
    <div class="card-footer">
        <a href="<?= e(url('profile')) ?>" class="btn btn-outline">Cancel</a>
        <button type="submit" class="btn btn-primary"><?= icon('key') ?> Update Password</button>
    </div>
</form>
