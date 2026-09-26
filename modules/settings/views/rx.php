<?php
/** @var array $sources */
use App\Core\View;
use App\Modules\Settings\SettingsController;

echo View::partial('page_header', [
    'title'       => 'Rx Split Configuration',
    'description' => 'Split each prescription into Rx sections by medicine source (BRD §18, §45).',
    'breadcrumbs' => [['Settings'], ['Rx Split']],
]);
$enabled = setting('rx_split_enabled') === '1';
$modeNow = (string) setting('rx_split_print_mode');
?>
<form method="post" action="<?= e(url('settings/rx')) ?>">
    <?= csrf_field() ?>
    <div class="grid grid-main">
        <div class="card">
            <div class="card-header"><div class="card-title"><?= icon('layers') ?> Splitting</div></div>
            <div class="card-body">
                <label class="check mb-2"><input type="checkbox" name="rx_split_enabled" value="1"<?= checked($enabled) ?>> <strong>Enable Rx splitting</strong></label>
                <div class="form-grid mt-1">
                    <div class="field col-6"><label>Split Method</label><select class="input" name="rx_split_method"><option value="source">By medicine source</option></select></div>
                    <div class="field col-6"><label>Label for medicines without a source</label><input class="input" name="rx_unassigned_label" value="<?= e(setting('rx_unassigned_label')) ?>" maxlength="60"></div>
                    <div class="field col-12"><label>How split sections print</label>
                        <?php foreach (SettingsController::SPLIT_MODES as $k => $l): ?>
                            <label class="check" style="display:flex;margin:.35rem 0"><input type="radio" name="rx_split_print_mode" value="<?= e($k) ?>"<?= checked($modeNow === $k) ?>> <?= e($l) ?></label>
                        <?php endforeach; ?>
                    </div>
                </div>
                <div class="alert alert-info mt-2 mb-0"><?= icon('info') ?><div>When a prescription has medicines from only one source, a normal single Rx is printed. Each visit keeps the grouping it was saved with.</div></div>
            </div>
        </div>
        <div class="card">
            <div class="card-header"><div class="card-title"><?= icon('file') ?> Example</div></div>
            <div class="card-body">
                <div class="rx-group-title">Rx 1 — Local Pharmacy</div><div style="padding:.3rem .8rem">1. Panadol 500mg<br>2. Brufen 400mg</div>
                <div class="rx-group-title">Rx 2 — Hospital Stock</div><div style="padding:.3rem .8rem">1. Augmentin 625mg</div>
                <div class="rx-group-title">Rx 3 — Distributor A</div><div style="padding:.3rem .8rem">1. Amlodipine 5mg</div>
            </div>
        </div>
    </div>
    <div class="card card-flush mt-2">
        <div class="card-header"><div class="card-title"><?= icon('truck') ?> Order of Rx sections</div><span class="muted small">Lower number prints first</span></div>
        <div class="card-body table-wrap">
            <table class="table">
                <thead><tr><th>Source</th><th>Code</th><th class="num">Medicines</th><th>Status</th><th style="width:140px">Order</th></tr></thead>
                <tbody>
                <?php foreach ($sources as $s): ?>
                    <tr><td><strong><?= e($s['name']) ?></strong></td><td><?= e($s['short_code']) ?></td><td class="num"><?= (int) $s['medicines'] ?></td><td><?= status_badge($s['is_active']) ?></td>
                        <td><input class="input input-sm" type="number" min="0" name="order[<?= (int) $s['id'] ?>]" value="<?= (int) $s['display_order'] ?>"></td></tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <div class="card-footer"><button type="submit" class="btn btn-primary"><?= icon('save') ?> Save Configuration</button></div>
    </div>
</form>
