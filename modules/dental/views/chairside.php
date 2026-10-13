<?php
/** @var array $today @var array|null $patient @var int $visitId @var array $plans @var array $teeth */
use App\Core\View;
use App\Modules\Dental\DentalService;

echo View::partial('page_header', [
    'title' => 'Dental Chairside', 'description' => 'Dental assistant / hygienist: consumables used at the chair, perio charting and hygiene steps (scaling, polishing, fluoride, sealants).',
    'breadcrumbs' => [['Dentistry', url('dental')], ['Chairside']],
]);
$quads = [[18, 17, 16, 15, 14, 13, 12, 11, 21, 22, 23, 24, 25, 26, 27, 28], [48, 47, 46, 45, 44, 43, 42, 41, 31, 32, 33, 34, 35, 36, 37, 38]];
?>
<div class="grid grid-main">
<div>
<?php if ($patient): ?>
    <div class="patient-banner"><strong><?= e($patient['name']) ?></strong> · <?= e($patient['mrn']) ?> · <?= e(age_text($patient['dob'])) ?><?= $visitId ? '' : ' · <span class="badge badge-warning">no visit today</span>' ?>
        <a class="btn btn-sm btn-outline" href="<?= e(url('dental', ['patient_id' => $patient['id']])) ?>">Dental record</a></div>
    <?php if (can('dental.consumables')): ?>
    <form class="card" method="post" action="<?= e(url('dental/chairside')) ?>"><?= csrf_field() ?><input type="hidden" name="do" value="consumable"><input type="hidden" name="patient_id" value="<?= (int) $patient['id'] ?>"><input type="hidden" name="visit_id" value="<?= $visitId ?>">
        <div class="card-header"><div class="card-title"><?= icon('box') ?> Consumables used (from dental stock)</div></div>
        <div class="card-body"><div class="search-box mb-2"><?= icon('plus') ?><input class="input" data-item-search placeholder="Add consumable…" autocomplete="off"><div class="search-results"></div></div><table class="table table-compact" id="cs-lines"><thead><tr><th>Item</th><th style="width:120px">Qty</th><th></th></tr></thead><tbody></tbody></table></div>
        <div class="card-footer"><button class="btn btn-primary">Deduct</button></div>
    </form>
    <script>document.addEventListener('DOMContentLoaded', function () { APP.itemLines(document.getElementById('cs-lines'), { types: 'dental_consumable,medical_consumable', columns: ['qty'], location: function () { return <?= (int) (\App\Modules\Inventory\StockService::locationOfType('dental_stock') ?? 0) ?>; } }); });</script>
    <?php endif; ?>
    <?php foreach ($plans as $p): if (!in_array($p['status'], ['proposed', 'accepted', 'in_progress'], true)) { continue; } ?>
    <div class="card card-flush"><div class="card-header"><div class="card-title">Plan #<?= (int) $p['id'] ?> — open steps</div></div><div class="card-body"><table class="table table-compact"><tbody>
        <?php foreach ($p['items'] as $i): if (!in_array($i['status'], ['planned', 'scheduled'], true)) { continue; } ?><tr><td><?= e($i['tooth_number'] ?: '—') ?></td><td><?= e($i['procedure_name']) ?></td>
            <td><?php if (can('dental.procedure.complete') || ($i['hygienist_allowed'] && can('dental.procedure.hygiene'))): ?><form method="post" action="<?= e(url('dental/plans')) ?>"><?= csrf_field() ?><input type="hidden" name="do" value="complete"><input type="hidden" name="back" value="record"><input type="hidden" name="plan_id" value="<?= (int) $p['id'] ?>"><input type="hidden" name="patient_id" value="<?= (int) $patient['id'] ?>"><input type="hidden" name="item_id" value="<?= (int) $i['id'] ?>"><button class="btn btn-sm btn-success" data-confirm="Mark done and bill?">Done</button></form>
            <?php else: ?><span class="muted small">Dentist</span><?php endif; ?></td></tr><?php endforeach; ?>
    </tbody></table></div></div>
    <?php endforeach; ?>
    <?php if (can('dental.perio.edit')): ?>
    <form class="card" method="post" action="<?= e(url('dental/chairside')) ?>"><?= csrf_field() ?><input type="hidden" name="do" value="perio"><input type="hidden" name="patient_id" value="<?= (int) $patient['id'] ?>"><input type="hidden" name="visit_id" value="<?= $visitId ?>">
        <div class="card-header"><div class="card-title">Perio chart — pocket depth (mm) per site; tick B for bleeding</div></div>
        <div class="card-body table-wrap"><table class="table table-compact perio-table">
            <?php foreach ($quads as $arch => $row): $sites = $arch === 0 ? ['DB', 'B', 'MB'] : ['DL', 'L', 'ML']; ?>
            <tr><th></th><?php foreach ($row as $t): ?><th><?= $t ?></th><?php endforeach; ?></tr>
            <?php foreach ($sites as $s): ?><tr><th><?= $s ?></th><?php foreach ($row as $t): ?><td><input class="input input-sm" type="number" min="0" max="15" name="m[<?= $t ?>][<?= $s ?>][pd]" style="width:42px"><label class="tiny"><input type="checkbox" name="m[<?= $t ?>][<?= $s ?>][bop]" value="1">B</label></td><?php endforeach; ?></tr><?php endforeach; ?>
            <?php endforeach; ?>
        </table><div class="field"><label>Notes</label><input class="input" name="notes" maxlength="500"></div></div>
        <div class="card-footer"><button class="btn btn-primary">Save perio chart</button></div>
    </form>
    <?php endif; ?>
<?php else: ?>
    <div class="card"><div class="card-body empty">Choose a patient from today's dental list.</div></div>
<?php endif; ?>
</div>
<div class="card card-flush"><div class="card-header"><div class="card-title">Today's dental patients</div></div><div class="card-body"><table class="table table-compact"><tbody>
    <?php foreach ($today as $v): ?><tr class="<?= $patient && (int) $patient['id'] === (int) $v['patient_id'] ? 'active' : '' ?>"><td><a href="<?= e(url('dental/chairside', ['patient_id' => $v['patient_id']])) ?>"><strong><?= e($v['name']) ?></strong></a><div class="muted small"><?= e($v['mrn']) ?> · <?= e($v['doctor']) ?></div></td></tr><?php endforeach; ?>
    <?php if (!$today): ?><tr><td class="muted">No dental visits today.</td></tr><?php endif; ?>
</tbody></table></div></div>
</div>
