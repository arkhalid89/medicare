<?php
use App\Core\DB;
use App\Core\Scope;

/** @var array $user */
$initials = strtoupper(implode('', array_map(static fn ($w) => mb_substr($w, 0, 1), array_slice(preg_split('/\s+/', preg_replace('/^Dr\.?\s+/i', '', trim($user['name']))) ?: ['U'], 0, 2))));

// Alerts: follow-ups due today and overdue (last 3 days) within the user's scope.
$params = [date('Y-m-d', strtotime('-3 days')), today()];
$scopeSql = Scope::sql('v.doctor_id', $params);
$followUps = DB::all(
    "SELECT v.id, v.follow_up_date, p.name, p.mrn FROM visits v JOIN patients p ON p.id = v.patient_id
      WHERE v.deleted_at IS NULL AND p.deleted_at IS NULL AND v.follow_up_date BETWEEN ? AND ?" . $scopeSql . '
      ORDER BY v.follow_up_date DESC, p.name LIMIT 8',
    $params
);
$dueToday = count(array_filter($followUps, static fn ($f) => $f['follow_up_date'] === today()));
?>
<header class="topbar">
    <button type="button" class="icon-btn" data-sidebar-toggle title="Toggle menu" aria-label="Toggle menu"><?= icon('menu') ?></button>

    <?php if (can('search.use')): ?>
    <form class="global-search search-box" action="<?= e(form_action('search')) ?>" method="get" autocomplete="off" data-global-search>
        <?= route_field('search') ?>
        <?= icon('search') ?>
        <input type="search" name="q" placeholder="Search MRN, name, CNIC, phone or visit no…" value="<?= e($_GET['q'] ?? '') ?>" aria-label="Global search">
        <div class="search-results"></div>
    </form>
    <?php else: ?>
    <div class="spacer"></div>
    <?php endif; ?>

    <div class="spacer"></div>

    <div class="dropdown" data-dropdown>
        <button type="button" class="icon-btn" data-dropdown-toggle title="Follow-up alerts" aria-label="Alerts">
            <?= icon('bell') ?>
            <?php if ($dueToday > 0): ?><span class="dot"><?= $dueToday ?></span><?php endif; ?>
        </button>
        <div class="dropdown-menu" style="min-width:300px">
            <div class="dropdown-header">Follow-ups due</div>
            <?php if (!$followUps): ?>
                <div class="muted small" style="padding:.5rem .65rem">No follow-ups due in the last 3 days.</div>
            <?php endif; ?>
            <?php foreach ($followUps as $f): ?>
                <a href="<?= e(url('visits/view', ['id' => $f['id']])) ?>">
                    <?= icon('calendar') ?>
                    <span style="flex:1;min-width:0"><strong><?= e($f['name']) ?></strong><br><span class="muted small"><?= e($f['mrn']) ?></span></span>
                    <?= $f['follow_up_date'] === today() ? '<span class="badge badge-warning">Today</span>' : '<span class="badge badge-danger">' . e(fmt_date($f['follow_up_date'])) . '</span>' ?>
                </a>
            <?php endforeach; ?>
        </div>
    </div>

    <div class="dropdown" data-dropdown>
        <button type="button" class="user-chip" data-dropdown-toggle>
            <span class="avatar"><?= e($initials ?: 'U') ?></span>
            <span class="meta"><strong><?= e($user['name']) ?></strong><span><?= e(role_label($user['role'])) ?></span></span>
            <?= icon('chevron-down') ?>
        </button>
        <div class="dropdown-menu">
            <div class="dropdown-header"><?= e($user['employee_code'] ?: $user['username']) ?></div>
            <a href="<?= e(url('profile')) ?>"><?= icon('user') ?> My Profile</a>
            <a href="<?= e(url('profile/password')) ?>"><?= icon('key') ?> Change Password</a>
            <div class="divider"></div>
            <form method="post" action="<?= e(url('logout')) ?>">
                <?= csrf_field() ?>
                <button type="submit"><?= icon('logout') ?> Logout</button>
            </form>
        </div>
    </div>
</header>
