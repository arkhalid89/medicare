<?php
/** @var string $tab @var array $procs @var array $modalities @var array $rooms @var array $equipment @var array $templates @var array $investigations @var array $techs @var array $dose */
use App\Core\View;

echo View::partial('page_header', [
    'title' => 'Radiology Setup', 'description' => 'Procedures & prices, rooms, equipment (QA dates), report templates, technician modalities and the radiation dose register.',
    'breadcrumbs' => [['Radiology'], ['Setup']],
]);
$sel = static function (string $name, array $opts, $cur, bool $blank = true): string {
    $h = '<select class="input input-sm" name="' . $name . '">' . ($blank ? '<option value="">—</option>' : '');
    foreach ($opts as $id => $n) {
        $h .= '<option value="' . $id . '"' . ((string) $id === (string) $cur ? ' selected' : '') . '>' . e($n) . '</option>';
    }
    return $h . '</select>';
};
$tplOpts = array_column($templates, 'name', 'id');
$roomOpts = array_column($rooms, 'name', 'id');
$hid = static fn (string $do, int $id = 0) => csrf_field() . '<input type="hidden" name="do" value="' . $do . '"><input type="hidden" name="tab" value="' . e($GLOBALS['__rtab'] ?? '') . '"><input type="hidden" name="id" value="' . $id . '">';
$GLOBALS['__rtab'] = $tab;
?>
<div class="tabs mb-2"><?php foreach (['procedures' => 'Procedures & prices', 'rooms' => 'Rooms & equipment', 'templates' => 'Report templates', 'techs' => 'Technicians', 'dose' => 'Dose register'] as $k => $l): ?><a class="<?= $tab === $k ? 'active' : '' ?>" href="<?= e(url('radiology/setup', ['tab' => $k])) ?>"><?= $l ?></a><?php endforeach; ?></div>

<?php if ($tab === 'procedures'): ?>
<div class="card card-flush"><div class="card-body table-wrap"><div class="setup-rows">
    
    <?php foreach (array_merge($procs, [['id' => 0, 'code' => '', 'name' => '', 'modality_id' => '', 'body_part' => '', 'price' => 0, 'investigation_id' => '', 'default_report_template_id' => '', 'prep_instructions' => '', 'est_duration_min' => 15, 'contrast_possible' => 0, 'is_obstetric' => 0, 'is_active' => 1]]) as $p): ?>
    <form method="post" action="<?= e(url('radiology/setup')) ?>" class="setup-row"><?= $hid('procedure', (int) $p['id']) ?>
        <div><input class="input input-sm" name="code" value="<?= e($p['code']) ?>" style="width:80px"></div><div><input class="input input-sm" name="name" value="<?= e($p['name']) ?>" placeholder="<?= $p['id'] ? '' : 'New procedure' ?>"></div>
        <div><?= $sel('modality_id', $modalities, $p['modality_id']) ?></div><div><input class="input input-sm" name="body_part" value="<?= e($p['body_part']) ?>" style="width:90px"></div>
        <div><input class="input input-sm" type="number" step="0.01" name="price" value="<?= e((string) $p['price']) ?>" style="width:90px"></div>
        <div><?= $sel('investigation_id', $investigations, $p['investigation_id']) ?></div><div><?= $sel('template_id', $tplOpts, $p['default_report_template_id']) ?></div>
        <div><input class="input input-sm" name="prep_instructions" value="<?= e($p['prep_instructions']) ?>"></div><div><input class="input input-sm" type="number" name="est_duration_min" value="<?= (int) $p['est_duration_min'] ?>" style="width:60px"></div>
        <div class="small nowrap"><label><input type="checkbox" name="contrast_possible" value="1"<?= $p['contrast_possible'] ? ' checked' : '' ?>> contrast</label><br><label><input type="checkbox" name="is_obstetric" value="1"<?= $p['is_obstetric'] ? ' checked' : '' ?>> OB</label><br><label><input type="checkbox" name="is_active" value="1"<?= $p['is_active'] ? ' checked' : '' ?>> active</label></div>
        <div><button class="btn btn-sm btn-outline"><?= $p['id'] ? 'Save' : 'Add' ?></button></div></form><?php endforeach; ?>
</div></div></div>

<?php elseif ($tab === 'rooms'): ?>
<div class="grid grid-2">
<div class="card card-flush"><div class="card-header"><div class="card-title">Rooms</div></div><div class="card-body"><div class="setup-rows">
    <?php foreach (array_merge($rooms, [['id' => 0, 'name' => '', 'modality_id' => '', 'slot_minutes' => 15, 'opens_at' => '09:00', 'closes_at' => '17:00', 'is_active' => 1]]) as $r): ?><form method="post" action="<?= e(url('radiology/setup')) ?>" class="setup-row"><?= $hid('room', (int) $r['id']) ?>
        <div><input class="input input-sm" name="name" value="<?= e($r['name']) ?>" placeholder="New room"></div><div><?= $sel('modality_id', $modalities, $r['modality_id']) ?></div>
        <div><input class="input input-sm" type="number" name="slot_minutes" value="<?= (int) $r['slot_minutes'] ?>" style="width:60px"> min</div>
        <div><input class="input input-sm" type="time" name="opens_at" value="<?= e(substr((string) $r['opens_at'], 0, 5)) ?>"></div><div><input class="input input-sm" type="time" name="closes_at" value="<?= e(substr((string) $r['closes_at'], 0, 5)) ?>"></div>
        <div><label class="small"><input type="checkbox" name="is_active" value="1"<?= $r['is_active'] ? ' checked' : '' ?>> active</label></div><div><button class="btn btn-sm btn-outline"><?= $r['id'] ? 'Save' : 'Add' ?></button></div></form><?php endforeach; ?>
