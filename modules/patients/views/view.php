<?php
/** @var array $p @var array $visits @var array $family */
use App\Core\View;

$actions = '';
if (can('patients.edit')) {
    $actions .= '<a class="btn btn-outline" href="' . e(url('patients/edit', ['id' => $p['id']])) . '">' . icon('edit') . ' Edit</a>';
}
if (can('visits.create')) {
    $actions .= '<a class="btn btn-gold" href="' . e(url('visits/new', ['patient_id' => $p['id']])) . '">' . icon('stethoscope') . ' New Visit</a>';
}
echo View::partial('page_header', [
    'title'       => $p['name'],
    'description' => 'MRN ' . $p['mrn'] . ' · ' . $p['gender'] . ($p['age'] ? ' · ' . $p['age'] : '') . ' · Registered ' . fmt_date($p['created_at']) . ' by ' . $p['doctor_name'],
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
                    <dt>CNIC</dt><dd><?= e($p['cnic']) ?: '—' ?></dd>
                    <dt>Phone</dt><dd><?= e($p['phone']) ?: '—' ?></dd>
                    <dt>City</dt><dd><?= e($p['city']) ?: '—' ?></dd>
                    <dt>Address</dt><dd><?= e($p['address']) ?: '—' ?></dd>
                </dl>
            </div>
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
