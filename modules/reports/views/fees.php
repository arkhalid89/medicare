<?php
/**
 * @var string $mode day|patient @var array $rows @var array $subtotals @var \App\Core\Paginator $pager
 * @var array $summary @var array $f @var array $doctors
 */
use App\Core\View;

echo View::partial('page_header', [
    'title'       => 'Fee Report',
    'description' => $mode === 'day' ? 'Day-wise consultation fee and medicine billing.' : 'Patient-wise consultation fee and medicine billing.',
    'breadcrumbs' => [['Reports'], ['Fee Report']],
    'actions'     => '<a class="btn btn-excel" href="' . e(url_with(['export' => 'xlsx', 'page' => null])) . '">' . icon('excel') . ' Export to Excel</a>'
        . '<button type="button" class="btn btn-outline" onclick="window.print()">' . icon('printer') . ' Print</button>',
]);
?>
<div class="tabs">
    <a href="<?= e(url_with(['mode' => 'day', 'page' => null])) ?>" class="<?= $mode === 'day' ? 'active' : '' ?>">Day-wise</a>
    <a href="<?= e(url_with(['mode' => 'patient', 'page' => null])) ?>" class="<?= $mode === 'patient' ? 'active' : '' ?>">Patient-wise</a>
</div>
<div class="card mb-2">
    <div class="card-body">
        <form class="filters" method="get" action="<?= e(form_action('reports/fees')) ?>">
            <?= route_field('reports/fees') ?>
            <input type="hidden" name="mode" value="<?= e($mode) ?>">
            <div class="field"><label>Date From</label><input class="input" type="date" name="from" value="<?= e($f['from']) ?>"></div>
            <div class="field"><label>Date To</label><input class="input" type="date" name="to" value="<?= e($f['to']) ?>"></div>
            <div class="field"><label>Patient</label><input class="input" name="name" value="<?= e($f['name']) ?>"></div>
            <div class="field"><label>MRN</label><input class="input" name="mrn" value="<?= e($f['mrn']) ?>"></div>
            <div class="field"><label>Payment</label><select class="input" name="payment"><option value="">All</option>
                <?php foreach (['Paid', 'Pending', 'Free'] as $s): ?><option<?= selected($s, $f['payment']) ?>><?= $s ?></option><?php endforeach; ?></select></div>
            <?php if (count($doctors) > 1): ?>
            <div class="field"><label>Doctor</label><select class="input" name="doctor_id"><option value="">All</option>
                <?php foreach ($doctors as $id => $name): ?><option value="<?= $id ?>"<?= selected($id, $f['doctor_id']) ?>><?= e($name) ?></option><?php endforeach; ?></select></div>
            <?php endif; ?>
            <div class="btns"><button class="btn btn-primary"><?= icon('filter') ?> Generate</button><a class="btn btn-ghost" href="<?= e(url('reports/fees', ['mode' => $mode])) ?>">Reset</a></div>
        </form>
    </div>
</div>

<?= View::render('reports/_summary', ['summary' => $summary], null) ?>

<div class="card card-flush">
    <div class="card-header"><div class="card-title"><?= icon('money') ?> <?= $mode === 'day' ? 'Day-wise' : 'Patient-wise' ?> · <?= e(fmt_date($f['from'])) ?> to <?= e(fmt_date($f['to'])) ?></div></div>
    <div class="card-body table-wrap">
        <?php if (!$rows): ?>
            <div class="empty"><?= icon('money') ?><strong>No visits in this period</strong></div>
        <?php else: ?>
        <table class="table table-compact">
            <thead><tr>
                <?php if ($mode === 'day'): ?><th>Date</th><th>Patient</th><th>MRN</th><th>Visit</th>
                <?php else: ?><th>Patient</th><th>MRN</th><th>Visit Date</th><th>Visit</th><?php endif; ?>
                <th>Doctor</th><th class="num">Fee</th><th>Payment</th><th class="num">Medicines</th><th class="num">Total</th>
            </tr></thead>
            <tbody>
            <?php $prev = null; foreach ($rows as $i => $r):
                $key = $mode === 'day' ? (string) $r['day'] : (string) $r['patient_id'];
                $groupStart = $key !== $prev;
                $next = $rows[$i + 1] ?? null;
                $groupEnd = !$next || ($mode === 'day' ? (string) $next['day'] : (string) $next['patient_id']) !== $key;
                $prev = $key; ?>
                <tr>
                    <?php if ($mode === 'day'): ?>
                        <td class="nowrap"><?= $groupStart ? '<strong>' . e(fmt_date($r['day'])) . '</strong>' : '' ?></td>
                        <td><?= e($r['name']) ?></td><td class="nowrap"><?= e($r['mrn']) ?></td>
                    <?php else: ?>
                        <td><?= $groupStart ? '<strong>' . e($r['name']) . '</strong>' : '' ?></td><td class="nowrap"><?= $groupStart ? e($r['mrn']) : '' ?></td>
                        <td class="nowrap"><?= e(fmt_date($r['visit_date'])) ?></td>
                    <?php endif; ?>
                    <td class="nowrap"><a href="<?= e(url('visits/view', ['id' => $r['id']])) ?>"><?= e($r['visit_no']) ?></a></td>
                    <td class="small"><?= e($r['doctor_name']) ?></td>
                    <td class="num"><?= e(money($r['consultation_fee'])) ?></td>
                    <td><?= payment_badge($r['payment_status']) ?></td>
                    <td class="num"><?= e(money($r['medicine_net'])) ?></td>
                    <td class="num strong"><?= e(money($r['grand_total'])) ?></td>
                </tr>
                <?php if ($groupEnd && isset($subtotals[$key])): $s = $subtotals[$key]; ?>
                    <tr style="background:var(--secondary-50)">
                        <td colspan="5" class="text-right strong"><?= $mode === 'day' ? 'Total for ' . e(fmt_date($r['day'])) : 'Total for ' . e($r['name']) ?> · <?= (int) $s['visits'] ?> visit(s)</td>
                        <td class="num strong"><?= e(money($s['fee'])) ?></td><td></td>
                        <td class="num strong"><?= e(money($s['med'])) ?></td>
                        <td class="num strong"><?= e(money($s['grand'])) ?></td>
                    </tr>
                <?php endif; ?>
            <?php endforeach; ?>
            </tbody>
            <tfoot><tr><td colspan="5">Grand total for the period</td><td class="num"><?= e(money($summary['fee'])) ?></td><td></td><td class="num"><?= e(money($summary['medicine_net'])) ?></td><td class="num"><?= e(money($summary['grand'])) ?></td></tr></tfoot>
        </table>
        <?php endif; ?>
    </div>
    <?= View::partial('pagination', ['pager' => $pager]) ?>
</div>
