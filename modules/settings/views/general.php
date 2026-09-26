<?php
/** @var array $fieldErrors */
use App\Core\View;
use App\Modules\Settings\SettingsController;
use App\Modules\Users\UserService;

echo View::partial('page_header', [
    'title'       => 'Organization & System',
    'description' => 'Branding, regional formats, consultation defaults and automatic backups.',
    'breadcrumbs' => [['Settings'], ['Organization & System']],
]);
$val = static fn (string $k) => old($k, setting($k));
$err = static fn (string $f): string => isset($fieldErrors[$f]) ? '<div class="error-text">' . e($fieldErrors[$f]) . '</div>' : '';
$logo = setting('org_logo');
?>
<form method="post" enctype="multipart/form-data" action="<?= e(url('settings/general')) ?>">
    <?= csrf_field() ?>
    <div class="grid grid-2">
        <div class="card">
            <div class="card-header"><div class="card-title"><?= icon('building') ?> Organization &amp; Branding</div></div>
            <div class="card-body"><div class="form-grid">
                <div class="field col-12"><label>Organization / Clinic Name <span class="req">*</span></label><input class="input" name="org_name" value="<?= e($val('org_name')) ?>" maxlength="150" required><?= $err('org_name') ?></div>
                <div class="field col-12"><label>Tagline</label><input class="input" name="org_tagline" value="<?= e($val('org_tagline')) ?>" maxlength="200"></div>
                <div class="field col-12"><label>Address</label><input class="input" name="org_address" value="<?= e($val('org_address')) ?>" maxlength="255"></div>
                <div class="field col-6"><label>Phone</label><input class="input" name="org_phone" value="<?= e($val('org_phone')) ?>" maxlength="100"></div>
                <div class="field col-6"><label>Email</label><input class="input" type="email" name="org_email" value="<?= e($val('org_email')) ?>"><?= $err('org_email') ?></div>
                <div class="field col-12"><label>Logo (PNG / JPG, shown in sidebar, login and prescriptions)</label>
                    <div class="flex">
                        <?php if ($logo): ?><img src="<?= e(upload_url($logo)) ?>" alt="" style="height:44px;border:1px solid var(--border);border-radius:8px;padding:2px;background:#fff"><?php endif; ?>
                        <input class="input" type="file" name="org_logo" accept="image/png,image/jpeg,image/gif,image/webp">
                    </div>
                    <?php if ($logo): ?><label class="check mt-1"><input type="checkbox" name="remove_logo" value="1"> Remove current logo</label><?php endif; ?>
                    <?= $err('org_logo') ?></div>
            </div></div>
        </div>
        <div class="card">
            <div class="card-header"><div class="card-title"><?= icon('settings') ?> Regional &amp; Display</div></div>
            <div class="card-body"><div class="form-grid">
                <div class="field col-4"><label>Currency Symbol</label><input class="input" name="currency_symbol" value="<?= e($val('currency_symbol')) ?>" maxlength="10"><?= $err('currency_symbol') ?></div>
                <div class="field col-4"><label>Currency Code</label><input class="input" name="currency_code" value="<?= e($val('currency_code')) ?>" maxlength="10"></div>
                <div class="field col-4"><label>Date Format</label><select class="input" name="date_format">
                    <?php foreach (SettingsController::DATE_FORMATS as $f => $ex): ?><option value="<?= e($f) ?>"<?= selected($f, $val('date_format')) ?>><?= e($ex) ?></option><?php endforeach; ?></select></div>
                <div class="field col-6"><label>Default Prescription Paper</label><select class="input" name="default_paper">
                    <?php foreach (UserService::PAPERS as $k => $l): ?><option value="<?= e($k) ?>"<?= selected($k, $val('default_paper')) ?>><?= e($l) ?></option><?php endforeach; ?></select></div>
                <div class="field col-6"><label>Records per Page</label><input class="input" type="number" min="5" max="200" name="records_per_page" value="<?= e($val('records_per_page')) ?>"><?= $err('records_per_page') ?></div>
                <div class="field col-12"><div class="hint">Time zone and the Base URL are set in <code>config/config.php</code>.</div></div>
            </div></div>
        </div>
        <div class="card">
            <div class="card-header"><div class="card-title"><?= icon('stethoscope') ?> Consultation</div></div>
            <div class="card-body"><div class="form-grid">
                <div class="field col-12"><label>Other Configured Vitals (comma separated)</label><input class="input" name="extra_vitals" value="<?= e($val('extra_vitals')) ?>" maxlength="500" placeholder="Blood Sugar (mg/dL), Head Circumference (cm)">
                    <div class="hint">Shown after the standard vitals on the consultation screen and printed on prescriptions.</div></div>
                <div class="field col-6"><label>Default Follow-up (days)</label><input class="input" type="number" min="0" max="365" name="follow_up_default_days" value="<?= e($val('follow_up_default_days')) ?>"></div>
                <div class="field col-6"><label>Billing Options</label>
                    <label class="check"><input type="checkbox" name="discount_enabled" value="1"<?= checked(setting('discount_enabled') === '1') ?>> Discount field</label><br>
                    <label class="check mt-1"><input type="checkbox" name="tax_enabled" value="1"<?= checked(setting('tax_enabled') === '1') ?>> Tax field</label></div>
            </div></div>
        </div>
        <div class="card">
            <div class="card-header"><div class="card-title"><?= icon('database') ?> Automatic Backup</div></div>
            <div class="card-body"><div class="form-grid">
                <div class="field col-6"><label>Schedule</label><select class="input" name="auto_backup">
                    <?php foreach (['off' => 'Off', 'daily' => 'Daily', 'weekly' => 'Weekly'] as $k => $l): ?><option value="<?= $k ?>"<?= selected($k, $val('auto_backup')) ?>><?= $l ?></option><?php endforeach; ?></select>
                    <div class="hint">Runs at the first login after the period has passed — no server cron needed.</div></div>
                <div class="field col-6"><label>Backups to Keep</label><input class="input" type="number" min="1" max="500" name="backup_retention" value="<?= e($val('backup_retention')) ?>"><?= $err('backup_retention') ?></div>
                <div class="field col-12"><div class="hint">Last automatic backup: <?= setting('last_auto_backup') ? e(fmt_datetime(setting('last_auto_backup'))) : 'never' ?></div></div>
            </div></div>
        </div>
    </div>
    <div class="card mt-2"><div class="card-footer" style="border-top:0"><button type="submit" class="btn btn-primary"><?= icon('save') ?> Save Settings</button></div></div>
</form>
