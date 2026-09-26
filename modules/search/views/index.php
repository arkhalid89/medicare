<?php
/** @var string $q @var array $patients @var array $visits */
use App\Core\View;

echo View::partial('page_header', [
    'title'       => 'Search',
    'description' => $q !== '' ? count($patients) . ' patient(s) and ' . count($visits) . ' visit(s) for “' . $q . '”' : 'Search patients by MRN, name, CNIC or phone, and visits by visit number.',
    'breadcrumbs' => [['Search']],
]);
?>
<div class="card mb-2"><div class="card-body">
    <form class="filters" method="get" action="<?= e(form_action('search')) ?>">
        <?= route_field('search') ?>
        <div class="field grow" style="max-width:none"><label>Search</label><input class="input" type="search" name="q" value="<?= e($q) ?>" autofocus placeholder="MRN, name, CNIC, phone or visit number"></div>
        <div class="btns"><button class="btn btn-primary"><?= icon('search') ?> Search</button></div>
    </form>
</div></div>

<?php if ($q !== '' && !$patients && !$visits): ?>
    <div class="card"><div class="empty"><?= icon('search') ?><strong>No results</strong>Nothing matches “<?= e($q) ?>”. Try the MRN, CNIC digits or phone number.</div></div>
<?php endif; ?>

<?php if ($patients): ?>
<div class="card card-flush mb-2">
    <div class="card-header"><div class="card-title"><?= icon('users') ?> Patients</div></div>
    <div class="card-body table-wrap"><table class="table">
        <thead><tr><th>MRN</th><th>Name</th><th>Gender / Age</th><th>CNIC</th><th>Phone</th><th>City</th><th style="width:1%"></th></tr></thead>
        <tbody>
        <?php foreach ($patients as $p): ?>
            <tr>
                <td><a href="<?= e(url('patients/view', ['id' => $p['id']])) ?>"><strong><?= e($p['mrn']) ?></strong></a></td>
                <td><?= e($p['name']) ?><?php if ($p['guardian_name']): ?><div class="muted small"><?= e($p['guardian_name']) ?></div><?php endif; ?></td>
                <td><?= e($p['gender']) ?> <?= e($p['age']) ?></td><td><?= e($p['cnic']) ?></td><td><?= e($p['phone']) ?></td><td><?= e($p['city']) ?></td>
                <td><div class="actions"><a class="act" href="<?= e(url('patients/view', ['id' => $p['id']])) ?>" title="View"><?= icon('eye') ?></a>
                    <?php if (can('visits.create')): ?><a class="act success" title="Start visit" href="<?= e(url('visits/new', ['patient_id' => $p['id']])) ?>"><?= icon('stethoscope') ?></a><?php endif; ?></div></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table></div>
</div>
<?php endif; ?>

<?php if ($visits): ?>
<div class="card card-flush">
    <div class="card-header"><div class="card-title"><?= icon('calendar') ?> Visits &amp; Prescriptions</div></div>
    <div class="card-body table-wrap"><table class="table">
        <thead><tr><th>Visit</th><th>Date</th><th>Patient</th><th>Doctor</th><th class="num">Total</th><th style="width:1%"></th></tr></thead>
        <tbody>
        <?php foreach ($visits as $v): ?>
            <tr>
                <td><a href="<?= e(url('visits/view', ['id' => $v['id']])) ?>"><strong><?= e($v['visit_no']) ?></strong></a></td>
                <td><?= e(fmt_datetime($v['visit_date'])) ?></td><td><?= e($v['name']) ?> <span class="muted small"><?= e($v['mrn']) ?></span></td>
                <td><?= e($v['doctor_name']) ?></td><td class="num"><?= e(money($v['grand_total'])) ?></td>
                <td><?php if (can('visits.print')): ?><a class="act" target="_blank" href="<?= e(url('visits/print', ['id' => $v['id']])) ?>" title="Print"><?= icon('printer') ?></a><?php endif; ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table></div>
</div>
<?php endif; ?>
