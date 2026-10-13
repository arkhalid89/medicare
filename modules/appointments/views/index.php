<?php
/** @var string $date @var array $doctors @var int $doctorId @var array $slots @var array $day @var array $visitTypes */
use App\Core\View;

$actions = '';
if (can('appointments.schedule.own') || can('appointments.slots.manage')) {
    $actions .= '<a class="btn btn-outline" href="' . e(url('appointments/schedules', ['doctor_id' => $doctorId])) . '">' . icon('clock') . ' Schedules</a>';
    $actions .= '<a class="btn btn-outline" href="' . e(url('appointments/leaves')) . '">' . icon('calendar') . ' Leave</a>';
}
echo View::partial('page_header', [
    'title'       => 'Appointments',
    'description' => 'Book only into published slots. Unavailable slots show no reason (doctor\'s personal data).',
    'breadcrumbs' => [['Front Desk'], ['Appointments']],
    'actions'     => $actions,
]);
$canBook = can('appointments.book');
?>
<div class="card">
    <div class="card-body">
        <form class="filters" method="get" action="<?= e(form_action('appointments')) ?>" data-autosubmit>
            <?= route_field('appointments') ?>
            <div class="field"><label>Date</label><input class="input" type="date" name="date" value="<?= e($date) ?>" onchange="this.form.submit()"></div>
            <div class="field grow"><label>Doctor</label><select class="input" name="doctor_id"><?php foreach ($doctors as $id => $n): ?><option value="<?= $id ?>"<?= selected($id, $doctorId) ?>><?= e($n) ?></option><?php endforeach; ?></select></div>
            <div class="btns">
                <a class="btn btn-ghost" href="<?= e(url('appointments', ['date' => date('Y-m-d', strtotime($date . ' -1 day')), 'doctor_id' => $doctorId])) ?>">‹ Prev</a>
                <a class="btn btn-ghost" href="<?= e(url('appointments', ['date' => today(), 'doctor_id' => $doctorId])) ?>">Today</a>
                <a class="btn btn-ghost" href="<?= e(url('appointments', ['date' => date('Y-m-d', strtotime($date . ' +1 day')), 'doctor_id' => $doctorId])) ?>">Next ›</a>
            </div>
        </form>
    </div>
</div>
<div class="grid grid-main">
    <div class="card card-flush">
        <div class="card-header"><div class="card-title"><?= icon('clock') ?> Slots — <?= e(fmt_date($date)) ?> (<?= e(date('l', strtotime($date))) ?>)</div></div>
        <div class="card-body">
            <?php if (!$slots): ?><div class="empty"><?= icon('calendar') ?><strong>No OPD session on this day</strong>Publish the doctor's sessions under Schedules.</div><?php else: ?>
            <div class="slot-grid">
                <?php foreach ($slots as $s): $t = date('h:i A', strtotime($s['start'])); ?>
                    <?php if ($s['status'] === 'booked'): $a = $s['appointment']; ?>
                        <div class="slot booked"><strong><?= e($t) ?></strong><span><?= e($a['patient_name']) ?></span><?= status_chip($a['status']) ?></div>
                    <?php elseif ($s['status'] === 'open' && $canBook): ?>
                        <button type="button" class="slot open" data-slot="<?= e($s['start']) ?>"><strong><?= e($t) ?></strong><span>Free</span></button>
                    <?php else: ?>
                        <div class="slot <?= e($s['status']) ?>"><strong><?= e($t) ?></strong><span><?= $s['status'] === 'blocked' ? 'Unavailable' : ($s['status'] === 'past' ? 'Past' : 'Free') ?></span></div>
                    <?php endif; ?>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
        </div>
    </div>
    <div class="card card-flush">
        <div class="card-header"><div class="card-title"><?= icon('list') ?> All appointments on this day</div></div>
        <div class="card-body">
            <?php if (!$day): ?><div class="muted small" style="padding:1rem">None.</div><?php endif; ?>
            <ul class="list">
                <?php foreach ($day as $a): ?>
                    <li><div class="grow"><div class="title"><?= e(date('h:i A', strtotime($a['scheduled_at']))) ?> · <?= e($a['patient_name']) ?> <span class="muted small"><?= e($a['mrn']) ?></span></div>
                        <div class="meta"><?= e($a['doctor_name']) ?><?= $a['visit_type'] ? ' · ' . e($a['visit_type']) : '' ?> · <?= e($a['phone']) ?></div></div>
                        <?= status_chip($a['status']) ?>
                        <?php if ($a['status'] === 'booked'): ?>
                        <form method="post" action="<?= e(url('appointments/update')) ?>" class="flex"><?= csrf_field() ?><input type="hidden" name="id" value="<?= (int) $a['id'] ?>">
                            <?php if (can('frontdesk.checkin') && substr($a['scheduled_at'], 0, 10) === today()): ?><button class="btn btn-sm btn-gold" name="do" value="checkin">Check in</button><?php endif; ?>
                            <?php if ($canBook): ?><button class="btn btn-sm btn-ghost" name="do" value="no_show">No-show</button>
                            <button class="btn btn-sm btn-ghost" name="do" value="cancel" onclick="var r=prompt('Reason for cancelling?');if(r===null)return false;this.form.reason.value=r">Cancel</button><input type="hidden" name="reason"><?php endif; ?>
                        </form>
                        <?php endif; ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
    </div>
