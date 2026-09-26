<?php
/**
 * @var array $user @var array $stats @var array $visitTrend @var array $months
 * @var array $topDiagnoses @var array $topMedicines @var array $recentVisits @var array $recentPatients @var array $activity
 */
use App\Core\View;

$actions = '';
if (can('reports.view')) {
    $actions .= '<a class="btn btn-outline" href="' . e(url('reports/fees')) . '">' . icon('money') . ' Fee Report</a>';
}
if (can('backup.create')) {
    $actions .= '<a class="btn btn-primary" href="' . e(url('system/backup')) . '">' . icon('database') . ' Backup</a>';
}
$scopeNote = $user['role'] === 'dept_admin' ? ' Figures cover your assigned departments.' : '';
echo View::partial('page_header', [
    'title'       => role_label($user['role']) . ' Dashboard',
    'description' => date('l, d F Y') . ' — organisation overview.' . $scopeNote,
    'actions'     => $actions,
]);

$bar = static function (array $rows, string $class = ''): string {
    if (!$rows) {
        return '<div class="empty">' . icon('chart') . '<strong>No data yet</strong>Figures appear after the first visits.</div>';
    }
    $max = max(array_map(static fn ($r) => (int) $r['total'], $rows)) ?: 1;
    $html = '<div class="hbar">';
    foreach ($rows as $r) {
        $pct = round((int) $r['total'] / $max * 100);
        $html .= '<div class="nowrap" style="overflow:hidden;text-overflow:ellipsis" title="' . e($r['name']) . '">' . e($r['name']) . '</div>'
            . '<div class="track"><div class="fill ' . $class . '" style="width:' . $pct . '%"></div></div><strong>' . (int) $r['total'] . '</strong>';
    }
    return $html . '</div>';
};
?>
<div class="grid grid-4 mb-2">
    <div class="stat"><div class="stat-icon"><?= icon('user-cog') ?></div><div><p class="label">Users / Doctors</p><div class="value"><?= $stats['users'] ?> <span class="muted" style="font-size:1rem">/ <?= $stats['doctors'] ?></span></div><div class="sub">Active accounts</div></div></div>
    <div class="stat"><div class="stat-icon gold"><?= icon('users') ?></div><div><p class="label">Total Patients</p><div class="value"><?= number_format($stats['patients']) ?></div><div class="sub">Registered records</div></div></div>
    <div class="stat"><div class="stat-icon green"><?= icon('stethoscope') ?></div><div><p class="label">Today's Visits</p><div class="value"><?= $stats['today_visits'] ?></div><div class="sub">Fee <?= e(money($stats['today_fee'])) ?> · Bills <?= e(money($stats['today_grand'])) ?></div></div></div>
    <div class="stat"><div class="stat-icon orange"><?= icon('calendar') ?></div><div><p class="label">This Month</p><div class="value"><?= number_format($stats['month_visits']) ?></div><div class="sub">Fee <?= e(money($stats['month_fee'])) ?> · Bills <?= e(money($stats['month_grand'])) ?></div></div></div>
</div>

<div class="grid grid-2 mb-2">
    <div class="card">
        <div class="card-header"><div class="card-title"><?= icon('activity') ?> Visit Trend — last 30 days</div></div>
        <div class="card-body"><div class="chart" data-chart='<?= e(json_encode(['type' => 'line', 'labels' => $visitTrend['labels'], 'series' => [['name' => 'Visits', 'data' => $visitTrend['data']]]])) ?>'></div></div>
    </div>
    <div class="card">
        <div class="card-header">
            <div class="card-title"><?= icon('money') ?> Fee Trend — 12 months</div>
            <div class="flex small"><span class="badge badge-primary">Consultation</span><span class="badge badge-gold">Medicines</span></div>
        </div>
        <div class="card-body"><div class="chart" data-chart='<?= e(json_encode(['type' => 'bar', 'money' => true, 'labels' => $months['labels'], 'series' => [['name' => 'Consultation', 'data' => $months['fee']], ['name' => 'Medicines', 'data' => $months['med']]]])) ?>'></div></div>
    </div>
</div>

