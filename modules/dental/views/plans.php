<?php
/** @var array $rows @var string $status */
use App\Core\View;

echo View::partial('page_header', [
    'title' => 'Treatment Plans', 'description' => 'Multi-visit dental plans. Each step is billed when it is done (staged billing) and its consumables leave dental stock.',
    'breadcrumbs' => [['Dentistry', url('dental')], ['Treatment plans']],
]);
?>
<div class="tabs mb-2"><?php foreach (['open' => 'Open', 'completed' => 'Completed', 'cancelled' => 'Cancelled'] as $k => $l): ?><a class="<?= $status === $k ? 'active' : '' ?>" href="<?= e(url('dental/plans', ['status' => $k])) ?>"><?= $l ?></a><?php endforeach; ?></div>
<div class="card card-flush"><div class="card-body table-wrap"><table class="table">
    <thead><tr><th>Plan</th><th>Patient</th><th>Dentist</th><th>Progress</th><th class="num">Estimate</th><th>Status</th><th></th></tr></thead><tbody>
    <?php foreach ($rows as $r): ?><tr><td>#<?= (int) $r['id'] ?><div class="muted small"><?= e(fmt_date($r['created_at'])) ?></div></td>
        <td><strong><?= e($r['patient_name']) ?></strong><div class="muted small"><?= e($r['mrn']) ?></div></td><td class="small"><?= e($r['dentist']) ?></td>
        <td><div class="hbar" style="grid-template-columns:1fr auto"><div class="track"><div class="fill" style="width:<?= $r['steps'] ? round($r['done'] / $r['steps'] * 100) : 0 ?>%"></div></div><span class="small"><?= (int) $r['done'] ?>/<?= (int) $r['steps'] ?></span></div></td>
        <td class="num"><?= e(money($r['estimated_total'])) ?></td><td><?= status_chip($r['status']) ?></td>
        <td><a class="btn btn-sm btn-primary" href="<?= e(url('dental', ['patient_id' => $r['patient_id']])) ?>">Open</a></td></tr><?php endforeach; ?>
    <?php if (!$rows): ?><tr><td colspan="7" class="muted">No plans.</td></tr><?php endif; ?>
</tbody></table></div></div>
