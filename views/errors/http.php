<?php /** @var int $code @var string $message */ ?>
<?php if (!\App\Core\Auth::check()): ?><div class="auth-panel" style="min-height:100vh"><div class="auth-card"><?php endif; ?>
<div class="error-page">
    <div class="code"><?= (int) $code ?><span>.</span></div>
    <h2 class="mt-1"><?= e($title ?? 'Error') ?></h2>
    <p class="muted mt-1"><?= e($message) ?></p>
    <div class="flex" style="justify-content:center">
        <a href="javascript:history.back()" class="btn btn-outline"><?= icon('arrow-left') ?> Go Back</a>
        <a href="<?= e(url(\App\Core\Auth::check() ? 'dashboard' : 'login')) ?>" class="btn btn-primary"><?= icon('home') ?> <?= \App\Core\Auth::check() ? 'Dashboard' : 'Login' ?></a>
    </div>
</div>
<?php if (!\App\Core\Auth::check()): ?></div></div><?php endif; ?>
