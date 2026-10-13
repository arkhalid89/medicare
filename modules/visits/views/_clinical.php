<?php
/**
 * Shared clinical blocks of the consultation screen and the template editor:
 * vitals (with live "usual range" hints), the four tag lists with trending
 * quick picks, history/findings, the prescription and overall instructions.
 * @var array $config  screen config
 * @var bool  $showBilling  price/total (visit mode)
 * @var bool  $showHistory  history + findings block (visit mode)
 */
use App\Modules\Visits\VisitService;

$step = $stepStart ?? 2;
$vitalFields = [
    'pulse'       => ['Pulse', '/min', '1'],
    'temperature' => ['Temp', '°F', '0.1'],
    'spo2'        => ['SpO₂', '%', '1'],
    'resp_rate'   => ['Resp. Rate', '/min', '1'],
    'weight'      => ['Weight', 'kg', '0.1'],
    'height'      => ['Height', 'cm', '0.1'],
];
$ranges = [];
foreach (VisitService::VITALS as $key => [$label, $min, $max]) {
    $ranges[$key] = [$label, $min, $max];
}
$tagCards = [
    ['complaints', 'complaint', 'Presenting Complaints', 'Search or type a complaint…', true],
    ['symptoms', 'symptom', 'Symptoms', 'Search or type a symptom…', true],
    ['diagnoses', 'diagnosis', 'Provisional / Diagnosis', 'Search diagnosis or ICD code…', false],
    ['investigations', 'investigation', 'Investigations', 'Search investigations…', false],
];
?>
<!-- Vitals -->
<div class="card">
    <div class="card-header"><div class="card-title"><span class="step-no"><?= $step++ ?></span> Vitals</div><span class="muted small">Unusual values are highlighted but always saved · BMI is automatic</span></div>
    <div class="card-body">
        <div class="vitals-row" id="vitals" data-ranges='<?= e(json_encode($ranges)) ?>'>
            <div class="field vital"><label>BP <span class="muted">(mmHg)</span></label>
                <div class="bp-pair"><input class="input" data-vital="bp_systolic" type="number" min="0" inputmode="numeric" placeholder="120" aria-label="Systolic">
                    <span class="muted">/</span><input class="input" data-vital="bp_diastolic" type="number" min="0" inputmode="numeric" placeholder="80" aria-label="Diastolic"></div>
                <div class="vital-hint"></div></div>
            <?php foreach ($vitalFields as $key => [$label, $unit, $stepVal]): ?>
                <div class="field vital"><label><?= e($label) ?> <span class="muted">(<?= e($unit) ?>)</span></label>
                    <input class="input" data-vital="<?= e($key) ?>" type="number" step="<?= $stepVal ?>" min="0" inputmode="decimal">
                    <div class="vital-hint"></div></div>
            <?php endforeach; ?>
            <div class="field vital"><label>BMI</label><input class="input" id="bmi" readonly tabindex="-1"><div class="vital-hint" id="bmi-hint"></div></div>
            <?php foreach ($config['extraVitals'] as $label): ?>
                <div class="field vital"><label><?= e($label) ?></label><input class="input" data-extra-vital="<?= e($label) ?>" maxlength="50"></div>
            <?php endforeach; ?>
        </div>
    </div>
</div>

<!-- Complaints / Symptoms / Diagnosis / Investigations: two columns on desktop and tablet -->
<div class="clinical-grid">
    <?php foreach ($tagCards as [$key, $type, $label, $placeholder, $free]): ?>
        <div class="card clinical-card">
            <div class="card-header"><div class="card-title"><span class="step-no"><?= $step++ ?></span> <?= e($label) ?></div>
                <span class="muted small"><?= $free ? 'Tap to add · free text allowed' : 'Tap to add from list' ?></span></div>
            <div class="card-body">
                <div class="tag-input" data-tags="<?= e($key) ?>" data-type="<?= e($type) ?>" data-free="<?= $free ? '1' : '0' ?>">
                    <div class="tags"></div>
                    <div class="search-box"><input type="text" placeholder="<?= e($placeholder) ?>" autocomplete="off" aria-label="<?= e($label) ?>"><div class="search-results"></div></div>
                </div>
                <div class="trend-head"><?= icon('activity') ?> Trending — your most used</div>
                <div class="suggest" data-suggest="<?= e($key) ?>"></div>
            </div>
        </div>
    <?php endforeach; ?>
</div>

<?php if (!empty($showHistory)): ?>
<div class="clinical-grid">
    <div class="card">
        <div class="card-header"><div class="card-title"><?= icon('file') ?> History</div><span class="muted small">Printed in the left column</span></div>
        <div class="card-body"><textarea class="input" id="current-history" rows="3" maxlength="5000" placeholder="Brief history of the present illness, past history…"></textarea></div>
    </div>
    <div class="card">
        <div class="card-header"><div class="card-title"><?= icon('clipboard') ?> Findings</div><span class="muted small">Examination findings</span></div>
        <div class="card-body"><textarea class="input" id="findings" rows="3" maxlength="5000" placeholder="Chest clear, abdomen soft, throat congested…"></textarea></div>
    </div>
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
            <input class="input" id="med-q" type="text" placeholder="Search medicine by name, generic or brand — tap to add" autocomplete="off">
            <div class="search-results"></div>
        </div>
        <?php if ($showBilling && !empty($config['settings']['split']) && $config['mainSourceName']): ?>
            <p class="hint mb-1"><?= icon('info') ?> Main Rx (bought outside, not billed) = medicines of <strong><?= e($config['mainSourceName']) ?></strong>.
                If this prescription has none from it, the source with the lowest order in the prescription becomes the main Rx. All other sources go below the cut line and are billed.</p>
        <?php endif; ?>
        <div class="rx-list" id="rx-body"></div>
        <div class="empty" id="rx-empty" style="padding:1.2rem"><?= icon('pill') ?><strong>No medicines added</strong>Search above and tap a medicine to add it.</div>
    </div>
</div>

<!-- Overall instructions -->
<div class="card">
    <div class="card-header"><div class="card-title"><?= icon('info') ?> Overall Instructions / Advice</div></div>
    <div class="card-body"><textarea class="input" id="overall-instructions" rows="3" maxlength="5000" placeholder="Diet, rest, warning signs, lifestyle advice (e.g. 8 glasses of water daily, 30 minutes walk)…"></textarea></div>
</div>
