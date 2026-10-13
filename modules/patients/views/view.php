<?php
/** @var array $p @var array $visits @var array $family @var array $identifiers @var array $contacts @var array $documents @var array $invoices @var array $tokens @var bool $canEdit */
use App\Core\View;

$actions = '';
if ($canEdit) {
    $actions .= '<a class="btn btn-outline" href="' . e(url('patients/edit', ['id' => $p['id']])) . '">' . icon('edit') . ' Edit</a>';
}
if (can('patients.merge') && $family) {
    $actions .= '<a class="btn btn-outline" href="' . e(url('patients/merge', ['keep' => $p['id']])) . '">' . icon('users') . ' Merge Duplicate</a>';
}
if (can('frontdesk.token.issue')) {
    $actions .= '<a class="btn btn-outline" href="' . e(url('frontdesk', ['patient_id' => $p['id']])) . '">' . icon('list') . ' Issue Token</a>';
}
if (can('visits.create')) {
    $actions .= '<a class="btn btn-gold" href="' . e(url('visits/new', ['patient_id' => $p['id']])) . '">' . icon('stethoscope') . ' New Visit</a>';
}
echo View::partial('page_header', [
    'title'       => $p['name'],
    'description' => 'MRN ' . $p['mrn'] . ' · ' . $p['gender'] . ($p['age'] ? ' · ' . $p['age'] : '') . ' · Registered ' . fmt_date($p['created_at']) . ($p['doctor_name'] ? ' for ' . $p['doctor_name'] : ''),
    'breadcrumbs' => [['Patients', url('patients')], [$p['mrn']]],
    'actions'     => $actions,
]);
$totalFee = array_sum(array_map(static fn ($v) => (float) $v['consultation_fee'], $visits));
$totalMed = array_sum(array_map(static fn ($v) => (float) $v['medicine_net'], $visits));
?>
<div class="grid grid-main mb-2">
    <div class="card">
        <div class="card-header"><div class="card-title"><?= icon('id-card') ?> Patient Details</div><span class="badge badge-primary">MRN <?= e($p['mrn']) ?></span></div>
        <div class="card-body">
            <div class="grid grid-2">
                <dl class="kv">
                    <dt>Father / Husband</dt><dd><?= e($p['guardian_name']) ?: '—' ?></dd>
                    <dt>Gender</dt><dd><?= e($p['gender']) ?></dd>
                    <dt>Age / DOB</dt><dd><?= e($p['age']) ?: '—' ?><?= $p['dob'] && !$p['dob_estimated'] ? ' <span class="muted">(' . e(fmt_date($p['dob'])) . ')</span>' : '' ?></dd>
                    <dt>Blood Group</dt><dd><?= e($p['blood_group']) ?: '—' ?></dd>
                </dl>
                <dl class="kv">
                    <dt>CNIC</dt><dd><?= e(cnic_mask($p['cnic'])) ?: '—' ?></dd>
                    <dt>Phone</dt><dd><?= e($p['phone']) ?: '—' ?></dd>
                    <dt>City</dt><dd><?= e($p['city']) ?: '—' ?></dd>
                    <dt>Address</dt><dd><?= e($p['address']) ?: '—' ?></dd>
                </dl>
            </div>
            <?php if ($p['registration_type'] !== 'normal' || $p['occupation'] || $p['marital_status'] || $p['preferred_language'] || $mother || $children): ?>
            <dl class="kv mt-1">
                <?php if ($p['registration_type'] !== 'normal'): ?><dt>Registration</dt><dd><span class="badge badge-danger"><?= e(hospital('registration_types')[$p['registration_type']] ?? $p['registration_type']) ?></span></dd><?php endif; ?>
                <?php if ($p['occupation']): ?><dt>Occupation</dt><dd><?= e($p['occupation']) ?></dd><?php endif; ?>
                <?php if ($p['marital_status']): ?><dt>Marital status</dt><dd><?= e(hospital('marital_statuses')[$p['marital_status']] ?? '') ?></dd><?php endif; ?>
                <?php if ($p['preferred_language']): ?><dt>Language</dt><dd><?= e(hospital('languages')[$p['preferred_language']] ?? '') ?></dd><?php endif; ?>
                <?php if ($mother): ?><dt>Mother</dt><dd><a href="<?= e(url('patients/view', ['id' => $mother['id']])) ?>"><?= e($mother['name']) ?> (<?= e($mother['mrn']) ?>)</a></dd><?php endif; ?>
                <?php if ($children): ?><dt>Newborns</dt><dd><?php foreach ($children as $c): ?><a href="<?= e(url('patients/view', ['id' => $c['id']])) ?>"><?= e($c['name']) ?></a> <?php endforeach; ?></dd><?php endif; ?>
            </dl>
            <?php endif; ?>
            <?php if ($p['allergies']): ?><div class="alert alert-danger mt-2 mb-0"><?= icon('alert') ?><div><strong>Allergies:</strong> <?= e($p['allergies']) ?></div></div><?php endif; ?>
            <?php if ($p['notes']): ?><div class="alert alert-warning mt-2 mb-0"><?= icon('alert') ?><div><strong>Notes:</strong> <?= nl2br(e($p['notes'])) ?></div></div><?php endif; ?>
        </div>
    </div>
    <div>
        <div class="grid grid-2 mb-2">
            <div class="stat"><div class="stat-icon"><?= icon('calendar') ?></div><div><p class="label">Visits</p><div class="value"><?= count($visits) ?></div></div></div>
            <div class="stat"><div class="stat-icon gold"><?= icon('money') ?></div><div><p class="label">Total Billed</p><div class="value" style="font-size:1.2rem"><?= e(money($totalFee + $totalMed)) ?></div></div></div>
        </div>
        <?php if ($family): ?>
        <div class="card card-flush">
            <div class="card-header"><div class="card-title"><?= icon('users') ?> Family (same CNIC / phone)</div></div>
            <div class="card-body"><ul class="list">
                <?php foreach ($family as $f): ?>
                    <li><div class="grow"><div class="title"><a href="<?= e(url('patients/view', ['id' => $f['id']])) ?>"><?= e($f['name']) ?></a></div><div class="meta"><?= e($f['mrn']) ?> · <?= e($f['gender']) ?> <?= e($f['age']) ?></div></div></li>
                <?php endforeach; ?>
            </ul></div>
        </div>
        <?php endif; ?>
    </div>
