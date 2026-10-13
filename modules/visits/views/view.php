<?php
/** @var array $v  full visit from VisitService::load() */
use App\Core\View;
use App\Modules\Users\UserService;
use App\Modules\Visits\Directions;

$p = $v['patient'];
$actions = '';
if (can('visits.print')) {
    $actions .= '<div class="dropdown" data-dropdown><button type="button" class="btn btn-primary" data-dropdown-toggle>' . icon('printer') . ' ' . ((int) $v['print_count'] ? 'Reprint' : 'Print') . ' ' . icon('chevron-down') . '</button><div class="dropdown-menu">';
    foreach (UserService::PAPERS as $key => $label) {
        $actions .= '<a target="_blank" href="' . e(url('visits/print', ['id' => $v['id'], 'paper' => $key])) . '">' . icon('printer') . ' ' . e($label) . '</a>';
    }
    $actions .= '</div></div>';
}
if (can('visits.create') && !$p['deleted_at']) {
    $actions .= '<a class="btn btn-outline" href="' . e(url('visits/new', ['repeat' => $v['id']])) . '">' . icon('repeat') . ' Repeat Visit</a>';
}
if (can('records.delete')) {
    $actions .= '<form method="post" action="' . e(url('visits/delete')) . '" data-confirm="Delete visit ' . e($v['visit_no']) . '? It moves to the Recycle Bin and is excluded from reports." data-confirm-ok="Delete">'
        . csrf_field() . '<input type="hidden" name="id" value="' . (int) $v['id'] . '"><button class="btn btn-danger">' . icon('trash') . ' Delete</button></form>';
}
echo View::partial('page_header', [
    'title'       => 'Visit Summary',
    'description' => $v['visit_no'] . ' · ' . fmt_datetime($v['visit_date']) . ((int) $v['print_count'] ? ' · printed ' . (int) $v['print_count'] . ' time(s)' : ''),
    'breadcrumbs' => [['Visits', url('visits')], [$v['visit_no']]],
    'actions'     => $actions,
]);

/** Plain-language status for a vital: [text, css class]. */
$status = static function (string $key, $val, $val2 = null): array {
    if ($val === null || $val === '') {
        return ['', ''];
    }
    $n = (float) $val;
    switch ($key) {
        case 'bp':
            $d = (float) $val2;
            if ($n >= 140 || $d >= 90) {
                return ['High', 'high'];
            }
            if ($n < 90 || $d < 60) {
                return ['Low', 'low'];
            }
            return $n >= 120 || $d >= 80 ? ['Slightly raised', 'warn'] : ['Normal', 'ok'];
        case 'pulse':
            return $n > 100 ? ['Fast', 'high'] : ($n < 60 ? ['Slow', 'low'] : ['Normal', 'ok']);
        case 'temperature':
            return $n >= 100.4 ? ['Fever', 'high'] : ($n < 95 ? ['Low', 'low'] : ['Normal', 'ok']);
        case 'spo2':
            return $n < 90 ? ['Low — needs attention', 'high'] : ($n < 95 ? ['Slightly low', 'warn'] : ['Normal', 'ok']);
        case 'resp_rate':
            return $n > 20 ? ['Fast', 'high'] : ($n < 12 ? ['Slow', 'low'] : ['Normal', 'ok']);
        case 'bmi':
            return $n < 18.5 ? ['Underweight', 'warn'] : ($n < 25 ? ['Healthy weight', 'ok'] : ($n < 30 ? ['Overweight', 'warn'] : ['Obese', 'high']));
    }
    return ['', ''];
};
$tiles = [];
if ($v['bp_systolic'] && $v['bp_diastolic']) {
    $tiles[] = ['Blood Pressure', $v['bp_systolic'] . '/' . $v['bp_diastolic'], 'mmHg', $status('bp', $v['bp_systolic'], $v['bp_diastolic']), 'heart'];
}
foreach ([['pulse', 'Pulse', '/min', 'activity'], ['temperature', 'Temperature', '°F', 'thermometer'], ['spo2', 'Oxygen (SpO₂)', '%', 'activity'],
          ['resp_rate', 'Breathing Rate', '/min', 'activity'], ['weight', 'Weight', 'kg', 'user'], ['height', 'Height', 'cm', 'user'], ['bmi', 'BMI', '', 'chart']] as [$k, $label, $unit, $ic]) {
    if ($v[$k] !== null && $v[$k] !== '') {
        $tiles[] = [$label, num($v[$k]), $unit, $status($k, $v[$k]), $ic];
    }
}
foreach ($v['other_vitals_list'] as $label => $val) {
    $tiles[] = [$label, $val, '', ['', ''], 'clipboard'];
}

