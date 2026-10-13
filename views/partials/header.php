<?php
use App\Core\Auth;
use App\Core\DB;
use App\Core\Notifications;
use App\Core\Scope;

/** @var array $user */
$initials = strtoupper(implode('', array_map(static fn ($w) => mb_substr($w, 0, 1), array_slice(preg_split('/\s+/', preg_replace('/^Dr\.?\s+/i', '', trim($user['name']))) ?: ['U'], 0, 2))));

// Alerts: follow-ups due (doctors / clinical scope) + work waiting for the user's roles.
$followUps = [];
$dueToday = 0;
if (can('visits.view')) {
    $params = [date('Y-m-d', strtotime('-3 days')), today()];
    $scopeSql = Scope::sql('v.doctor_id', $params);
    $followUps = DB::all(
        "SELECT v.id, v.follow_up_date, p.name, p.mrn FROM visits v JOIN patients p ON p.id = v.patient_id
          WHERE v.deleted_at IS NULL AND p.deleted_at IS NULL AND v.follow_up_date BETWEEN ? AND ?" . $scopeSql . '
          ORDER BY v.follow_up_date DESC, p.name LIMIT 8',
        $params
    );
    $dueToday = count(array_filter($followUps, static fn ($f) => $f['follow_up_date'] === today()));
}
$tasks = Notifications::forUser();
$taskCount = array_sum(array_column($tasks, 'count'));

$ctx = Auth::contexts();
$branchId = Auth::branchId();
$deptId = Auth::departmentId();
$showSwitch = count($ctx['branches']) > 1 || count($ctx['departments']) > 1;
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

    <?php if ($showSwitch): ?>
    <form class="ctx-switch" method="post" action="<?= e(url('context')) ?>">
        <?= csrf_field() ?>
        <?php if (count($ctx['branches']) > 1): ?>
            <select class="input input-sm" name="branch_id" title="Branch" onchange="this.form.submit()">
                <?php foreach ($ctx['branches'] as $id => $name): ?><option value="<?= $id ?>"<?= selected($id, $branchId) ?>><?= e($name) ?></option><?php endforeach; ?>
            </select>
        <?php else: ?><input type="hidden" name="branch_id" value="<?= (int) $branchId ?>"><?php endif; ?>
        <?php if (count($ctx['departments']) > 1): ?>
            <select class="input input-sm" name="department_id" title="Department" onchange="this.form.submit()">
                <option value="">All my departments</option>
                <?php foreach ($ctx['departments'] as $id => $name): ?><option value="<?= $id ?>"<?= selected($id, $deptId) ?>><?= e($name) ?></option><?php endforeach; ?>
            </select>
        <?php endif; ?>
    </form>
    <?php endif; ?>

    <div class="dropdown" data-dropdown>
        <button type="button" class="icon-btn" data-dropdown-toggle title="Alerts & tasks" aria-label="Alerts">
            <?= icon('bell') ?>
            <?php if ($dueToday + $taskCount > 0): ?><span class="dot"><?= $dueToday + $taskCount ?></span><?php endif; ?>
        </button>
        <div class="dropdown-menu" style="min-width:300px">
            <?php if ($tasks): ?>
                <div class="dropdown-header">Waiting for you</div>
                <?php foreach ($tasks as $t): ?>
                    <a href="<?= e($t['url']) ?>"><?= icon($t['icon']) ?><span style="flex:1"><?= e($t['label']) ?></span><span class="badge badge-warning"><?= (int) $t['count'] ?></span></a>
                <?php endforeach; ?>
                <div class="divider"></div>
            <?php endif; ?>
            <?php if (can('visits.view')): ?>
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
            <?php elseif (!$tasks): ?>
                <div class="muted small" style="padding:.5rem .65rem">Nothing waiting for you.</div>
            <?php endif; ?>
        </div>
    </div>

    <div class="dropdown" data-dropdown>
        <button type="button" class="user-chip" data-dropdown-toggle>
            <span class="avatar"><?= e($initials ?: 'U') ?></span>
            <span class="meta"><strong><?= e($user['name']) ?></strong><span><?= e(user_role_name($user)) ?></span></span>
            <?= icon('chevron-down') ?>
        </button>
        <div class="dropdown-menu">
            <div class="dropdown-header"><?= e($user['employee_code'] ?: $user['username']) ?></div>
            <a href="<?= e(url('profile')) ?>"><?= icon('user') ?> My Profile</a>
            <a href="<?= e(url('profile/password')) ?>"><?= icon('key') ?> Change Password</a>
            <a href="<?= e(url('profile/2fa')) ?>"><?= icon('lock') ?> Two-factor Login</a>
            <div class="divider"></div>
            <form method="post" action="<?= e(url('logout')) ?>">
                <?= csrf_field() ?>
                <button type="submit"><?= icon('logout') ?> Logout</button>
            </form>
        </div>
    </div>
</header>