</div>

<div class="card card-flush">
    <div class="card-header"><div class="card-title"><?= icon('clipboard') ?> Visit History</div><span class="muted small">Original prescriptions are never changed — “Repeat” creates a new visit.</span></div>
    <div class="card-body table-wrap">
        <?php if (!$visits): ?>
            <div class="empty"><?= icon('calendar') ?><strong>No visits yet</strong><?= can('visits.create') ? 'Start the first consultation with “New Visit”.' : '' ?></div>
        <?php else: ?>
        <table class="table">
            <thead><tr><th>Date / Visit</th><th>Complaints &amp; Symptoms</th><th>Diagnosis</th><th>Investigations</th><th>Medicines</th><th>Follow-up</th><th class="num">Fee</th><th class="num">Medicines</th><th style="width:1%">Actions</th></tr></thead>
            <tbody>
            <?php foreach ($visits as $v): ?>
                <tr>
                    <td class="nowrap"><strong><?= e(fmt_date($v['visit_date'])) ?></strong><div class="small"><a href="<?= e(url('visits/view', ['id' => $v['id']])) ?>"><?= e($v['visit_no']) ?></a></div><div class="muted small"><?= e($v['doctor_name']) ?></div></td>
                    <td class="small"><?= e(trim(($v['complaints'] ?? '') . ($v['symptoms'] ? ($v['complaints'] ? '; ' : '') . $v['symptoms'] : ''))) ?: '<span class="muted">—</span>' ?></td>
                    <td class="small"><?= e($v['diagnoses']) ?: '<span class="muted">—</span>' ?></td>
                    <td class="small"><?= e($v['investigations']) ?: '<span class="muted">—</span>' ?></td>
                    <td class="small" style="min-width:180px"><?= e($v['medicines']) ?: '<span class="muted">—</span>' ?></td>
                    <td class="nowrap small"><?= e(fmt_date($v['follow_up_date'])) ?: '—' ?></td>
                    <td class="num"><?= e(money($v['consultation_fee'])) ?><div><?= payment_badge($v['payment_status']) ?></div></td>
                    <td class="num"><?= e(money($v['medicine_net'])) ?></td>
                    <td>
                        <div class="actions">
                            <a class="act" title="View" href="<?= e(url('visits/view', ['id' => $v['id']])) ?>"><?= icon('eye') ?></a>
                            <?php if (can('visits.print')): ?><a class="act" title="<?= (int) $v['print_count'] ? 'Reprint' : 'Print' ?>" target="_blank" href="<?= e(url('visits/print', ['id' => $v['id']])) ?>"><?= icon('printer') ?></a><?php endif; ?>
                            <?php if (can('visits.create')): ?><a class="act success" title="Repeat medicines" href="<?= e(url('visits/new', ['repeat' => $v['id']])) ?>"><?= icon('repeat') ?></a><?php endif; ?>
                        </div>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>
    </div>
