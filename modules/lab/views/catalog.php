<?php
/** @var array $tests @var array|null $test @var array $params @var array $sections @var array $specimens @var array $containers @var array $investigations @var float|null $price */
use App\Core\View;

echo View::partial('page_header', [
    'title' => 'Lab Test Catalog', 'description' => 'Tests, parameters, critical limits and age / sex / pregnancy reference ranges. Changes apply to new results only — released results keep the range that was applied.',
    'breadcrumbs' => [['Laboratory'], ['Catalog']],
    'actions' => '<a class="btn btn-primary" href="' . e(url('lab/catalog', ['id' => 0, 'new' => 1])) . '">' . icon('plus') . ' New test</a>',
]);
$t = $test ?? [];
$sel = static function (string $name, array $opts, $cur): string {
    $h = '<select class="input" name="' . $name . '"><option value="">—</option>';
    foreach ($opts as $id => $n) {
        $h .= '<option value="' . $id . '"' . ((string) $id === (string) $cur ? ' selected' : '') . '>' . e($n) . '</option>';
    }
    return $h . '</select>';
};
?>
<div class="grid grid-main">
<div>
<?php if ($test || input('new')): ?>
<form class="card" method="post" action="<?= e(url('lab/catalog')) ?>"><?= csrf_field() ?><input type="hidden" name="do" value="test"><input type="hidden" name="test_id" value="<?= (int) ($t['id'] ?? 0) ?>">
    <div class="card-header"><div class="card-title"><?= $test ? e($t['name']) : 'New test' ?></div></div>
    <div class="card-body form-grid">
        <div class="field col-3"><label>Code</label><input class="input" name="code" value="<?= e($t['code'] ?? '') ?>" maxlength="30"></div>
        <div class="field col-6"><label>Name <span class="req">*</span></label><input class="input" name="name" value="<?= e($t['name'] ?? '') ?>" required maxlength="150"></div>
        <div class="field col-3"><label>Price</label><input class="input" type="number" step="0.01" min="0" name="price" value="<?= e((string) ($price ?? 0)) ?>"></div>
        <div class="field col-4"><label>Section</label><?= $sel('section_id', $sections, $t['section_id'] ?? '') ?></div>
        <div class="field col-4"><label>Specimen</label><?= $sel('specimen_type_id', $specimens, $t['specimen_type_id'] ?? '') ?></div>
        <div class="field col-4"><label>Container</label><?= $sel('container_id', $containers, $t['container_id'] ?? '') ?></div>
        <div class="field col-6"><label>Doctor's investigation (consult ordering)</label><?= $sel('investigation_id', $investigations, $t['investigation_id'] ?? '') ?></div>
        <div class="field col-3"><label>Result type</label><select class="input" name="result_type"><?php foreach (['numeric', 'select', 'text', 'structured'] as $rt): ?><option<?= ($t['result_type'] ?? 'numeric') === $rt ? ' selected' : '' ?>><?= $rt ?></option><?php endforeach; ?></select></div>
        <div class="field col-3"><label>TAT (hours)</label><input class="input" type="number" min="1" name="turnaround_hours" value="<?= (int) ($t['turnaround_hours'] ?? 24) ?>"></div>
        <div class="field col-6"><label>Method</label><input class="input" name="method" value="<?= e($t['method'] ?? '') ?>"></div>
        <div class="field col-6"><label>Patient instructions</label><input class="input" name="instructions" value="<?= e($t['instructions'] ?? '') ?>"></div>
        <div class="field col-12 flex">
            <label class="check"><input type="checkbox" name="fasting_required" value="1"<?= !empty($t['fasting_required']) ? ' checked' : '' ?>> Fasting</label>
            <label class="check"><input type="checkbox" name="is_outsourceable" value="1"<?= !empty($t['is_outsourceable']) ? ' checked' : '' ?>> Can be outsourced</label>
            <label class="check"><input type="checkbox" name="is_active" value="1"<?= !$test || !empty($t['is_active']) ? ' checked' : '' ?>> Active</label></div>
    </div>
    <div class="card-footer"><button class="btn btn-primary">Save test</button></div>
