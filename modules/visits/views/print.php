<?php
/**
 * Prescription print (BRD §49–54, §72–73). Stand-alone page, independent of
 * the application UI. Supports A4, A5, Thermal (80 mm) and Legal, the doctor's
 * own design, the 20/80 layout, hidden empty sections and source-wise Rx split.
 *
 * @var array  $v       visit (VisitService::load)
 * @var string $paper   a4|a5|thermal|legal
 * @var array  $design  print_designs row
 * @var string $mode    single|same_page|separate_pages|separate_prescriptions
 * @var array  $papers
 */
$p = $v['patient'];
$thermal = $paper === 'thermal';
$sizes = ['a4' => ['A4', '210mm'], 'a5' => ['A5', '148mm'], 'legal' => ['8.5in 14in', '8.5in'], 'thermal' => ['80mm auto', '80mm']];
[$pageSize, $pageWidth] = $sizes[$paper];
$margin = max(2, min(30, (int) $design['margin_mm']));
$font = max(6, min(16, (float) $design['font_size_pt']));
$logo = setting('org_logo', '');
$showPrices = (int) $design['show_prices'] === 1;
$showBilling = (int) $design['show_billing'] === 1;

$vitals = array_filter([
    'BP'     => $v['bp_systolic'] && $v['bp_diastolic'] ? $v['bp_systolic'] . '/' . $v['bp_diastolic'] : null,
    'Pulse'  => $v['pulse'] ? $v['pulse'] . '/min' : null,
    'Temp'   => $v['temperature'] ? num($v['temperature']) . '°F' : null,
    'SpO₂'   => $v['spo2'] ? $v['spo2'] . '%' : null,
    'RR'     => $v['resp_rate'] ? $v['resp_rate'] . '/min' : null,
    'Weight' => $v['weight'] ? num($v['weight']) . ' kg' : null,
    'Height' => $v['height'] ? num($v['height']) . ' cm' : null,
    'BMI'    => $v['bmi'] ? num($v['bmi']) : null,
]) + $v['other_vitals_list'];

// Left column sections — hidden entirely when empty (BRD §53).
$left = [];
foreach (['Presenting Complaints' => 'complaints', 'Symptoms' => 'symptoms', 'Diagnosis' => 'diagnoses', 'Investigations' => 'investigations'] as $label => $key) {
    if ($v['items'][$key]) {
        $left[$label] = array_map(static fn ($i) => $i['name'] . ($i['code'] ? ' (' . $i['code'] . ')' : ''), $v['items'][$key]);
    }
}
if ($v['follow_up_date'] || $v['follow_up_instructions']) {
    $left['Follow-up'] = array_filter([$v['follow_up_date'] ? fmt_date($v['follow_up_date']) : null, $v['follow_up_instructions']]);
}
if ($v['current_history']) {
    $left['Current History'] = [$v['current_history']];
}

$lines = static fn (?string $text): array => array_values(array_filter(array_map('trim', explode("\n", (string) $text)), static fn ($l) => $l !== ''));

$header = static function () use ($design, $logo, $lines): string {
    ob_start(); ?>
    <header class="rx-head">
        <?php if ($design['header_image']): ?>
            <img class="head-img" src="<?= e(upload_url($design['header_image'])) ?>" alt="">
        <?php else: ?>
            <div class="head-left">
                <?php if ((int) $design['show_logo'] && $logo): ?><img class="logo" src="<?= e(upload_url($logo)) ?>" alt=""><?php endif; ?>
                <div>
                    <?php if ($design['header_title']): ?><div class="doc-name"><?= e($design['header_title']) ?></div><?php endif; ?>
                    <?php if ($design['header_subtitle']): ?><div class="doc-sub"><?= e($design['header_subtitle']) ?></div><?php endif; ?>
                    <?php foreach ($lines($design['header_lines']) as $l): ?><div class="doc-line"><?= e($l) ?></div><?php endforeach; ?>
                </div>
            </div>
            <?php if ($lines($design['clinic_info'])): ?>
                <div class="head-right"><?php foreach ($lines($design['clinic_info']) as $i => $l): ?><div class="<?= $i === 0 ? 'clinic-name' : '' ?>"><?= e($l) ?></div><?php endforeach; ?></div>
            <?php endif; ?>
        <?php endif; ?>
    </header>
    <?php return (string) ob_get_clean();
};