</div></div></div>
<div class="card card-flush"><div class="card-header"><div class="card-title">Equipment</div></div><div class="card-body"><div class="setup-rows">
    <?php foreach (array_merge($equipment, [['id' => 0, 'name' => '', 'modality_id' => '', 'room_id' => '', 'manufacturer' => '', 'serial_no' => '', 'last_qa_at' => '', 'status' => 'active']]) as $q): $qaOld = $q['last_qa_at'] && $q['last_qa_at'] < date('Y-m-d', strtotime('-1 year')); ?><form method="post" action="<?= e(url('radiology/setup')) ?>" class="setup-row"><?= $hid('equipment', (int) $q['id']) ?>
        <div><input class="input input-sm" name="name" value="<?= e($q['name']) ?>" placeholder="New unit"></div><div><?= $sel('modality_id', $modalities, $q['modality_id']) ?></div><div><?= $sel('room_id', $roomOpts, $q['room_id']) ?></div>
        <div><input class="input input-sm" name="serial_no" value="<?= e($q['serial_no']) ?>" placeholder="Serial" style="width:90px"></div>
        <div><input class="input input-sm<?= $qaOld ? ' text-danger' : '' ?>" type="date" name="last_qa_at" value="<?= e($q['last_qa_at']) ?>" title="Last QA"></div>
        <div><?= $sel('status', ['active' => 'Active', 'maintenance' => 'Maintenance', 'out_of_service' => 'Out of service'], $q['status'], false) ?></div><div><button class="btn btn-sm btn-outline"><?= $q['id'] ? 'Save' : 'Add' ?></button></div></form><?php endforeach; ?>
</div></div></div>
</div>

<?php elseif ($tab === 'templates'): ?>
<?php foreach (array_merge($templates, [['id' => 0, 'name' => '', 'modality_id' => '', 'body_json' => '{}']]) as $t): $b = json_decode((string) $t['body_json'], true) ?: []; ?>
<details class="card"<?= $t['id'] ? '' : ' open' ?>><summary class="card-header"><span class="card-title"><?= $t['id'] ? e($t['name']) : 'New template' ?></span></summary>
<form class="card-body form-grid" method="post" action="<?= e(url('radiology/setup')) ?>"><?= $hid('template', (int) $t['id']) ?>
    <div class="field col-8"><label>Name</label><input class="input" name="name" value="<?= e($t['name']) ?>" required></div><div class="field col-4"><label>Modality</label><?= $sel('modality_id', $modalities, $t['modality_id']) ?></div>
    <div class="field col-12"><label>Technique</label><textarea class="input" name="technique" rows="2"><?= e($b['technique'] ?? '') ?></textarea></div>
    <div class="field col-12"><label>Findings (normal text)</label><textarea class="input" name="findings" rows="4"><?= e($b['findings'] ?? '') ?></textarea></div>
    <div class="field col-12"><label>Impression</label><textarea class="input" name="impression" rows="2"><?= e($b['impression'] ?? '') ?></textarea></div>
    <div class="col-12"><button class="btn btn-primary btn-sm">Save template</button></div></form></details>
<?php endforeach; ?>

<?php elseif ($tab === 'techs'): ?>
<div class="card card-flush"><div class="card-body"><p class="muted small">A technician with no modality ticked sees every modality's worklist.</p><div class="setup-rows">
    <?php foreach ($techs as $u): $mods = array_filter(explode(',', (string) $u['mods'])); ?><form method="post" action="<?= e(url('radiology/setup')) ?>" class="setup-row"><?= $hid('tech') ?><input type="hidden" name="user_id" value="<?= (int) $u['id'] ?>">
        <div><strong><?= e($u['name']) ?></strong></div><div class="flex flex-wrap"><?php foreach ($modalities as $id => $n): ?><label class="check small"><input type="checkbox" name="modalities[]" value="<?= $id ?>"<?= in_array((string) $id, $mods, true) ? ' checked' : '' ?>> <?= e($n) ?></label><?php endforeach; ?></div>
        <div><button class="btn btn-sm btn-outline">Save</button></div></form><?php endforeach; ?>
    <?php if (!$techs): ?><div class="muted">No radiology technicians.</div><?php endif; ?>
</div></div></div>

<?php else: ?>
<div class="card card-flush"><div class="card-body table-wrap"><table class="table table-compact">
    <thead><tr><th>Date</th><th>Order</th><th>Patient</th><th>Study</th><th>Equipment</th><th class="num">kVp</th><th class="num">mAs</th><th class="num">DAP</th><th class="num">CTDIvol</th><th class="num">DLP</th><th class="num">Exp.</th></tr></thead><tbody>
    <?php foreach ($dose as $d): ?><tr><td class="small"><?= e(fmt_datetime($d['recorded_at'])) ?></td><td><?= e($d['order_number']) ?></td><td><?= e($d['patient_name']) ?></td><td><?= e($d['procedure_name']) ?></td><td class="small"><?= e($d['equipment_name']) ?></td>
        <td class="num"><?= e(num($d['kvp'])) ?></td><td class="num"><?= e(num($d['mas'])) ?></td><td class="num"><?= e(num($d['dap_value'])) ?></td><td class="num"><?= e(num($d['ctdi_vol'])) ?></td><td class="num"><?= e(num($d['dlp'])) ?></td><td class="num"><?= (int) $d['exposure_count'] ?></td></tr><?php endforeach; ?>
    <?php if (!$dose): ?><tr><td colspan="11" class="muted">No exposures recorded.</td></tr><?php endif; ?>
</tbody></table></div></div>
<?php endif; ?>
