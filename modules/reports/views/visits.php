<?php
/** @var array $rows @var \App\Core\Paginator $pager @var array $summary @var array $f @var array $doctors */
use App\Core\View;

echo View::partial('page_header', [
    'title'       => 'Patient Visit Report',
    'description' => 'Visits with complaints, symptoms, diagnosis, investigations, medicines, fee and follow-up.',
    'breadcrumbs' => [['Reports'], ['Patient Visit Report']],
    'actions'     => '<a class="btn btn-excel" href="' . e(url_with(['export' => 'xlsx', 'page' => null])) . '">' . icon('excel') . ' Export to Excel</a>'
        . '<button type="button" class="btn btn-outline" onclick="window.print()">' . icon('printer') . ' Print</button>',
]);
?>
<div class="card mb-2">
    <div class="card-body">
        <form class="filters" method="get" action="<?= e(form_action('reports/visits')) ?>">
            <?= route_field('reports/visits') ?>
            <div class="field"><label>Date From</label><input class="input" type="date" name="from" value="<?= e($f['from']) ?>"></div>
            <div class="field"><label>Date To</label><input class="input" type="date" name="to" value="<?= e($f['to']) ?>"></div>
            <div class="field"><label>Patient Name</label><input class="input" name="name" value="<?= e($f['name']) ?>"></div>
            <div class="field"><label>MRN</label><input class="input" name="mrn" value="<?= e($f['mrn']) ?>"></div>
            <div class="field"><label>CNIC</label><input class="input" name="cnic" value="<?= e($f['cnic']) ?>"></div>
            <div class="field"><label>Phone</label><input class="input" name="phone" value="<?= e($f['phone']) ?>"></div>
            <div class="field"><label>Diagnosis</label><input class="input" name="diagnosis" value="<?= e($f['diagnosis']) ?>" placeholder="Name or ICD code"></div>
            <div class="field"><label>Gender</label><select class="input" name="gender"><option value="">All</option>
                <?php foreach (['Male', 'Female', 'Other'] as $g): ?><option<?= selected($g, $f['gender']) ?>><?= $g ?></option><?php endforeach; ?></select></div>
            <?php if (count($doctors) > 1): ?>
            <div class="field"><label>Doctor</label><select class="input" name="doctor_id"><option value="">All</option>
                <?php foreach ($doctors as $id => $name): ?><option value="<?= $id ?>"<?= selected($id, $f['doctor_id']) ?>><?= e($name) ?></option><?php endforeach; ?></select></div>
            <?php endif; ?>
            <div class="btns"><button class="btn btn-primary"><?= icon('filter') ?> Generate</button><a class="btn btn-ghost" href="<?= e(url('reports/visits')) ?>">Reset</a></div>
        </form>
    </div>
</div>

<?= View::render('reports/_summary', ['summary' => $summary], null) ?>

<div class="card card-flush">
    <div class="card-header"><div class="card-title"><?= icon('clipboard') ?> <?= e(fmt_date($f['from'])) ?> to <?= e(fmt_date($f['to'])) ?></div><span class="muted small"><?= number_format($pager->total) ?> visit(s)</span></div>
    <div class="card-body table-wrap">
        <?php if (!$rows): ?>
            <div class="empty"><?= icon('chart') ?><strong>No visits in this period</strong>Change the filters and generate again.</div>
        <?php else: ?>
        <table class="table table-compact">
            <thead><tr><th>Visit Date</th><th>Patient</th><th>MRN</th><th>Complaints</th><th>Symptoms</th><th>Diagnosis</th><th>Investigation</th><th>Medicines</th><th class="num">Fee</th><th>Follow-up</th></tr></thead>
            <tbody>
            <?php foreach ($rows as $r): ?>
                <tr>
                    <td class="nowrap"><?= e(fmt_date($r['visit_date'])) ?><div class="small"><a href="<?= e(url('visits/view', ['id' => $r['id']])) ?>"><?= e($r['visit_no']) ?></a></div></td>
                    <td><a href="<?= e(url('patients/view', ['id' => $r['patient_id']])) ?>"><?= e($r['name']) ?></a><div class="muted small"><?= e($r['gender']) ?> <?= e(age_text($r['dob'], $r['visit_date'])) ?></div></td>
                    <td class="nowrap"><?= e($r['mrn']) ?></td>
                    <td class="small"><?= e($r['complaints']) ?></td>
                    <td class="small"><?= e($r['symptoms']) ?></td>
                    <td class="small"><?= e($r['diagnoses']) ?></td>
                    <td class="small"><?= e($r['investigations']) ?></td>
                    <td class="small"><?= e(mb_strimwidth((string) $r['medicines'], 0, 90, '…')) ?></td>
                    <td class="num"><?= e(money($r['consultation_fee'])) ?><div><?= payment_badge($r['payment_status']) ?></div></td>
                    <td class="nowrap small"><?= e(fmt_date($r['follow_up_date'])) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>
    </div>
    <?= View::partial('pagination', ['pager' => $pager]) ?>
</div>
