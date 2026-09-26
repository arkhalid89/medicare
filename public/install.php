<?php
/**
 * First-run web installer. Creates the database and tables from
 * database/schema.sql and loads the default master data (plus optional
 * demo patients). Locked by storage/installed.lock once finished.
 */
declare(strict_types=1);

require dirname(__DIR__) . '/core/bootstrap.php';

use App\Core\Csrf;
use App\Core\Installer;
use App\Core\Session;

Session::start();
$installed = Installer::isInstalled();
$error = null;
$done = false;

$checks = [
    ['PHP 8.0 or newer', PHP_VERSION_ID >= 80000, 'Current: ' . PHP_VERSION],
    ['PDO MySQL extension', extension_loaded('pdo_mysql'), 'Enable extension=pdo_mysql in php.ini'],
    ['mbstring extension', extension_loaded('mbstring'), 'Enable extension=mbstring in php.ini'],
    ['storage/ is writable', is_writable(STORAGE_PATH), 'Give the web server write access to storage/'],
    ['public/uploads/ is writable', is_writable(PUBLIC_PATH . '/uploads'), 'Give the web server write access to public/uploads/'],
];
$ready = !in_array(false, array_column($checks, 1), true);

if (!$installed && is_post() && $ready) {
    try {
        Csrf::verify();
        Installer::install(['demo' => !empty($_POST['demo'])]);
        $done = true;
    } catch (\Throwable $e) {
        $error = $e instanceof PDOException && str_contains($e->getMessage(), '[')
            ? 'Database error: ' . $e->getMessage() . ' — check the "db" section of config/config.php and that MySQL is running.'
            : $e->getMessage();
    }
}
$db = config('db');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Install · <?= e(config('app.name')) ?></title>
    <link rel="stylesheet" href="<?= e(asset('css/app.css')) ?>">
</head>
<body>
<div class="auth-panel" style="min-height:100vh">
    <div class="auth-card" style="max-width:640px">
        <div class="flex mb-2"><div class="brand-mark">MC</div><div><h2 class="mb-0"><?= e(config('app.name')) ?> Setup</h2><span class="muted">Version <?= e(config('app.version')) ?></span></div></div>

        <?php if ($installed && !$done): ?>
            <div class="alert alert-info"><?= icon('info') ?><div>The application is already installed. To reinstall, delete <code>storage/installed.lock</code> (this erases all data) or use Database Reset inside the app.</div></div>
            <a class="btn btn-primary" href="<?= e(base_url() . '/index.php') ?>">Open the application</a>
        <?php elseif ($done): ?>
            <div class="alert alert-success"><?= icon('check') ?><div><strong>Installation complete.</strong> The database, tables and default master data are ready.</div></div>
            <table class="table table-compact mb-2">
                <thead><tr><th>Role</th><th>Username</th><th>Password</th></tr></thead>
                <tbody>
                <tr><td>Super Admin</td><td>superadmin</td><td>admin123</td></tr>
                <tr><td>Department Admin</td><td>deptadmin</td><td>admin123</td></tr>
                <tr><td>Doctor</td><td>doctor</td><td>doctor123</td></tr>
                <tr><td>Doctor (2nd)</td><td>doctor2</td><td>doctor123</td></tr>
                <tr><td>Reporting User</td><td>reporting</td><td>report123</td></tr>
                </tbody>
            </table>
            <p class="hint">Change these passwords after the first login (Users → Reset password).</p>
            <a class="btn btn-primary" href="<?= e(base_url() . '/index.php') ?>">Go to Login</a>
        <?php else: ?>
            <?php if ($error): ?><div class="alert alert-danger"><?= icon('alert') ?><div><?= e($error) ?></div></div><?php endif; ?>
            <h3 class="mb-1" style="font-size:1rem">1. Requirements</h3>
            <ul class="list mb-2" style="border:1px solid var(--border);border-radius:10px">
                <?php foreach ($checks as [$label, $ok, $hint]): ?>
                    <li><span class="badge <?= $ok ? 'badge-success' : 'badge-danger' ?>"><?= $ok ? 'OK' : 'Missing' ?></span><div class="grow"><?= e($label) ?><?php if (!$ok): ?><div class="meta"><?= e($hint) ?></div><?php endif; ?></div></li>
                <?php endforeach; ?>
            </ul>
            <h3 class="mb-1" style="font-size:1rem">2. Configuration <span class="muted small">(edit config/config.php to change)</span></h3>
            <dl class="kv mb-2">
                <dt>Base URL</dt><dd><code><?= e(base_url()) ?></code></dd>
                <dt>Database host</dt><dd><?= e($db['host'] . ':' . $db['port']) ?></dd>
                <dt>Database name</dt><dd><?= e($db['name']) ?> <span class="muted small">(created if missing)</span></dd>
                <dt>Database user</dt><dd><?= e($db['user']) ?></dd>
            </dl>
            <form method="post" action="<?= e(base_url() . '/install.php') ?>" data-loading="Installing — this takes a few seconds…">
                <?= csrf_field() ?>
                <label class="check mb-2"><input type="checkbox" name="demo" value="1" checked> Load demo patients &amp; visits (recommended for evaluation)</label>
                <div class="alert alert-warning"><?= icon('alert') ?><div>Installing creates all tables in <strong><?= e($db['name']) ?></strong>. Existing tables with the same names are replaced.</div></div>
                <button type="submit" class="btn btn-primary"<?= $ready ? '' : ' disabled' ?>><?= icon('database') ?> Install Now</button>
            </form>
        <?php endif; ?>
    </div>
</div>
<?= \App\Core\View::partial('common_ui') ?>
<script>window.APP = { base: <?= json_encode(base_url()) ?>, csrf: <?= json_encode(\App\Core\Csrf::token()) ?>, currency: 'Rs.' };</script>
<script src="<?= e(asset('js/app.js')) ?>"></script>
</body>
</html>
