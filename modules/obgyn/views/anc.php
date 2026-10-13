<?php
/** @var array $rows @var string $filter */
use App\Core\View;

echo View::partial('page_header', [
    'title' => 'ANC / Labour Room', 'description' => 'Midwife & labour-room work list: ANC visits, deliveries (draft until the gynecologist confirms), newborn registration and postnatal care.',
    'breadcrumbs' => [['Gynae / Obstetrics'], ['ANC / labour room']],
]);
?>
<div class="tabs mb-2"><?php foreach (['term' => 'Due ≤ 4 weeks', 'high' => 'High risk', 'all' => 'All ongoing', 'delivered' => 'Delivered (42 days)'] as $k => $l): ?><a class="<?= $filter === $k ? 'active' : '' ?>" href="<?= e(url('obgyn/anc', ['filter' => $k])) ?>"><?= $l ?></a><?php endforeach; ?></div>
<div class="card card-flush"><div class="card-body table-wrap"><table class="table">
    <thead><tr><th>Mother</th><th>Pregnancy</th><th>GA / EDD</th><th>Risk</th><th>Last ANC</th><th></th></tr></thead><tbody>
    <?php foreach ($rows as $r): $label = $r['name'] . ' — #' . $r['pregnancy_no']; $reasons = json_decode((string) $r['risk_reasons_json'], true) ?: []; ?><tr>
        <td><?= can('obgyn.history.view') ? '<a href="' . e(url('obgyn', ['patient_id' => $r['patient_id']])) . '"><strong>' . e($r['name']) . '</strong></a>' : '<strong>' . e($r['name']) . '</strong>' ?><div class="muted small"><?= e($r['mrn']) ?> · <?= e(age_text($r['dob'])) ?></div></td>
        <td>#<?= (int) $r['pregnancy_no'] ?> <?= status_chip($r['status']) ?><?= $r['drafts'] ? ' <span class="badge badge-warning">draft delivery</span>' : '' ?></td>
        <td><?= $r['status'] === 'ongoing' ? '<strong>' . e($r['ga']) . '</strong> · ' : '' ?><span class="<?= $r['status'] === 'ongoing' && $r['edd_date'] < today() ? 'text-danger' : '' ?>"><?= e(fmt_date($r['edd_date'])) ?></span></td>
        <td><?= $r['risk_level'] === 'high' ? '<span class="badge badge-danger" title="' . e(implode('; ', $reasons)) . '">High</span>' : '<span class="badge badge-muted">Low</span>' ?></td>
        <td class="small"><?= e(fmt_date($r['last_anc'])) ?></td>
        <td class="nowrap"><?php if ($r['status'] === 'ongoing'): ?>
            <?php if (can('obgyn.anc.record')): ?><button type="button" class="btn btn-sm btn-outline" onclick="obForm('m-anc', <?= (int) $r['id'] ?>, '<?= e(addslashes($label)) ?>')">ANC</button><?php endif; ?>
            <?php if (can('obgyn.delivery.record')): ?><button type="button" class="btn btn-sm btn-success" onclick="obForm('m-delivery', <?= (int) $r['id'] ?>, '<?= e(addslashes($label)) ?>')">Delivery</button><?php endif; ?>
        <?php elseif (can('obgyn.anc.record')): ?><button type="button" class="btn btn-sm btn-outline" onclick="obForm('m-pnc', <?= (int) $r['id'] ?>, '<?= e(addslashes($label)) ?>')">PNC</button><?php endif; ?></td></tr><?php endforeach; ?>
    <?php if (!$rows): ?><tr><td colspan="6" class="muted">No pregnancies in this list.</td></tr><?php endif; ?>
</tbody></table></div></div>
<?= View::render('obgyn/_forms', ['back' => 'anc', 'patientId' => 0], null) ?>
