<?php
/** @var array $rows */
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>Sample labels</title>
<style>
    @page { size: 50mm 25mm; margin: 1mm; }
    body { font-family: Arial, Helvetica, sans-serif; margin: 0; color: #000; }
    .lbl { width: 48mm; height: 23mm; overflow: hidden; page-break-after: always; font-size: 8px; line-height: 1.2; }
    .lbl:last-child { page-break-after: auto; }
    .bc { font-family: 'Libre Barcode 39', 'Free 3 of 9', monospace; font-size: 26px; line-height: 1; letter-spacing: 0; }
    svg { display: block; height: 9mm; width: 46mm; }
    .code { font-size: 10px; font-weight: 700; letter-spacing: .5px; }
    .noprint { margin: 8px; } @media print { .noprint { display: none; } }
</style>
</head>
<body>
<div class="noprint"><button onclick="window.print()">Print</button> <button onclick="window.close()">Close</button></div>
<?php foreach ($rows as $r): ?>
<div class="lbl">
    <strong><?= e(mb_strimwidth($r['patient_name'], 0, 26, '…')) ?></strong> · <?= e($r['mrn']) ?> · <?= e(substr($r['gender'], 0, 1)) ?>/<?= e(age_text($r['dob'])) ?>
    <svg class="c39" data-code="<?= e($r['barcode']) ?>"></svg>
    <div class="code"><?= e($r['barcode']) ?> · <?= e($r['code']) ?></div>
    <?= e($r['container_name']) ?> · <?= e(date('d/m H:i', strtotime($r['collected_at']))) ?>
</div>
<?php endforeach; ?>
<script>
// Code 39 rendered as SVG bars (no external font needed).
(function () {
    var P = {'0':'nnnwwnwnn','1':'wnnwnnnnw','2':'nnwwnnnnw','3':'wnwwnnnnn','4':'nnnwwnnnw','5':'wnnwwnnnn','6':'nnwwwnnnn','7':'nnnwnnwnw','8':'wnnwnnwnn','9':'nnwwnnwnn',
        'A':'wnnnnwnnw','B':'nnwnnwnnw','C':'wnwnnwnnn','D':'nnnnwwnnw','E':'wnnnwwnnn','F':'nnwnwwnnn','G':'nnnnnwwnw','H':'wnnnnwwnn','I':'nnwnnwwnn','J':'nnnnwwwnn',
        'K':'wnnnnnnww','L':'nnwnnnnww','M':'wnwnnnnwn','N':'nnnnwnnww','O':'wnnnwnnwn','P':'nnwnwnnwn','Q':'nnnnnnwww','R':'wnnnnnwwn','S':'nnwnnnwwn','T':'nnnnwnwwn',
        'U':'wwnnnnnnw','V':'nwwnnnnnw','W':'wwwnnnnnn','X':'nwnnwnnnw','Y':'wwnnwnnnn','Z':'nwwnwnnnn','-':'nwnnnnwnw','*':'nwnnwnwnn'};
    document.querySelectorAll('svg.c39').forEach(function (svg) {
        var s = '*' + svg.dataset.code.toUpperCase().replace(/[^0-9A-Z-]/g, '') + '*', x = 0, bars = '';
        for (var i = 0; i < s.length; i++) {
            var p = P[s[i]];
            for (var j = 0; j < 9; j++) { var w = p[j] === 'w' ? 3 : 1; if (j % 2 === 0) bars += '<rect x="' + x + '" y="0" width="' + w + '" height="40"/>'; x += w; }
            x += 1;
        }
        svg.setAttribute('viewBox', '0 0 ' + x + ' 40'); svg.setAttribute('preserveAspectRatio', 'none'); svg.innerHTML = bars;
    });
})();
</script>
</body>
</html>
