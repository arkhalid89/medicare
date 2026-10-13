<?php
/** @var array $tests @var array $orders */
use App\Core\View;

echo View::partial('page_header', [
    'title' => 'Lab Reception', 'description' => 'Walk-in and referral orders (billed at order), today\'s orders and hand-over of released reports.',
    'breadcrumbs' => [['Laboratory'], ['Reception']],
    'actions' => '<a class="btn btn-outline" href="' . e(url('lab/orders')) . '">' . icon('list') . ' All orders</a>',
]);
$bySection = [];
foreach ($tests as $t) {
    $bySection[$t['section_name'] ?: 'Other'][] = $t;
}
?>
<?php if (can('lab.reception') || can('lab.order.create')): ?>
<form class="card" method="post" action="<?= e(url('lab/reception')) ?>" id="lab-order-form"><?= csrf_field() ?>
    <input type="hidden" name="patient_id" id="lo-pid">
    <div class="card-header"><div class="card-title"><?= icon('flask') ?> New lab order</div></div>
    <div class="card-body">
        <div class="form-grid">
            <div class="field col-6"><label>Patient <span class="req">*</span></label><div class="search-box"><?= icon('search') ?><input class="input" id="lo-patient" autocomplete="off" placeholder="Name, MRN, phone or CNIC"><div class="search-results"></div></div>
                <div class="muted small mt-1">New patient? <a href="<?= e(url('frontdesk')) ?>">Register at the front desk</a> first.</div></div>
            <div class="field col-3"><label>Priority</label><select class="input" name="priority"><option value="routine">Routine</option><option value="urgent">Urgent</option><option value="stat">STAT</option></select></div>
            <div class="field col-3"><label>Referring doctor (external)</label><input class="input" name="referring_doctor" maxlength="150"></div>
            <div class="field col-12"><label>Clinical notes</label><input class="input" name="clinical_notes" maxlength="500"></div>
        </div>
        <div class="grid grid-3 mt-2">
            <?php foreach ($bySection as $sec => $list): ?>
                <div><div class="section-title small"><?= e($sec) ?></div>
                    <?php foreach ($list as $t): ?>
                        <label class="check-row"><input type="checkbox" name="tests[]" value="<?= (int) $t['id'] ?>" data-price="<?= e((string) $t['price']) ?>">
                            <span class="grow"><?= e($t['name']) ?><?= $t['fasting_required'] ? ' <span class="badge badge-warning">Fasting</span>' : '' ?></span><span class="muted small"><?= e(money($t['price'])) ?></span></label>
                    <?php endforeach; ?></div>
            <?php endforeach; ?>
        </div>
    </div>
    <div class="card-footer flex"><strong>Total: <span id="lo-total"><?= e(money(0)) ?></span></strong><span class="grow"></span><button class="btn btn-primary" id="lo-ok" disabled>Create order &amp; bill</button></div>
</form>
<?php endif; ?>
<div class="card card-flush"><div class="card-header"><div class="card-title">Today's orders &amp; reports to hand over</div></div>
    <div class="card-body table-wrap"><table class="table">
        <thead><tr><th>Order</th><th>Patient</th><th class="num">Tests</th><th>Status</th><th>Bill</th><th>Report</th></tr></thead><tbody>
        <?php foreach ($orders as $o): ?><tr>
            <td><a href="<?= e(url('lab/order', ['id' => $o['id']])) ?>"><strong><?= e($o['order_number']) ?></strong></a><div class="muted small"><?= e(fmt_datetime($o['ordered_at'])) ?> · <?= e($o['source']) ?></div></td>
            <td><?= e($o['patient_name']) ?><div class="muted small"><?= e($o['mrn']) ?></div></td><td class="num"><?= (int) $o['tests'] ?></td>
            <td><?= status_chip($o['status']) ?><?= $o['priority'] !== 'routine' ? ' <span class="badge badge-danger">' . e(strtoupper($o['priority'])) . '</span>' : '' ?></td>
            <td><?= $o['bill_status'] ? status_chip($o['bill_status']) . '<div class="muted small">' . e(money($o['paid_amount'])) . ' / ' . e(money($o['net_amount'])) . '</div>' : '<span class="muted">—</span>' ?></td>
            <td><?php if ($o['report_id']): ?>
                <a class="btn btn-sm btn-outline" target="_blank" href="<?= e(url('lab/report', ['id' => $o['id']])) ?>"><?= icon('printer') ?> <?= e($o['report_number']) ?></a>
                <?php if ($o['handed_over_at']): ?><div class="muted small">Handed over <?= e(fmt_datetime($o['handed_over_at'])) ?></div>
                <?php elseif (can('lab.report.handover')): ?>
                <form method="post" action="<?= e(url('lab/reception')) ?>" class="flex mt-1"><?= csrf_field() ?><input type="hidden" name="do" value="handover"><input type="hidden" name="report_id" value="<?= (int) $o['report_id'] ?>">
                    <input class="input input-sm" name="to" placeholder="Received by" style="width:130px"><button class="btn btn-sm btn-success">Hand over</button></form>
                <?php endif; ?>
            <?php else: ?><span class="muted small">Pending</span><?php endif; ?></td></tr><?php endforeach; ?>
        <?php if (!$orders): ?><tr><td colspan="6" class="muted">No orders today.</td></tr><?php endif; ?>
    </tbody></table></div></div>
<?php if (can('lab.reception') || can('lab.order.create')): ?>
<script>
(function () {
    var form = document.getElementById('lab-order-form'), pid = document.getElementById('lo-pid'), ok = document.getElementById('lo-ok');
    function refresh() {
        var t = 0, n = 0;
        form.querySelectorAll('input[name="tests[]"]:checked').forEach(function (c) { t += parseFloat(c.dataset.price || 0); n++; });
        document.getElementById('lo-total').textContent = APP.money(t);
        ok.disabled = !pid.value || !n;
    }
    form.addEventListener('change', refresh);
    APP.liveSearch(document.getElementById('lo-patient'), { minLength: 2,
        fetch: function (q) { return APP.ajax(APP.url('patients/search', { q: q })).then(function (d) { return d.items || []; }); },
        render: function (p) { return '<div class="grow"><div class="t">' + APP.esc(p.name) + '</div><div class="s">' + APP.esc(p.mrn) + ' · ' + APP.esc(p.phone || '') + '</div></div>'; },
        onSelect: function (p) { pid.value = p.id; document.getElementById('lo-patient').value = p.name + ' (' + p.mrn + ')'; refresh(); } });
})();
</script>
<?php endif; ?>