/** When to take it, from the frequency. */
$timesOfDay = static function (?string $code): array {
    return [
        'OD' => ['Morning'], 'BD' => ['Morning', 'Night'], 'TDS' => ['Morning', 'Afternoon', 'Night'],
        'QID' => ['Morning', 'Noon', 'Evening', 'Night'], 'HS' => ['Night'], 'Weekly' => ['Once a week'],
        'SOS' => ['Only when needed'], 'PRN' => ['Only when needed'], 'STAT' => ['Right now, once'],
    ][$code ?? ''] ?? [];
};
$urduOn = setting('rx_show_urdu', '1') === '1';
$daysLeft = $v['follow_up_date'] ? (int) floor((strtotime($v['follow_up_date']) - strtotime(today())) / 86400) : null;
?>
<div class="visit-hero card mb-2">
    <div class="card-body">
        <div class="hero-row">
            <span class="avatar hero-avatar"><?= e(mb_strtoupper(mb_substr($p['name'], 0, 1))) ?></span>
            <div class="grow">
                <h2 class="hero-name"><?= e($p['name']) ?></h2>
                <div class="muted"><?= e($p['gender']) ?> · <?= e(age_text($p['dob'], $v['visit_date'])) ?><?= $p['guardian_name'] ? ' · s/o, d/o, w/o ' . e($p['guardian_name']) : '' ?></div>
                <div class="flex flex-wrap mt-1">
                    <a class="badge badge-primary" href="<?= e(url('patients/view', ['id' => $p['id']])) ?>">MRN <?= e($p['mrn']) ?></a>
                    <span class="badge"><?= icon('calendar') ?> Visit #<?= (int) $v['visit_number'] ?> · <?= e(fmt_datetime($v['visit_date'])) ?></span>
                    <span class="badge"><?= icon('stethoscope') ?> <?= e($v['doctor_name']) ?></span>
                    <?= payment_badge($v['payment_status']) ?>
                </div>
            </div>
            <div class="hero-total"><small>Total bill</small><strong><?= e(money($v['grand_total'])) ?></strong></div>
        </div>
        <?php if ($v['allergies']): ?><div class="alert alert-danger mt-2 mb-0"><?= icon('alert') ?><div><strong>Allergies:</strong> <?= e($v['allergies']) ?></div></div><?php endif; ?>
        <?php if ($v['repeated_from_visit_id']): ?><div class="alert alert-info mt-2 mb-0"><?= icon('repeat') ?><div>This visit repeats <a href="<?= e(url('visits/view', ['id' => $v['repeated_from_visit_id']])) ?>">an earlier visit</a>.</div></div><?php endif; ?>
    </div>
</div>

<?php if ($tiles): ?>
<h3 class="section-title"><?= icon('heart') ?> Health check today</h3>
<div class="vital-tiles mb-2">
    <?php foreach ($tiles as [$label, $value, $unit, [$text, $cls], $ic]): ?>
        <div class="vtile <?= e($cls) ?>">
            <div class="vt-label"><?= icon($ic) ?> <?= e($label) ?></div>
            <div class="vt-value"><?= e($value) ?> <small><?= e($unit) ?></small></div>
            <?php if ($text): ?><div class="vt-status"><?= e($text) ?></div><?php endif; ?>
        </div>
    <?php endforeach; ?>
</div>
<?php endif; ?>

