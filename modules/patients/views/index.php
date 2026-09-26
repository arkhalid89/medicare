<?php
/** @var array $rows @var \App\Core\Paginator $pager @var array $doctors */
use App\Core\View;

$actions = '<a class="btn btn-excel" href="' . e(url_with(['export' => 'xlsx', 'page' => null])) . '">' . icon('excel') . ' Export to Excel</a>';
if (can('patients.create')) {
    $actions .= '<a class="btn btn-primary" href="' . e(url('patients/create')) . '">' . icon('user-plus') . ' Register Patient</a>';
}
echo View::partial('page_header', [
    'title'       => 'Patients',
    'description' => 'Search by MRN, name, father/husband name, CNIC or phone.',
    'breadcrumbs' => [['Patients']],
    'actions'     => $actions,
]);
?>
<div class="card card-flush">
    <div class="card-header">
        <form class="filters" method="get" action="<?= e(form_action('patients')) ?>">
            <?= route_field('patients') ?>
            <div class="field grow"><label>Search</label><input class="input" type="search" name="q" value="<?= e(input('q', '')) ?>" placeholder="MRN, name, CNIC or phone…" autofocus></div>
            <div class="field"><label>Gender</label>
                <select class="input" name="gender"><option value="">All</option>
                    <?php foreach (\App\Modules\Patients\PatientService::GENDERS as $g): ?><option<?= selected($g, input('gender', '')) ?>><?= e($g) ?></option><?php endforeach; ?>
                </select></div>
            <div class="field"><label>Doctor</label>
                <select class="input" name="doctor_id"><option value="">All</option>
                    <?php foreach ($doctors as $id => $name): ?><option value="<?= $id ?>"<?= selected($id, input('doctor_id', '')) ?>><?= e($name) ?></option><?php endforeach; ?>
                </select></div>
            <div class="field"><label>Registered From</label><input class="input" type="date" name="from" value="<?= e(input('from', '')) ?>"></div>
            <div class="field"><label>To</label><input class="input" type="date" name="to" value="<?= e(input('to', '')) ?>"></div>
            <div class="btns"><button class="btn btn-primary"><?= icon('search') ?> Search</button><a class="btn btn-ghost" href="<?= e(url('patients')) ?>">Reset</a></div>
        </form>
    </div>
    <div class="card-body table-wrap">
        <?php if (!$rows): ?>
            <div class="empty"><?= icon('users') ?><strong>No patients found</strong>Try another search<?= can('patients.create') ? ' or register a new patient.' : '.' ?></div>
        <?php else: ?>
        <table class="table">
            <thead><tr><th>MRN</th><th>Patient</th><th>Gender / Age</th><th>CNIC</th><th>Phone</th><th>City</th><th class="num">Visits</th><th>Last Visit</th><th style="width:1%">Actions</th></tr></thead>
            <tbody>
            <?php foreach ($rows as $r): ?>
                <tr>
                    <td class="nowrap"><a href="<?= e(url('patients/view', ['id' => $r['id']])) ?>"><strong><?= e($r['mrn']) ?></strong></a></td>
                    <td><strong><?= e($r['name']) ?></strong><?php if ($r['guardian_name']): ?><div class="muted small">s/o, d/o, w/o <?= e($r['guardian_name']) ?></div><?php endif; ?></td>
                    <td class="nowrap"><?= e($r['gender']) ?><?= $r['dob'] ? ' · ' . e(age_text($r['dob'])) : '' ?></td>
                    <td class="nowrap"><?= e($r['cnic']) ?></td>
                    <td class="nowrap"><?= e($r['phone']) ?></td>
                    <td><?= e($r['city']) ?></td>
                    <td class="num"><?= (int) $r['visit_count'] ?></td>
                    <td class="nowrap small"><?= e(fmt_date($r['last_visit'])) ?: '<span class="muted">—</span>' ?></td>
                    <td>
                        <div class="actions">
                            <a class="act" title="View history" href="<?= e(url('patients/view', ['id' => $r['id']])) ?>"><?= icon('eye') ?></a>
                            <?php if (can('visits.create')): ?><a class="act success" title="Start visit" href="<?= e(url('visits/new', ['patient_id' => $r['id']])) ?>"><?= icon('stethoscope') ?></a><?php endif; ?>
                            <?php if (can('patients.edit')): ?><a class="act" title="Edit" href="<?= e(url('patients/edit', ['id' => $r['id']])) ?>"><?= icon('edit') ?></a><?php endif; ?>
                            <?php if (can('records.delete')): ?>
                                <form method="post" action="<?= e(url('patients/delete')) ?>" data-confirm="Delete patient <?= e($r['name']) ?> (<?= e($r['mrn']) ?>)? The record and its visits are hidden and can be restored from the Recycle Bin." data-confirm-ok="Delete">
                                    <?= csrf_field() ?><input type="hidden" name="id" value="<?= (int) $r['id'] ?>"><button class="act danger" title="Delete"><?= icon('trash') ?></button>
                                </form>
                            <?php endif; ?>
                        </div>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>
    </div>
    <?= View::partial('pagination', ['pager' => $pager]) ?>
</div>
