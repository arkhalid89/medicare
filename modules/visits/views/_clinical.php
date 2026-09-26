<?php
/**
 * Shared clinical blocks of the consultation screen and the template editor:
 * vitals, the four tag lists, history, prescription table and instructions.
 * @var array $config  screen config (extraVitals, instructions ...)
 * @var bool  $showBilling  price/total columns & summary (visit mode)
 */
$step = $stepStart ?? 2;
$vitals = [
    'pulse'       => ['Pulse', '/min', '1'],
    'temperature' => ['Temp', '°F', '0.1'],
    'spo2'        => ['SpO₂', '%', '1'],
    'resp_rate'   => ['Resp. Rate', '/min', '1'],
    'weight'      => ['Weight', 'kg', '0.1'],
    'height'      => ['Height', 'cm', '0.1'],
];
$tagCards = [
    ['complaints', 'complaint', 'Presenting Complaints', 'message', 'Search or type a complaint…', true],
    ['symptoms', 'symptom', 'Symptoms', 'activity', 'Search or type a symptom…', true],
    ['diagnoses', 'diagnosis', 'Diagnosis', 'clipboard', 'Search diagnosis or ICD code…', false],
    ['investigations', 'investigation', 'Investigations', 'flask', 'Search investigations…', false],
];
?>
<!-- Vitals -->
<div class="card">
    <div class="card-header"><div class="card-title"><span class="step-no"><?= $step++ ?></span> Vitals</div><span class="muted small">BMI is calculated automatically</span></div>
    <div class="card-body">
        <div class="vitals-row" id="vitals">
            <div class="field"><label>BP (mmHg)</label>
                <div class="bp-pair"><input class="input" data-vital="bp_systolic" type="number" min="40" max="300" placeholder="120" aria-label="Systolic">
                    <span class="muted">/</span><input class="input" data-vital="bp_diastolic" type="number" min="20" max="200" placeholder="80" aria-label="Diastolic"></div></div>
            <?php foreach ($vitals as $key => [$label, $unit, $stepVal]): ?>
                <div class="field"><label><?= e($label) ?> <span class="muted">(<?= e($unit) ?>)</span></label>
                    <input class="input" data-vital="<?= e($key) ?>" type="number" step="<?= $stepVal ?>" min="0"></div>
            <?php endforeach; ?>
            <div class="field"><label>BMI</label><input class="input" id="bmi" readonly tabindex="-1"></div>
            <?php foreach ($config['extraVitals'] as $label): ?>
                <div class="field"><label><?= e($label) ?></label><input class="input" data-extra-vital="<?= e($label) ?>" maxlength="50"></div>
            <?php endforeach; ?>
        </div>
    </div>
</div>

<!-- Complaints / Symptoms / Diagnosis / Investigations -->
<?php foreach (array_chunk($tagCards, 2) as $pair): ?>
<div class="grid grid-2">
    <?php foreach ($pair as [$key, $type, $label, $ic, $placeholder, $free]): ?>
        <div class="card">
            <div class="card-header"><div class="card-title"><span class="step-no"><?= $step++ ?></span> <?= e($label) ?></div>
                <span class="muted small"><?= $free ? 'Click to add · free text allowed' : 'Click to add from list' ?></span></div>
            <div class="card-body">
                <div class="tag-input" data-tags="<?= e($key) ?>" data-type="<?= e($type) ?>" data-free="<?= $free ? '1' : '0' ?>">
                    <div class="tags"></div>
                    <div class="search-box"><input type="text" placeholder="<?= e($placeholder) ?>" autocomplete="off" aria-label="<?= e($label) ?>"><div class="search-results"></div></div>
                </div>
                <div class="suggest" data-suggest="<?= e($key) ?>"></div>
            </div>
        </div>
    <?php endforeach; ?>
</div>
<?php endforeach; ?>

<?php if (!empty($showHistory)): ?>
<div class="card">
    <div class="card-header"><div class="card-title"><?= icon('file') ?> Current History / Examination Notes</div><span class="muted small">Printed in the left column</span></div>
    <div class="card-body"><textarea class="input" id="current-history" rows="2" maxlength="5000" placeholder="Brief history, examination findings…"></textarea></div>
</div>
<?php endif; ?>

<!-- Prescription -->
<div class="card">
    <div class="card-header">
        <div class="card-title"><span class="step-no"><?= $step++ ?></span> Prescription (Rx)</div>
        <div class="rx-groups" id="rx-groups"></div>
    </div>
    <div class="card-body">
        <div class="search-box patient-search mb-2">
            <?= icon('search') ?>
            <input class="input" id="med-q" type="text" placeholder="Search medicine by name, generic or brand — click to add" autocomplete="off">
            <div class="search-results"></div>
        </div>
        <div class="table-wrap">
            <table class="table rx-table">
                <thead><tr>
                    <th style="width:28px">#</th><th>Medicine / Source</th><th class="w-dose">Dose</th><th class="w-freq">Frequency</th><th class="w-route">Route</th>
                    <th class="w-days">Days</th><th class="w-qty">Qty</th>
                    <?php if ($showBilling): ?><th class="w-price">Unit Price</th><th class="num">Total</th><?php endif; ?>
                    <th class="w-instr">Instructions</th><th style="width:66px"></th>
                </tr></thead>
                <tbody id="rx-body"></tbody>
            </table>
        </div>
        <div class="empty" id="rx-empty" style="padding:1.2rem"><?= icon('pill') ?><strong>No medicines added</strong>Search above and click a medicine to add it.</div>
    </div>
</div>

<!-- Overall instructions -->
<div class="card">
    <div class="card-header"><div class="card-title"><?= icon('info') ?> Overall Instructions / Advice</div></div>
    <div class="card-body"><textarea class="input" id="overall-instructions" rows="3" maxlength="5000" placeholder="Diet, rest, warning signs, lifestyle advice…"></textarea></div>
</div>
