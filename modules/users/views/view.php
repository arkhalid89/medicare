<?php
/** @var array $u @var array $departments @var array|null $stats @var array $activity */
use App\Core\View;

$actions = '';
if (can('users.manage')) {
    $actions .= '<a class="btn btn-primary" href="' . e(url('users/form', ['id' => $u['id']])) . '">' . icon('edit') . ' Edit User</a>';
}
if ($u['role'] === 'doctor' && can('print_designs.manage')) {
    $actions .= '<a class="btn btn-outline" href="' . e(url('settings/print', ['doctor_id' => $u['id']])) . '">' . icon('printer') . ' Print Designs</a>';
}
echo View::partial('page_header', [
    'title'       => $u['name'],
    'description' => role_label($u['role']) . ' · ' . ($u['employee_code'] ?: $u['username']),
    'breadcrumbs' => [['Users', url('users')], [$u['name']]],
    'actions'     => $actions,
]);
?>
<?php if ($stats): ?>
<div class="grid grid-3 mb-2">
    <div class="stat"><div class="stat-icon"><?= icon('stethoscope') ?></div><div><p class="label">Total Visits</p><div class="value"><?= number_format((int) $stats['visits']) ?></div></div></div>
    <div class="stat"><div class="stat-icon gold"><?= icon('users') ?></div><div><p class="label">Patients Seen</p><div class="value"><?= number_format((int) $stats['patients']) ?></div></div></div>
    <div class="stat"><div class="stat-icon green"><?= icon('money') ?></div><div><p class="label">Consultation Fee</p><div class="value"><?= e(money($stats['fee'])) ?></div></div></div>
</div>
<?php endif; ?>
<div class="grid grid-main">
    <div class="card">
        <div class="card-header"><div class="card-title"><?= icon('id-card') ?> Details</div><?= status_badge($u['is_active']) ?></div>
        <div class="card-body">
            <dl class="kv">
                <dt>Employee Code</dt><dd><code><?= e($u['employee_code']) ?></code></dd>
                <dt>Username</dt><dd><?= e($u['username']) ?></dd>
                <dt>Role</dt><dd><?= e(role_label($u['role'])) ?></dd>
                <dt>Departments</dt><dd><?= $departments ? e(implode(', ', $departments)) : '—' ?></dd>
                <dt>Mobile</dt><dd><?= e($u['mobile']) ?: '—' ?></dd>
                <dt>Email</dt><dd><?= e($u['email']) ?: '—' ?></dd>
                <dt>CNIC</dt><dd><?= e($u['cnic']) ?: '—' ?></dd>
                <?php if ($u['role'] === 'doctor'): ?>
                    <dt>PMDC</dt><dd><?= e($u['pmdc_registration']) ?: '—' ?></dd>
                    <dt>Qualification</dt><dd><?= e($u['qualification']) ?: '—' ?></dd>
                    <dt>Specialization</dt><dd><?= e($u['specialization']) ?: '—' ?></dd>
                    <dt>Default Fee</dt><dd><?= e(money($u['default_fee'])) ?></dd>
                    <dt>MRN Pattern</dt><dd><code><?= e($u['mrn_pattern'] ?: setting('default_mrn_pattern') . ' (default)') ?></code></dd>
                <?php endif; ?>
                <dt>Last Login</dt><dd><?= e(fmt_datetime($u['last_login_at'])) ?: 'Never' ?></dd>
                <dt>Created</dt><dd><?= e(fmt_datetime($u['created_at'])) ?></dd>
            </dl>
        </div>
    </div>
    <div class="card card-flush">
        <div class="card-header"><div class="card-title"><?= icon('list') ?> Recent Activity</div></div>
        <div class="card-body">
            <?php if (!$activity): ?><div class="empty"><?= icon('list') ?><strong>No activity recorded</strong></div><?php else: ?>
            <ul class="list">
                <?php foreach ($activity as $a): ?>
                    <li><div class="grow"><div class="title"><?= e($a['action']) ?></div><div class="meta"><?= e($a['description']) ?></div></div><span class="muted small nowrap"><?= e(fmt_datetime($a['created_at'])) ?></span></li>
                <?php endforeach; ?>
            </ul>
            <?php endif; ?>
        </div>
    </div>
</div>
