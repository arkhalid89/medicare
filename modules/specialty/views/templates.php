<?php
/** @var array $specialties @var int $sid @var array $fields */
use App\Core\View;
use App\Modules\Specialty\SpecialtyService;

echo View::partial('page_header', [
    'title' => 'Specialty Templates', 'description' => 'Tier 1 structured fields (Doc 6 §4): defined once per specialty, they appear on the consultation of every department tagged with that specialty — no code change needed.',
    'breadcrumbs' => [['Administration'], ['Specialty templates']],
    'actions' => '<a class="btn btn-outline" href="' . e(url('masters', ['type' => 'departments'])) . '">Tag departments</a>',
]);
?>
<div class="grid grid-main">
<div>
    <div class="card card-flush"><div class="card-header"><div class="card-title">Fields</div></div><div class="card-body table-wrap"><table class="table">
        <thead><tr><th>#</th><th>Label</th><th>Type</th><th>Options</th><th>Required</th><th>Scope</th><th></th></tr></thead><tbody>
        <?php foreach ($fields as $f): ?><tr class="<?= $f['is_active'] ? '' : 'muted' ?>">
            <td><?= (int) $f['display_order'] ?></td><td><strong><?= e($f['label']) ?></strong><?= $f['unit'] ? ' <span class="muted">(' . e($f['unit']) . ')</span>' : '' ?><div class="muted small"><?= e($f['field_key']) ?></div></td>
            <td><?= e(SpecialtyService::FIELD_TYPES[$f['field_type']] ?? $f['field_type']) ?></td>
            <td class="small"><?= e(implode(', ', $f['options_json'] ? (json_decode($f['options_json'], true) ?: []) : [])) ?></td>
            <td><?= $f['is_required'] ? 'Yes' : '' ?></td><td class="small"><?= e($f['branch_name'] ?: 'All branches') ?></td>
            <td class="nowrap"><button type="button" class="act" onclick='fillField(<?= e(json_encode(['id' => (int) $f['id'], 'label' => $f['label'], 'field_type' => $f['field_type'], 'unit' => $f['unit'], 'is_required' => (int) $f['is_required'], 'display_order' => (int) $f['display_order'], 'options' => implode(', ', $f['options_json'] ? (json_decode($f['options_json'], true) ?: []) : [])])) ?>)'>Edit</button>
                <form method="post" action="<?= e(url('specialty/templates', ['specialty_id' => $sid])) ?>" style="display:inline"><?= csrf_field() ?><input type="hidden" name="do" value="toggle"><input type="hidden" name="id" value="<?= (int) $f['id'] ?>"><button class="act"><?= $f['is_active'] ? 'Disable' : 'Enable' ?></button></form></td></tr><?php endforeach; ?>
        <?php if (!$fields): ?><tr><td colspan="7" class="muted">No fields yet — this specialty shows the generic consultation only.</td></tr><?php endif; ?>
    </tbody></table></div></div>
    <form class="card" method="post" action="<?= e(url('specialty/templates', ['specialty_id' => $sid])) ?>" id="field-form"><?= csrf_field() ?><input type="hidden" name="id" id="ff-id" value="0">
        <div class="card-header"><div class="card-title" id="ff-title">Add a field</div></div>
        <div class="card-body form-grid">
            <div class="field col-5"><label>Label <span class="req">*</span></label><input class="input" name="label" id="ff-label" required maxlength="150"></div>
            <div class="field col-3"><label>Type</label><select class="input" name="field_type" id="ff-type"><?php foreach (SpecialtyService::FIELD_TYPES as $k => $l): ?><option value="<?= $k ?>"><?= e($l) ?></option><?php endforeach; ?></select></div>
            <div class="field col-2"><label>Unit</label><input class="input" name="unit" id="ff-unit" maxlength="20"></div>
            <div class="field col-2"><label>Order</label><input class="input" type="number" name="display_order" id="ff-order" value="<?= count($fields) + 1 ?>"></div>
            <div class="field col-12"><label>Options (single / multiple choice — comma separated)</label><input class="input" name="options" id="ff-options"></div>
            <div class="field col-12 flex"><label class="check"><input type="checkbox" name="is_required" value="1" id="ff-req"> Required at checkout</label>
                <label class="check"><input type="checkbox" name="branch_only" value="1"> This branch only</label></div>
        </div>
        <div class="card-footer"><button class="btn btn-primary">Save field</button> <button type="button" class="btn btn-ghost" onclick="fillField(null)">Clear</button></div>
    </form>
</div>
<div class="card card-flush"><div class="card-header"><div class="card-title">Specialties</div></div><div class="card-body"><table class="table table-compact"><tbody>
    <?php foreach ($specialties as $s): ?><tr class="<?= (int) $s['id'] === $sid ? 'active' : '' ?>"><td><a href="<?= e(url('specialty/templates', ['specialty_id' => $s['id']])) ?>"><strong><?= e($s['name']) ?></strong></a>
        <div class="muted small"><?= $s['module_key'] ? 'Module: ' . e($s['module_key']) . ' · ' : '' ?><?= e($s['departments'] ?: 'no department tagged') ?></div></td><td class="num"><?= (int) $s['fields'] ?></td></tr><?php endforeach; ?>
</tbody></table></div></div>
</div>
<script>
function fillField(f) {
    f = f || { id: 0, label: '', field_type: 'text', unit: '', is_required: 0, display_order: <?= count($fields) + 1 ?>, options: '' };
    document.getElementById('ff-id').value = f.id; document.getElementById('ff-label').value = f.label; document.getElementById('ff-type').value = f.field_type;
    document.getElementById('ff-unit').value = f.unit || ''; document.getElementById('ff-req').checked = !!f.is_required; document.getElementById('ff-order').value = f.display_order;
    document.getElementById('ff-options').value = f.options; document.getElementById('ff-title').textContent = f.id ? 'Edit field' : 'Add a field';
    document.getElementById('field-form').scrollIntoView({ behavior: 'smooth' });
}
</script>
