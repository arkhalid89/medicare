<?php
/**
 * @var array $def @var array|null $row @var array $fieldErrors @var array $lookups @var int $nextOrder
 */
use App\Core\View;

$key = $def['key'];
echo View::partial('page_header', [
    'title'       => ($row ? 'Edit ' : 'Add ') . $def['singular'],
    'description' => $def['description'],
    'breadcrumbs' => [['Master Data'], [$def['title'], url('masters/' . $key)], [$row ? 'Edit' : 'Add']],
]);

$val = static function (string $field, $default = '') use ($row) {
    return old($field, $row[$field] ?? $default);
};
$err = static fn (string $f): string => isset($fieldErrors[$f]) ? '<div class="error-text">' . e($fieldErrors[$f]) . '</div>' : '';
$cls = static fn (string $f): string => isset($fieldErrors[$f]) ? ' is-invalid' : '';
?>
<form method="post" class="card" style="max-width:980px" action="<?= e(url('masters/form', ['type' => $key, 'id' => $row['id'] ?? null])) ?>">
    <?= csrf_field() ?>
    <div class="card-header"><div class="card-title"><?= icon($def['icon']) ?> <?= e($def['singular']) ?> details</div></div>
    <div class="card-body">
        <div class="form-grid">
            <div class="field col-6">
                <label><?= e($def['name_label']) ?> <span class="req">*</span></label>
                <input class="input<?= $cls('name') ?>" name="name" value="<?= e($val('name')) ?>" required maxlength="150" autofocus>
                <?= $err('name') ?>
            </div>
            <?php foreach ($def['fields'] as $field => $f):
                $span = $f['type'] === 'textarea' ? 'col-12' : ($f['type'] === 'options' ? 'col-6' : ($key === 'medicines' ? 'col-3' : 'col-6'));
                if ($key === 'medicines' && in_array($field, ['generic_name', 'brand_name'], true)) {
                    $span = 'col-6';
                }
                if ($key === 'medicines' && $field === 'generic_name') {
                    $span = 'col-6';
                }
                ?>
                <div class="field <?= $span ?>">
                    <label><?= e($f['label']) ?><?= !empty($f['required']) ? ' <span class="req">*</span>' : '' ?></label>
                    <?php if ($f['type'] === 'textarea'): ?>
                        <textarea class="input<?= $cls($field) ?>" name="<?= e($field) ?>" maxlength="<?= (int) ($f['max'] ?? 255) ?>" rows="3"><?= e($val($field)) ?></textarea>
                    <?php elseif ($f['type'] === 'select'): ?>
                        <select class="input<?= $cls($field) ?>" name="<?= e($field) ?>">
                            <option value="">— None —</option>
                            <?php foreach ($lookups[$field] as $id => $label): ?>
                                <option value="<?= $id ?>"<?= selected($id, $val($field)) ?>><?= e($label) ?></option>
                            <?php endforeach; ?>
                        </select>
                    <?php elseif ($f['type'] === 'options'): ?>
                        <select class="input<?= $cls($field) ?>" name="<?= e($field) ?>">
                            <?php foreach ($f['options'] as $value => $label): ?>
                                <option value="<?= e($value) ?>"<?= selected($value, $val($field, $f['default'] ?? '')) ?>><?= e($label) ?></option>
                            <?php endforeach; ?>
                        </select>
                    <?php elseif (in_array($f['type'], ['int', 'decimal', 'money'], true)): ?>
                        <?php $v = $val($field, $f['default'] ?? ''); ?>
                        <input class="input<?= $cls($field) ?>" type="number" name="<?= e($field) ?>" value="<?= e(is_numeric($v) ? num($v) : $v) ?>"
                               min="<?= e((string) ($f['min'] ?? 0)) ?>" step="<?= $f['type'] === 'int' ? '1' : '0.01' ?>">
                    <?php else: ?>
                        <input class="input<?= $cls($field) ?>" name="<?= e($field) ?>" value="<?= e($val($field)) ?>" maxlength="<?= (int) ($f['max'] ?? 255) ?>" placeholder="<?= e($f['placeholder'] ?? '') ?>">
                    <?php endif; ?>
                    <?php if (!empty($f['help'])): ?><div class="hint"><?= e($f['help']) ?></div><?php endif; ?>
                    <?= $err($field) ?>
                </div>
            <?php endforeach; ?>
            <div class="field col-3">
                <label>Display Order</label>
                <input class="input<?= $cls('display_order') ?>" type="number" min="0" step="1" name="display_order" value="<?= e($val('display_order', $nextOrder)) ?>">
                <?= $err('display_order') ?>
            </div>
            <div class="field col-3">
                <label>Status</label>
                <label class="check" style="height:36px"><input type="checkbox" name="is_active" value="1"<?= checked(is_post() ? !empty($_POST['is_active']) : (int) ($row['is_active'] ?? 1) === 1) ?>> Active</label>
            </div>
        </div>
    </div>
    <div class="card-footer">
        <a href="<?= e(url('masters/' . $key)) ?>" class="btn btn-outline">Cancel</a>
        <?php if (!$row): ?><button type="submit" name="save_new" value="1" class="btn btn-outline"><?= icon('plus') ?> Save &amp; Add Another</button><?php endif; ?>
        <button type="submit" class="btn btn-primary"><?= icon('save') ?> Save <?= e($def['singular']) ?></button>
    </div>
</form>
