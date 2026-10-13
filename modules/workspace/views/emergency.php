<?php
/** @var bool $reviewer @var array $rows @var array|null $patient */
use App\Core\View;

echo View::partial('page_header', [
    'title'       => 'Emergency Access (Break-glass)',
    'description' => 'Opens one patient\'s clinical record outside your normal scope for 4 hours. Every use is logged and reviewed by the Branch Admin / Auditor — it never silently bypasses access control.',
    'breadcrumbs' => [['Administration'], ['Emergency Access']],
]);
?>
<?php if (can('emergency.access')): ?>
<form class="card" method="post" action="<?= e(url('emergency/grant')) ?>" style="max-width:900px">
    <?= csrf_field() ?><input type="hidden" name="patient_id" id="eg-pid" value="<?= (int) ($patient['id'] ?? 0) ?>">
    <div class="card-header"><div class="card-title"><?= icon('alert') ?> Request emergency access</div></div>
    <div class="card-body"><div class="form-grid">
        <div class="field col-6"><label>Patient</label><div class="search-box"><?= icon('search') ?><input class="input" id="eg-patient" value="<?= e($patient ? $patient['name'] . ' (' . $patient['mrn'] . ')' : '') ?>" autocomplete="off"><div class="search-results"></div></div></div>
        <div class="field col-6"><label>Reason <span class="req">*</span></label><input class="input" name="reason" minlength="10" maxlength="500" required placeholder="e.g. Unconscious patient in ER, attending doctor not available"></div>
    </div></div>
    <div class="card-footer"><button class="btn btn-danger"><?= icon('unlock') ?> Break the glass</button></div>
</form>
<script>
APP.liveSearch(document.getElementById('eg-patient'), {
    minLength: 2,
    fetch: function (q) { return APP.ajax(APP.url('patients/search', { q: q })).then(function (d) { return d.items || []; }); },
    render: function (p) { return '<div class="grow"><div class="t">' + APP.esc(p.name) + '</div><div class="s">' + APP.esc(p.mrn) + '</div></div>'; },
    onSelect: function (p) { document.getElementById('eg-pid').value = p.id; document.getElementById('eg-patient').value = p.name + ' (' + p.mrn + ')'; }
});
</script>
<?php endif; ?>
<div class="card card-flush">
    <div class="card-header"><div class="card-title"><?= icon('list') ?> <?= $reviewer ? 'All grants (unreviewed first)' : 'My emergency access' ?></div></div>
    <div class="card-body table-wrap"><table class="table">
        <thead><tr><th>When</th><th>User</th><th>Patient</th><th>Reason</th><th>Expires</th><th>Review</th></tr></thead><tbody>
        <?php foreach ($rows as $r): ?>
            <tr><td class="small"><?= e(fmt_datetime($r['granted_at'])) ?></td><td><?= e($r['user_name']) ?></td>
                <td><a href="<?= e(url('patients/view', ['id' => $r['patient_id']])) ?>"><?= e($r['patient_name']) ?></a><div class="muted small"><?= e($r['mrn']) ?></div></td>
                <td class="small"><?= e($r['reason']) ?></td><td class="small"><?= e(fmt_datetime($r['expires_at'])) ?></td>
                <td><?php if ($r['reviewed_at']): ?><span class="badge badge-success">Reviewed</span><div class="muted small"><?= e($r['reviewed_by_name']) ?> · <?= e($r['review_note']) ?></div>
                    <?php elseif ($reviewer): ?><form method="post" action="<?= e(url('emergency/review')) ?>" class="flex"><?= csrf_field() ?><input type="hidden" name="id" value="<?= (int) $r['id'] ?>">
                        <input class="input input-sm" name="note" placeholder="Finding" style="width:140px"><button class="btn btn-sm btn-outline">Mark reviewed</button></form>
                    <?php else: ?><span class="badge badge-warning">Pending review</span><?php endif; ?></td></tr>
        <?php endforeach; ?>
        <?php if (!$rows): ?><tr><td colspan="6" class="muted">No emergency access recorded.</td></tr><?php endif; ?>
    </tbody></table></div>
</div>
