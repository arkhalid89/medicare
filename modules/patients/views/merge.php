<?php
/** @var array|null $keep @var array|null $other @var callable $count */
use App\Core\View;

echo View::partial('page_header', [
    'title'       => 'Merge Duplicate Patients',
    'description' => 'Everything of the duplicate (visits, tokens, bills, lab, imaging, dental and pregnancy records) moves to the record you keep. The duplicate MRN is retired and logged.',
    'breadcrumbs' => [['Patients', url('patients')], ['Merge']],
]);
$card = static function (?array $p, string $title, string $field) use ($count): string {
    ob_start(); ?>
    <div class="card">
        <div class="card-header"><div class="card-title"><?= e($title) ?></div></div>
        <div class="card-body">
            <div class="search-box mb-2"><?= icon('search') ?><input class="input" data-pick="<?= e($field) ?>" placeholder="Search MRN, name, CNIC or phone…" autocomplete="off"><div class="search-results"></div></div>
            <?php if ($p): ?>
                <dl class="kv">
                    <dt>MRN</dt><dd><strong><?= e($p['mrn']) ?></strong></dd>
                    <dt>Name</dt><dd><?= e($p['name']) ?></dd>
                    <dt>Father / Husband</dt><dd><?= e($p['guardian_name']) ?: '—' ?></dd>
                    <dt>Gender / Age</dt><dd><?= e($p['gender']) ?> <?= e($p['age']) ?></dd>
                    <dt>CNIC</dt><dd><?= e(cnic_mask($p['cnic'])) ?: '—' ?></dd>
                    <dt>Phone</dt><dd><?= e($p['phone']) ?: '—' ?></dd>
                    <dt>Visits</dt><dd><?= (int) $count((int) $p['id']) ?></dd>
                    <dt>Registered</dt><dd><?= e(fmt_date($p['created_at'])) ?></dd>
                </dl>
            <?php else: ?><div class="muted">Search and select a patient.</div><?php endif; ?>
        </div>
    </div>
    <?php return (string) ob_get_clean();
};
?>
<div class="grid grid-2">
    <?= $card($keep, 'Keep this record', 'keep') ?>
    <?= $card($other, 'Merge (retire) this duplicate', 'merge') ?>
</div>
<?php if ($keep && $other): ?>
<form class="card mt-2" method="post" action="<?= e(url('patients/merge')) ?>" data-confirm="Merge <?= e($other['mrn']) ?> into <?= e($keep['mrn']) ?>? This cannot be undone from the screen." data-confirm-ok="Merge">
    <?= csrf_field() ?>
    <input type="hidden" name="keep" value="<?= (int) $keep['id'] ?>"><input type="hidden" name="merge" value="<?= (int) $other['id'] ?>">
    <div class="card-body"><div class="field"><label>Reason <span class="req">*</span></label><input class="input" name="reason" maxlength="500" required placeholder="e.g. Same person registered twice at two counters"></div></div>
    <div class="card-footer"><button class="btn btn-danger"><?= icon('users') ?> Merge Patients</button></div>
</form>
<?php endif; ?>
<script>
document.querySelectorAll('[data-pick]').forEach(function (input) {
    APP.liveSearch(input, {
        minLength: 2,
        fetch: function (q) { return APP.ajax(APP.url('patients/search', { q: q })).then(function (d) { return d.items || []; }); },
        render: function (p) { return '<div class="grow"><div class="t">' + APP.esc(p.name) + '</div><div class="s">' + APP.esc(p.mrn) + ' · ' + APP.esc(p.gender) + ' ' + APP.esc(p.age) + ' · ' + APP.esc(p.phone) + '</div></div>'; },
        onSelect: function (p) {
            var params = new URLSearchParams(location.search);
            params.set(input.dataset.pick, p.id);
            var q = {}; params.forEach(function (v, k) { if (k !== 'r') q[k] = v; });
            location.href = APP.url('patients/merge', q);
        }
    });
});
</script>
