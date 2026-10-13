<?php
/** @var bool $enabled @var string $secret @var string $uri @var string|null $error @var bool $forced */
use App\Core\View;

echo View::partial('page_header', [
    'title'       => 'Two-factor Login',
    'description' => 'A code from your phone is asked at every sign in — required for administrators, cashiers and approvers (Doc 1 §2.10).',
    'breadcrumbs' => [['My Profile', url('profile')], ['Two-factor']],
]);
?>
<?php if ($error): ?><div class="alert alert-danger"><?= icon('alert') ?><div><?= e($error) ?></div></div><?php endif; ?>
<?php if ($forced): ?><div class="alert alert-warning"><?= icon('lock') ?><div>Your role requires two-factor login. Set it up to continue working.</div></div><?php endif; ?>
<div class="grid grid-2" style="max-width:1000px">
    <?php if (!$enabled): ?>
    <form class="card" method="post" action="<?= e(url('profile/2fa')) ?>">
        <?= csrf_field() ?>
        <div class="card-header"><div class="card-title"><?= icon('lock') ?> Turn on</div></div>
        <div class="card-body">
            <ol class="small" style="padding-left:1.1rem">
                <li>Install Google Authenticator or Microsoft Authenticator on your phone.</li>
                <li>Add an account → <strong>Enter a setup key</strong> (time based).</li>
                <li>Account: <code><?= e(auth()['username']) ?></code> — Key:</li>
            </ol>
            <div class="secret-key"><?= e(trim(chunk_split($secret, 4, ' '))) ?></div>
            <div class="hint">Or copy this link into an app that accepts otpauth links: <code style="word-break:break-all"><?= e($uri) ?></code></div>
            <div class="field mt-2"><label>Code shown by the app</label><input class="input" name="code" inputmode="numeric" maxlength="7" required></div>
        </div>
        <div class="card-footer"><button class="btn btn-primary"><?= icon('check') ?> Verify &amp; turn on</button></div>
    </form>
    <?php else: ?>
    <form class="card" method="post" action="<?= e(url('profile/2fa')) ?>">
        <?= csrf_field() ?><input type="hidden" name="disable" value="1">
        <div class="card-header"><div class="card-title"><?= icon('check') ?> Two-factor login is ON</div></div>
        <div class="card-body">
            <p class="muted">To turn it off, confirm with your password.</p>
            <div class="field"><label>Password</label><input class="input" type="password" name="password" required></div>
        </div>
        <div class="card-footer"><button class="btn btn-outline">Turn off</button></div>
    </form>
    <?php endif; ?>
</div>
