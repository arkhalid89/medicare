<?php
/** @var array $backups @var bool $canRestore */
use App\Core\Backup;
use App\Core\View;

echo View::partial('page_header', [
    'title'       => 'Backup & Restore',
    'description' => 'Full database backup (all tables, structure and data) — downloaded as a .sql file and kept in storage/backups.',
    'breadcrumbs' => [['Administration'], ['Backup & Restore']],
]);
$kinds = ['manual' => ['Manual', 'badge-primary'], 'auto' => ['Automatic', 'badge-info'], 'pre-restore' => ['Before restore', 'badge-warning'], 'pre-reset' => ['Before reset', 'badge-danger']];
?>
<div class="grid grid-main mb-2">
    <div class="card">
        <div class="card-header"><div class="card-title"><?= icon('download') ?> Create Full Backup</div></div>
        <div class="card-body">
            <p>Creates a complete copy of the database: patients, visits, prescriptions, masters, users and settings. The file downloads immediately and a copy is kept on this computer.</p>
            <form method="post" action="<?= e(url('system/backup/create')) ?>" data-no-lock>
                <?= csrf_field() ?>
                <button type="submit" class="btn btn-primary btn-lg" id="btn-backup"><?= icon('database') ?> Backup &amp; Download Now</button>
            </form>
            <p class="hint mt-2">File name: <code>MediCare_Backup_YYYYMMDD_HHMMSS.sql</code> · Automatic schedule and retention are in Settings → Organization &amp; System.</p>
        </div>
    </div>
    <?php if ($canRestore): ?>
    <form class="card" method="post" enctype="multipart/form-data" action="<?= e(url('system/backup/restore')) ?>"
          data-confirm="Restore the uploaded backup? ALL current data will be replaced. The current database is archived first." data-confirm-ok="Restore" data-loading="Restoring database — do not close this window…">
        <?= csrf_field() ?>
        <div class="card-header"><div class="card-title"><?= icon('upload') ?> Restore from File</div></div>
        <div class="card-body">
            <div class="field mb-2"><label>Backup file (.sql)</label><input class="input" type="file" name="backup_file" accept=".sql" required></div>
            <div class="field"><label>Type <strong>RESTORE</strong> to confirm</label><input class="input" name="confirm" autocomplete="off" required pattern="RESTORE"></div>
        </div>
        <div class="card-footer"><button type="submit" class="btn btn-danger"><?= icon('upload') ?> Restore Database</button></div>
    </form>
    <?php endif; ?>
</div>

<div class="card card-flush">
    <div class="card-header"><div class="card-title"><?= icon('archive') ?> Saved Backups</div><span class="muted small"><?= count($backups) ?> file(s)</span></div>
    <div class="card-body table-wrap">
        <?php if (!$backups): ?>
            <div class="empty"><?= icon('database') ?><strong>No backups yet</strong>Create your first backup above.</div>
        <?php else: ?>
        <table class="table">
            <thead><tr><th>File</th><th>Type</th><th>Created</th><th class="num">Size</th><th style="width:1%">Actions</th></tr></thead>
            <tbody>
            <?php foreach ($backups as $b): [$kl, $kc] = $kinds[$b['kind']]; ?>
                <tr>
                    <td><code><?= e($b['name']) ?></code></td>
                    <td><span class="badge <?= $kc ?>"><?= e($kl) ?></span></td>
                    <td class="nowrap"><?= e(fmt_datetime(date('Y-m-d H:i:s', $b['time']))) ?></td>
                    <td class="num"><?= e(Backup::humanSize($b['size'])) ?></td>
                    <td><div class="actions">
                        <a class="act" title="Download" href="<?= e(url('system/backup/download', ['file' => $b['name']])) ?>"><?= icon('download') ?></a>
                        <?php if ($canRestore): ?>
                            <button type="button" class="act success" title="Restore this backup" data-restore="<?= e($b['name']) ?>"><?= icon('restore') ?></button>
                            <form method="post" action="<?= e(url('system/backup/delete')) ?>" data-confirm="Delete backup file <?= e($b['name']) ?>?" data-confirm-ok="Delete">
                                <?= csrf_field() ?><input type="hidden" name="file" value="<?= e($b['name']) ?>"><button class="act danger" title="Delete file"><?= icon('trash') ?></button>
                            </form>
                        <?php endif; ?>
                    </div></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>
    </div>
</div>

<?php if ($canRestore): ?>
<div class="modal-backdrop" id="restore-modal">
    <form class="modal" method="post" action="<?= e(url('system/backup/restore')) ?>" data-loading="Restoring database — do not close this window…">
        <?= csrf_field() ?>
        <input type="hidden" name="file" id="restore-file">
        <div class="modal-head"><?= icon('restore') ?><h3>Restore Backup</h3><button type="button" class="icon-btn close" data-close><?= icon('x') ?></button></div>
        <div class="modal-body">
            <div class="alert alert-warning"><?= icon('alert') ?><div>All current data will be replaced by <strong id="restore-name"></strong>. The current database is archived automatically before the restore, and everyone must log in again.</div></div>
            <div class="field"><label>Type <strong>RESTORE</strong> to confirm</label><input class="input" name="confirm" autocomplete="off" required pattern="RESTORE"></div>
        </div>
        <div class="modal-foot"><button type="button" class="btn btn-outline" data-close>Cancel</button><button type="submit" class="btn btn-danger">Restore Now</button></div>
    </form>
</div>
<script>
document.querySelectorAll('[data-restore]').forEach(function (b) {
    b.addEventListener('click', function () {
        document.getElementById('restore-file').value = b.dataset.restore;
        document.getElementById('restore-name').textContent = b.dataset.restore;
        APP.openModal('restore-modal');
    });
});
document.querySelectorAll('#restore-modal [data-close]').forEach(function (b) { b.addEventListener('click', function () { APP.closeModal('restore-modal'); }); });
</script>
<?php endif; ?>
<script>
// The backup download does not reload the page; refresh the list shortly after.
document.getElementById('btn-backup').closest('form').addEventListener('submit', function () {
    APP.toast('Creating backup… the download will start automatically.', 'info');
    setTimeout(function () { location.reload(); }, 4000);
});
</script>
