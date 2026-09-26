<?php
/** @var array $config @var array|null $template */
use App\Core\View;

echo View::partial('page_header', [
    'title'       => $template ? 'Edit Template — ' . $template['name'] : 'New Template',
    'description' => 'Complaints, symptoms, diagnosis, investigations, vitals, medicines and instructions you prescribe often.',
    'breadcrumbs' => [['Favourite Templates', url('templates')], [$template ? 'Edit' : 'New']],
    'actions'     => '<button type="button" class="btn btn-ghost" id="btn-clear">' . icon('refresh') . ' Clear</button>',
]);
?>
<div class="alert alert-danger hidden" id="consult-errors"></div>
<div class="consult" id="consult" style="padding-bottom:1rem">
    <div class="card">
        <div class="card-header"><div class="card-title"><span class="step-no">1</span> Template Details</div></div>
        <div class="card-body">
            <div class="form-grid">
                <div class="field col-4"><label>Template Name <span class="req">*</span></label><input class="input" id="tpl-name" maxlength="150" placeholder="e.g. Hypertension follow-up"></div>
                <div class="field col-5"><label>Description</label><input class="input" id="tpl-description" maxlength="255"></div>
                <div class="field col-2"><label>Follow-up after (days)</label><input class="input" type="number" min="0" max="3650" id="tpl-follow-days"></div>
                <div class="field col-1"><label>Favourite</label><label class="check" style="height:36px"><input type="checkbox" id="tpl-fav" checked> ★</label></div>
            </div>
        </div>
    </div>
    <?= View::render('visits/_clinical', ['config' => $config, 'showBilling' => false, 'showHistory' => false, 'stepStart' => 2], null) ?>
    <div class="flex" style="justify-content:flex-end">
        <a class="btn btn-outline" href="<?= e(url('templates')) ?>">Cancel</a>
        <button type="button" class="btn btn-primary btn-lg" id="btn-template-save"><?= icon('save') ?> Save Template</button>
    </div>
</div>
<datalist id="instr-list"><?php foreach ($config['instructions'] as $ins): ?><option value="<?= e($ins) ?>"><?php endforeach; ?></datalist>
<script type="application/json" id="consult-data"><?= json_encode($config, JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE) ?></script>