</div>
<?php if ($canBook): ?>
<div class="modal-backdrop" id="book-modal">
    <form class="modal" method="post" action="<?= e(url('appointments/book')) ?>">
        <?= csrf_field() ?><input type="hidden" name="doctor_id" value="<?= (int) $doctorId ?>"><input type="hidden" name="start" id="bk-start"><input type="hidden" name="patient_id" id="bk-pid">
        <div class="modal-head"><?= icon('calendar') ?><h3>Book <span id="bk-time"></span></h3><button type="button" class="icon-btn close" onclick="APP.closeModal('book-modal')"><?= icon('x') ?></button></div>
        <div class="modal-body"><div class="form-grid">
            <div class="field col-12"><label>Patient <span class="req">*</span></label>
                <div class="search-box"><?= icon('search') ?><input class="input" id="bk-patient" autocomplete="off" placeholder="Search MRN, phone, name…"><div class="search-results"></div></div>
                <div class="hint">New patient? <a href="<?= e(url('patients/create')) ?>" target="_blank">Register first</a>.</div></div>
            <div class="field col-6"><label>Visit type</label><select class="input" name="visit_type_id"><?php foreach ($visitTypes as $id => $n): ?><option value="<?= $id ?>"><?= e($n) ?></option><?php endforeach; ?></select></div>
            <div class="field col-6"><label>Source</label><select class="input" name="source"><option value="reception">Reception</option><option value="phone">Phone</option><option value="walk_in">Walk-in</option></select></div>
            <div class="field col-12"><label>Notes</label><input class="input" name="notes" maxlength="255"></div>
        </div></div>
        <div class="modal-foot"><button type="button" class="btn btn-outline" onclick="APP.closeModal('book-modal')">Cancel</button><button class="btn btn-primary" id="bk-ok" disabled>Book</button></div>
    </form>
</div>
<script>
document.querySelectorAll('[data-slot]').forEach(function (b) {
    b.addEventListener('click', function () {
        document.getElementById('bk-start').value = b.dataset.slot;
        document.getElementById('bk-time').textContent = b.querySelector('strong').textContent;
        APP.openModal('book-modal');
    });
});
APP.liveSearch(document.getElementById('bk-patient'), {
    minLength: 2,
    fetch: function (q) { return APP.ajax(APP.url('patients/search', { q: q })).then(function (d) { return d.items || []; }); },
    render: function (p) { return '<div class="grow"><div class="t">' + APP.esc(p.name) + '</div><div class="s">' + APP.esc(p.mrn) + ' · ' + APP.esc(p.phone) + '</div></div>'; },
    onSelect: function (p) { document.getElementById('bk-pid').value = p.id; document.getElementById('bk-patient').value = p.name + ' (' + p.mrn + ')'; document.getElementById('bk-ok').disabled = false; }
});
</script>
<?php endif; ?>