<div class="grid grid-2 mb-2">
    <div class="card">
        <div class="card-header"><div class="card-title"><?= icon('clipboard') ?> What the doctor found</div></div>
        <div class="card-body plain-list">
            <?php $any = false; foreach (['diagnoses' => 'Diagnosis', 'complaints' => 'Your complaints', 'symptoms' => 'Symptoms'] as $key => $label):
                if (!$v['items'][$key]) { continue; } $any = true; ?>
                <div class="pl-row"><div class="pl-label"><?= e($label) ?></div>
                    <div><?php foreach ($v['items'][$key] as $it): ?><span class="tag<?= $key === 'diagnoses' ? '' : ' free' ?>" style="margin:0 .25rem .25rem 0;padding-right:.6rem"><?= e($it['name']) ?></span><?php endforeach; ?></div></div>
            <?php endforeach; ?>
            <?php if ($v['findings']): $any = true; ?><div class="pl-row"><div class="pl-label">Examination</div><div><?= nl2br(e($v['findings'])) ?></div></div><?php endif; ?>
            <?php if ($v['current_history']): $any = true; ?><div class="pl-row"><div class="pl-label">History</div><div><?= nl2br(e($v['current_history'])) ?></div></div><?php endif; ?>
            <?php if (!$any): ?><p class="muted mb-0">Nothing recorded.</p><?php endif; ?>
        </div>
    </div>
    <div class="card">
        <div class="card-header"><div class="card-title"><?= icon('flask') ?> Tests to get done</div></div>
        <div class="card-body">
            <?php if (!$v['items']['investigations']): ?>
                <p class="muted mb-0">No tests advised.</p>
            <?php else: ?>
                <ul class="check-ul"><?php foreach ($v['items']['investigations'] as $it): ?><li><?= icon('check') ?> <?= e($it['name']) ?></li><?php endforeach; ?></ul>
                <p class="hint mb-0">Bring the reports on your next visit.</p>
            <?php endif; ?>
        </div>
    </div>
</div>

<h3 class="section-title"><?= icon('pill') ?> Your medicines — how to take them</h3>
<?php if (!$v['medicines']): ?>
    <div class="card mb-2"><div class="empty"><?= icon('pill') ?><strong>No medicines prescribed</strong></div></div>
<?php else: ?>
<div class="med-cards mb-2">
    <?php foreach ($v['medicines'] as $i => $m): $times = $timesOfDay($m['frequency_code']); ?>
        <div class="med-card<?= (int) ($m['is_main_rx'] ?? 1) ? '' : ' hospital' ?>">
            <div class="mc-top">
                <span class="rx-no"><?= $i + 1 ?></span>
                <div class="grow">
                    <div class="mc-name"><?= e(Directions::line1($m)) ?></div>
                    <div class="muted small"><?= (int) ($m['is_main_rx'] ?? 1) && (int) $v['rx_split_enabled'] ? 'Buy from any pharmacy' : 'Given by the hospital pharmacy' ?> · Qty <?= e(num($m['quantity'])) ?> <?= e($m['unit']) ?></div>
                </div>
            </div>
            <div class="mc-how"><?= e(ucfirst(Directions::english($m))) ?><?= $m['instructions'] ? '<br><em>' . e($m['instructions']) . '</em>' : '' ?></div>
            <?php if ($urduOn): ?><div class="mc-ur urdu" lang="ur"><?= e(Directions::urdu($m)) ?></div><?php endif; ?>
            <?php if ($times): ?><div class="mc-times"><?php foreach ($times as $t): ?><span><?= e($t) ?></span><?php endforeach; ?></div><?php endif; ?>
        </div>
    <?php endforeach; ?>
</div>
<?php endif; ?>

