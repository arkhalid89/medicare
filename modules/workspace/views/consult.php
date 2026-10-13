<?php
/** @var int $queueId @var array $tokens @var array $doctors @var int $doctorId @var array $points @var array $appointments */
use App\Core\View;

echo View::partial('page_header', [
    'title'       => 'My OPD Queue',
    'description' => 'Call the next patient, see the nurse\'s vitals, and open the consultation — the token closes automatically at checkout.',
    'breadcrumbs' => [['Clinical'], ['OPD Queue']],
    'actions'     => '<a class="btn btn-gold" href="' . e(url('visits/new')) . '">' . icon('stethoscope') . ' Walk-in Visit (no token)</a>',
]);
?>
<?php if ($doctors): ?>
<form class="card" method="get" action="<?= e(form_action('consult')) ?>"><div class="card-body"><div class="filters">
    <?= route_field('consult') ?><div class="field grow"><label>Doctor</label>
    <select class="input" name="doctor_id" onchange="this.form.submit()"><option value="">Select…</option><?php foreach ($doctors as $id => $n): ?><option value="<?= $id ?>"<?= selected($id, $doctorId) ?>><?= e($n) ?></option><?php endforeach; ?></select></div>
</div></div></form>
<?php endif; ?>
<div class="grid grid-main">
    <div class="card card-flush">
        <div class="card-header"><div class="card-title"><?= icon('list') ?> Today's patients</div>
            <?php if ($queueId): ?><div class="flex">
                <select class="input input-sm" id="cq-point"><option value="">Room…</option><?php foreach ($points as $id => $n): ?><option value="<?= $id ?>"><?= e($n) ?></option><?php endforeach; ?></select>
                <button class="btn btn-gold" id="cq-next"><?= icon('volume') ?> Call Next</button></div><?php endif; ?></div>
        <div class="card-body table-wrap">
            <?php if (!$tokens): ?><div class="empty"><?= icon('users') ?><strong>No tokens yet today</strong>Patients appear here when the registration desk issues a token for you.</div><?php else: ?>
            <table class="table">
                <thead><tr><th>Token</th><th>Patient</th><th>Vitals</th><th>Status</th><th style="width:1%"></th></tr></thead>
                <tbody>
                <?php foreach ($tokens as $t): $v = $t['vitals']; ?>
                    <tr class="<?= in_array($t['status'], ['called', 'serving'], true) ? 'row-active' : '' ?>">
                        <td><strong class="tok-label"><?= e($t['token_label']) ?></strong><div><?= $t['priority'] !== 'normal' ? priority_badge($t['priority']) : '' ?></div></td>
                        <td><strong><?= e($t['patient_name']) ?></strong><div class="muted small"><?= e($t['mrn']) ?> · <?= e($t['gender']) ?> <?= e(age_text($t['dob'])) ?></div></td>
                        <td class="small"><?php if ($v): ?>BP <?= e($v['bp_systolic'] . '/' . $v['bp_diastolic']) ?> · P <?= e($v['pulse']) ?> · T <?= e(num($v['temperature'])) ?> · Wt <?= e(num($v['weight'])) ?>
                            <?= $v['triage_level'] ? ' <span class="badge badge-danger">' . e(strtoupper($v['triage_level'])) . '</span>' : '' ?><?php else: ?><span class="muted">—</span><?php endif; ?></td>
                        <td><?= status_chip($t['status']) ?></td>
                        <td class="nowrap">
                            <?php if (in_array($t['status'], ['waiting', 'called', 'serving', 'no_show'], true)): ?>
                                <?php if ($t['status'] !== 'serving'): ?><button class="act" title="Call" data-call="<?= (int) $t['id'] ?>"><?= icon('volume') ?></button><?php endif; ?>
                                <a class="btn btn-sm btn-primary" href="<?= e(url('consult/start', ['id' => $t['id']])) ?>"><?= icon('stethoscope') ?> Consult</a>
                            <?php elseif ($t['visit_id']): ?>
                                <a class="btn btn-sm btn-outline" href="<?= e(url('visits/view', ['id' => $t['visit_id']])) ?>">Visit</a>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            <?php endif; ?>
        </div>
    </div>
    <div class="card card-flush">
        <div class="card-header"><div class="card-title"><?= icon('calendar') ?> Today's appointments</div></div>
        <div class="card-body"><ul class="list">
            <?php foreach ($appointments as $a): ?><li><div class="grow"><div class="title"><?= e(date('h:i A', strtotime($a['scheduled_at']))) ?> · <?= e($a['name']) ?></div><div class="meta"><?= e($a['mrn']) ?></div></div><?= status_chip($a['status']) ?></li><?php endforeach; ?>
            <?php if (!$appointments): ?><li class="muted small">No appointments.</li><?php endif; ?>
        </ul></div>
    </div>
</div>
<script>
(function () {
    var queueId = <?= (int) $queueId ?>;
    function call(action, id) {
        return APP.ajax(APP.url('frontdesk/token-action'), { method: 'POST', body: { action: action, token_id: id || 0, queue_id: queueId, service_point_id: (document.getElementById('cq-point') || {}).value } })
            .then(function (d) {
                if (!d.ok) { APP.toast(d.message || 'Failed', 'error'); return; }
                if (d.token && window.speechSynthesis) speechSynthesis.speak(new SpeechSynthesisUtterance('Token ' + d.token.token_label.split('').join(' ')));
                setTimeout(function () { location.reload(); }, 700);
            });
    }
    var n = document.getElementById('cq-next'); if (n) n.addEventListener('click', function () { call('call_next'); });
    document.querySelectorAll('[data-call]').forEach(function (b) { b.addEventListener('click', function () { call('call', b.dataset.call); }); });
    setInterval(function () { if (!document.querySelector('.modal-backdrop.open')) location.reload(); }, 60000);
})();
</script>
