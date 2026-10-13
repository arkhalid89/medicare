<?php
/** @var array $materials @var int $mid @var array $runs @var array $params @var array $analyzers */
use App\Core\View;

echo View::partial('page_header', [
    'title' => 'Quality Control', 'description' => 'Westgard rules: 1-3s, 2-2s and R-4s fail; 1-2s warns. A failed run blocks verification of that parameter until a passing run or a documented release by someone else.',
    'breadcrumbs' => [['Laboratory'], ['QC']],
]);
$cur = null;
foreach ($materials as $m) {
    if ((int) $m['id'] === $mid) {
        $cur = $m;
    }
}
?>
<div class="grid grid-main">
<div>
    <div class="card card-flush"><div class="card-header"><div class="card-title">QC materials</div></div><div class="card-body"><table class="table table-compact"><tbody>
        <?php foreach ($materials as $m): ?><tr class="<?= (int) $m['id'] === $mid ? 'active' : '' ?>">
            <td><a href="<?= e(url('lab/qc', ['material_id' => $m['id']])) ?>"><strong><?= e($m['name']) ?></strong></a><div class="muted small"><?= e($m['test_name']) ?> — <?= e($m['parameter_name']) ?> · lot <?= e($m['lot_no']) ?></div></td>
            <td class="small">Mean <?= e(num($m['target_mean'])) ?> ± <?= e(num($m['target_sd'])) ?></td>
            <td><?= $m['last_status'] ? '<span class="flag flag-qc-' . e($m['last_status']) . '">' . e($m['last_status']) . '</span>' : '<span class="muted small">no runs</span>' ?>
                <?= $m['expiry_date'] && $m['expiry_date'] < today() ? '<span class="badge badge-danger">Expired</span>' : '' ?></td></tr><?php endforeach; ?>
        <?php if (!$materials): ?><tr><td class="muted">No QC materials yet.</td></tr><?php endif; ?>
    </tbody></table></div></div>
    <?php if ($cur): ?>
    <div class="card"><div class="card-header"><div class="card-title">Levey-Jennings — <?= e($cur['name']) ?></div></div><div class="card-body">
        <?php $w = 600; $h = 220; $n = max(count($runs), 2); $y = static fn (float $z): float => $h / 2 - max(-4, min(4, $z)) * ($h / 9); ?>
        <svg viewBox="0 0 <?= $w ?> <?= $h ?>" class="lj-chart" style="width:100%;height:auto">
            <?php foreach ([3, 2, 1, 0, -1, -2, -3] as $z): ?><line x1="30" x2="<?= $w ?>" y1="<?= $y($z) ?>" y2="<?= $y($z) ?>" class="lj-<?= abs($z) === 3 ? 'fail' : (abs($z) === 2 ? 'warn' : 'line') ?>"/>
                <text x="0" y="<?= $y($z) + 4 ?>" class="lj-label"><?= $z > 0 ? '+' . $z : $z ?>s</text><?php endforeach; ?>
            <?php $pts = []; foreach ($runs as $i => $r) { $pts[] = (30 + $i * (($w - 40) / ($n - 1))) . ',' . $y((float) $r['z_score']); } ?>
            <polyline points="<?= implode(' ', $pts) ?>" class="lj-path"/>
            <?php foreach ($runs as $i => $r): ?><circle cx="<?= 30 + $i * (($w - 40) / ($n - 1)) ?>" cy="<?= $y((float) $r['z_score']) ?>" r="4" class="lj-pt lj-<?= e($r['status']) ?>"><title><?= e(fmt_datetime($r['run_at'])) ?>: <?= e(num($r['measured_value'])) ?> (z <?= e($r['z_score']) ?>)</title></circle><?php endforeach; ?>
        </svg>
    </div></div>
    <div class="card card-flush"><div class="card-header"><div class="card-title">Runs</div></div><div class="card-body table-wrap"><table class="table table-compact">
        <thead><tr><th>When</th><th class="num">Value</th><th class="num">z</th><th>Result</th><th>By</th><th>Release</th></tr></thead><tbody>
        <?php foreach (array_reverse($runs) as $r): ?><tr><td class="small"><?= e(fmt_datetime($r['run_at'])) ?></td><td class="num"><?= e(num($r['measured_value'])) ?></td><td class="num"><?= e($r['z_score']) ?></td>
            <td><span class="flag flag-qc-<?= e($r['status']) ?>"><?= e($r['status']) ?></span> <span class="small"><?= e($r['westgard_rule']) ?></span></td><td class="small"><?= e($r['recorded_by_name']) ?></td>
            <td class="small"><?php if ($r['override_by']): ?>Released by <?= e($r['override_by_name']) ?>: <?= e($r['override_note']) ?>
                <?php elseif ($r['status'] === 'fail' && can('lab.qc.manage')): ?><form method="post" action="<?= e(url('lab/qc')) ?>" class="flex"><?= csrf_field() ?><input type="hidden" name="do" value="override"><input type="hidden" name="run_id" value="<?= (int) $r['id'] ?>"><input type="hidden" name="material_id" value="<?= $mid ?>">
                    <input class="input input-sm" name="note" placeholder="Corrective action" required><button class="btn btn-sm btn-outline">Release</button></form><?php endif; ?></td></tr><?php endforeach; ?>
        <?php if (!$runs): ?><tr><td colspan="6" class="muted">No runs recorded.</td></tr><?php endif; ?>
    </tbody></table></div></div>
    <?php endif; ?>