<div class="grid grid-main mb-2">
    <div>
        <?php if ($v['follow_up_date'] || $v['follow_up_instructions']): ?>
        <div class="card next-visit">
            <div class="card-body flex">
                <div class="nv-date"><?= $v['follow_up_date'] ? '<small>' . e(date('M', strtotime($v['follow_up_date']))) . '</small><strong>' . e(date('d', strtotime($v['follow_up_date']))) . '</strong>' : icon('calendar') ?></div>
                <div class="grow">
                    <div class="strong">Next visit<?= $v['follow_up_date'] ? ': ' . e(date('l, d F Y', strtotime($v['follow_up_date']))) : '' ?></div>
                    <?php if ($daysLeft !== null): ?><div class="muted small"><?= $daysLeft > 0 ? 'in ' . $daysLeft . ' day(s)' : ($daysLeft === 0 ? 'today' : abs($daysLeft) . ' day(s) ago') ?></div><?php endif; ?>
                    <?php if ($v['follow_up_instructions']): ?><div class="mt-1"><?= e($v['follow_up_instructions']) ?></div><?php endif; ?>
                </div>
            </div>
        </div>
        <?php endif; ?>
        <?php if ($v['overall_instructions']): ?>
        <div class="card">
            <div class="card-header"><div class="card-title"><?= icon('info') ?> Doctor's advice</div></div>
            <div class="card-body advice-text"><?= nl2br(e($v['overall_instructions'])) ?></div>
        </div>
        <?php endif; ?>
        <?php if ((int) $v['refills'] > 0): ?><p class="hint mt-1">Refill <?= (int) $v['refills'] ?>: this prescription has been repeated <?= (int) $v['refills'] ?> time(s) from the original.</p><?php endif; ?>
    </div>
    <div class="card">
        <div class="card-header"><div class="card-title"><?= icon('money') ?> Bill</div><?= payment_badge($v['payment_status']) ?></div>
        <div class="card-body summary">
            <div class="row"><span>Hospital medicines</span><strong><?= e(money($v['medicines_total'])) ?></strong></div>
            <?php if ((float) $v['discount_amount'] > 0): ?><div class="row"><span>Discount<?= $v['discount_type'] === 'percent' ? ' (' . num($v['discount_value']) . '%)' : '' ?></span><span>− <?= e(money($v['discount_amount'])) ?></span></div><?php endif; ?>
            <?php if ((float) $v['tax_amount'] > 0): ?><div class="row"><span>Tax (<?= num($v['tax_percent']) ?>%)</span><span><?= e(money($v['tax_amount'])) ?></span></div><?php endif; ?>
            <div class="row"><span>Consultation fee</span><strong><?= e(money($v['consultation_fee'])) ?></strong></div>
            <div class="row total"><span>Total</span><span><?= e(money($v['grand_total'])) ?></span></div>
            <p class="hint mb-0">Main-Rx medicines are bought from any pharmacy and are not part of this bill.</p>
        </div>
    </div>
</div>

<?php
/* v2: encounter details (department, token, invoice, co-sign) and department module panels */
$dept = $v['department_id'] ? \App\Core\DB::value('SELECT name FROM departments WHERE id = ?', [$v['department_id']]) : null;
$tok = $v['token_id'] ? \App\Core\DB::value('SELECT token_label FROM tokens WHERE id = ?', [$v['token_id']]) : null;
$inv = $v['invoice_id'] ? \App\Core\DB::one('SELECT id, invoice_no, status FROM invoices WHERE id = ?', [$v['invoice_id']]) : null;
$cos = $v['cosigned_by'] ? \App\Core\DB::value('SELECT name FROM users WHERE id = ?', [$v['cosigned_by']]) : null;
?>
<div class="card mt-2"><div class="card-body flex flex-wrap" style="gap:1.5rem">
    <?php if ($dept): ?><div><span class="muted small">Department</span><div><strong><?= e($dept) ?></strong></div></div><?php endif; ?>
    <?php if ($tok): ?><div><span class="muted small">Token</span><div><strong><?= e($tok) ?></strong></div></div><?php endif; ?>
    <?php if ($inv): ?><div><span class="muted small">Invoice</span><div><?php if (can('billing.view')): ?><a href="<?= e(url('billing/invoice', ['id' => $inv['id']])) ?>"><?= e($inv['invoice_no']) ?></a><?php else: ?><?= e($inv['invoice_no']) ?><?php endif; ?> <?= status_chip($inv['status']) ?></div></div><?php endif; ?>
    <?php if ((int) $v['cosign_required']): ?><div><span class="muted small">Co-sign</span><div><?= $cos ? '<span class="badge badge-success">Co-signed by ' . e($cos) . '</span>' : '<span class="badge badge-warning">Awaiting consultant co-sign</span>' ?></div></div><?php endif; ?>
</div></div>
<?php foreach (($panels ?? []) as $panel): ?>
    <?= $panel ?>
<?php endforeach; ?>
