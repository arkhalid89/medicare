<?php
/** @var array $rows @var \App\Core\Paginator $pager @var array $totals @var array $doctors */
use App\Core\View;

$actions = '<a class="btn btn-excel" href="' . e(url_with(['export' => 'xlsx', 'page' => null])) . '">' . icon('excel') . ' Export to Excel</a>';
if (can('visits.create')) {
    $actions .= '<a class="btn btn-gold" href="' . e(url('visits/new')) . '">' . icon('stethoscope') . ' New Visit</a>';
}
echo View::partial('page_header', [
    'title'       => 'Visits',
    'description' => 'All consultations with prescriptions and bills.',
    'breadcrumbs' => [['Visits']],
    'actions'     => $actions,
]);
?>
<div class="card card-flush">
    <div class="card-header">
        <form class="filters" method="get" action="<?= e(form_action('visits')) ?>">
            <?= route_field('visits') ?>
            <div class="field grow"><label>Search</label><input class="input" type="search" name="q" value="<?= e(input('q', '')) ?>" placeholder="Visit no, MRN, patient, phone, CNIC…"></div>
            <div class="field"><label>From</label><input class="input" type="date" name="from" value="<?= e(input('from', '')) ?>"></div>
            <div class="field"><label>To</label><input class="input" type="date" name="to" value="<?= e(input('to', '')) ?>"></div>
            <?php if (count($doctors) > 1): ?>
            <div class="field"><label>Doctor</label><select class="input" name="doctor_id"><option value="">All</option>
                <?php foreach ($doctors as $id => $name): ?><option value="<?= $id ?>"<?= selected($id, input('doctor_id', '')) ?>><?= e($name) ?></option><?php endforeach; ?></select></div>
            <?php endif; ?>
            <div class="field"><label>Payment</label><select class="input" name="payment"><option value="">All</option>
                <?php foreach (['Paid', 'Pending', 'Free'] as $s): ?><option<?= selected($s, input('payment', '')) ?>><?= $s ?></option><?php endforeach; ?></select></div>
            <div class="btns"><button class="btn btn-primary"><?= icon('filter') ?> Filter</button><a class="btn btn-ghost" href="<?= e(url('visits')) ?>">Reset</a></div>
        </form>
    </div>
    <div class="card-body table-wrap">
        <?php if (!$rows): ?>
            <div class="empty"><?= icon('calendar') ?><strong>No visits found</strong></div>
        <?php else: ?>
        <table class="table">
            <thead><tr><th>Visit</th><th>Date</th><th>Patient</th><th>Doctor</th><th>Diagnosis</th><th class="num">Fee</th><th class="num">Medicines</th><th class="num">Grand Total</th><th>Payment</th><th style="width:1%">Actions</th></tr></thead>
            <tbody>
            <?php foreach ($rows as $r): ?>
                <tr>
                    <td class="nowrap"><a href="<?= e(url('visits/view', ['id' => $r['id']])) ?>"><strong><?= e($r['visit_no']) ?></strong></a></td>
                    <td class="nowrap small"><?= e(fmt_datetime($r['visit_date'])) ?></td>
                    <td><a href="<?= e(url('patients/view', ['id' => $r['patient_id']])) ?>"><?= e($r['name']) ?></a><div class="muted small"><?= e($r['mrn']) ?> · <?= e($r['gender']) ?> <?= e(age_text($r['dob'])) ?></div></td>
                    <td class="small"><?= e($r['doctor_name']) ?></td>
                    <td class="small"><?= e(mb_strimwidth((string) $r['diagnoses'], 0, 60, '…')) ?: '<span class="muted">—</span>' ?></td>
                    <td class="num"><?= e(money($r['consultation_fee'])) ?></td>
                    <td class="num"><?= e(money($r['medicine_net'])) ?><div class="muted small"><?= (int) $r['medicine_count'] ?> item(s)</div></td>
                    <td class="num strong"><?= e(money($r['grand_total'])) ?></td>
                    <td><?= payment_badge($r['payment_status']) ?></td>
                    <td><div class="actions">
                        <a class="act" title="View" href="<?= e(url('visits/view', ['id' => $r['id']])) ?>"><?= icon('eye') ?></a>
                        <?php if (can('visits.print')): ?><a class="act" title="<?= (int) $r['print_count'] ? 'Reprint' : 'Print' ?>" target="_blank" href="<?= e(url('visits/print', ['id' => $r['id']])) ?>"><?= icon('printer') ?></a><?php endif; ?>
                        <?php if (can('visits.create')): ?><a class="act success" title="Repeat medicines" href="<?= e(url('visits/new', ['repeat' => $r['id']])) ?>"><?= icon('repeat') ?></a><?php endif; ?>
                    </div></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
            <tfoot><tr><td colspan="5">Totals (all filtered visits)</td><td class="num"><?= e(money($totals['fee'])) ?></td><td class="num"><?= e(money($totals['med'])) ?></td><td class="num"><?= e(money($totals['grand'])) ?></td><td colspan="2"></td></tr></tfoot>
        </table>
        <?php endif; ?>
    </div>
    <?= View::partial('pagination', ['pager' => $pager]) ?>
</div>
