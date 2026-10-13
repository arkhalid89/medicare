<?php
/** @var array $sources @var array $samples @var string $currency */
use App\Core\View;
use App\Modules\Settings\SettingsController;

echo View::partial('page_header', [
    'title'       => 'Rx Split Configuration',
    'description' => 'The main-source medicines are written on the prescription (bought outside, no prices). All other sources print below a cut line with prices — the hospital pharmacy & billing slip.',
    'breadcrumbs' => [['Settings'], ['Rx Split']],
]);
$mainId = (string) setting('rx_main_source_id', '');
?>
<form method="post" action="<?= e(url('settings/rx')) ?>" id="rx-form">
    <?= csrf_field() ?>
    <div class="grid grid-main">
        <div>
            <div class="card">
                <div class="card-header"><div class="card-title"><?= icon('layers') ?> Splitting</div></div>
                <div class="card-body">
                    <label class="check mb-2"><input type="checkbox" name="rx_split_enabled" value="1"<?= checked(setting('rx_split_enabled') === '1') ?>> <strong>Enable Rx splitting by source</strong></label>
                    <div class="form-grid mt-1">
                        <div class="field col-6"><label>Main Rx source (written on the prescription, not billed)</label>
                            <select class="input" name="rx_main_source_id">
                                <option value="">Automatic — the source with the lowest order</option>
                                <?php foreach ($sources as $s): ?><option value="<?= (int) $s['id'] ?>"<?= selected($s['id'], $mainId) ?>><?= e($s['name']) ?></option><?php endforeach; ?>
                            </select>
                            <div class="hint">If a prescription has no medicine from this source, the lowest-order source in that prescription becomes its main Rx — the main Rx is never empty.</div></div>
                        <div class="field col-6"><label>Label for medicines without a source</label><input class="input" name="rx_unassigned_label" value="<?= e(setting('rx_unassigned_label')) ?>" maxlength="60"></div>
                        <div class="field col-12"><label>Where the hospital slip prints</label>
                            <?php foreach (SettingsController::SPLIT_MODES as $k => $l): ?>
                                <label class="check" style="display:flex;margin:.35rem 0"><input type="radio" name="rx_split_print_mode" value="<?= e($k) ?>"<?= checked(setting('rx_split_print_mode') === $k) ?>> <?= e($l) ?></label>
                            <?php endforeach; ?></div>
                        <div class="field col-6"><label>Slip title</label><input class="input" name="rx_slip_title" value="<?= e(setting('rx_slip_title')) ?>" maxlength="80"></div>
                        <div class="field col-6"><label>Options</label>
                            <label class="check"><input type="checkbox" name="rx_show_urdu" value="1"<?= checked(setting('rx_show_urdu') === '1') ?>> Urdu noskha under each medicine</label><br>
                            <label class="check mt-1"><input type="checkbox" name="rx_slip_prices" value="1"<?= checked(setting('rx_slip_prices') === '1') ?>> Prices on the slip</label><br>
                            <label class="check mt-1"><input type="checkbox" name="rx_slip_cumulative" value="1"<?= checked(setting('rx_slip_cumulative') === '1') ?>> Day's cumulative total on the slip</label></div>
                    </div>
                </div>
            </div>
            <div class="card card-flush">
                <div class="card-header"><div class="card-title"><?= icon('truck') ?> Source order</div><span class="muted small">Order 1 = main Rx (when automatic) · others print on the slip in this order</span></div>
                <div class="card-body table-wrap">
                    <table class="table">
                        <thead><tr><th>Source</th><th class="num">Medicines</th><th>Status</th><th style="width:120px">Order</th></tr></thead>
                        <tbody>
                        <?php foreach ($sources as $s): ?>
                            <tr><td><strong><?= e($s['name']) ?></strong> <span class="muted small"><?= e($s['short_code']) ?></span></td><td class="num"><?= (int) $s['medicines'] ?></td><td><?= status_badge($s['is_active']) ?></td>
                                <td><input class="input input-sm" type="number" min="0" name="order[<?= (int) $s['id'] ?>]" value="<?= (int) $s['display_order'] ?>" data-order="<?= (int) $s['id'] ?>"></td></tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <div class="card-footer"><button type="submit" class="btn btn-primary"><?= icon('save') ?> Save Configuration</button></div>
            </div>
        </div>
        <div class="card" style="position:sticky;top:76px;align-self:start">
            <div class="card-header"><div class="card-title"><?= icon('eye') ?> Live preview</div><span class="badge badge-gold" id="pv-changed" hidden>Unsaved changes</span></div>
            <div class="card-body"><div class="rx-preview" id="rx-preview"></div></div>
        </div>
    </div>
