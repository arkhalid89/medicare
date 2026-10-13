<?php
/** @var array $m module config @var array $config */
?>
<div class="card module-card hidden" id="sp-card">
    <div class="card-header"><div class="card-title"><?= icon('eye') ?> <span id="sp-title">Specialty</span></div>
        <span class="muted small">Supplements the diagnosis &amp; prescription above</span></div>
    <div class="card-body"><div class="form-grid" id="sp-fields"></div><div id="sp-form" class="mt-2"></div></div>
</div>
<?php if ($m['canNote'] || $m['canRefer']): ?>
<div class="card">
    <div class="card-header"><div class="card-title"><?= icon('file-text') ?> Clinical note &amp; referral</div></div>
    <div class="card-body form-grid">
        <?php if ($m['canNote']): ?>
        <div class="field col-12"><label>Clinical note</label><textarea class="input" id="sp-note" rows="2" maxlength="5000" placeholder="Optional progress / consultation note"></textarea>
            <label class="check mt-1"><input type="checkbox" id="sp-note-restricted"> <?= icon('lock') ?> Restricted — only the treating team can read it</label></div>
        <?php endif; ?>
        <?php if ($m['canRefer']): ?>
        <div class="field col-4"><label>Refer to department</label><select class="input" id="sp-ref-dept"><option value="">—</option>
            <?php foreach ($m['departments'] as $id => $n): ?><option value="<?= $id ?>"><?= e($n) ?></option><?php endforeach; ?></select></div>
        <div class="field col-3"><label>or outside facility</label><input class="input" id="sp-ref-ext" maxlength="150"></div>
        <div class="field col-2"><label>Urgency</label><select class="input" id="sp-ref-urgency"><option value="routine">Routine</option><option value="urgent">Urgent</option><option value="emergency">Emergency</option></select></div>
        <div class="field col-3"><label>Reason</label><input class="input" id="sp-ref-reason" maxlength="1000" placeholder="Leave empty for no referral"></div>
        <?php endif; ?>
    </div>
</div>
<?php endif; ?>
