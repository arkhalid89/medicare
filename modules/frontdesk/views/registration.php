<?php
/**
 * @var array|null $patient @var array|null $appointment @var array $departments @var array $doctors @var array $onLeave
 * @var array $visitTypes @var array $queues @var array $recent @var string $priority
 */
use App\Core\View;

echo View::partial('page_header', [
    'title'       => 'Registration Desk',
    'description' => 'Find or register the patient → doctor & visit type → collect fee (or authorised waiver) → token.',
    'breadcrumbs' => [['Front Desk'], ['Registration']],
    'actions'     => (can('patients.create') ? '<a class="btn btn-outline" href="' . e(url('patients/create')) . '">' . icon('user-plus') . ' Full Registration Form</a>' : '')
        . (can('frontdesk.token.manage') ? '<a class="btn btn-outline" href="' . e(url('frontdesk/tokens')) . '">' . icon('list') . ' Token Console</a>' : ''),
]);
$printId = (int) input('print', 0);
?>
<div class="grid grid-main">
    <div>
        <div class="card">
            <div class="card-header"><div class="card-title"><span class="step-no">1</span> Patient</div>
                <?php if ($patient): ?><a class="btn btn-sm btn-ghost" href="<?= e(url('frontdesk')) ?>"><?= icon('x') ?> Change patient</a><?php endif; ?></div>
            <div class="card-body">
                <?php if (!$patient): ?>
                    <div class="search-box mb-2"><?= icon('search') ?>
                        <input class="input" id="fd-search" placeholder="Search by CNIC, MRN, phone or name…" autocomplete="off" autofocus>
                        <div class="search-results"></div></div>
                    <div class="flex flex-wrap">
                        <?php if (can('patients.create')): ?>
                            <a class="btn btn-primary" href="<?= e(url('patients/create')) ?>"><?= icon('user-plus') ?> Register New Patient</a>
                        <?php endif; ?>
                        <?php if (can('patients.emergency')): ?>
                            <button type="button" class="btn btn-danger" onclick="APP.openModal('er-modal')"><?= icon('alert') ?> Emergency / Unidentified</button>
                        <?php endif; ?>
                    </div>
                    <p class="muted small mt-2 mb-0">New patients are checked for duplicates on CNIC and on name + date of birth / father's name before saving.</p>
                <?php else: ?>
                    <div class="patient-banner">
                        <div class="pb-main"><strong><?= e($patient['name']) ?></strong>
                            <span class="muted"><?= e($patient['guardian_name'] ? 's/o, d/o, w/o ' . $patient['guardian_name'] : '') ?></span></div>
                        <div class="pb-meta">
                            <span class="badge badge-primary">MRN <?= e($patient['mrn']) ?></span>
                            <span><?= e($patient['gender']) ?> · <?= e($patient['age']) ?: 'age ?' ?></span>
                            <?php if ($patient['cnic']): ?><span><?= e(cnic_mask($patient['cnic'])) ?></span><?php endif; ?>
                            <?php if ($patient['phone']): ?><span><?= e($patient['phone']) ?></span><?php endif; ?>
                            <?php if ($patient['allergies']): ?><span class="badge badge-danger"><?= icon('alert') ?> Allergy: <?= e($patient['allergies']) ?></span><?php endif; ?>
                            <?php if ($patient['registration_type'] !== 'normal'): ?><span class="badge badge-danger"><?= e(hospital('registration_types')[$patient['registration_type']] ?? '') ?></span><?php endif; ?>
                        </div>
                        <a class="small" href="<?= e(url('patients/view', ['id' => $patient['id']])) ?>">Open patient record →</a>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <?php if ($patient): ?>
        <form class="card" method="post" action="<?= e(url('frontdesk/issue')) ?>" id="issue-form" data-lock>
            <?= csrf_field() ?>
            <input type="hidden" name="patient_id" value="<?= (int) $patient['id'] ?>">
            <?php if ($appointment): ?><input type="hidden" name="appointment_id" value="<?= (int) $appointment['id'] ?>"><?php endif; ?>
            <div class="card-header"><div class="card-title"><span class="step-no">2</span> Visit, Fee &amp; Token</div>
                <?php if ($appointment): ?><span class="badge badge-info">Appointment <?= e(date('h:i A', strtotime($appointment['scheduled_at']))) ?> · <?= e($appointment['doctor_name']) ?></span><?php endif; ?></div>
            <div class="card-body">
                <div class="form-grid">
                    <div class="field col-4"><label>Department</label>
                        <select class="input" name="department_id" id="fd-dept"><option value="">All departments</option>
                            <?php foreach ($departments as $id => $name): ?><option value="<?= $id ?>"><?= e($name) ?></option><?php endforeach; ?>
                        </select></div>
                    <div class="field col-4"><label>Doctor <span class="req">*</span></label>
                        <select class="input" name="doctor_id" id="fd-doctor" required><option value="">Select doctor…</option>
                            <?php foreach ($doctors as $d): $leave = in_array((int) $d['id'], $onLeave, true); ?>
                                <option value="<?= (int) $d['id'] ?>" data-depts="<?= e((string) $d['dept_ids']) ?>"<?= selected($d['id'], $appointment['doctor_id'] ?? '') ?><?= $leave ? ' disabled' : '' ?>>
                                    <?= e($d['name']) ?><?= $d['specialization'] ? ' — ' . e($d['specialization']) : '' ?><?= $leave ? ' (unavailable)' : '' ?></option>
                            <?php endforeach; ?>
                        </select><div class="hint" id="fd-waiting"></div></div>
                    <div class="field col-4"><label>Visit type</label>
                        <select class="input" name="visit_type_id" id="fd-vt">
                            <?php foreach ($visitTypes as $id => $name): ?><option value="<?= $id ?>"<?= selected($id, $appointment['visit_type_id'] ?? '') ?>><?= e($name) ?></option><?php endforeach; ?>
                        </select></div>
                    <div class="field col-12"><label>Priority</label>
                        <div class="seg seg-wide">
                            <?php foreach (hospital('priorities') as $key => $p): ?>
                                <input type="radio" name="priority" id="pr-<?= e($key) ?>" value="<?= e($key) ?>"<?= checked($priority === $key) ?>><label for="pr-<?= e($key) ?>"><?= e($p[0]) ?></label>
                            <?php endforeach; ?>
                        </div></div>
                </div>
                <div class="fee-box mt-2" id="fd-fee"><span class="muted">Select a doctor to see the fee.</span></div>
                <div class="form-grid mt-2" id="pay-fields">
                    <div class="field col-4"><label>Payment mode</label>
                        <select class="input" name="payment_mode"><?php foreach (hospital('payment_modes') as $k => $l): ?><option value="<?= e($k) ?>"><?= e($l) ?></option><?php endforeach; ?></select></div>
                    <div class="field col-4"><label>Reference (card / transaction no.)</label><input class="input" name="payment_ref" maxlength="100"></div>
                    <input type="hidden" name="collect" value="1">
                </div>
                <?php if (can('frontdesk.fee.waive')): ?>
                <div class="form-grid mt-1">
                    <div class="field col-4"><label class="check"><input type="checkbox" name="waive" value="1" id="fd-waive"> Waive the fee</label></div>
                    <div class="field col-8 hidden" id="waive-reason"><label>Waiver reason <span class="req">*</span></label><input class="input" name="waive_reason" maxlength="255" placeholder="e.g. Staff family, Zakat case, Emergency"></div>
                </div>
                <?php endif; ?>
            </div>
            <div class="card-footer">
                <button class="btn btn-gold btn-lg" type="submit" id="fd-submit"><?= icon('receipt') ?> Collect Fee &amp; Issue Token</button>
            </div>
        </form>
        <?php endif; ?>
    </div>

    <div>
        <div class="card card-flush">
            <div class="card-header"><div class="card-title"><?= icon('list') ?> Today's Queues</div></div>
            <div class="card-body">
                <?php if (!$queues): ?><div class="muted small" style="padding:1rem">No queues yet — they are created when the first token is issued for a doctor.</div><?php endif; ?>
                <ul class="list" id="fd-queues">
                    <?php foreach ($queues as $q): ?>
                        <li><div class="grow"><div class="title"><?= e($q['name']) ?></div>
                            <div class="meta"><?= (int) $q['waiting'] ?> waiting · <?= (int) $q['done'] ?> seen<?= $q['avg_wait'] !== null ? ' · avg wait ' . (int) $q['avg_wait'] . ' min' : '' ?></div></div>
                            <span class="badge <?= (int) $q['waiting'] > 10 ? 'badge-danger' : 'badge-primary' ?>"><?= (int) $q['waiting'] ?></span></li>
                    <?php endforeach; ?>
                </ul>
            </div>
        </div>
        <div class="card card-flush">
            <div class="card-header"><div class="card-title"><?= icon('receipt') ?> Issued by me today</div></div>
            <div class="card-body">
                <?php if (!$recent): ?><div class="muted small" style="padding:1rem">No tokens issued yet today.</div><?php endif; ?>
                <ul class="list">
                    <?php foreach ($recent as $r): ?>
                        <li><div class="grow"><div class="title"><strong><?= e($r['token_label']) ?></strong> · <?= e($r['name']) ?></div>
                            <div class="meta"><?= e($r['queue_name']) ?> · <?= e(date('h:i A', strtotime($r['issued_at']))) ?> · <?= $r['waived'] ? 'waived' : e(money($r['net_amount'])) ?></div></div>
                            <?= status_chip($r['status']) ?>
                            <a class="act" target="_blank" title="Print token & receipt" href="<?= e(url('frontdesk/token-print', ['id' => $r['id']])) ?>"><?= icon('printer') ?></a></li>
                    <?php endforeach; ?>
                </ul>
            </div>
        </div>
    </div>
