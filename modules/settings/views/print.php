<?php
/**
 * @var array $doctors @var int $doctorId @var string $paper @var array|null $design @var int|null $sampleVisit
 */
use App\Core\View;
use App\Modules\Users\UserService;

echo View::partial('page_header', [
    'title'       => 'Prescription Designs',
    'description' => 'Each doctor has an own design for A4, A5, Thermal and Legal. Empty fields are hidden on the print.',
    'breadcrumbs' => [['Settings'], ['Prescription Designs']],
    'actions'     => $sampleVisit ? '<a class="btn btn-outline" target="_blank" href="' . e(url('visits/print', ['id' => $sampleVisit, 'paper' => $paper])) . '">' . icon('eye') . ' Preview with latest visit</a>' : '',
]);
if (!$doctors): ?>
    <div class="card"><div class="empty"><?= icon('stethoscope') ?><strong>No doctors available</strong>Create a doctor user first.</div></div>
<?php return; endif;
$val = static fn (string $k) => old($k, $design[$k] ?? '');
?>
<div class="card mb-2"><div class="card-body">
    <form class="filters" method="get" action="<?= e(form_action('settings/print')) ?>" data-autosubmit>
        <?= route_field('settings/print') ?>
        <input type="hidden" name="paper" value="<?= e($paper) ?>">
        <div class="field grow"><label>Doctor</label><select class="input" name="doctor_id">
            <?php foreach ($doctors as $id => $name): ?><option value="<?= $id ?>"<?= selected($id, $doctorId) ?>><?= e($name) ?></option><?php endforeach; ?></select></div>
    </form>
</div></div>
<div class="tabs">
    <?php foreach (UserService::PAPERS as $k => $l): ?>
        <a href="<?= e(url('settings/print', ['doctor_id' => $doctorId, 'paper' => $k])) ?>" class="<?= $k === $paper ? 'active' : '' ?>"><?= icon('printer') ?> <?= e($l) ?></a>
    <?php endforeach; ?>
</div>
<form method="post" enctype="multipart/form-data" action="<?= e(url('settings/print', ['doctor_id' => $doctorId, 'paper' => $paper])) ?>">
    <?= csrf_field() ?>
    <div class="grid grid-2">
        <div class="card">
            <div class="card-header"><div class="card-title"><?= icon('file') ?> Header</div></div>
            <div class="card-body"><div class="form-grid">
                <div class="field col-12"><label>Header Image (replaces the text header)</label>
                    <?php if ($design['header_image']): ?><img src="<?= e(upload_url($design['header_image'])) ?>" alt="" style="max-width:100%;max-height:90px;border:1px solid var(--border);border-radius:6px;margin-bottom:.4rem;display:block"><?php endif; ?>
                    <input class="input" type="file" name="header_image" accept="image/png,image/jpeg,image/gif,image/webp">
                    <?php if ($design['header_image']): ?><label class="check mt-1"><input type="checkbox" name="remove_header_image" value="1"> Remove header image</label><?php endif; ?></div>
                <div class="field col-12"><label class="check"><input type="checkbox" name="show_logo" value="1"<?= checked((int) $design['show_logo']) ?>> Show organization logo</label></div>
                <div class="field col-6"><label>Doctor Name / Title</label><input class="input" name="header_title" value="<?= e($val('header_title')) ?>" maxlength="200"></div>
                <div class="field col-6"><label>Qualification Line</label><input class="input" name="header_subtitle" value="<?= e($val('header_subtitle')) ?>" maxlength="255"></div>
                <div class="field col-6"><label>More Doctor Lines (one per line)</label><textarea class="input" name="header_lines" rows="3" maxlength="1000"><?= e($val('header_lines')) ?></textarea></div>
                <div class="field col-6"><label>Clinic / Organization Info (right side)</label><textarea class="input" name="clinic_info" rows="3" maxlength="1000"><?= e($val('clinic_info')) ?></textarea></div>
            </div></div>
        </div>
        <div class="card">
            <div class="card-header"><div class="card-title"><?= icon('list') ?> Footer</div></div>
            <div class="card-body"><div class="form-grid">
                <div class="field col-8"><label>Clinic Address</label><input class="input" name="footer_address" value="<?= e($val('footer_address')) ?>" maxlength="255"></div>
                <div class="field col-4"><label>Phone</label><input class="input" name="footer_phone" value="<?= e($val('footer_phone')) ?>" maxlength="100"></div>
                <div class="field col-12"><label>Follow-up Note</label><input class="input" name="footer_followup" value="<?= e($val('footer_followup')) ?>" maxlength="500"></div>
                <div class="field col-12"><label>Appointment Info</label><input class="input" name="footer_appointment" value="<?= e($val('footer_appointment')) ?>" maxlength="500"></div>
                <div class="field col-12"><label>Disclaimer</label><input class="input" name="footer_disclaimer" value="<?= e($val('footer_disclaimer')) ?>" maxlength="500"></div>
            </div></div>
        </div>
        <div class="card">
            <div class="card-header"><div class="card-title"><?= icon('sliders') ?> Layout — <?= e(UserService::PAPERS[$paper]) ?></div></div>
            <div class="card-body"><div class="form-grid">
                <div class="field col-6"><label>Page Margin (mm)</label><input class="input" type="number" min="2" max="30" name="margin_mm" value="<?= e($val('margin_mm')) ?>"></div>
                <div class="field col-6"><label>Font Size (pt)</label><input class="input" type="number" min="6" max="16" step="0.5" name="font_size_pt" value="<?= e(num($val('font_size_pt'))) ?>"></div>
                <div class="field col-12">
                    <label class="check"><input type="checkbox" name="show_prices" value="1"<?= checked((int) $design['show_prices']) ?>> Show unit price &amp; line total</label><br>
                    <label class="check mt-1"><input type="checkbox" name="show_billing" value="1"<?= checked((int) $design['show_billing']) ?>> Show billing summary</label><br>
                    <label class="check mt-1"><input type="checkbox" name="show_signature" value="1"<?= checked((int) $design['show_signature']) ?>> Show signature line</label>
                </div>
            </div></div>
        </div>
        <div class="card">
            <div class="card-header"><div class="card-title"><?= icon('info') ?> Layout Rules</div></div>
            <div class="card-body small">
                <ul style="margin:0;padding-left:1.1rem">
                    <li>Row 1: header image, or logo + doctor details + clinic info.</li>
                    <li>Row 2: patient information · Row 3: vitals (only recorded ones).</li>
                    <li>Body: 20% left column (complaints, symptoms, diagnosis, investigations, follow-up, history) and 80% Rx.</li>
                    <li>Empty sections and their headings are hidden automatically.</li>
                    <li>Thermal prints as a single-column receipt (80 mm).</li>
                </ul>
            </div>
        </div>
    </div>
    <div class="card mt-2"><div class="card-footer" style="border-top:0;justify-content:space-between">
        <label class="check"><input type="checkbox" name="apply_all" value="1"> Also copy header &amp; footer text to the other paper sizes</label>
        <button type="submit" class="btn btn-primary"><?= icon('save') ?> Save Design</button>
    </div></div>
</form>
