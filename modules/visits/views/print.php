<?php
/**
 * Prescription print — Punjab Healthcare Commission (PHC) recommended
 * letterhead, with the hospital pharmacy & billing slip below a cut line.
 *
 *   Header      clinic name, address, PM&DC / PHC reg. no., PHC RL no.,
 *               doctor, qualification, specialization, Ref. No., QR, slogan
 *   Grey band   patient name, S/o D/o W/o, no. of visit, time, date, address, contact
 *   Left        BP, pulse, temperature, RR, allergies, complaints, history,
 *               findings, investigations …, dispensing stamp
 *   Right       age, gender, weight, provisional diagnosis, Rx (main source,
 *               no prices, 3-line format + Urdu noskha), advice, signature
 *   Footer      refill 0-4, contact number, clinic days & timings
 *   Slip        other sources, line break per source, prices, bill and the
 *               doctor's cumulative total for the day
 *
 * @var array  $v @var string $paper @var array $design @var string $mode @var array $papers
 */
use App\Modules\Visits\Directions;

$p = $v['patient'];
$thermal = $paper === 'thermal';
$sizes = ['a4' => ['A4', '210mm', '297mm'], 'a5' => ['A5', '148mm', '210mm'], 'legal' => ['8.5in 14in', '8.5in', '14in'], 'thermal' => ['80mm auto', '80mm', 'auto']];
[$pageSize, $pageWidth, $pageHeight] = $sizes[$paper];
$margin = max(2, min(30, (int) $design['margin_mm']));
$font = max(6, min(16, (float) $design['font_size_pt']));
$logo = setting('org_logo', '');
$qr = setting('org_qr_image', '');
$urdu = setting('rx_show_urdu', '1') === '1';
$lines = static fn (?string $text): array => array_values(array_filter(array_map('trim', explode("\n", (string) $text)), static fn ($l) => $l !== ''));

$main = array_values(array_filter($v['medicines'], static fn ($m) => (int) ($m['is_main_rx'] ?? 1) === 1));
// Hospital slip = billable lines (other sources; or everything when splitting was off).
$slipLines = [];
foreach ($v['medicines'] as $m) {
    if ((int) ($m['billable'] ?? 1) === 1) {
        $slipLines[$m['source_name'] ?: setting('rx_unassigned_label', 'General')][] = $m;
    }
}
$showSlip = $slipLines || (float) $v['grand_total'] > 0;
$fmtVital = static fn ($val, string $unit = '') => $val !== null && $val !== '' ? num($val) . $unit : '';

