<?php
/**
 * Doctor dashboard — same layout as the admin dashboard, limited to the doctor's own work.
 * @var array $user @var array $today @var array $month @var int $lastMonth @var int $followDue @var array $pending
 * @var array $years @var array $visitTrend @var array $topDiagnoses @var array $topMedicines @var array $recentVisits @var array $followUps
 */
use App\Core\View;

$hour = (int) date('G');
$greet = $hour < 12 ? 'Good morning' : ($hour < 17 ? 'Good afternoon' : 'Good evening');
echo View::partial('page_header', [
    'title'       => $greet . ', ' . $user['name'],
    'description' => date('l, d F Y') . ' — your practice at a glance.',
    'actions'     => '<a class="btn btn-outline" href="' . e(url('reports/fees')) . '">' . icon('money') . ' Fee Report</a>'
        . '<a class="btn btn-gold" href="' . e(url('visits/new')) . '">' . icon('stethoscope') . ' New Visit</a>',
]);
$change = $lastMonth > 0 ? (int) round(((int) $month['visits'] - $lastMonth) / $lastMonth * 100) : null;
$monthNames = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
$bar = static function (array $rows, string $class = ''): string {
    if (!$rows) {
        return '<div class="empty">' . icon('chart') . '<strong>No data yet</strong>Figures appear after your first visits.</div>';
    }
    $max = max(array_map(static fn ($r) => (int) $r['total'], $rows)) ?: 1;
    $html = '<div class="hbar">';
    foreach ($rows as $r) {
        $html .= '<div class="nowrap" style="overflow:hidden;text-overflow:ellipsis" title="' . e($r['name']) . '">' . e($r['name']) . '</div>'
            . '<div class="track"><div class="fill ' . $class . '" style="width:' . round((int) $r['total'] / $max * 100) . '%"></div></div><strong>' . (int) $r['total'] . '</strong>';
    }
    return $html . '</div>';
};
?>
<div class="grid grid-4 mb-2">
    <div class="stat"><div class="stat-icon"><?= icon('stethoscope') ?></div><div><p class="label">Today's Visits</p><div class="value"><?= (int) $today['visits'] ?></div><div class="sub"><?= (int) $today['patients'] ?> patient(s) seen</div></div></div>
    <div class="stat"><div class="stat-icon green"><?= icon('money') ?></div><div><p class="label">Today's Fee</p><div class="value"><?= e(money($today['fee'])) ?></div><div class="sub">Bills <?= e(money($today['grand'])) ?></div></div></div>
    <div class="stat"><div class="stat-icon gold"><?= icon('calendar') ?></div><div><p class="label">This Month</p>
        <div class="value"><?= number_format((int) $month['visits']) ?><?php if ($change !== null): ?> <span class="trend-chip <?= $change >= 0 ? 'up' : 'down' ?>"><?= $change >= 0 ? '▲' : '▼' ?> <?= abs($change) ?>%</span><?php endif; ?></div>
        <div class="sub">Fee <?= e(money($month['fee'])) ?> · last month <?= $lastMonth ?></div></div></div>
    <div class="stat"><div class="stat-icon orange"><?= icon('bell') ?></div><div><p class="label">Follow-ups Today</p><div class="value"><?= $followDue ?></div><div class="sub">Pending fees <?= e(money($pending['amount'])) ?> (<?= (int) $pending['n'] ?>)</div></div></div>
</div>

<div class="grid grid-2 mb-2">
    <div class="card">
        <div class="card-header"><div class="card-title"><?= icon('activity') ?> My Visits — last 30 days</div></div>
        <div class="card-body"><div class="chart" data-chart='<?= e(json_encode(['type' => 'line', 'labels' => $visitTrend['labels'], 'series' => [['name' => 'Visits', 'data' => $visitTrend['data']]]])) ?>'></div></div>
    </div>
    <div class="card">
        <div class="card-header">
            <div class="card-title"><?= icon('chart') ?> Visits by month</div>
            <div class="flex small"><span class="badge badge-primary"><?= e($years[0]['name']) ?>: <?= $years[0]['total'] ?></span><span class="badge badge-gold"><?= e($years[1]['name']) ?>: <?= $years[1]['total'] ?></span></div>
        </div>
        <div class="card-body"><div class="chart" data-chart='<?= e(json_encode(['type' => 'bar', 'labels' => $monthNames, 'series' => array_map(static fn ($y) => ['name' => $y['name'], 'data' => $y['data']], $years)])) ?>'></div></div>
    </div>
