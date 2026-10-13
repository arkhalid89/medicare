<?php
/** @var array $rows growth_measurements @var string $gender */
use App\Modules\Specialty\GrowthChart;

$rows = array_values(array_filter($rows, static fn ($r) => (float) $r['age_months'] <= 60 && $r['weight_kg'] !== null));
if (!$rows) {
    return;
}
$w = 360; $h = 200; $pad = 28;
$maxKg = 24;
$x = static fn (float $m): float => $pad + $m / 60 * ($w - $pad - 8);
$y = static fn (float $kg): float => $h - $pad - $kg / $maxKg * ($h - $pad - 8);
$curve = GrowthChart::curve($gender, 'weight');
$line = static function (float $zf) use ($curve, $x, $y): string {
    $pts = [];
    foreach ($curve as $m => $med) {
        $pts[] = round($x((float) $m), 1) . ',' . round($y($med * (1 + $zf * GrowthChart::cv('weight'))), 1);
    }
    return implode(' ', $pts);
};
?>
<svg viewBox="0 0 <?= $w ?> <?= $h ?>" class="growth-chart" style="width:100%;height:auto" role="img" aria-label="Weight-for-age chart">
    <?php foreach ([0, 12, 24, 36, 48, 60] as $m): ?><line x1="<?= $x($m) ?>" x2="<?= $x($m) ?>" y1="8" y2="<?= $h - $pad ?>" class="gc-grid"/><text x="<?= $x($m) ?>" y="<?= $h - 10 ?>" class="gc-lbl" text-anchor="middle"><?= $m ?>m</text><?php endforeach; ?>
    <?php foreach ([0, 6, 12, 18, 24] as $kg): ?><text x="2" y="<?= $y($kg) + 3 ?>" class="gc-lbl"><?= $kg ?></text><?php endforeach; ?>
    <polyline points="<?= $line(-1.88) ?>" class="gc-p3"/><polyline points="<?= $line(0) ?>" class="gc-p50"/><polyline points="<?= $line(1.88) ?>" class="gc-p3"/>
    <polyline points="<?= implode(' ', array_map(static fn ($r) => round($x((float) $r['age_months']), 1) . ',' . round($y((float) $r['weight_kg']), 1), $rows)) ?>" class="gc-child"/>
    <?php foreach ($rows as $r): ?><circle cx="<?= round($x((float) $r['age_months']), 1) ?>" cy="<?= round($y((float) $r['weight_kg']), 1) ?>" r="3" class="gc-pt"><title><?= e(fmt_date($r['measured_on'])) ?>: <?= e(num($r['weight_kg'])) ?> kg</title></circle><?php endforeach; ?>
</svg>
<div class="muted small">Weight-for-age (approx. WHO): 3rd, 50th and 97th centile.</div>