</div>
<div>
    <?php if ($cur): ?>
    <form class="card" method="post" action="<?= e(url('lab/qc')) ?>"><?= csrf_field() ?><input type="hidden" name="do" value="run"><input type="hidden" name="material_id" value="<?= $mid ?>">
        <div class="card-header"><div class="card-title">Record a run</div></div>
        <div class="card-body"><div class="field"><label><?= e($cur['parameter_name']) ?> (<?= e($cur['unit']) ?>)</label><input class="input" name="value" type="number" step="any" required autofocus></div>
            <?php if ($analyzers): ?><div class="field"><label>Analyzer</label><select class="input" name="analyzer_id"><option value="">—</option><?php foreach ($analyzers as $id => $n): ?><option value="<?= $id ?>"><?= e($n) ?></option><?php endforeach; ?></select></div><?php endif; ?></div>
        <div class="card-footer"><button class="btn btn-primary">Save run</button></div>
    </form>
    <?php endif; ?>
    <?php if (can('lab.qc.manage')): ?>
    <form class="card" method="post" action="<?= e(url('lab/qc')) ?>"><?= csrf_field() ?><input type="hidden" name="do" value="material">
        <div class="card-header"><div class="card-title">New QC material / lot</div></div>
        <div class="card-body">
            <div class="field"><label>Name</label><input class="input" name="name" required></div>
            <div class="field"><label>Parameter</label><select class="input" name="parameter_id" required><option value="">—</option><?php foreach ($params as $id => $n): ?><option value="<?= $id ?>"><?= e($n) ?></option><?php endforeach; ?></select></div>
            <div class="form-grid"><div class="field col-6"><label>Lot</label><input class="input" name="lot_no"></div><div class="field col-6"><label>Level</label><input class="input" name="level" placeholder="Normal / High"></div>
                <div class="field col-6"><label>Target mean</label><input class="input" type="number" step="any" name="target_mean" required></div><div class="field col-6"><label>Target SD</label><input class="input" type="number" step="any" min="0.0001" name="target_sd" required></div>
                <div class="field col-12"><label>Expiry</label><input class="input" type="date" name="expiry_date"></div></div></div>
        <div class="card-footer"><button class="btn btn-outline">Add material</button></div>
    </form>
    <?php endif; ?>
</div>
</div>