</div>

<div class="grid grid-3 mb-2">
    <div class="card card-flush">
        <div class="card-header"><div class="card-title"><?= icon('bell') ?> Upcoming Follow-ups</div><span class="muted small">next 7 days</span></div>
        <div class="card-body">
            <?php if (!$followUps): ?><div class="empty"><?= icon('calendar') ?><strong>All clear</strong></div><?php else: ?>
            <ul class="list">
                <?php foreach ($followUps as $f): ?>
                    <li>
                        <div class="grow"><div class="title"><a href="<?= e(url('patients/view', ['id' => $f['patient_id']])) ?>"><?= e($f['name']) ?></a></div><div class="meta"><?= e($f['mrn']) ?><?= $f['phone'] ? ' · ' . e($f['phone']) : '' ?></div></div>
                        <span class="badge <?= $f['follow_up_date'] === today() ? 'badge-warning' : 'badge-primary' ?>"><?= $f['follow_up_date'] === today() ? 'Today' : e(fmt_date($f['follow_up_date'])) ?></span>
                        <a class="act" title="Start visit" href="<?= e(url('visits/new', ['patient_id' => $f['patient_id']])) ?>"><?= icon('stethoscope') ?></a>
                    </li>
                <?php endforeach; ?>
            </ul>
            <?php endif; ?>
        </div>
    </div>
    <div class="card">
        <div class="card-header"><div class="card-title"><?= icon('clipboard') ?> My Top Diagnoses <span class="muted small">(90 days)</span></div></div>
        <div class="card-body"><?= $bar($topDiagnoses) ?></div>
    </div>
    <div class="card">
        <div class="card-header"><div class="card-title"><?= icon('pill') ?> My Top Medicines <span class="muted small">(90 days)</span></div></div>
        <div class="card-body"><?= $bar($topMedicines, 'gold') ?></div>
    </div>
</div>

<div class="card card-flush">
    <div class="card-header"><div class="card-title"><?= icon('calendar') ?> Recent Visits</div><a class="btn btn-sm btn-ghost" href="<?= e(url('visits')) ?>">View all</a></div>
    <div class="card-body table-wrap">
        <?php if (!$recentVisits): ?>
            <div class="empty"><?= icon('stethoscope') ?><strong>No visits yet</strong>Start your first consultation with “New Visit”.</div>
        <?php else: ?>
        <table class="table">
            <thead><tr><th>Visit</th><th>Patient</th><th>Date</th><th class="num">Total</th><th>Payment</th><th style="width:1%"></th></tr></thead>
            <tbody>
            <?php foreach ($recentVisits as $v): ?>
                <tr>
                    <td class="nowrap"><a href="<?= e(url('visits/view', ['id' => $v['id']])) ?>"><?= e($v['visit_no']) ?></a></td>
                    <td><strong><?= e($v['name']) ?></strong> <span class="muted small"><?= e($v['mrn']) ?></span></td>
                    <td class="nowrap"><?= e(fmt_datetime($v['visit_date'])) ?></td>
                    <td class="num"><?= e(money($v['grand_total'])) ?></td>
                    <td><?= payment_badge($v['payment_status']) ?></td>
                    <td><div class="actions">
                        <a class="act" title="Print" target="_blank" href="<?= e(url('visits/print', ['id' => $v['id']])) ?>"><?= icon('printer') ?></a>
                        <a class="act success" title="Repeat" href="<?= e(url('visits/new', ['repeat' => $v['id']])) ?>"><?= icon('repeat') ?></a>
                    </div></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>
    </div>
</div>