$patientRow = static function () use ($v, $p, $vitals): string {
    ob_start(); ?>
    <div class="rx-patient">
        <div><span>Patient</span><strong><?= e($p['name']) ?></strong><?= $p['guardian_name'] ? ' <em>s/o, d/o, w/o ' . e($p['guardian_name']) . '</em>' : '' ?></div>
        <div><span>MRN</span><strong><?= e($p['mrn']) ?></strong></div>
        <div><span>Age / Gender</span><?= e(age_text($p['dob'], $v['visit_date'])) ?> / <?= e($p['gender']) ?></div>
        <div><span>Date</span><?= e(fmt_datetime($v['visit_date'])) ?></div>
        <?php if ($p['cnic']): ?><div><span>CNIC</span><?= e($p['cnic']) ?></div><?php endif; ?>
        <?php if ($p['phone']): ?><div><span>Phone</span><?= e($p['phone']) ?></div><?php endif; ?>
        <div><span>Visit No</span><?= e($v['visit_no']) ?></div>
        <?php if ($p['address'] || $p['city']): ?><div class="wide"><span>Address</span><?= e(trim(($p['address'] ?? '') . ', ' . ($p['city'] ?? ''), ', ')) ?></div><?php endif; ?>
    </div>
    <?php if ($vitals): ?>
        <div class="rx-vitals"><?php foreach ($vitals as $k => $val): ?><span><b><?= e($k) ?>:</b> <?= e($val) ?></span><?php endforeach; ?></div>
    <?php endif; ?>
    <?php return (string) ob_get_clean();
};

$leftColumn = static function () use ($left): string {
    if (!$left) {
        return '';
    }
    ob_start(); ?>
    <aside class="rx-left">
        <?php foreach ($left as $label => $items): ?>
            <section><h4><?= e($label) ?></h4><ul><?php foreach ($items as $it): ?><li><?= nl2br(e($it)) ?></li><?php endforeach; ?></ul></section>
        <?php endforeach; ?>
    </aside>
    <?php return (string) ob_get_clean();
};

$rxTable = static function (array $group, bool $titled) use ($showPrices, $thermal): string {
    ob_start(); ?>
    <div class="rx-group">
        <?php if ($titled): ?><div class="rx-title">Rx <?= (int) $group['group'] ?> — <?= e($group['source_name']) ?></div><?php endif; ?>
        <?php if ($thermal): ?>
            <?php foreach ($group['lines'] as $i => $m): ?>
                <div class="t-line">
                    <div><b><?= $i + 1 ?>. <?= e($m['medicine_name']) ?> <?= e($m['strength']) ?></b></div>
                    <div><?= e(num($m['dose']) . ' ' . $m['dose_unit']) ?> · <?= e($m['frequency_code'] ?: $m['frequency_name']) ?><?= $m['duration_days'] !== null ? ' · ' . (int) $m['duration_days'] . 'd' : '' ?> · Qty <?= e(num($m['quantity'])) ?></div>
                    <?php if ($m['instructions']): ?><div><i><?= e($m['instructions']) ?></i></div><?php endif; ?>
                    <?php if ($showPrices): ?><div class="t-price"><?= e(num($m['quantity'])) ?> × <?= e(money($m['unit_price'], false)) ?> = <b><?= e(money($m['line_total'], false)) ?></b></div><?php endif; ?>
                </div>
            <?php endforeach; ?>
        <?php else: ?>
        <table class="rx-table">
            <thead><tr><th>#</th><th>Medicine</th><th>Dose</th><th>Freq.</th><th>Route</th><th>Days</th><th class="n">Qty</th>
                <?php if ($showPrices): ?><th class="n">Price</th><th class="n">Total</th><?php endif; ?></tr></thead>
            <tbody>
            <?php foreach ($group['lines'] as $i => $m): ?>
                <tr>
                    <td><?= $i + 1 ?></td>
                    <td><b><?= e($m['medicine_name']) ?></b> <?= e($m['strength']) ?><?php if ($m['dosage_form']): ?> <small>(<?= e($m['dosage_form']) ?>)</small><?php endif; ?>
                        <?php if ($m['instructions']): ?><div class="instr"><?= e($m['instructions']) ?></div><?php endif; ?></td>
                    <td><?= e(num($m['dose'])) ?> <?= e($m['dose_unit']) ?></td>
                    <td><?= e($m['frequency_code'] ?: $m['frequency_name']) ?></td>
                    <td><?= e($m['route_name']) ?></td>
                    <td><?= $m['duration_days'] !== null ? (int) $m['duration_days'] : '' ?></td>
                    <td class="n"><?= e(num($m['quantity'])) ?></td>
                    <?php if ($showPrices): ?><td class="n"><?= e(money($m['unit_price'], false)) ?></td><td class="n"><?= e(money($m['line_total'], false)) ?></td><?php endif; ?>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>
        <?php if ($titled && $showPrices): ?>
            <div class="rx-sub">Subtotal (<?= e($group['source_name']) ?>): <b><?= e(money(array_sum(array_map(static fn ($m) => (float) $m['line_total'], $group['lines'])))) ?></b></div>
        <?php endif; ?>
    </div>
    <?php return (string) ob_get_clean();
};

