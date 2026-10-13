<?php
/** @var array $counts */
use App\Core\View;

echo View::partial('page_header', [
    'title'       => 'Database Reset',
    'description' => 'Super Admin only. A full safety backup is created automatically before any reset.',
    'breadcrumbs' => [['Administration'], ['Database Reset']],
]);
?>
<div class="alert alert-danger"><?= icon('alert') ?><div><strong>Danger zone.</strong> A reset permanently removes data from the live database. You can undo it only by restoring the safety backup from Backup &amp; Restore.</div></div>
<div class="grid grid-main">
    <form method="post" class="card" action="<?= e(url('system/reset')) ?>" data-confirm="This will erase data from the database. A safety backup is taken first. Continue?" data-confirm-ok="Reset Database" data-loading="Resetting database…">
        <?= csrf_field() ?>
        <div class="card-header"><div class="card-title"><?= icon('alert') ?> Reset Options</div></div>
        <div class="card-body">
            <label class="check" style="display:flex;align-items:flex-start;gap:.6rem;padding:.8rem;border:1px solid var(--border);border-radius:10px;margin-bottom:.6rem">
                <input type="radio" name="mode" value="clinical"<?= checked(old('mode', 'clinical') === 'clinical') ?>>
                <span><strong>Clear clinical data</strong><br><span class="muted">Removes patients, visits, prescriptions, templates and activity logs; restarts MRN and visit numbering. Keeps users, master data and settings.</span></span>
            </label>
            <label class="check" style="display:flex;align-items:flex-start;gap:.6rem;padding:.8rem;border:1px solid var(--border);border-radius:10px">
                <input type="radio" name="mode" value="factory"<?= checked(old('mode') === 'factory') ?>>
                <span><strong>Factory reset</strong><br><span class="muted">Rebuilds every table and reloads the default master data. Only your Super Admin account is kept (same username and password).</span></span>
            </label>
            <div class="form-grid mt-2">
                <div class="field col-6"><label>Type <strong>RESET</strong> to confirm</label><input class="input" name="confirm" autocomplete="off" required></div>
                <div class="field col-6"><label>Your password</label><input class="input" type="password" name="password" required autocomplete="current-password"></div>
            </div>
        </div>
        <div class="card-footer"><button type="submit" class="btn btn-danger"><?= icon('trash') ?> Reset Database</button></div>
    </form>
    <div class="card">
        <div class="card-header"><div class="card-title"><?= icon('database') ?> Current Data</div></div>
        <div class="card-body"><dl class="kv"><?php foreach ($counts as $label => $n): ?><dt><?= e($label) ?></dt><dd><?= number_format($n) ?></dd><?php endforeach; ?></dl>
            <p class="hint mt-2">Command-line alternative: <code>php database/reset.php --mode=clinical</code></p></div>
    </div>
</div>

<div class="card mt-2">
    <div class="card-header"><div class="card-title"><?= icon('filter') ?> Delete by table &amp; date range</div><span class="muted small">Permanent · preview first · safety backup taken automatically</span></div>
    <form method="post" action="<?= e(url('system/purge')) ?>" id="purge-form" class="card-body"
          data-confirm="Permanently delete the selected data in this date range? A safety backup is taken first." data-confirm-ok="Delete permanently" data-loading="Deleting…">
        <?= csrf_field() ?>
        <div class="grid grid-2">
            <div>
                <?php foreach (\App\Core\DataPurger::DATASETS as $key => [$label, $desc]): ?>
                    <label class="check purge-opt">
                        <input type="checkbox" name="datasets[]" value="<?= e($key) ?>">
                        <span><strong><?= e($label) ?></strong> <span class="badge badge-muted" data-count="<?= e($key) ?>">–</span><br><span class="muted small"><?= e($desc) ?></span></span>
                    </label>
                <?php endforeach; ?>
            </div>
            <div>
                <div class="form-grid">
                    <div class="field col-6"><label>From date <span class="req">*</span></label><input class="input" type="date" name="from" required></div>
                    <div class="field col-6"><label>To date <span class="req">*</span></label><input class="input" type="date" name="to" required></div>
                    <div class="field col-6"><label>Type <strong>DELETE</strong> to confirm</label><input class="input" name="confirm" autocomplete="off" required></div>
                    <div class="field col-6"><label>Your password</label><input class="input" type="password" name="password" required autocomplete="current-password"></div>
                </div>
                <div class="alert alert-warning mt-2 mb-0"><?= icon('alert') ?><div>Counts update when you pick tables and dates. Deleted rows cannot be restored except from the safety backup.</div></div>
            </div>
        </div>
        <div class="flex mt-2" style="justify-content:flex-end"><button type="submit" class="btn btn-danger"><?= icon('trash') ?> Delete Selected Data</button></div>
    </form>
</div>
<style>.purge-opt{display:flex;align-items:flex-start;gap:.6rem;padding:.6rem .8rem;border:1px solid var(--border);border-radius:10px;margin-bottom:.5rem}</style>
<script>
(function () {
    var f = document.getElementById('purge-form');
    var update = APP.debounce(function () {
        var d = new FormData(f), sets = d.getAll('datasets[]');
        document.querySelectorAll('[data-count]').forEach(function (b) { b.textContent = '–'; });
        if (!d.get('from') || !d.get('to') || !sets.length) return;
        var q = 'from=' + encodeURIComponent(d.get('from')) + '&to=' + encodeURIComponent(d.get('to')) + sets.map(function (s) { return '&datasets[]=' + encodeURIComponent(s); }).join('');
        var u = APP.url('system/purge/preview');
        APP.ajax(u + (u.indexOf('?') === -1 ? '?' : '&') + q).then(function (r) {
            if (!r.ok) return;
            Object.keys(r.counts).forEach(function (k) {
                var b = document.querySelector('[data-count="' + k + '"]');
                b.textContent = r.counts[k] + ' row(s)';
                b.className = 'badge ' + (r.counts[k] ? 'badge-danger' : 'badge-muted');
            });
        });
    }, 300);
    f.addEventListener('change', update);
})();
</script>