</div>

<?php if (can('patients.emergency')): ?>
<div class="modal-backdrop" id="er-modal">
    <form class="modal" method="post" action="<?= e(url('patients/emergency')) ?>">
        <?= csrf_field() ?>
        <div class="modal-head"><?= icon('alert') ?><h3>Emergency / Unidentified Patient</h3><button type="button" class="icon-btn close" onclick="APP.closeModal('er-modal')"><?= icon('x') ?></button></div>
        <div class="modal-body">
            <p class="muted">A temporary ER ID is created so treatment can start now. Capture the CNIC and name later (edit or merge).</p>
            <div class="form-grid">
                <div class="field col-12"><label>Description</label><input class="input" name="description" maxlength="100" placeholder="e.g. RTA male, brought by Rescue 1122"></div>
                <div class="field col-6"><label>Gender</label><select class="input" name="gender"><option>Male</option><option>Female</option><option>Other</option></select></div>
                <div class="field col-6"><label>Approximate age</label><input class="input" type="number" name="age" min="0" max="120"></div>
            </div>
        </div>
        <div class="modal-foot"><button type="button" class="btn btn-outline" onclick="APP.closeModal('er-modal')">Cancel</button><button class="btn btn-danger">Register &amp; Continue</button></div>
    </form>
</div>
<?php endif; ?>