$billing = static function () use ($v, $showBilling): string {
    if (!$showBilling) {
        return '';
    }
    ob_start(); ?>
    <table class="bill">
        <tr><td>Medicines Total</td><td><?= e(money($v['medicines_total'])) ?></td></tr>
        <?php if ((float) $v['discount_amount'] > 0): ?><tr><td>Discount</td><td>− <?= e(money($v['discount_amount'])) ?></td></tr><?php endif; ?>
        <?php if ((float) $v['tax_amount'] > 0): ?><tr><td>Tax</td><td><?= e(money($v['tax_amount'])) ?></td></tr><?php endif; ?>
        <tr><td>Medicine Net</td><td><?= e(money($v['medicine_net'])) ?></td></tr>
        <tr><td>Consultation Fee<?= $v['payment_status'] !== 'Paid' ? ' (' . e($v['payment_status']) . ')' : '' ?></td><td><?= e(money($v['consultation_fee'])) ?></td></tr>
        <tr class="grand"><td>Grand Total</td><td><?= e(money($v['grand_total'])) ?></td></tr>
    </table>
    <?php return (string) ob_get_clean();
};

$footer = static function () use ($design, $v): string {
    $parts = array_filter([
        $design['footer_address'] ? e($design['footer_address']) : null,
        $design['footer_phone'] ? 'Ph: ' . e($design['footer_phone']) : null,
    ]);
    $extra = array_filter([$design['footer_followup'], $design['footer_appointment'], $design['footer_disclaimer']]);
    ob_start(); ?>
    <?php if ((int) $design['show_signature']): ?>
        <div class="sign"><div class="line"><?= e($v['doctor']['signature_text'] ?: $v['doctor_name']) ?></div></div>
    <?php endif; ?>
    <?php if ($parts || $extra): ?>
        <footer class="rx-foot">
            <?php if ($parts): ?><div class="strong"><?= implode(' · ', $parts) ?></div><?php endif; ?>
            <?php foreach ($extra as $x): ?><div><?= e($x) ?></div><?php endforeach; ?>
        </footer>
    <?php endif; ?>
    <?php return (string) ob_get_clean();
};