</div>

<div class="grid grid-2 mt-2">
    <div class="card card-flush">
        <div class="card-header"><div class="card-title"><?= icon('id-card') ?> Identifiers</div></div>
        <div class="card-body">
            <?php if (!$identifiers): ?><div class="muted small" style="padding:.75rem 1rem">No identifiers recorded.</div><?php else: ?>
            <table class="table table-compact"><tbody>
                <?php foreach ($identifiers as $i): ?>
                    <tr><td><?= e(hospital('patient_id_types')[$i['id_type']] ?? $i['id_type']) ?><?= (int) $i['is_primary'] ? ' <span class="badge badge-primary">Primary</span>' : '' ?></td>
                        <td><?= e(in_array($i['id_type'], ['cnic', 'nicop', 'b_form'], true) ? cnic_mask($i['id_value']) : $i['id_value']) ?></td>
                        <td class="small muted"><?= $i['verified_at'] ? 'Verified ' . e(fmt_date($i['verified_at'])) : '' ?></td></tr>
                <?php endforeach; ?>
            </tbody></table>
            <?php endif; ?>
            <?php if ($canEdit): ?>
            <form class="filters" method="post" action="<?= e(url('patients/identifier-add')) ?>" style="padding:.75rem 1rem">
                <?= csrf_field() ?><input type="hidden" name="patient_id" value="<?= (int) $p['id'] ?>">
                <div class="field"><label>Type</label><select class="input" name="id_type"><?php foreach (hospital('patient_id_types') as $k => $l): ?><option value="<?= e($k) ?>"><?= e($l) ?></option><?php endforeach; ?></select></div>
                <div class="field grow"><label>Number</label><input class="input" name="id_value" maxlength="60" required></div>
                <label class="check"><input type="checkbox" name="verified" value="1"> Verified</label>
                <div class="btns"><button class="btn btn-outline btn-sm"><?= icon('plus') ?> Add</button></div>
            </form>
            <?php endif; ?>
        </div>
    </div>
    <div class="card card-flush">
        <div class="card-header"><div class="card-title"><?= icon('users') ?> Guardian / Next of Kin</div></div>
        <div class="card-body">
            <?php if (!$contacts): ?><div class="muted small" style="padding:.75rem 1rem">No contacts recorded.</div><?php else: ?>
            <table class="table table-compact"><tbody>
                <?php foreach ($contacts as $c): ?>
                    <tr><td><strong><?= e($c['name']) ?></strong> <span class="muted small"><?= e($c['relation']) ?></span>
                        <div><?= (int) $c['is_guardian'] ? '<span class="badge badge-gold">Guardian</span> ' : '' ?><?= (int) $c['is_emergency_contact'] ? '<span class="badge badge-danger">Emergency</span> ' : '' ?><?= (int) $c['is_next_of_kin'] ? '<span class="badge badge-info">Next of kin</span>' : '' ?></div></td>
                        <td class="nowrap"><?= e($c['phone']) ?><div class="small muted"><?= e(cnic_mask($c['cnic'])) ?></div></td>
                        <td><?php if (can('patients.edit')): ?><form method="post" action="<?= e(url('patients/contact-remove')) ?>" data-confirm="Remove this contact?"><?= csrf_field() ?><input type="hidden" name="id" value="<?= (int) $c['id'] ?>"><button class="act danger" title="Remove"><?= icon('x') ?></button></form><?php endif; ?></td></tr>
                <?php endforeach; ?>
            </tbody></table>
            <?php endif; ?>
            <?php if (can('patients.edit') || can('patients.create')): ?>
            <form method="post" action="<?= e(url('patients/contact-add')) ?>" style="padding:.75rem 1rem" class="form-grid">
                <?= csrf_field() ?><input type="hidden" name="patient_id" value="<?= (int) $p['id'] ?>">
                <div class="field col-4"><label>Name</label><input class="input" name="name" maxlength="150" required></div>
                <div class="field col-4"><label>Relation</label><select class="input" name="relation"><?php foreach (hospital('relations') as $r): ?><option><?= e($r) ?></option><?php endforeach; ?></select></div>
                <div class="field col-4"><label>Phone</label><input class="input" name="phone" maxlength="20"></div>
                <div class="field col-4"><label>CNIC</label><input class="input" name="cnic" maxlength="15"></div>
                <div class="field col-8"><label>&nbsp;</label><div class="flex flex-wrap">
                    <label class="check"><input type="checkbox" name="is_guardian" value="1"> Guardian</label>
                    <label class="check"><input type="checkbox" name="is_emergency_contact" value="1"> Emergency</label>
                    <label class="check"><input type="checkbox" name="is_next_of_kin" value="1"> Next of kin</label>
                    <button class="btn btn-outline btn-sm"><?= icon('plus') ?> Add</button></div></div>
            </form>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php if (can('patients.documents')): ?>
