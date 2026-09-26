<?php
/** @var array $v  full visit from VisitService::load() */
use App\Core\View;
use App\Modules\Users\UserService;

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
    $actions .= '<a class="btn btn-outline" href="' . e(url('visits/new', ['repeat' => $v['id']])) . '">' . icon('repeat') . ' Repeat Medicines</a>';
}
if (can('records.delete')) {
    $actions .= '<form method="post" action="' . e(url('visits/delete')) . '" data-confirm="Delete visit ' . e($v['visit_no']) . '? It moves to the Recycle Bin and is excluded from reports." data-confirm-ok="Delete">'
        . csrf_field() . '<input type="hidden" name="id" value="' . (int) $v['id'] . '"><button class="btn btn-danger">' . icon('trash') . ' Delete</button></form>';
}
echo View::partial('page_header', [
    'title'       => 'Visit ' . $v['visit_no'],
    'description' => fmt_datetime($v['visit_date']) . ' · ' . $v['doctor_name'] . ((int) $v['print_count'] ? ' · printed ' . (int) $v['print_count'] . ' time(s)' : ''),
    'breadcrumbs' => [['Visits', url('visits')], [$v['visit_no']]],
    'actions'     => $actions,
]);
$vitals = array_filter([
    'BP'        => $v['bp_systolic'] && $v['bp_diastolic'] ? $v['bp_systolic'] . '/' . $v['bp_diastolic'] . ' mmHg' : null,
    'Pulse'     => $v['pulse'] ? $v['pulse'] . ' /min' : null,
    'Temp'      => $v['temperature'] ? num($v['temperature']) . ' °F' : null,
    'SpO₂'      => $v['spo2'] ? $v['spo2'] . ' %' : null,
    'Resp.'     => $v['resp_rate'] ? $v['resp_rate'] . ' /min' : null,
    'Weight'    => $v['weight'] ? num($v['weight']) . ' kg' : null,
    'Height'    => $v['height'] ? num($v['height']) . ' cm' : null,
    'BMI'       => $v['bmi'] ? num($v['bmi']) : null,
]) + $v['other_vitals_list'];
$lists = ['Presenting Complaints' => 'complaints', 'Symptoms' => 'symptoms', 'Diagnosis' => 'diagnoses', 'Investigations' => 'investigations'];
?>
<?php if ($v['repeated_from_visit_id']): ?><div class="alert alert-info"><?= icon('repeat') ?><div>Medicines repeated from <a href="<?= e(url('visits/view', ['id' => $v['repeated_from_visit_id']])) ?>">an earlier visit</a>.</div></div><?php endif; ?>
<div class="grid grid-main mb-2">
    <div class="card">
        <div class="card-header"><div class="card-title"><?= icon('user') ?> <?= e($p['name']) ?></div><a class="badge badge-primary" href="<?= e(url('patients/view', ['id' => $p['id']])) ?>">MRN <?= e($p['mrn']) ?></a></div>
        <div class="card-body">
            <dl class="kv">
                <dt>Gender / Age</dt><dd><?= e($p['gender']) ?> <?= e(age_text($p['dob'], $v['visit_date'])) ?></dd>
                <dt>CNIC / Phone</dt><dd><?= e($p['cnic'] ?: '—') ?> · <?= e($p['phone'] ?: '—') ?></dd>
                <dt>Address</dt><dd><?= e(trim(($p['address'] ?? '') . ' ' . ($p['city'] ?? ''))) ?: '—' ?></dd>
                <dt>Vitals</dt><dd><?php if (!$vitals): ?><span class="muted">Not recorded</span><?php else: foreach ($vitals as $k => $val): ?><span class="badge" style="margin:0 .25rem .25rem 0"><?= e($k) ?>: <?= e($val) ?></span><?php endforeach; endif; ?></dd>
                <?php foreach ($lists as $label => $key): ?>
                    <dt><?= e($label) ?></dt><dd><?php if (!$v['items'][$key]): ?><span class="muted">—</span><?php else: foreach ($v['items'][$key] as $it): ?><span class="tag<?= $it['id'] ? '' : ' free' ?>" style="margin:0 .25rem .25rem 0;padding-right:.6rem"><?= e($it['name']) ?><?= $it['code'] ? ' <span class="code">' . e($it['code']) . '</span>' : '' ?></span><?php endforeach; endif; ?></dd>
                <?php endforeach; ?>
                <?php if ($v['current_history']): ?><dt>History</dt><dd><?= nl2br(e($v['current_history'])) ?></dd><?php endif; ?>
                <dt>Follow-up</dt><dd><?= $v['follow_up_date'] ? e(fmt_date($v['follow_up_date'])) : '—' ?><?= $v['follow_up_instructions'] ? ' — ' . e($v['follow_up_instructions']) : '' ?></dd>
            </dl>
        </div>
    </div>
    <div class="card">
        <div class="card-header"><div class="card-title"><?= icon('money') ?> Bill</div><?= payment_badge($v['payment_status']) ?></div>
        <div class="card-body summary">
            <div class="row"><span>Medicines Total</span><strong><?= e(money($v['medicines_total'])) ?></strong></div>
            <?php if ((float) $v['discount_amount'] > 0): ?><div class="row"><span>Discount<?= $v['discount_type'] === 'percent' ? ' (' . num($v['discount_value']) . '%)' : '' ?></span><span>− <?= e(money($v['discount_amount'])) ?></span></div><?php endif; ?>
            <?php if ((float) $v['tax_amount'] > 0): ?><div class="row"><span>Tax (<?= num($v['tax_percent']) ?>%)</span><span><?= e(money($v['tax_amount'])) ?></span></div><?php endif; ?>
            <div class="row"><span>Medicine Net</span><strong><?= e(money($v['medicine_net'])) ?></strong></div>
            <div class="row"><span>Consultation Fee</span><strong><?= e(money($v['consultation_fee'])) ?></strong></div>
            <div class="row total"><span>Grand Total</span><span><?= e(money($v['grand_total'])) ?></span></div>
        </div>
    </div>
