<?php
/** @var string $from @var string $to @var array $byQueue @var array $byHour */
use App\Core\View;

echo View::partial('page_header', [
    'title' => 'Token & Waiting Times', 'description' => 'Tokens issued and served per queue, average and longest wait (issue → call) and consultation time (start → done).',
    'breadcrumbs' => [['Reports'], ['Tokens']],
    'actions' => '<a class="btn btn-excel" href="' . e(url_with(['export' => 'xlsx'])) . '">' . icon('excel') . ' Export to Excel</a>',
]);
echo View::render('reports/_range', ['route' => 'reports/tokens', 'from' => $from, 'to' => $to], null);
$max = $byHour ? max($byHour) : 1;
?>
<div class="card card-flush"><div class="card-body table-wrap"><table class="table">
    <thead><tr><th>Queue</th><th class="num">Issued</th><th class="num">Served</th><th class="num">No-show / cancelled</th><th class="num">Priority</th><th class="num">Avg wait</th><th class="num">Max wait</th><th class="num">Avg consult</th></tr></thead><tbody>
    <?php foreach ($byQueue as $q): ?><tr><td><?= e($q['label']) ?></td><td class="num"><?= (int) $q['issued'] ?></td><td class="num"><?= (int) $q['served'] ?></td><td class="num"><?= (int) $q['missed'] ?></td>
        <td class="num"><?= (int) $q['priority'] ?></td><td class="num"><?= $q['avg_wait'] !== null ? round((float) $q['avg_wait']) . ' min' : '—' ?></td>
        <td class="num"><?= $q['max_wait'] !== null ? (int) $q['max_wait'] . ' min' : '—' ?></td><td class="num"><?= $q['avg_service'] !== null ? round((float) $q['avg_service']) . ' min' : '—' ?></td></tr><?php endforeach; ?>
    <?php if (!$byQueue): ?><tr><td colspan="8" class="muted">No tokens in this period.</td></tr><?php endif; ?>
</tbody></table></div></div>
<?php if ($byHour): ?>
<div class="card"><div class="card-header"><div class="card-title">Arrivals by hour</div></div><div class="card-body"><div class="hbar">
    <?php foreach ($byHour as $h => $n): ?><div><?= sprintf('%02d:00', $h) ?></div><div class="track"><div class="fill" style="width:<?= round($n / $max * 100) ?>%"></div></div><strong><?= (int) $n ?></strong><?php endforeach; ?>
</div></div></div>
<?php endif; ?>