<div class="grid grid-3 mb-2">
    <div class="card">
        <div class="card-header"><div class="card-title"><?= icon('user-plus') ?> Patient Registrations</div></div>
        <div class="card-body"><div class="chart" style="height:200px" data-chart='<?= e(json_encode(['type' => 'bar', 'labels' => $months['labels'], 'series' => [['name' => 'Registrations', 'data' => $months['reg']]]])) ?>'></div></div>
    </div>
    <div class="card">
        <div class="card-header"><div class="card-title"><?= icon('clipboard') ?> Popular Diagnoses <span class="muted small">(90 days)</span></div></div>
        <div class="card-body"><?= $bar($topDiagnoses) ?></div>
    </div>
    <div class="card">
        <div class="card-header"><div class="card-title"><?= icon('pill') ?> Popular Medicines <span class="muted small">(90 days)</span></div></div>
        <div class="card-body"><?= $bar($topMedicines, 'gold') ?></div>
    </div>
</div>

<div class="grid <?= $activity ? 'grid-3' : 'grid-2' ?>">
    <div class="card card-flush">
        <div class="card-header"><div class="card-title"><?= icon('calendar') ?> Recent Visits</div><a class="btn btn-sm btn-ghost" href="<?= e(url('visits')) ?>">View all</a></div>
        <div class="card-body">
            <?php if (!$recentVisits): ?><div class="empty"><?= icon('calendar') ?><strong>No visits yet</strong></div><?php else: ?>
            <ul class="list">
                <?php foreach ($recentVisits as $v): ?>
                    <li>
                        <div class="grow">
                            <div class="title"><a href="<?= e(url('visits/view', ['id' => $v['id']])) ?>"><?= e($v['name']) ?></a></div>
                            <div class="meta"><?= e($v['visit_no']) ?> · <?= e($v['doctor_name']) ?> · <?= e(fmt_datetime($v['visit_date'])) ?></div>
                        </div>
                        <strong class="nowrap"><?= e(money($v['grand_total'])) ?></strong>
                    </li>
                <?php endforeach; ?>
            </ul>
            <?php endif; ?>
        </div>
    </div>
    <div class="card card-flush">
        <div class="card-header"><div class="card-title"><?= icon('users') ?> Recent Patients</div><a class="btn btn-sm btn-ghost" href="<?= e(url('patients')) ?>">View all</a></div>
        <div class="card-body">
            <?php if (!$recentPatients): ?><div class="empty"><?= icon('users') ?><strong>No patients yet</strong></div><?php else: ?>
            <ul class="list">
                <?php foreach ($recentPatients as $p): ?>
                    <li>
                        <span class="avatar" style="background:var(--secondary);color:var(--primary)"><?= e(mb_strtoupper(mb_substr($p['name'], 0, 1))) ?></span>
                        <div class="grow">
                            <div class="title"><a href="<?= e(url('patients/view', ['id' => $p['id']])) ?>"><?= e($p['name']) ?></a></div>
                            <div class="meta"><?= e($p['mrn']) ?> · <?= e($p['gender']) ?><?= $p['dob'] ? ' · ' . e(age_text($p['dob'])) : '' ?></div>
                        </div>
                        <span class="muted small nowrap"><?= e(fmt_date($p['created_at'])) ?></span>
                    </li>
                <?php endforeach; ?>
            </ul>
            <?php endif; ?>
        </div>
    </div>
    <?php if ($activity): ?>
    <div class="card card-flush">
        <div class="card-header"><div class="card-title"><?= icon('list') ?> Recent Activity</div><a class="btn btn-sm btn-ghost" href="<?= e(url('system/logs')) ?>">View all</a></div>
        <div class="card-body">
            <ul class="list">
                <?php foreach ($activity as $a): ?>
                    <li>
                        <div class="grow">
                            <div class="title"><?= e($a['action']) ?></div>
                            <div class="meta" style="white-space:nowrap;overflow:hidden;text-overflow:ellipsis"><?= e($a['username'] ?: 'system') ?> · <?= e($a['description']) ?></div>
                        </div>
                        <span class="muted small nowrap"><?= e(date('h:i A', strtotime($a['created_at']))) ?></span>
                    </li>
                <?php endforeach; ?>
            </ul>
        </div>
    </div>
    <?php endif; ?>
</div>
