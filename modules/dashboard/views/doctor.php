<?php
/**
 * @var array $user @var array $stats @var int $followDue @var int $newPatients
 * @var array $visitTrend @var array $recentVisits @var array $followUps @var array $templates
 */
use App\Core\View;

$hour = (int) date('G');
$greet = $hour < 12 ? 'Good morning' : ($hour < 17 ? 'Good afternoon' : 'Good evening');
echo View::partial('page_header', [
    'title'       => $greet . ', ' . $user['name'],
    'description' => date('l, d F Y') . ' — here is your practice at a glance.',
    'actions'     => '<a class="btn btn-outline" href="' . e(url('patients/create')) . '">' . icon('user-plus') . ' New Patient</a>'
        . '<a class="btn btn-gold btn-lg" href="' . e(url('visits/new')) . '">' . icon('stethoscope') . ' Start New Visit</a>',
]);
?>
<div class="grid grid-4 mb-2">
    <div class="stat"><div class="stat-icon"><?= icon('users') ?></div><div><p class="label">Today's Patients</p><div class="value"><?= (int) $stats['patients'] ?></div><div class="sub"><?= $newPatients ?> newly registered</div></div></div>
    <div class="stat"><div class="stat-icon gold"><?= icon('stethoscope') ?></div><div><p class="label">Today's Visits</p><div class="value"><?= (int) $stats['visits'] ?></div><div class="sub">Consultations completed</div></div></div>
    <div class="stat"><div class="stat-icon green"><?= icon('money') ?></div><div><p class="label">Today's Fee</p><div class="value"><?= e(money($stats['fee'])) ?></div><div class="sub">Paid <?= e(money($stats['paid'])) ?> · Bills <?= e(money($stats['grand'])) ?></div></div></div>
    <div class="stat"><div class="stat-icon orange"><?= icon('calendar') ?></div><div><p class="label">Follow-ups Due</p><div class="value"><?= $followDue ?></div><div class="sub">Scheduled for today</div></div></div>
</div>

<div class="card mb-2">
    <div class="card-header"><div class="card-title"><?= icon('grid') ?> Quick Actions</div></div>
    <div class="card-body">
        <div class="quick-actions">
            <a class="quick primary" href="<?= e(url('visits/new')) ?>"><?= icon('stethoscope') ?><strong>New Visit</strong><span>One-page consultation</span></a>
            <a class="quick" href="<?= e(url('patients/create')) ?>"><?= icon('user-plus') ?><strong>New Patient</strong><span>Register with MRN</span></a>
            <a class="quick" href="<?= e(url('patients')) ?>"><?= icon('search') ?><strong>Find Patient</strong><span>MRN, CNIC, phone, name</span></a>
            <a class="quick" href="<?= e(url('templates')) ?>"><?= icon('star') ?><strong>Templates</strong><span>Favourite prescriptions</span></a>
            <a class="quick" href="<?= e(url('reports/fees')) ?>"><?= icon('money') ?><strong>Fee Report</strong><span>Day-wise collection</span></a>
        </div>
    </div>
</div>

<div class="grid grid-main">
    <div>
        <div class="card mb-2">
            <div class="card-header"><div class="card-title"><?= icon('activity') ?> My Visits — last 14 days</div></div>
            <div class="card-body"><div class="chart" data-chart='<?= e(json_encode(['type' => 'bar', 'labels' => $visitTrend['labels'], 'series' => [['name' => 'Visits', 'data' => $visitTrend['data']]]])) ?>'></div></div>
        </div>
        <div class="card card-flush">
            <div class="card-header"><div class="card-title"><?= icon('calendar') ?> Recent Visits</div><a href="<?= e(url('visits')) ?>" class="btn btn-sm btn-ghost">View all</a></div>
            <div class="card-body table-wrap">
                <?php if (!$recentVisits): ?>
                    <div class="empty"><?= icon('stethoscope') ?><strong>No visits yet</strong>Start your first consultation with “New Visit”.</div>
                <?php else: ?>
                <table class="table">
                    <thead><tr><th>Visit</th><th>Patient</th><th>Date</th><th class="num">Total</th><th>Payment</th><th></th></tr></thead>
                    <tbody>
                    <?php foreach ($recentVisits as $v): ?>
                        <tr>
                            <td class="nowrap"><a href="<?= e(url('visits/view', ['id' => $v['id']])) ?>"><?= e($v['visit_no']) ?></a></td>
                            <td><strong><?= e($v['name']) ?></strong><div class="muted small"><?= e($v['mrn']) ?></div></td>
                            <td class="nowrap"><?= e(fmt_datetime($v['visit_date'])) ?></td>
                            <td class="num"><?= e(money($v['grand_total'])) ?></td>
                            <td><?= payment_badge($v['payment_status']) ?></td>
                            <td><a class="act" title="Print" target="_blank" href="<?= e(url('visits/print', ['id' => $v['id']])) ?>"><?= icon('printer') ?></a></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
                <?php endif; ?>
            </div>
        </div>
    </div>
    <div>
        <div class="card card-flush mb-2">
            <div class="card-header"><div class="card-title"><?= icon('bell') ?> Upcoming Follow-ups</div></div>
            <div class="card-body">
                <?php if (!$followUps): ?>
                    <div class="empty"><?= icon('calendar') ?><strong>All clear</strong>No follow-ups in the next 7 days.</div>
                <?php else: ?>
                <ul class="list">
                    <?php foreach ($followUps as $f): ?>
                        <li>
                            <div class="grow">
                                <div class="title"><a href="<?= e(url('patients/view', ['id' => $f['patient_id']])) ?>"><?= e($f['name']) ?></a></div>
                                <div class="meta"><?= e($f['mrn']) ?><?= $f['phone'] ? ' · ' . e($f['phone']) : '' ?></div>
                            </div>
                            <span class="badge <?= $f['follow_up_date'] === today() ? 'badge-warning' : 'badge-primary' ?>"><?= $f['follow_up_date'] === today() ? 'Today' : e(fmt_date($f['follow_up_date'])) ?></span>
                            <a class="act" title="Start visit" href="<?= e(url('visits/new', ['patient_id' => $f['patient_id']])) ?>"><?= icon('stethoscope') ?></a>
                        </li>
                    <?php endforeach; ?>
                </ul>
                <?php endif; ?>
            </div>
        </div>
        <div class="card card-flush">
            <div class="card-header"><div class="card-title"><?= icon('star') ?> Favourite Templates</div><a href="<?= e(url('templates/create')) ?>" class="btn btn-sm btn-ghost"><?= icon('plus') ?> New</a></div>
            <div class="card-body">
                <?php if (!$templates): ?>
                    <div class="empty"><?= icon('star') ?><strong>No templates</strong>Save a prescription as a template to reuse it.</div>
                <?php else: ?>
                <ul class="list">
                    <?php foreach ($templates as $t): ?>
                        <li>
                            <div class="grow"><div class="title"><?= e($t['name']) ?></div><div class="meta">Used <?= (int) $t['usage_count'] ?> time(s)</div></div>
                            <a class="btn btn-sm btn-outline" href="<?= e(url('visits/new', ['template_id' => $t['id']])) ?>">Use</a>
                        </li>
                    <?php endforeach; ?>
                </ul>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>