<script>
(function () {
    var search = document.getElementById('fd-search');
    if (search) {
        APP.liveSearch(search, {
            minLength: 2,
            fetch: function (q) { return APP.ajax(APP.url('patients/search', { q: q })).then(function (d) { return d.items || []; }); },
            render: function (p) {
                return '<div class="grow"><div class="t">' + APP.esc(p.name) + (p.guardian_name ? ' <span class="muted">s/o ' + APP.esc(p.guardian_name) + '</span>' : '') + '</div>' +
                    '<div class="s">' + APP.esc(p.mrn) + ' · ' + APP.esc(p.gender) + ' ' + APP.esc(p.age) + ' · ' + APP.esc(p.phone) + ' · ' + APP.esc(p.cnic) + '</div></div>';
            },
            onSelect: function (p) { location.href = APP.url('frontdesk', { patient_id: p.id }); }
        });
    }
    var doctor = document.getElementById('fd-doctor'), dept = document.getElementById('fd-dept'), vt = document.getElementById('fd-vt'), box = document.getElementById('fd-fee');
    if (!doctor) return;
    var form = document.getElementById('issue-form');
    var patientId = form.querySelector('[name=patient_id]').value;
    dept.addEventListener('change', function () {
        Array.prototype.forEach.call(doctor.options, function (o) {
            if (!o.value) return;
            var ids = (o.dataset.depts || '').split(',');
            o.hidden = dept.value && ids.indexOf(dept.value) === -1;
        });
        if (doctor.selectedOptions[0] && doctor.selectedOptions[0].hidden) doctor.value = '';
        load();
    });
    function load() {
        if (!doctor.value) { box.innerHTML = '<span class="muted">Select a doctor to see the fee.</span>'; return; }
        APP.ajax(APP.url('frontdesk/fee', { doctor_id: doctor.value, visit_type_id: vt.value, patient_id: patientId })).then(function (d) {
            if (!d.ok) return;
            box.innerHTML = d.lines.map(function (l) { return '<div class="row"><span>' + APP.esc(l.label) + '</span><strong>' + APP.money(l.amount) + '</strong></div>'; }).join('') +
                '<div class="row total"><span>Total to collect</span><span>' + APP.money(d.total) + '</span></div>';
            document.getElementById('fd-waiting').textContent = d.waiting + ' patient(s) waiting for this doctor';
            document.getElementById('fd-submit').innerHTML = d.total > 0 ? 'Collect ' + APP.esc(APP.money(d.total)) + ' &amp; Issue Token' : 'Issue Token (no fee)';
        });
    }
    doctor.addEventListener('change', load);
    vt.addEventListener('change', load);
    var waive = document.getElementById('fd-waive');
    if (waive) waive.addEventListener('change', function () {
        document.getElementById('waive-reason').classList.toggle('hidden', !waive.checked);
        document.getElementById('pay-fields').classList.toggle('hidden', waive.checked);
    });
    load();
})();
<?php if ($printId): ?>
window.open(APP.url('frontdesk/token-print', { id: <?= $printId ?> }), '_blank', 'width=420,height=640');
<?php endif; ?>
</script>
