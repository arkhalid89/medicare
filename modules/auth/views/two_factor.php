<?php
/** @var string|null $error */
?>
<div class="auth-page">
    <section class="auth-hero">
        <div class="hero-brand"><div class="brand-mark" style="width:44px;height:44px"><?= e(org_initials()) ?></div>
            <div><strong><?= e(config('app.name')) ?></strong><span><?= e(setting('org_name')) ?></span></div></div>
        <div class="hero-copy"><h1>Two-factor <em>sign in</em></h1><p>Open your authenticator app (Google / Microsoft Authenticator) and enter the 6-digit code for this account.</p></div>
        <div class="hero-foot"><?= e(setting('org_address', '')) ?></div>
    </section>
    <section class="auth-panel">
        <div class="auth-card">
            <h2>Enter your code</h2>
            <p class="sub">The code changes every 30 seconds.</p>
            <?php if ($error): ?><div class="alert alert-danger"><?= icon('alert') ?><div><?= e($error) ?></div></div><?php endif; ?>
            <form method="post" action="<?= e(url('login/2fa')) ?>" autocomplete="off">
                <?= csrf_field() ?>
                <div class="field"><label for="code">6-digit code</label>
                    <input class="input" id="code" name="code" inputmode="numeric" pattern="[0-9 ]{6,7}" maxlength="7" required autofocus style="font-size:1.6rem;letter-spacing:.4em;text-align:center"></div>
                <button type="submit" class="btn btn-primary"><?= icon('lock') ?> Verify</button>
            </form>
            <p class="muted small mt-2"><a href="<?= e(url('login')) ?>">Back to sign in</a> · Lost your phone? Ask the administrator to reset two-factor login.</p>
        </div>
    </section>
</div>
