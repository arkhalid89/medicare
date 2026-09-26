<?php
use App\Core\View;

echo View::partial('page_header', [
    'title'       => 'Theme',
    'description' => 'Global application colours and base font size. The default is Navy Blue & Gold.',
    'breadcrumbs' => [['Settings'], ['Theme']],
]);
$primary = (string) setting('theme_primary');
$secondary = (string) setting('theme_secondary');
?>
<div class="grid grid-main">
    <form method="post" class="card" action="<?= e(url('settings/theme')) ?>">
        <?= csrf_field() ?>
        <div class="card-header"><div class="card-title"><?= icon('sliders') ?> Appearance</div></div>
        <div class="card-body">
            <div class="form-grid">
                <div class="field col-4"><label>Primary Colour</label><div class="flex"><input type="color" class="color-input" id="c1" value="<?= e($primary) ?>"><input class="input" name="theme_primary" id="t1" value="<?= e($primary) ?>" maxlength="7"></div></div>
                <div class="field col-4"><label>Secondary (Accent) Colour</label><div class="flex"><input type="color" class="color-input" id="c2" value="<?= e($secondary) ?>"><input class="input" name="theme_secondary" id="t2" value="<?= e($secondary) ?>" maxlength="7"></div></div>
                <div class="field col-4"><label>Base Font Size</label><select class="input" name="theme_font_size">
                    <?php foreach (['12', '13', '14', '15'] as $s): ?><option value="<?= $s ?>"<?= selected($s, setting('theme_font_size')) ?>><?= $s ?> px<?= $s === '13' ? ' (default)' : '' ?></option><?php endforeach; ?></select></div>
            </div>
        </div>
        <div class="card-footer">
            <button type="submit" name="reset" value="1" class="btn btn-outline" formnovalidate><?= icon('refresh') ?> Reset to Navy &amp; Gold</button>
            <button type="submit" class="btn btn-primary"><?= icon('save') ?> Save Theme</button>
        </div>
    </form>
    <div class="card">
        <div class="card-header"><div class="card-title"><?= icon('eye') ?> Preview</div></div>
        <div class="card-body">
            <div class="flex flex-wrap mb-2"><button class="btn btn-primary" type="button">Primary</button><button class="btn btn-gold" type="button">Accent</button><button class="btn btn-outline" type="button">Outline</button></div>
            <div class="stat"><div class="stat-icon"><?= icon('users') ?></div><div><p class="label">Sample card</p><div class="value">1,234</div></div></div>
        </div>
    </div>
</div>
<script>
[['c1', 't1'], ['c2', 't2']].forEach(function (p) {
    var c = document.getElementById(p[0]), t = document.getElementById(p[1]);
    c.addEventListener('input', function () { t.value = c.value.toUpperCase(); });
    t.addEventListener('input', function () { if (/^#[0-9a-f]{6}$/i.test(t.value)) c.value = t.value; });
});
</script>