</div>

<div class="card card-flush">
    <div class="card-header"><div class="card-title"><?= icon('pill') ?> Prescription</div><span class="muted small"><?= count($v['groups']) > 1 ? count($v['groups']) . ' Rx sections (source-wise)' : 'Single Rx' ?> · prices as charged at the time of the visit</span></div>
    <div class="card-body">
        <?php if (!$v['medicines']): ?>
            <div class="empty"><?= icon('pill') ?><strong>No medicines prescribed</strong></div>
        <?php endif; ?>
        <?php foreach ($v['groups'] as $g): ?>
            <?php if (count($v['groups']) > 1): ?><div class="rx-group-title"><?= icon('truck') ?> Rx <?= (int) $g['group'] ?> — <?= e($g['source_name']) ?></div><?php endif; ?>
            <div class="table-wrap"><table class="table">
                <thead><tr><th>#</th><th>Medicine</th><th>Dose</th><th>Frequency</th><th>Route</th><th>Duration</th><th class="num">Qty</th><th class="num">Unit Price</th><th class="num">Total</th><th>Source</th><th>Instructions</th></tr></thead>
                <tbody>
                <?php foreach ($g['lines'] as $i => $m): ?>
                    <tr>
                        <td class="muted"><?= $i + 1 ?></td>
                        <td><strong><?= e($m['medicine_name']) ?></strong> <?= e($m['strength']) ?><div class="muted small"><?= e(trim($m['generic_name'] . ' · ' . $m['dosage_form'], ' ·')) ?></div></td>
                        <td class="nowrap"><?= e(num($m['dose'])) ?> <?= e($m['dose_unit']) ?></td>
                        <td><?= e($m['frequency_code'] ?: $m['frequency_name']) ?></td>
                        <td><?= e($m['route_name']) ?></td>
                        <td class="nowrap"><?= $m['duration_days'] !== null ? (int) $m['duration_days'] . ' day(s)' : '—' ?></td>
                        <td class="num"><?= e(num($m['quantity'])) ?> <?= e($m['unit']) ?><?= (int) $m['qty_manual'] ? ' <span class="badge badge-warning" title="Manually entered">M</span>' : '' ?></td>
                        <td class="num"><?= e(money($m['unit_price'])) ?></td>
                        <td class="num strong"><?= e(money($m['line_total'])) ?></td>
                        <td><?= $m['source_name'] ? '<span class="badge badge-gold">' . e($m['source_name']) . '</span>' : '—' ?></td>
                        <td class="small"><?= e($m['instructions']) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table></div>
        <?php endforeach; ?>
        <?php if ($v['overall_instructions']): ?>
            <div class="alert alert-info" style="margin:1rem"><?= icon('info') ?><div><strong>Overall instructions:</strong><br><?= nl2br(e($v['overall_instructions'])) ?></div></div>
        <?php endif; ?>
    </div>
</div>
