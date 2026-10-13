<?php
/** @var array $rows @var string $status @var bool $own @var array $technicians @var array $vendors */
use App\Core\View;
use App\Modules\Dental\DentalService;

echo View::partial('page_header', [
    'title' => 'Dental Lab Cases', 'description' => 'Crowns, bridges, dentures and aligners from creation to fitting. Technicians update the status of their own cases; the dentist records the fitting.',
    'breadcrumbs' => [['Dentistry', url('dental')], ['Lab cases']],
]);
?>
<div class="tabs mb-2"><a class="<?= $status === 'open' ? 'active' : '' ?>" href="<?= e(url('dental/lab-cases')) ?>">Open</a><a class="<?= $status === 'all' ? 'active' : '' ?>" href="<?= e(url('dental/lab-cases', ['status' => 'all'])) ?>">All</a></div>
<div class="card card-flush"><div class="card-body table-wrap"><table class="table">
    <thead><tr><th>Case</th><th>Patient</th><th>Work</th><th>Lab</th><th>Due</th><th>Status</th><th>Update</th></tr></thead><tbody>
    <?php foreach ($rows as $r): $next = DentalService::LAB_STATUSES[$r['status']] ?? []; $late = $r['due_date'] && $r['due_date'] < today() && !in_array($r['status'], ['fitted', 'cancelled', 'received'], true); ?><tr>
        <td><strong><?= e($r['case_number']) ?></strong><div class="muted small"><?= e(fmt_date($r['created_at'])) ?> · <?= e($r['created_by_name']) ?></div></td>
        <td><?= e($r['patient_name']) ?><div class="muted small"><?= e($r['mrn']) ?></div></td>
        <td><?= e(ucfirst($r['case_type'])) ?> <?= e($r['tooth_numbers']) ?><div class="muted small"><?= e(trim($r['shade'] . ' ' . $r['material'])) ?> <?= e($r['instructions']) ?></div></td>
        <td class="small"><?= e($r['tech_name'] ?: ($r['vendor_name'] ?: 'Unassigned')) ?></td>
        <td class="small <?= $late ? 'text-danger' : '' ?>"><?= e(fmt_date($r['due_date'])) ?></td>
        <td><?= status_chip($r['status']) ?><?= $r['status_note'] ? '<div class="muted small">' . e($r['status_note']) . '</div>' : '' ?></td>
        <td><?php if ($next && (can('dental.labcase.update') || can('dental.labcase.manage'))): ?><form method="post" action="<?= e(url('dental/lab-cases')) ?>" class="flex"><?= csrf_field() ?><input type="hidden" name="id" value="<?= (int) $r['id'] ?>">
            <select class="input input-sm" name="status"><?php foreach ($next as $n): if ($n === 'fitted' && !can('dental.labcase.manage')) { continue; } ?><option value="<?= $n ?>"><?= e(ucwords(str_replace('_', ' ', $n))) ?></option><?php endforeach; ?></select>
            <input class="input input-sm" name="note" placeholder="Note" style="width:110px"><button class="btn btn-sm btn-outline">Set</button></form><?php endif; ?></td></tr><?php endforeach; ?>
    <?php if (!$rows): ?><tr><td colspan="7" class="muted">No lab cases.</td></tr><?php endif; ?>
</tbody></table></div></div>
<?php if (can('dental.labcase.manage')): ?>
<form class="card" method="post" action="<?= e(url('dental/lab-cases')) ?>"><?= csrf_field() ?><input type="hidden" name="do" value="create"><input type="hidden" name="patient_id" id="nc-pid">
    <div class="card-header"><div class="card-title">New lab case</div></div>
    <div class="card-body form-grid">
        <div class="field col-6"><label>Patient</label><div class="search-box"><?= icon('search') ?><input class="input" id="nc-patient" autocomplete="off"><div class="search-results"></div></div></div>
        <div class="field col-3"><label>Type</label><select class="input" name="case_type"><option>crown</option><option>bridge</option><option>denture</option><option>implant</option><option>aligner</option><option>other</option></select></div>
        <div class="field col-3"><label>Teeth</label><input class="input" name="tooth_numbers"></div>
        <div class="field col-3"><label>Shade</label><input class="input" name="shade"></div><div class="field col-3"><label>Material</label><input class="input" name="material"></div>
        <div class="field col-3"><label>Technician</label><select class="input" name="technician_user_id"><option value="">—</option><?php foreach ($technicians as $id => $n): ?><option value="<?= $id ?>"><?= e($n) ?></option><?php endforeach; ?></select></div>
        <div class="field col-3"><label>or outside lab</label><select class="input" name="external_vendor_id"><option value="">—</option><?php foreach ($vendors as $id => $n): ?><option value="<?= $id ?>"><?= e($n) ?></option><?php endforeach; ?></select></div>
        <div class="field col-3"><label>Due</label><input class="input" type="date" name="due_date"></div><div class="field col-9"><label>Instructions</label><input class="input" name="instructions"></div>
    </div>
    <div class="card-footer"><button class="btn btn-primary">Create</button></div>
</form>
<script>APP.liveSearch(document.getElementById('nc-patient'), { minLength: 2,
    fetch: function (q) { return APP.ajax(APP.url('patients/search', { q: q })).then(function (d) { return d.items || []; }); },
    render: function (p) { return '<div class="grow"><div class="t">' + APP.esc(p.name) + '</div><div class="s">' + APP.esc(p.mrn) + '</div></div>'; },
    onSelect: function (p) { document.getElementById('nc-pid').value = p.id; document.getElementById('nc-patient').value = p.name + ' (' + p.mrn + ')'; } });</script>
<?php endif; ?>
