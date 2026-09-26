<?php
/** @var string|null $error @var array $sampleUsers */
$logo = setting('org_logo', '');
$org = (string) setting('org_name', 'MediCare Clinic');
$initials = org_initials();
$features = [
    ['stethoscope', 'One-page consultation', 'Vitals to print in a single screen'],
    ['pill', 'Smart prescriptions', 'Auto quantity, pricing & Rx split'],
    ['printer', 'A4 · A5 · Thermal · Legal', 'Doctor-specific print designs'],
    ['chart', 'Reports & Excel', 'Visits, fees and every listing'],
    ['shield', 'Role-based access', 'Super Admin, Dept Admin, Doctor, Reporting'],
    ['database', 'Offline & backed up', 'One-click full database backup'],
];
?>
<div class="auth-page">
    <section class="auth-hero">
        <div class="hero-brand">
            <div class="brand-mark" style="width:44px;height:44px"><?php if ($logo): ?><img src="<?= e(upload_url($logo)) ?>" alt=""><?php else: ?><?= e($initials) ?><?php endif; ?></div>
            <div><strong><?= e(config('app.name')) ?></strong><span><?= e($org) ?></span></div>
        </div>

        <div class="hero-copy">
            <h1>Medical practice &amp; prescription management, <em>built for clinical speed.</em></h1>
            <p><?= e(setting('org_tagline', '')) ?: 'Register patients, record vitals, diagnose, prescribe and print — all from one screen.' ?></p>
            <div class="hero-features">
                <?php foreach ($features as [$ic, $t, $s]): ?>
                    <div class="hero-feature"><?= icon($ic) ?><div><strong><?= e($t) ?></strong><span><?= e($s) ?></span></div></div>
                <?php endforeach; ?>
            </div>
        </div>

        <div class="hero-foot">
            <?= e(setting('org_address', '')) ?><?= setting('org_phone') ? ' · ' . e(setting('org_phone')) : '' ?>
            <br>© <?= date('Y') ?> <?= e($org) ?> · <?= e(config('app.name')) ?> v<?= e(config('app.version')) ?>
        </div>
    </section>

    <section class="auth-panel">
        <div class="auth-card">
            <h2>Welcome back</h2>
            <p class="sub">Sign in to continue to your workspace.</p>

            <?= \App\Core\View::partial('flash') ?>
            <?php if ($error): ?>
                <div class="alert alert-danger"><?= icon('alert') ?><div><?= e($error) ?></div></div>
            <?php endif; ?>

            <form method="post" action="<?= e(url('login')) ?>" autocomplete="off">
                <?= csrf_field() ?>
                <div class="field">
                    <label for="username">Username</label>
                    <input class="input" id="username" name="username" value="<?= e(old('username')) ?>" required autofocus maxlength="60">
                </div>
                <div class="field">
                    <label for="password">Password</label>
                    <div class="pw-wrap">
                        <input class="input" id="password" type="password" name="password" required maxlength="100">
                        <button type="button" class="icon-btn pw-toggle" data-pw-toggle aria-label="Show password"><?= icon('eye') ?></button>
                    </div>
                </div>
                <button type="submit" class="btn btn-primary"><?= icon('lock') ?> Sign in</button>
            </form>

            <?php if ($sampleUsers): ?>
                <div class="demo-logins">
                    <strong>Sample logins</strong> <span class="muted">(shown because debug mode is on)</span>
                    <table>
                        <?php foreach ($sampleUsers as $u): ?>
                            <tr>
                                <td><?= e(role_label($u['role'])) ?></td>
                                <td><button type="button" data-fill="<?= e($u['username']) ?>" data-pass="<?= e($u['password']) ?>"><?= e($u['username']) ?></button></td>
                                <td class="muted"><?= e($u['password']) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </table>
                </div>
                <script>
                document.querySelectorAll('[data-fill]').forEach(function (b) {
                    b.addEventListener('click', function () {
                        document.getElementById('username').value = b.dataset.fill;
                        document.getElementById('password').value = b.dataset.pass;
                    });
                });
                </script>
            <?php endif; ?>
        </div>
    </section>
</div>
