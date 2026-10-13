<?php
/** Read-only FDI map. @var array $state tooth => ['condition', 'procedure_status'] @var array $teeth @var string $dentition @var array|null $ghost earlier state */
$rows = [
    'perm' => [[18, 17, 16, 15, 14, 13, 12, 11], [21, 22, 23, 24, 25, 26, 27, 28], [48, 47, 46, 45, 44, 43, 42, 41], [31, 32, 33, 34, 35, 36, 37, 38]],
    'dec'  => [[55, 54, 53, 52, 51], [61, 62, 63, 64, 65], [85, 84, 83, 82, 81], [71, 72, 73, 74, 75]],
];
$sets = ($dentition ?? 'permanent') === 'deciduous' ? ['dec'] : (($dentition ?? '') === 'mixed' ? ['dec', 'perm'] : ['perm']);
$ghost = $ghost ?? null;
$tooth = static function (int $n) use ($state, $teeth, $ghost): string {
    $t = $teeth[(string) $n] ?? ['tooth_type' => 'molar', 'name' => ''];
    $f = $state[(string) $n] ?? null;
    $g = $ghost[(string) $n] ?? null;
    $cls = 'tooth ' . $t['tooth_type'] . ($f ? ' c-' . $f['condition'] : '') . ($f && ($f['procedure_status'] ?? '') === 'planned' ? ' planned' : '');
    $label = 'Tooth ' . $n . ' — ' . $t['name'] . ($f ? ': ' . str_replace('_', ' ', $f['condition']) : '') . ($g && (!$f || $g['condition'] !== $f['condition']) ? ' (was ' . str_replace('_', ' ', $g['condition']) . ')' : '');
    $mk = $f ? ($f['condition'] === 'missing' ? '×' : implode('', array_map(static fn ($s) => strtoupper($s[0]), (array) ($f['surfaces'] ?? [])))) : '';
    $changed = $g && (!$f || $g['condition'] !== $f['condition']) ? ' <i class="chg" title="changed">•</i>' : '';
    return '<div class="' . e($cls) . '" title="' . e($label) . '" aria-label="' . e($label) . '"><span class="mk">' . e($mk) . $changed . '</span><span class="n">' . $n . '</span></div>';
};
?>
<div class="dc-arch dc-static">
    <div class="dc-lbl">UPPER · patient's RIGHT ◂ ▸ LEFT</div>
    <?php foreach ($sets as $s): ?><div class="dc-row upper<?= $s === 'dec' ? ' dec' : '' ?>"><?php foreach ($rows[$s][0] as $n) { echo $tooth($n); } ?><div class="mid"></div><?php foreach ($rows[$s][1] as $n) { echo $tooth($n); } ?></div><?php endforeach; ?>
    <?php foreach (array_reverse($sets) as $s): ?><div class="dc-row lower<?= $s === 'dec' ? ' dec' : '' ?>"><?php foreach ($rows[$s][2] as $n) { echo $tooth($n); } ?><div class="mid"></div><?php foreach ($rows[$s][3] as $n) { echo $tooth($n); } ?></div><?php endforeach; ?>
    <div class="dc-lbl">LOWER</div>
</div>