<div class="card card-flush mt-2">
    <div class="card-header"><div class="card-title"><?= icon('file') ?> Documents</div></div>
    <div class="card-body">
        <?php if ($documents): ?>
        <table class="table table-compact"><thead><tr><th>Type</th><th>Title</th><th>Uploaded</th><th></th></tr></thead><tbody>
            <?php foreach ($documents as $d): ?>
                <tr><td><?= e(hospital('document_types')[$d['doc_type']] ?? $d['doc_type']) ?></td><td><a target="_blank" href="<?= e(url('patients/document', ['id' => $d['id']])) ?>"><?= e($d['title'] ?: 'Open') ?></a></td>
                    <td class="small"><?= e(fmt_datetime($d['uploaded_at'])) ?> · <?= e($d['uploaded_by_name']) ?></td>
                    <td><?php if (can('records.delete')): ?><form method="post" action="<?= e(url('patients/document-delete')) ?>" data-confirm="Remove this document?"><?= csrf_field() ?><input type="hidden" name="id" value="<?= (int) $d['id'] ?>"><button class="act danger"><?= icon('trash') ?></button></form><?php endif; ?></td></tr>
            <?php endforeach; ?>
        </tbody></table>
        <?php endif; ?>
        <form class="filters" method="post" enctype="multipart/form-data" action="<?= e(url('patients/document-upload')) ?>" style="padding:.75rem 1rem">
            <?= csrf_field() ?><input type="hidden" name="patient_id" value="<?= (int) $p['id'] ?>">
            <div class="field"><label>Type</label><select class="input" name="doc_type"><?php foreach (hospital('document_types') as $k => $l): ?><option value="<?= e($k) ?>"><?= e($l) ?></option><?php endforeach; ?></select></div>
            <div class="field grow"><label>Title</label><input class="input" name="title" maxlength="150"></div>
            <div class="field"><label>File (PDF / image)</label><input class="input" type="file" name="file" accept=".pdf,image/*" required></div>
            <div class="btns"><button class="btn btn-outline btn-sm"><?= icon('upload') ?> Upload</button></div>
        </form>
    </div>
</div>
<?php endif; ?>

<?php if ($invoices || $tokens): ?>
<div class="grid grid-2 mt-2">
    <?php if ($invoices): ?>
    <div class="card card-flush"><div class="card-header"><div class="card-title"><?= icon('receipt') ?> Bills</div></div>
        <div class="card-body"><table class="table table-compact"><thead><tr><th>Invoice</th><th>Date</th><th>Status</th><th class="num">Net</th><th class="num">Paid</th></tr></thead><tbody>
            <?php foreach ($invoices as $i): ?>
                <tr><td><a href="<?= e(url('billing/invoice', ['id' => $i['id']])) ?>"><?= e($i['invoice_no']) ?></a> <span class="muted small"><?= e(strtoupper($i['invoice_type'])) ?></span></td>
                    <td class="small"><?= e(fmt_date($i['created_at'])) ?></td><td><?= status_chip($i['status']) ?></td>
                    <td class="num"><?= e(money($i['net_amount'])) ?></td><td class="num"><?= e(money((float) $i['paid_amount'] - (float) $i['refunded_amount'])) ?></td></tr>
            <?php endforeach; ?>
        </tbody></table></div></div>
    <?php endif; ?>
    <?php if ($tokens): ?>
    <div class="card card-flush"><div class="card-header"><div class="card-title"><?= icon('list') ?> Recent Tokens</div></div>
        <div class="card-body"><table class="table table-compact"><tbody>
            <?php foreach ($tokens as $t): ?>
                <tr><td><strong><?= e($t['token_label']) ?></strong></td><td><?= e($t['queue_name']) ?></td><td class="small"><?= e(fmt_date($t['issue_date'])) ?></td><td><?= status_chip($t['status']) ?></td></tr>
            <?php endforeach; ?>
        </tbody></table></div></div>
    <?php endif; ?>
</div>
<?php endif; ?>