$vitals = [
    'B.P'         => $v['bp_systolic'] && $v['bp_diastolic'] ? $v['bp_systolic'] . '/' . $v['bp_diastolic'] . ' mmHg' : '',
    'Pulse'       => $fmtVital($v['pulse'], ' /min'),
    'Temperature' => $fmtVital($v['temperature'], ' °F'),
    'RR'          => $fmtVital($v['resp_rate'], ' /min'),
];
$extraVitals = array_filter(['SpO₂' => $fmtVital($v['spo2'], ' %'), 'Height' => $fmtVital($v['height'], ' cm'), 'BMI' => $fmtVital($v['bmi'])]) + $v['other_vitals_list'];
$names = static fn (array $items): array => array_map(static fn ($i) => $i['name'] . ($i['code'] ? ' (' . $i['code'] . ')' : ''), $items);
$left = array_filter([
    'Allergies'            => $v['allergies'] ? [$v['allergies']] : [],
    'Presenting Complaints'=> $names($v['items']['complaints']),
    'Symptoms'             => $names($v['items']['symptoms']),
    'History'              => $lines($v['current_history']),
    'Findings'             => $lines($v['findings'] ?? ''),
    'Investigations'       => $names($v['items']['investigations']),
    'Follow-up'            => array_filter([$v['follow_up_date'] ? fmt_date($v['follow_up_date']) : null, $v['follow_up_instructions']]),
]);
$diagnosis = implode(', ', $names($v['items']['diagnoses']));
$ordinal = static function (int $n): string {
    $s = ['th', 'st', 'nd', 'rd'];
    $vv = $n % 100;
    return $n . ($s[($vv - 20) % 10] ?? $s[$vv] ?? $s[0]);
};
$contact = $design['footer_phone'] ?: setting('org_phone');
$specialization = $v['doctor']['specialization'] ?? '';
$pmdc = $v['doctor']['pmdc_registration'] ?? '';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Prescription <?= e($v['visit_no']) ?> — <?= e($p['name']) ?></title>
    <style>
        @page { size: <?= $pageSize ?>; margin: <?= $margin ?>mm; }
        * { box-sizing: border-box; }
        body { margin: 0; font-family: "Segoe UI", Arial, sans-serif; font-size: <?= $font ?>pt; color: #1a1a1a; background: #e9edf3; line-height: 1.35; }
        .ur { font-family: "Jameel Noori Nastaleeq", "Noto Nastaliq Urdu", "Urdu Typesetting", "Alvi Nastaleeq", "Segoe UI", Tahoma, sans-serif; direction: rtl; }
        .toolbar { position: sticky; top: 0; z-index: 5; display: flex; gap: .5rem; align-items: center; flex-wrap: wrap; padding: .6rem 1rem; background: #0A2463; color: #fff; font-size: 13px; }
        .toolbar a, .toolbar button { font: inherit; padding: .4rem .8rem; border-radius: 6px; border: 1px solid rgba(255,255,255,.3); background: transparent; color: #fff; text-decoration: none; cursor: pointer; }
        .toolbar a.active { background: #C9A227; color: #0A2463; border-color: #C9A227; font-weight: 600; }
        .toolbar .print { background: #C9A227; color: #0A2463; border-color: #C9A227; font-weight: 700; }
        .toolbar .spacer { flex: 1; }
        .sheet { width: <?= $pageWidth ?>; margin: 1rem auto; background: #fff; padding: <?= $margin ?>mm; box-shadow: 0 6px 24px rgba(0,0,0,.15); }
        .page-break { break-before: page; page-break-before: always; }

        /* Header (PHC items 1-10) */
        .phc-head { display: grid; grid-template-columns: 1.35fr 1fr auto; gap: .8em; align-items: start; padding-bottom: .5em; }
        .clinic-name { font-size: 2.2em; font-weight: 300; line-height: 1.05; color: #111; letter-spacing: -.5px; }
        .clinic-sub { font-size: .82em; color: #333; }
        .clinic-logo { max-height: 14mm; max-width: 26mm; object-fit: contain; float: left; margin-right: .5em; }
        .doc-name { font-size: 1.35em; font-weight: 700; color: #111; }
        .doc-sub { font-size: .88em; }
        .head-right { text-align: right; font-size: .78em; min-width: 26mm; }
        .head-right img { width: 22mm; height: 22mm; object-fit: contain; display: block; margin: .2em 0 .2em auto; }
        .slogan { font-size: 1.1em; white-space: nowrap; }
        .head-img { width: 100%; max-height: 40mm; object-fit: contain; }
        .red-bar { height: 5px; background: #e2231a; margin: .2em 0 0; -webkit-print-color-adjust: exact; print-color-adjust: exact; }

        /* Grey patient band (item 11) */
        .band { background: #e6e7e8; padding: .45em .7em; display: grid; grid-template-columns: 1.4fr 1fr; gap: .25em 1.2em; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
        .band .f { display: flex; gap: .4em; align-items: baseline; min-width: 0; }
        .band .f span { white-space: nowrap; color: #333; }
        .band .f b { flex: 1; border-bottom: 1px dashed #777; font-weight: 600; min-width: 0; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
        .band .pair { display: grid; grid-template-columns: 1fr 1fr; gap: 1em; }

        /* Body */
        .phc-body { display: grid; grid-template-columns: 27% 1fr; min-height: <?= $paper === 'a5' ? '95mm' : ($paper === 'legal' ? '200mm' : '150mm') ?>; }
        .phc-left { border-right: 2px solid #ddd; padding: .7em .8em .7em 0; display: flex; flex-direction: column; gap: .55em; font-size: .92em; }
        .phc-left .vit { display: grid; grid-template-columns: auto 1fr; gap: .15em .6em; }
        .phc-left .vit b { font-weight: 600; }
        .phc-left h4 { margin: 0 0 .15em; font-size: .9em; font-weight: 600; color: #222; }
        .phc-left ul { margin: 0; padding-left: 1.05em; }
        .phc-left .allergy { color: #b3261e; font-weight: 600; }
        .stamp { margin-top: auto; border: 1.5px dashed #aaa; border-radius: 6px; color: #999; font-weight: 700; font-size: .8em; text-align: center; padding: .6em .3em; letter-spacing: .5px; }
        .phc-right { padding: .7em 0 .7em 1em; display: flex; flex-direction: column; }
        .agl { display: flex; gap: 1.2em; flex-wrap: wrap; }
        .agl .f { display: flex; gap: .35em; } .agl .f b { border-bottom: 1px dashed #777; min-width: 16mm; font-weight: 600; }
        .dx { margin: .35em 0 .2em; display: flex; gap: .4em; } .dx b { flex: 1; border-bottom: 1px dashed #777; font-weight: 600; }
        .rx-mark { font-family: "Brush Script MT", "Segoe Script", cursive; font-size: 2.1em; color: #888; margin: .1em 0 .1em; }
        .med { padding: .3em 0 .45em; border-bottom: 1px dotted #ccc; page-break-inside: avoid; }
        .med .l1 { font-weight: 700; }
        .med .l1 .qty { float: right; font-weight: 600; font-size: .9em; color: #333; }
        .med .l2 { padding-left: 1.2em; }
        .med .l3 { padding-left: 1.2em; font-style: italic; color: #333; }
        .med .lu { padding-left: 1.2em; text-align: right; font-size: 1.12em; line-height: 1.8; color: #0A2463; }
        .advice { margin-top: .7em; padding: .35em .5em; border-left: 3px solid #0A2463; background: #f5f7fb; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
        .sign { margin-top: auto; padding-top: 1.4em; text-align: right; font-weight: 700; }
        .sign small { display: block; font-weight: 400; color: #555; }

        /* Footer (items 19, 20) */
        .phc-foot { border-top: 2px solid #ddd; padding-top: .4em; display: flex; justify-content: space-between; gap: 1em; font-size: .9em; }
        .refill b { margin-right: .4em; }
        .refill span { display: inline-block; width: 1.5em; height: 1.5em; line-height: 1.4em; text-align: center; border-radius: 50%; }
        .refill span.on { border: 1.5px solid #111; font-weight: 700; }
        .refill-note { margin-left: .5em; color: #666; font-size: .8em; }
        .contact { text-align: right; }
        .contact .red { color: #e2231a; font-weight: 700; }
        .disclaimer { font-size: .72em; color: #666; text-align: center; margin-top: .2em; }

        /* Hospital slip (below the cut line) */
        .cut { margin: 1em 0 .6em; border-top: 1.5px dashed #555; position: relative; text-align: center; }
        .cut span { position: relative; top: -.75em; background: #fff; padding: 0 .6em; font-size: .78em; color: #555; }
        .slip-head { display: flex; justify-content: space-between; gap: 1em; flex-wrap: wrap; font-size: .9em; margin-bottom: .3em; }
        .slip-head h3 { margin: 0; font-size: 1.05em; color: #0A2463; }
        .src { margin: .35em 0 0; font-weight: 700; color: #6f5409; border-bottom: 1px solid #e3cf86; }
        table.slip { width: 100%; border-collapse: collapse; font-size: .92em; }
        table.slip td, table.slip th { padding: .15em .3em; border-bottom: 1px solid #eee; text-align: left; }
        table.slip .n { text-align: right; white-space: nowrap; }
        .bill { margin-left: auto; margin-top: .4em; border-collapse: collapse; min-width: 55%; font-size: .95em; }
        .bill td { padding: .1em .4em; } .bill td:last-child { text-align: right; white-space: nowrap; }
        .bill .grand td { border-top: 2px solid #0A2463; font-weight: 700; color: #0A2463; }
        .bill .cum td { color: #555; font-size: .92em; border-top: 1px dashed #aaa; }

        /* Thermal receipt */
        .thermal .phc-head { grid-template-columns: 1fr; text-align: center; }
        .thermal .head-right { text-align: center; } .thermal .head-right img { margin: .2em auto; }
        .thermal .clinic-name { font-size: 1.5em; font-weight: 600; }
        .thermal .band { grid-template-columns: 1fr; } .thermal .band .pair { grid-template-columns: 1fr; gap: .2em; }
        .thermal .phc-body { grid-template-columns: 1fr; min-height: 0; }
        .thermal .phc-left { border-right: 0; border-bottom: 1px dashed #000; padding: .4em 0; }
        .thermal .phc-right { padding: .4em 0; }
        .thermal .stamp { display: none; }

        @media print {
            body { background: #fff; }
            .toolbar { display: none; }
            .sheet { width: auto; margin: 0; padding: 0; box-shadow: none; }
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

<div class="sheet">
    <!-- 1-10 Header -->
    <?php if ($design['header_image']): ?>
        <img class="head-img" src="<?= e(upload_url($design['header_image'])) ?>" alt="">
    <?php else: ?>
    <header class="phc-head">
        <div>
            <?php if ((int) $design['show_logo'] && $logo): ?><img class="clinic-logo" src="<?= e(upload_url($logo)) ?>" alt=""><?php endif; ?>
            <div class="clinic-name"><?= e(setting('org_name')) ?></div>
            <div class="clinic-sub"><?= e(trim(setting('org_address') . (setting('org_phone') ? ' · ' . setting('org_phone') : ''))) ?></div>
            <?php if ($pmdc): ?><div class="clinic-sub">PM&amp;DC Reg. No. <?= e($pmdc) ?></div><?php endif; ?>
            <?php if (setting('org_phc_reg_no') || setting('org_phc_rl_no')): ?>
                <div class="clinic-sub"><?= setting('org_phc_reg_no') ? 'PHC Reg. No. ' . e(setting('org_phc_reg_no')) : '' ?>&nbsp;&nbsp;&nbsp;<?= setting('org_phc_rl_no') ? 'PHC RL. No. ' . e(setting('org_phc_rl_no')) : '' ?></div>
            <?php endif; ?>
        </div>
        <div>
            <div class="doc-name"><?= e($design['header_title'] ?: $v['doctor_name']) ?></div>
            <?php if ($design['header_subtitle']): ?><div class="doc-sub">Qualification: <?= e($design['header_subtitle']) ?></div><?php endif; ?>
            <?php if ($specialization): ?><div class="doc-sub">Specialization: <?= e($specialization) ?></div><?php endif; ?>
            <?php foreach ($lines($design['header_lines']) as $l): if ($l === $specialization || str_contains($l, 'PMDC')) { continue; } ?><div class="doc-sub"><?= e($l) ?></div><?php endforeach; ?>
        </div>
        <div class="head-right">
            <div>Ref. No. <b><?= e($v['visit_no']) ?></b></div>
            <?php if ((int) ($design['show_qr'] ?? 1) && $qr): ?><img src="<?= e(upload_url($qr)) ?>" alt="PHC QR"><?php endif; ?>
            <?php if ((int) ($design['show_slogan'] ?? 1) && setting('org_slogan_ur')): ?><div class="slogan ur" lang="ur"><?= e(setting('org_slogan_ur')) ?></div><?php endif; ?>
        </div>
    </header>
    <?php endif; ?>
    <div class="red-bar"></div>

    <!-- 11 Patient band -->
    <section class="band">
        <div class="f"><span>Patient Name</span><b><?= e($p['name']) ?> <small>(<?= e($p['mrn']) ?>)</small></b></div>
        <div class="f"><span>S/o, D/o, W/o</span><b><?= e($p['guardian_name']) ?></b></div>
        <div class="f"><span>No. of Visit</span><b><?= e($ordinal((int) $v['visit_number'])) ?></b></div>
        <div class="pair"><div class="f"><span>Time</span><b><?= e(date('h:i A', strtotime($v['visit_date']))) ?></b></div><div class="f"><span>Date/s</span><b><?= e(fmt_date($v['visit_date'])) ?></b></div></div>
        <div class="f"><span>Address</span><b><?= e(trim(($p['address'] ?? '') . ', ' . ($p['city'] ?? ''), ', ')) ?></b></div>
        <div class="f"><span>Contact No.</span><b><?= e($p['phone']) ?></b></div>
    </section>

    <div class="phc-body">
        <!-- 12 Left column -->
        <aside class="phc-left">
            <div class="vit">
                <?php foreach ($vitals as $k => $val): ?><span><?= e($k) ?></span><b><?= e($val) ?></b><?php endforeach; ?>
                <?php foreach ($extraVitals as $k => $val): ?><span><?= e($k) ?></span><b><?= e($val) ?></b><?php endforeach; ?>
            </div>
            <?php foreach ($left as $label => $items): ?>
                <section><h4><?= e($label) ?></h4><ul class="<?= $label === 'Allergies' ? 'allergy' : '' ?>"><?php foreach ($items as $it): ?><li><?= nl2br(e($it)) ?></li><?php endforeach; ?></ul></section>
            <?php endforeach; ?>
            <div class="stamp">DISPENSING STAMP</div>
        </aside>

        <!-- 13-17 Right column -->
        <section class="phc-right">
            <div class="agl">
                <div class="f"><span>Age</span><b><?= e(age_text($p['dob'], $v['visit_date'])) ?></b></div>
                <div class="f"><span>Gender</span><b><?= e($p['gender']) ?></b></div>
                <div class="f"><span>Weight (kg)</span><b><?= e($fmtVital($v['weight'])) ?></b></div>
            </div>
            <div class="dx"><span>Provisional/Diagnosis</span><b><?= e($diagnosis) ?></b></div>
            <div class="rx-mark">Rx</div>
            <?php if (!$main): ?><p><i><?= $v['medicines'] ? 'All medicines are supplied by the hospital — see the slip below.' : 'No medicines prescribed.' ?></i></p><?php endif; ?>
            <?php foreach ($main as $i => $m): ?>
                <div class="med">
                    <div class="l1"><?= $i + 1 ?>. <?= e(Directions::line1($m)) ?> <span class="qty">Qty: <?= e(num($m['quantity'])) ?> <?= e($m['unit']) ?></span></div>
                    <div class="l2"><?= e(ucfirst(Directions::english($m))) ?></div>
                    <?php if ($m['instructions']): ?><div class="l3"><?= e($m['instructions']) ?></div><?php endif; ?>
                    <?php if ($urdu): ?><div class="lu ur" lang="ur"><?= e(Directions::urdu($m)) ?></div><?php endif; ?>
                </div>
            <?php endforeach; ?>
            <?php if ($v['overall_instructions']): ?><div class="advice"><b>Advice:</b> <?= nl2br(e($v['overall_instructions'])) ?></div><?php endif; ?>
            <?php if ((int) $design['show_signature']): ?>
                <div class="sign">(Signature &amp; Stamp)<small><?= e($v['doctor']['signature_text'] ?: $v['doctor_name']) ?></small></div>
            <?php endif; ?>
        </section>
    </div>

    <!-- 19-20 Footer -->
    <footer class="phc-foot">
        <div class="refill"><b>Refill</b><?php for ($r = 0; $r <= 4; $r++): ?><span class="<?= min(4, (int) $v['refills']) === $r ? 'on' : '' ?>"><?= $r === 4 && (int) $v['refills'] > 4 ? (int) $v['refills'] : $r ?></span><?php endfor; ?>
            <small class="refill-note"><?= (int) $v['refills'] ? 'repeat #' . (int) $v['refills'] . ' of the original prescription' : 'original prescription' ?></small></div>
        <div class="contact"><span class="red">Contact Number</span> <?= e($contact) ?><br>Clinic days &amp; timings: <?= e(setting('clinic_timings')) ?></div>
    </footer>
    <?php if ($design['footer_disclaimer']): ?><div class="disclaimer"><?= e($design['footer_disclaimer']) ?></div><?php endif; ?>

    <?php if ($showSlip): ?>
        <?php if ($mode === 'separate_page' && !$thermal): ?>
            </div><div class="sheet page-break">
        <?php else: ?>
            <div class="cut"><span>✂ cut here — <?= e(setting('rx_slip_title', 'Hospital Pharmacy & Billing')) ?></span></div>
        <?php endif; ?>
        <section class="slip">
            <div class="slip-head">
                <h3><?= e(setting('rx_slip_title', 'Hospital Pharmacy & Billing')) ?></h3>
                <div><b><?= e($p['name']) ?></b> · <?= e($p['mrn']) ?> · <?= e($v['visit_no']) ?> · <?= e(fmt_datetime($v['visit_date'])) ?></div>
            </div>
            <?php foreach ($slipLines as $source => $meds): $sub = 0.0; ?>
                <div class="src"><?= e($source) ?></div>
                <table class="slip">
                    <?php foreach ($meds as $i => $m): $sub += (float) $m['line_total']; ?>
                        <tr>
                            <td><?= $i + 1 ?>. <?= e(Directions::line1($m)) ?><br><small><?= e(ucfirst(Directions::english($m))) ?><?= $urdu ? ' · <span class="ur" lang="ur">' . e(Directions::urdu($m)) . '</span>' : '' ?></small></td>
                            <?php if (setting('rx_slip_prices', '1') === '1'): ?>
                                <td class="n"><?= e(num($m['quantity'])) ?> × <?= e(money($m['unit_price'], false)) ?></td>
                                <td class="n"><b><?= e(money($m['line_total'])) ?></b></td>
                            <?php else: ?>
                                <td class="n">Qty <?= e(num($m['quantity'])) ?></td>
                            <?php endif; ?>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (setting('rx_slip_prices', '1') === '1'): ?>
                        <tr><td class="n" colspan="2"><?= e($source) ?> subtotal</td><td class="n"><b><?= e(money($sub)) ?></b></td></tr>
                    <?php endif; ?>
                </table>
            <?php endforeach; ?>
            <table class="bill">
                <?php if ($slipLines): ?><tr><td>Medicines</td><td><?= e(money($v['medicines_total'])) ?></td></tr><?php endif; ?>
                <?php if ((float) $v['discount_amount'] > 0): ?><tr><td>Discount</td><td>− <?= e(money($v['discount_amount'])) ?></td></tr><?php endif; ?>
                <?php if ((float) $v['tax_amount'] > 0): ?><tr><td>Tax</td><td><?= e(money($v['tax_amount'])) ?></td></tr><?php endif; ?>
                <tr><td>Consultation Fee<?= $v['payment_status'] !== 'Paid' ? ' (' . e($v['payment_status']) . ')' : '' ?></td><td><?= e(money($v['consultation_fee'])) ?></td></tr>
                <tr class="grand"><td>This patient</td><td><?= e(money($v['grand_total'])) ?></td></tr>
                <?php if (setting('rx_slip_cumulative', '1') === '1'): ?>
                    <tr class="cum"><td>Prescriptions today (<?= e(fmt_date($v['visit_date'])) ?>, <?= e($v['doctor_name']) ?>)</td><td>#<?= (int) $v['day_count'] ?></td></tr>
                    <tr class="cum"><td>Today's cumulative amount (<?= (int) $v['day_count'] ?> prescription<?= (int) $v['day_count'] === 1 ? '' : 's' ?>)</td><td><?= e(money($v['day_cumulative'])) ?></td></tr>
                <?php endif; ?>
            </table>
        </section>
    <?php endif; ?>
</div>

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