</form>
<style>
.rx-preview { border: 1px solid var(--border); border-radius: 6px; background: #fff; padding: 10px 12px; font-size: 11px; line-height: 1.35; box-shadow: var(--shadow-sm); }
.rx-preview .pv-head { display: flex; justify-content: space-between; border-bottom: 3px solid #e2231a; padding-bottom: 4px; }
.rx-preview .pv-clinic { font-size: 15px; font-weight: 300; } .rx-preview .pv-doc { font-weight: 700; }
.rx-preview .pv-band { background: #e6e7e8; padding: 3px 6px; margin: 4px 0; color: #444; }
.rx-preview .pv-rx { font-family: "Segoe Script", cursive; color: #888; font-size: 16px; }
.rx-preview .pv-med { border-bottom: 1px dotted #ccc; padding: 2px 0; }
.rx-preview .pv-ur { text-align: right; color: #0A2463; font-family: var(--urdu); direction: rtl; }
.rx-preview .pv-cut { border-top: 1.5px dashed #555; text-align: center; margin: 8px 0 4px; color: #555; font-size: 9px; }
.rx-preview .pv-page { border-top: 3px double #999; margin: 10px 0 6px; text-align: center; color: #999; font-size: 9px; }
.rx-preview .pv-src { font-weight: 700; color: #6f5409; border-bottom: 1px solid #e3cf86; margin-top: 4px; }
.rx-preview .pv-row { display: flex; justify-content: space-between; } .rx-preview .pv-total { font-weight: 700; color: #0A2463; border-top: 2px solid #0A2463; margin-top: 3px; }
</style>
<script>
(function () {
    var sources = <?= json_encode(array_map(static fn ($s) => ['id' => (int) $s['id'], 'name' => $s['name'], 'active' => (int) $s['is_active']], $sources)) ?>;
    var samples = <?= json_encode($samples) ?>;
    var cur = <?= json_encode($currency) ?>;
    var form = document.getElementById('rx-form'), box = document.getElementById('rx-preview');
    var initial = new URLSearchParams(new FormData(form)).toString();
    function esc(s) { return APP.esc(s); }
    function render() {
        var fd = new FormData(form);
        var enabled = fd.get('rx_split_enabled') === '1';
        var order = {};
        sources.forEach(function (s) { order[s.id] = parseInt(fd.get('order[' + s.id + ']'), 10) || 0; });
        var active = sources.filter(function (s) { return s.active; }).sort(function (a, b) { return order[a.id] - order[b.id] || a.id - b.id; });
        var mainId = parseInt(fd.get('rx_main_source_id'), 10) || (active[0] ? active[0].id : 0);
        var urdu = fd.get('rx_show_urdu') === '1', prices = fd.get('rx_slip_prices') === '1', cum = fd.get('rx_slip_cumulative') === '1';
        var mainMeds = [], slip = [];
        active.forEach(function (s) {
            var meds = samples[s.id] || [];
            if (!meds.length) return;
            if (!enabled || s.id === mainId) mainMeds = mainMeds.concat(meds);
            if (!enabled || s.id !== mainId) slip.push({ name: s.name, meds: meds });
        });
        var html = '<div class="pv-head"><div><div class="pv-clinic">' + esc(<?= json_encode((string) setting('org_name')) ?>) + '</div><div>PHC Reg. No. …</div></div>' +
            '<div><div class="pv-doc">Dr. Doctor Name</div><div>Qualification · Specialization</div></div><div style="text-align:right">Ref. No. V-…<br><span style="font-family:var(--urdu)">' + esc(<?= json_encode((string) setting('org_slogan_ur')) ?>) + '</span></div></div>' +
            '<div class="pv-band">Patient Name · S/o · No. of Visit · Date · Contact</div><div class="pv-rx">Rx</div>';
        if (!mainMeds.length) html += '<div class="muted">No main-Rx medicines in this sample.</div>';
        mainMeds.forEach(function (m, i) {
            html += '<div class="pv-med"><b>' + (i + 1) + '. ' + esc(m.name) + '</b><div>1 tablet, twice a day, for 5 days</div>' + (urdu ? '<div class="pv-ur">1 گولی، دن میں دو بار، 5 دن تک</div>' : '') + '</div>';
        });
        html += '<div style="text-align:right;margin-top:6px"><b>(Signature &amp; Stamp)</b></div><div class="pv-row" style="border-top:1px solid #ddd;margin-top:4px"><span>Refill 0 1 2 3 4</span><span><b style="color:#e2231a">Contact Number</b></span></div>';
        if (slip.length) {
            html += fd.get('rx_split_print_mode') === 'separate_page' ? '<div class="pv-page">— next page —</div>' : '<div class="pv-cut">✂ cut here — ' + esc(fd.get('rx_slip_title') || '') + '</div>';
            html += '<b style="color:#0A2463">' + esc(fd.get('rx_slip_title') || '') + '</b>';
            var total = 0;
            slip.forEach(function (g) {
                html += '<div class="pv-src">' + esc(g.name) + '</div>';
                g.meds.forEach(function (m) {
                    var line = m.price * 10;
                    total += line;
                    html += '<div class="pv-row"><span>' + esc(m.name) + '</span>' + (prices ? '<span>10 × ' + m.price + ' = ' + cur + ' ' + line.toLocaleString() + '</span>' : '<span>Qty 10</span>') + '</div>';
                });
            });
            html += '<div class="pv-row"><span>Consultation Fee</span><span>' + cur + ' 1,500</span></div>';
            html += '<div class="pv-row pv-total"><span>This patient</span><span>' + cur + ' ' + (total + 1500).toLocaleString() + '</span></div>';
            if (cum) html += '<div class="pv-row muted"><span>Today\'s cumulative</span><span>' + cur + ' ' + (total + 1500 + 3200).toLocaleString() + '</span></div>';
        }
        box.innerHTML = html;
        document.getElementById('pv-changed').hidden = new URLSearchParams(new FormData(form)).toString() === initial;
    }
    form.addEventListener('input', render);
    form.addEventListener('change', render);
    render();
})();
</script>
