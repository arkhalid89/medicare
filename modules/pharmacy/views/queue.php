<?php
/** @var array $rows @var string $tab @var array $counts */
use App\Core\View;

echo View::partial('page_header', [
    'title' => 'Pharmacy Dispensing', 'description' => 'Prescriptions arrive here the moment the doctor checks out — no paper hand-off. Emergency tokens first.',
    'breadcrumbs' => [['Pharmacy'], ['Dispensing']],
    'actions' => (can('pharmacy.counter_sale') ? '<a class="btn btn-outline" href="' . e(url('pharmacy/sale')) . '">' . icon('cart') . ' Counter Sale</a>' : ''),
]);
?>
<div class="tabs mb-2">
    <?php foreach (['pending' => 'Waiting', 'partial' => 'Partly given', 'cosign' => 'Controlled — co-sign', 'done' => 'Dispensed today'] as $k => $l):
        $n = (int) ($counts[['pending' => 'pending', 'partial' => 'partial', 'cosign' => 'awaiting_cosign'][$k] ?? ''] ?? 0); ?>
        <a class="<?= $tab === $k ? 'active' : '' ?>" href="<?= e(url('pharmacy', ['tab' => $k])) ?>"><?= e($l) ?><?= $n ? ' <span class="badge badge-warning">' . $n . '</span>' : '' ?></a>
    <?php endforeach; ?>
</div>
<div class="card card-flush"><div class="card-body table-wrap"><table class="table">
    <thead><tr><th>Token</th><th>Patient</th><th>Prescription</th><th>Doctor</th><th class="num">Lines</th><th>Since</th><th>Status</th><th></th></tr></thead><tbody>
    <?php foreach ($rows as $r): ?><tr>
        <td><?= $r['token_label'] ? '<strong class="tok-label">' . e($r['token_label']) . '</strong>' : '<span class="muted">—</span>' ?><?= $r['priority'] && $r['priority'] !== 'normal' ? '<div>' . priority_badge($r['priority']) . '</div>' : '' ?></td>
        <td><strong><?= e($r['patient_name']) ?></strong><div class="muted small"><?= e($r['mrn']) ?></div><?= $r['allergies'] ? '<span class="badge badge-danger">Allergy: ' . e($r['allergies']) . '</span>' : '' ?></td>
        <td><?= e($r['visit_no']) ?></td><td class="small"><?= e($r['doctor_name']) ?></td><td class="num"><?= (int) $r['line_count'] ?></td>
        <td class="small"><?= e(fmt_datetime($r['created_at'])) ?></td><td><?= status_chip($r['status']) ?></td>
        <td><a class="btn btn-sm btn-primary" href="<?= e(url('pharmacy/dispense', ['id' => $r['id']])) ?>"><?= $tab === 'cosign' ? 'Co-sign' : 'Open' ?></a></td></tr><?php endforeach; ?>
    <?php if (!$rows): ?><tr><td colspan="8" class="muted">Nothing here.</td></tr><?php endif; ?>
</tbody></table></div></div>
<script>setTimeout(function () { location.reload(); }, 30000);</script>