</form>
<?php endif; ?>
<?php if ($test): ?>
<div class="card card-flush"><div class="card-header"><div class="card-title">Parameters &amp; reference ranges</div></div><div class="card-body">
    <?php foreach ($params as $p): ?>
    <div class="param-block<?= $p['is_active'] ? '' : ' muted' ?>">
        <form method="post" action="<?= e(url('lab/catalog')) ?>" class="form-grid"><?= csrf_field() ?><input type="hidden" name="do" value="param"><input type="hidden" name="test_id" value="<?= (int) $t['id'] ?>"><input type="hidden" name="param_id" value="<?= (int) $p['id'] ?>">
            <div class="field col-2"><label>Code</label><input class="input input-sm" name="code" value="<?= e($p['code']) ?>"></div>
            <div class="field col-4"><label>Name</label><input class="input input-sm" name="name" value="<?= e($p['name']) ?>" required></div>
            <div class="field col-2"><label>Unit</label><input class="input input-sm" name="unit" value="<?= e($p['unit']) ?>"></div>
            <div class="field col-2"><label>Type</label><select class="input input-sm" name="data_type"><?php foreach (['numeric', 'select', 'text'] as $dt): ?><option<?= $p['data_type'] === $dt ? ' selected' : '' ?>><?= $dt ?></option><?php endforeach; ?></select></div>
            <div class="field col-2"><label>Decimals</label><input class="input input-sm" type="number" min="0" max="4" name="decimals" value="<?= (int) $p['decimals'] ?>"></div>
            <div class="field col-2"><label>Critical low</label><input class="input input-sm" type="number" step="any" name="critical_low" value="<?= e($p['critical_low'] ?? '') ?>"></div>
            <div class="field col-2"><label>Critical high</label><input class="input input-sm" type="number" step="any" name="critical_high" value="<?= e($p['critical_high'] ?? '') ?>"></div>
            <div class="field col-5"><label>Options (select, comma separated)</label><input class="input input-sm" name="options" value="<?= e(implode(', ', $p['options_json'] ? (json_decode($p['options_json'], true) ?: []) : [])) ?>"></div>
            <div class="field col-1"><label>Order</label><input class="input input-sm" type="number" name="display_order" value="<?= (int) $p['display_order'] ?>"></div>
            <div class="field col-2 btns"><button class="btn btn-sm btn-outline">Save</button></div>
        </form>
        <table class="table table-compact"><tbody>
            <?php foreach ($p['ranges'] as $r): ?><tr><td class="small"><?= ['A' => 'All', 'M' => 'Male', 'F' => 'Female'][$r['sex']] ?></td>
                <td class="small"><?= e(age_range_text((int) $r['age_min_days'], (int) $r['age_max_days'])) ?><?= $r['pregnant'] !== null ? ($r['pregnant'] ? ' · pregnant' : ' · not pregnant') : '' ?></td>
                <td><?= $r['low'] !== null || $r['high'] !== null ? e(num($r['low']) . ' – ' . num($r['high'])) : e($r['text_range']) ?></td>
                <td><form method="post" action="<?= e(url('lab/catalog')) ?>"><?= csrf_field() ?><input type="hidden" name="do" value="range_del"><input type="hidden" name="test_id" value="<?= (int) $t['id'] ?>"><input type="hidden" name="range_id" value="<?= (int) $r['id'] ?>"><button class="act danger">×</button></form></td></tr><?php endforeach; ?>
        </tbody></table>
        <form method="post" action="<?= e(url('lab/catalog')) ?>" class="flex flex-wrap small"><?= csrf_field() ?><input type="hidden" name="do" value="range"><input type="hidden" name="test_id" value="<?= (int) $t['id'] ?>"><input type="hidden" name="param_id" value="<?= (int) $p['id'] ?>">
            <select class="input input-sm" name="sex"><option value="A">All</option><option value="M">Male</option><option value="F">Female</option></select>
            Age <input class="input input-sm" name="age_min" value="0" style="width:60px"> to <input class="input input-sm" name="age_max" placeholder="any" style="width:60px">
            <select class="input input-sm" name="age_unit"><option>years</option><option>months</option><option>days</option></select>
            <select class="input input-sm" name="pregnant"><option value="">Any</option><option value="1">Pregnant</option><option value="0">Not pregnant</option></select>
            <input class="input input-sm" name="low" placeholder="Low" style="width:70px"><input class="input input-sm" name="high" placeholder="High" style="width:70px">
            <input class="input input-sm" name="text_range" placeholder="or text (e.g. Negative)" style="width:140px"><button class="btn btn-sm btn-outline">Add range</button></form>
    </div>
    <?php endforeach; ?>
    <form method="post" action="<?= e(url('lab/catalog')) ?>" class="form-grid param-block"><?= csrf_field() ?><input type="hidden" name="do" value="param"><input type="hidden" name="test_id" value="<?= (int) $t['id'] ?>">
        <div class="field col-2"><label>Code</label><input class="input input-sm" name="code"></div><div class="field col-4"><label>New parameter</label><input class="input input-sm" name="name" required></div>
        <div class="field col-2"><label>Unit</label><input class="input input-sm" name="unit"></div>
        <div class="field col-2"><label>Type</label><select class="input input-sm" name="data_type"><option>numeric</option><option>select</option><option>text</option></select></div>
        <div class="field col-2 btns"><input type="hidden" name="decimals" value="1"><input type="hidden" name="critical_low" value=""><input type="hidden" name="critical_high" value=""><button class="btn btn-sm btn-primary">Add</button></div></form>
</div></div>
<?php endif; ?>
</div>
<div class="card card-flush"><div class="card-header"><div class="card-title">Tests (<?= count($tests) ?>)</div></div><div class="card-body"><table class="table table-compact"><tbody>
    <?php foreach ($tests as $x): ?><tr class="<?= (int) ($t['id'] ?? 0) === (int) $x['id'] ? 'active' : '' ?>"><td><a href="<?= e(url('lab/catalog', ['id' => $x['id']])) ?>"><strong><?= e($x['code']) ?></strong> <?= e($x['name']) ?></a>
        <div class="muted small"><?= e($x['section_name']) ?> · <?= e($x['specimen_name']) ?> · <?= (int) $x['params'] ?> parameter(s)</div></td>
        <td><?= $x['is_active'] ? '' : '<span class="badge badge-muted">Inactive</span>' ?></td></tr><?php endforeach; ?>
</tbody></table></div></div>
</div>
