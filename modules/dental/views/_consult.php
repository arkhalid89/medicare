<?php
/** @var array $m module config */
?>
<div class="card module-card hidden" id="dental-card">
    <div class="card-header"><div class="card-title"><?= icon('tooth') ?> Dental chart (FDI)</div>
        <span class="muted small" id="dc-baseline"></span></div>
    <div class="card-body">
        <div class="dental-grid">
            <section>
                <div class="flex flex-wrap mb-2">
                    <span class="muted small">Dentition:</span>
                    <div class="seg" id="dc-dent"><button type="button" data-d="permanent" aria-pressed="true">Permanent</button><button type="button" data-d="deciduous" aria-pressed="false">Deciduous</button><button type="button" data-d="mixed" aria-pressed="false">Mixed</button></div>
                    <span class="muted small">Select:</span>
                    <button type="button" class="btn btn-sm btn-outline" data-arch="upper">Upper arch</button><button type="button" class="btn btn-sm btn-outline" data-arch="lower">Lower arch</button>
                    <button type="button" class="btn btn-sm btn-ghost" id="dc-clear">Clear</button>
                </div>
                <div class="dc-arch" id="dc-arch" role="group" aria-label="Tooth chart"></div>
                <div class="dc-legend" id="dc-legend"></div>
                <p class="hint">Click a tooth to select; Ctrl / Shift-click for several; click Q1–Q8 for a quadrant; arrow keys move, Space / Enter toggles, Esc clears.</p>
            </section>
            <section class="dc-form"<?= $m['canEdit'] ? '' : ' hidden' ?>>
                <div class="strong" id="dc-sel">No tooth selected</div>
                <div class="field"><label>Condition</label><select class="input" id="dc-cond"></select></div>
                <div class="field"><label>Surfaces</label><div class="check-list" id="dc-surf"></div></div>
                <div class="form-grid">
                    <div class="field col-6"><label>Severity</label><select class="input" id="dc-sev"><option value="">—</option><option>mild</option><option>moderate</option><option>severe</option></select></div>
                    <div class="field col-6"><label>Mobility (0–3)</label><select class="input" id="dc-mob"><option value="">—</option><option>0</option><option>1</option><option>2</option><option>3</option></select></div>
                </div>
                <div class="field"><label>Procedure</label><select class="input" id="dc-proc"></select></div>
                <div class="flex"><label class="check"><input type="radio" name="dc-st" value="planned" checked> Plan for later</label><label class="check"><input type="radio" name="dc-st" value="completed"> Done today (billed)</label></div>
                <div class="field"><label>Notes</label><input class="input" id="dc-notes" maxlength="500"></div>
                <button type="button" class="btn btn-primary" id="dc-add">Add to chart</button> <span class="hint" id="dc-msg"></span>
            </section>
        </div>
        <?php if (!$m['canEdit']): ?><p class="muted small">You can view the chart; recording findings needs the dentist.</p><?php endif; ?>
        <div class="table-wrap mt-2"><table class="table table-compact">
            <thead><tr><th>Tooth</th><th>Condition</th><th>Surfaces</th><th>Procedure</th><th>Status</th><th class="num">PKR</th><th></th></tr></thead>
            <tbody id="dc-rows"><tr><td colspan="7" class="muted">Nothing recorded in this visit yet.</td></tr></tbody>
            <tfoot><tr><td colspan="5" class="text-right"><strong>Done today</strong> <span id="dc-today">0</span> · <strong>Planned</strong></td><td class="num"><strong id="dc-total">0</strong></td><td></td></tr></tfoot>
        </table></div>
        <div class="field mt-2"><label>Dental notes</label><input class="input" id="dc-general" maxlength="1000" placeholder="Oral hygiene, soft tissue, occlusion …"></div>
    </div>
</div>