$instructions = $v['overall_instructions'] ? '<div class="advice"><b>Advice:</b> ' . nl2br(e($v['overall_instructions'])) . '</div>' : '';
$groups = $v['groups'];
$split = $mode !== 'single';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Prescription <?= e($v['visit_no']) ?> — <?= e($p['name']) ?></title>
    <style>
        @page { size: <?= $pageSize ?>; margin: <?= $margin ?>mm; }
        * { box-sizing: border-box; }
        body { margin: 0; font-family: "Segoe UI", Arial, sans-serif; font-size: <?= $font ?>pt; color: #111; background: #e9edf3; line-height: 1.35; }
        .toolbar { position: sticky; top: 0; z-index: 5; display: flex; gap: .5rem; align-items: center; flex-wrap: wrap; padding: .6rem 1rem; background: #0A2463; color: #fff; font-size: 13px; }
        .toolbar a, .toolbar button { font: inherit; padding: .4rem .8rem; border-radius: 6px; border: 1px solid rgba(255,255,255,.3); background: transparent; color: #fff; text-decoration: none; cursor: pointer; }
        .toolbar a.active { background: #C9A227; color: #0A2463; border-color: #C9A227; font-weight: 600; }
        .toolbar .print { background: #C9A227; color: #0A2463; border-color: #C9A227; font-weight: 700; }
        .toolbar .spacer { flex: 1; }
        .sheet { width: <?= $pageWidth ?>; min-height: <?= $thermal ? 'auto' : '100mm' ?>; margin: 1rem auto; background: #fff; padding: <?= $margin ?>mm; box-shadow: 0 6px 24px rgba(0,0,0,.15); }
        .page-break { break-before: page; page-break-before: always; }
        .rx-head { display: flex; justify-content: space-between; gap: 1rem; border-bottom: 2px solid #0A2463; padding-bottom: .5em; margin-bottom: .5em; }
        .rx-head .head-img { width: 100%; max-height: 40mm; object-fit: contain; }
        .head-left { display: flex; gap: .7em; align-items: center; }
        .logo { max-height: 18mm; max-width: 30mm; object-fit: contain; }
        .doc-name { font-size: 1.55em; font-weight: 700; color: #0A2463; }
        .doc-sub { font-weight: 600; }
        .doc-line, .head-right div { font-size: .9em; color: #333; }
        .head-right { text-align: right; }
        .clinic-name { font-weight: 700; color: #0A2463; font-size: 1.05em !important; }
        .rx-patient { display: grid; grid-template-columns: repeat(4, 1fr); gap: .15em 1em; padding: .35em .5em; background: #f3f5f9; border-radius: 4px; }
        .rx-patient span { display: block; font-size: .72em; text-transform: uppercase; color: #666; letter-spacing: .3px; }
        .rx-patient .wide { grid-column: span 2; }
        .rx-vitals { display: flex; flex-wrap: wrap; gap: .25em 1.1em; padding: .35em .5em; border-bottom: 1px solid #ccc; font-size: .95em; }
        .rx-body { display: flex; gap: 1em; margin-top: .6em; min-height: 60mm; }
        .rx-left { width: 20%; border-right: 1px solid #ccc; padding-right: .7em; font-size: .92em; }
        .rx-left h4 { margin: .5em 0 .2em; font-size: .85em; text-transform: uppercase; color: #0A2463; letter-spacing: .3px; }
        .rx-left ul { margin: 0; padding-left: 1.05em; }
        .rx-right { flex: 1; min-width: 0; }
        .rx-right.full { width: 100%; }
        .rx-symbol { font-family: Georgia, serif; font-size: 2em; font-weight: 700; color: #0A2463; line-height: 1; margin-bottom: .2em; }
        .rx-title { font-weight: 700; color: #6f5409; background: #fbf6e6; border-left: 3px solid #C9A227; padding: .2em .5em; margin: .5em 0 .25em; }
        .rx-table { width: 100%; border-collapse: collapse; }
        .rx-table th { text-align: left; font-size: .8em; text-transform: uppercase; color: #555; border-bottom: 1px solid #999; padding: .25em .3em; }
        .rx-table td { border-bottom: 1px solid #e3e3e3; padding: .3em; vertical-align: top; }
        .rx-table .n { text-align: right; white-space: nowrap; }
        .rx-table .instr { font-size: .88em; color: #444; font-style: italic; }
        .rx-sub { text-align: right; font-size: .9em; margin-top: .2em; }
        .advice { margin-top: .7em; padding: .4em .5em; border: 1px dashed #aaa; border-radius: 4px; }
        .bill { margin: .7em 0 0 auto; border-collapse: collapse; min-width: 45%; }
        .bill td { padding: .15em .4em; }
        .bill td:last-child { text-align: right; white-space: nowrap; }
        .bill .grand td { border-top: 2px solid #0A2463; font-weight: 700; font-size: 1.1em; color: #0A2463; }
        .sign { display: flex; justify-content: flex-end; margin-top: 1.6em; }
        .sign .line { border-top: 1px solid #333; padding-top: .2em; min-width: 45mm; text-align: center; font-size: .9em; }
        .rx-foot { margin-top: .8em; padding-top: .4em; border-top: 1px solid #0A2463; text-align: center; font-size: .82em; color: #333; }
        .strong { font-weight: 600; }
        .continued { font-size: .9em; color: #555; border-bottom: 1px solid #ccc; padding-bottom: .3em; margin-bottom: .4em; }
        /* Thermal: single column receipt */
        .thermal .rx-head { flex-direction: column; align-items: center; text-align: center; gap: .2em; border-bottom: 1px dashed #000; }
        .thermal .head-left { flex-direction: column; }
        .thermal .head-right { text-align: center; }
        .thermal .rx-patient { grid-template-columns: 1fr 1fr; background: none; padding: .2em 0; }
        .thermal .rx-patient .wide { grid-column: span 2; }
        .thermal .rx-body { display: block; min-height: 0; }
        .thermal .rx-left { width: auto; border-right: 0; border-bottom: 1px dashed #000; padding: 0 0 .3em; }
        .thermal .t-line { border-bottom: 1px dotted #999; padding: .25em 0; }
        .thermal .t-price { text-align: right; }
        .thermal .bill { width: 100%; }
        .thermal .rx-title { background: none; border-left: 0; border-bottom: 1px dashed #000; padding: .2em 0; }
        @media print {
            body { background: #fff; }
            .toolbar { display: none; }
            .sheet { width: auto; margin: 0; padding: 0; box-shadow: none; min-height: 0; }
            .sheet + .sheet { break-before: page; page-break-before: always; }
            .rx-patient { -webkit-print-color-adjust: exact; print-color-adjust: exact; }
            .rx-title { -webkit-print-color-adjust: exact; print-color-adjust: exact; }
        }
    </style>
</head>
<body class="<?= $thermal ? 'thermal' : '' ?>">
<div class="toolbar">
    <strong>Print Preview · <?= e($v['visit_no']) ?></strong>
    <?php foreach ($papers as $key => $label): ?>
        <a href="<?= e(url('visits/print', ['id' => $v['id'], 'paper' => $key])) ?>" class="<?= $key === $paper ? 'active' : '' ?>"><?= e($label) ?></a>
    <?php endforeach; ?>
    <span class="spacer"></span>
    <span><?= (int) $v['print_count'] ? 'Printed ' . (int) $v['print_count'] . ' time(s)' : 'Not printed yet' ?></span>
    <button type="button" class="print" id="btn-print"><?= (int) $v['print_count'] ? 'Reprint' : 'Print' ?></button>
    <a href="<?= e(url('visits/view', ['id' => $v['id']])) ?>">Close</a>
</div>

<?php if ($mode === 'separate_prescriptions'): ?>
    <?php foreach ($groups as $gi => $g): ?>
        <div class="sheet">
            <?= $header() ?><?= $patientRow() ?>
            <div class="rx-body">
                <?= $leftColumn() ?>
                <div class="rx-right<?= $left ? '' : ' full' ?>">
                    <div class="rx-symbol">℞</div>
                    <?= $rxTable($g, true) ?>
                    <?= $gi === count($groups) - 1 ? $instructions . $billing() : '' ?>
                </div>
            </div>
            <?= $footer() ?>
        </div>
    <?php endforeach; ?>
<?php else: ?>
    <div class="sheet">
        <?= $header() ?><?= $patientRow() ?>
        <div class="rx-body">
            <?= $leftColumn() ?>
            <div class="rx-right<?= $left ? '' : ' full' ?>">
                <div class="rx-symbol">℞</div>
                <?php if (!$v['medicines']): ?><p><i>No medicines prescribed.</i></p><?php endif; ?>
                <?php foreach ($groups as $gi => $g): ?>
                    <?php if ($mode === 'separate_pages' && $gi > 0): ?>
                        </div></div><?= $footer() ?></div>
                        <div class="sheet">
                            <div class="continued"><b><?= e($p['name']) ?></b> · <?= e($p['mrn']) ?> · <?= e($v['visit_no']) ?> · <?= e(fmt_date($v['visit_date'])) ?> — continued</div>
                            <div class="rx-body"><div class="rx-right full">
                    <?php endif; ?>
                    <?= $rxTable($g, $split) ?>
                <?php endforeach; ?>
                <?= $instructions ?>
                <?= $billing() ?>
            </div>
        </div>
        <?= $footer() ?>
    </div>
<?php endif; ?>

<script>
document.getElementById('btn-print').addEventListener('click', function () {
    fetch(<?= json_encode(url('visits/printed')) ?>, {
        method: 'POST', credentials: 'same-origin',
        headers: { 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest', 'X-CSRF-Token': <?= json_encode(csrf_token()) ?> },
        body: JSON.stringify({ id: <?= (int) $v['id'] ?>, paper: <?= json_encode($paper) ?> })
    }).catch(function () {}).then(function () { window.print(); });
});
</script>
</body>
</html>
