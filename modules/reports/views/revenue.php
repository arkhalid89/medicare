<?php
/** @var string $from @var string $to @var array $byDept @var array $byModule @var array $collected @var float $refunds @var float $discounts */
use App\Core\View;

echo View::partial('page_header', [
    'title' => 'Revenue by Department', 'description' => 'Billed amounts by department and by source module, collections by payment mode, refunds and discounts.',
    'breadcrumbs' => [['Reports'], ['Revenue']],
    'actions' => '<a class="btn btn-excel" href="' . e(url_with(['export' => 'xlsx'])) . '">' . icon('excel') . ' Export to Excel</a>'
        . '<button type="button" class="btn btn-outline" onclick="window.print()">' . icon('printer') . ' Print</button>',
]);
echo View::render('reports/_range', ['route' => 'reports/revenue', 'from' => $from, 'to' => $to], null);
$billed = array_sum(array_column($byDept, 'billed'));
$coll = array_sum(array_column($collected, 'amount'));
$table = static function (array $rows, string $first): string {
    $tot = array_sum(array_column($rows, 'billed')) ?: 1;
    $h = '<table class="table table-compact"><thead><tr><th>' . $first . '</th><th class="num">Billed</th><th class="num">Refunded</th><th class="num">Share</th></tr></thead><tbody>';
    foreach ($rows as $r) {
        $h .= '<tr><td>' . e(ucwords(str_replace('_', ' ', (string) $r['label']))) . '</td><td class="num">' . e(money($r['billed'])) . '</td><td class="num">' . e(money($r['refunded']))
            . '</td><td class="num">' . round((float) $r['billed'] / $tot * 100, 1) . '%</td></tr>';
    }
    return $h . ($rows ? '' : '<tr><td colspan="4" class="muted">No billing in this period.</td></tr>') . '</tbody></table>';
};
?>
<div class="grid grid-4 mb-2">
    <div class="stat"><div class="stat-icon"><?= icon('receipt') ?></div><div><p class="label">Billed</p><div class="value"><?= e(money($billed)) ?></div></div></div>
    <div class="stat"><div class="stat-icon green"><?= icon('money') ?></div><div><p class="label">Collected</p><div class="value"><?= e(money($coll)) ?></div></div></div>
    <div class="stat"><div class="stat-icon orange"><?= icon('repeat') ?></div><div><p class="label">Refunds paid</p><div class="value"><?= e(money($refunds)) ?></div></div></div>
    <div class="stat"><div class="stat-icon gold"><?= icon('money') ?></div><div><p class="label">Discounts</p><div class="value"><?= e(money($discounts)) ?></div></div></div>
</div>
<div class="grid grid-2">
    <div class="card card-flush"><div class="card-header"><div class="card-title">By department</div></div><div class="card-body"><?= $table($byDept, 'Department') ?></div></div>
    <div class="card card-flush"><div class="card-header"><div class="card-title">By module</div></div><div class="card-body"><?= $table($byModule, 'Module') ?></div></div>
    <div class="card card-flush"><div class="card-header"><div class="card-title">Collections by mode</div></div><div class="card-body"><table class="table table-compact"><tbody>
        <?php foreach ($collected as $c): ?><tr><td><?= e(ucwords(str_replace('_', ' ', $c['label']))) ?></td><td class="num"><?= (int) $c['n'] ?></td><td class="num"><?= e(money($c['amount'])) ?></td></tr><?php endforeach; ?>
        <?php if (!$collected): ?><tr><td class="muted">No payments.</td></tr><?php endif; ?>
    </tbody></table></div></div>
</div>
