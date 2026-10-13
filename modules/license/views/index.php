<?php
/**
 * @var array $status @var string|null $error @var bool $admin @var bool $canIssue @var array $issued @var array $activations
 * @var string|null $newKey @var string|null $generateError @var array $fieldErrors
 */
use App\Core\Auth;
use App\Core\License;
use App\Core\View;

$badge = [
    'active' => ['Active', 'badge-success'], 'expiring' => ['Expiring soon', 'badge-warning'], 'trial' => ['Trial', 'badge-info'],
    'expired' => ['Expired', 'badge-danger'], 'not_started' => ['Not started', 'badge-warning'], 'missing' => ['Not activated', 'badge-danger'],
    'invalid' => ['Invalid key', 'badge-danger'], 'clock' => ['Clock changed', 'badge-danger'],
][$status['state']] ?? [$status['state'], 'badge-muted'];
$statusBadge = static function (string $s): string {
    $cls = str_starts_with($s, 'In use') ? 'badge-success' : (['Active' => 'badge-success', 'Expiring' => 'badge-warning', 'Scheduled' => 'badge-info',
        'Expired' => 'badge-danger', 'Replaced' => 'badge-muted'][$s] ?? 'badge-muted');
    return '<span class="badge ' . $cls . '">' . e($s) . '</span>';
};
$inLayout = Auth::check();
$err = static fn (string $f): string => isset($fieldErrors[$f]) ? '<div class="error-text">' . e($fieldErrors[$f]) . '</div>' : '';
if ($inLayout) {
    echo View::partial('page_header', [
        'title'       => 'Subscription',
        'description' => 'Offline subscription keys — payments are made manually. Keys are signed and cannot be edited or forged.',
        'breadcrumbs' => [['Administration'], ['Subscription']],
    ]);
}
?>
<?php if (!$inLayout): ?><div class="auth-panel" style="min-height:100vh"><div class="auth-card" style="max-width:560px"><?php endif; ?>

<?php if ($newKey): ?>
    <div class="card mb-2" style="border:2px solid var(--success)">
        <div class="card-header"><div class="card-title"><?= icon('key') ?> New subscription key</div><button type="button" class="btn btn-sm btn-success" id="copy-key"><?= icon('copy') ?> Copy key</button></div>
        <div class="card-body"><code id="new-key" style="display:block;word-break:break-all;font-size:1rem;padding:.8rem;background:var(--surface-2)"><?= e($newKey) ?></code>
            <p class="hint mb-0">Send this key to the clinic (WhatsApp / SMS / email). It is also saved in the issued-keys log below.</p></div>
    </div>
<?php endif; ?>

<div class="<?= $inLayout ? 'grid grid-main mb-2' : '' ?>">
    <div class="<?= $inLayout ? 'card' : '' ?>">
        <?php if ($inLayout): ?><div class="card-header"><div class="card-title"><?= icon('key') ?> Activate or renew this installation</div></div><div class="card-body"><?php else: ?>
            <h2><?= icon('key') ?> Subscription</h2><p class="sub">Activate or renew MediCare Practice.</p><?= View::partial('flash') ?>
        <?php endif; ?>
            <div class="alert <?= $status['ok'] ? ($status['state'] === 'active' ? 'alert-success' : 'alert-warning') : 'alert-danger' ?>">
                <?= icon($status['ok'] ? 'check' : 'alert') ?><div><span class="badge <?= $badge[1] ?>"><?= e($badge[0]) ?></span> <?= e($status['message']) ?></div>
            </div>
            <?php if ($error): ?><div class="alert alert-danger"><?= icon('alert') ?><div><?= e($error) ?></div></div><?php endif; ?>
            <form method="post" action="<?= e(url('license/activate')) ?>" autocomplete="off">
                <?= csrf_field() ?>
                <div class="field mb-2"><label>Registered phone number <span class="req">*</span></label>
                    <input class="input" name="phone" required maxlength="20" inputmode="tel" placeholder="03001234567" value="<?= e(old('phone', setting('license_phone', ''))) ?>">
                    <div class="hint">The phone number given when paying for the subscription.</div></div>
                <div class="field mb-2"><label>Subscription key <span class="req">*</span></label>
                    <textarea class="input" name="key" required rows="4" style="font-family:Consolas,monospace;letter-spacing:.5px" placeholder="MCP1-XXXXX-XXXXX-…"><?= e(old('key', '')) ?></textarea></div>
                <button type="submit" class="btn btn-primary"><?= icon('key') ?> Activate Key</button>
                <?php if (!$inLayout): ?><a class="btn btn-ghost" href="<?= e(url('login')) ?>">Back to login</a><?php endif; ?>
            </form>
        <?php if ($inLayout): ?></div><?php endif; ?>
    </div>
    <?php if ($inLayout): ?>
    <div class="card">
        <div class="card-header"><div class="card-title"><?= icon('shield') ?> This installation</div></div>
        <div class="card-body">
            <dl class="kv">
                <dt>Status</dt><dd><span class="badge <?= $badge[1] ?>"><?= e($badge[0]) ?></span></dd>
                <dt>Phone</dt><dd><?= e($status['phone'] ?: setting('license_phone') ?: '—') ?></dd>
                <dt>Valid from</dt><dd><?= e($status['from'] ? fmt_date($status['from']) : '—') ?></dd>
                <dt>Valid to</dt><dd><?= e($status['to'] ? fmt_date($status['to']) : '—') ?></dd>
                <dt>Days left</dt><dd><?= $status['days_left'] !== null ? (int) $status['days_left'] : '—' ?></dd>
            </dl>
            <p class="hint mt-2 mb-0">New installations run a <?= License::TRIAL_DAYS ?>-day trial. Setting the computer clock back blocks the software until the date is corrected.</p>
        </div>
    </div>
    <?php endif; ?>
</div>

<?php if ($admin): ?>
    <div class="card mb-2">
        <div class="card-header"><div class="card-title"><?= icon('plus') ?> Generate subscription key</div>
            <span class="badge <?= $canIssue ? 'badge-success' : 'badge-muted' ?>"><?= $canIssue ? 'Vendor installation' : 'Not available on clinic installations' ?></span></div>
        <div class="card-body">
            <?php if (!$canIssue): ?>
                <p class="muted mb-0">Keys can only be generated on the vendor's own installation, where the vendor private key (<code>tools/license-private.key</code>) is present. Clinic installations never contain it — otherwise any clinic could make its own keys.</p>
            <?php else: ?>
                <?php if ($generateError): ?><div class="alert alert-danger"><?= icon('alert') ?><div><?= e($generateError) ?></div></div><?php endif; ?>
                <form method="post" action="<?= e(url('license/generate')) ?>" autocomplete="off" data-confirm="Generate a subscription key with these details?" data-confirm-ok="Generate" data-confirm-danger="false">
                    <?= csrf_field() ?>
                    <div class="form-grid">
                        <div class="field col-4"><label>Clinic / doctor name</label><input class="input" name="clinic_name" maxlength="150" value="<?= e(old('clinic_name')) ?>"></div>
                        <div class="field col-4"><label>Clinic phone <span class="req">*</span></label><input class="input" name="phone" required maxlength="20" inputmode="tel" placeholder="03001234567" value="<?= e(old('phone')) ?>"><?= $err('phone') ?></div>
                        <div class="field col-4"><label>Amount received</label><input class="input" type="number" min="0" step="1" name="amount" value="<?= e(old('amount', '0')) ?>"><?= $err('amount') ?></div>
                        <div class="field col-3"><label>Valid from <span class="req">*</span></label><input class="input" type="date" name="from" id="gen-from" required value="<?= e(old('from', today())) ?>"><?= $err('from') ?></div>
                        <div class="field col-3"><label>Valid to <span class="req">*</span></label><input class="input" type="date" name="to" id="gen-to" required value="<?= e(old('to', date('Y-m-d', strtotime('+1 year -1 day')))) ?>"><?= $err('to') ?>
                            <div class="followup-quick"><?php foreach ([1 => '1 month', 3 => '3 months', 6 => '6 months', 12 => '1 year'] as $m => $l): ?><button type="button" class="btn btn-sm btn-outline" data-months="<?= $m ?>"><?= $l ?></button><?php endforeach; ?></div></div>
                        <div class="field col-3"><label>Payment reference</label><input class="input" name="payment_ref" maxlength="100" placeholder="e.g. EasyPaisa TID" value="<?= e(old('payment_ref')) ?>"></div>
                        <div class="field col-3"><label>Notes</label><input class="input" name="notes" maxlength="500" value="<?= e(old('notes')) ?>"></div>
                    </div>
                    <div class="flex mt-2" style="justify-content:flex-end"><button type="submit" class="btn btn-primary"><?= icon('key') ?> Generate Key</button></div>
                </form>
            <?php endif; ?>
        </div>
    </div>

    <?php if ($canIssue || $issued): ?>
    <div class="card card-flush mb-2">
        <div class="card-header"><div class="card-title"><?= icon('list') ?> Issued keys</div>
            <a class="btn btn-sm btn-excel" href="<?= e(url('license/export', ['type' => 'issued'])) ?>"><?= icon('excel') ?> Export to Excel</a></div>
        <div class="card-body table-wrap">
            <?php if (!$issued): ?><div class="empty"><?= icon('key') ?><strong>No keys issued yet</strong></div><?php else: ?>
            <table class="table table-compact">
                <thead><tr><th>Issued</th><th>Clinic</th><th>Phone</th><th>Valid</th><th>Status</th><th class="num">Amount</th><th>Payment ref</th><th>By</th><th style="width:1%"></th></tr></thead>
                <tbody>
                <?php foreach ($issued as $r): ?>
                    <tr>
                        <td class="nowrap small"><?= e(fmt_datetime($r['created_at'])) ?></td>
                        <td><?= e($r['clinic_name'] ?: '—') ?><?php if ($r['notes']): ?><div class="muted small"><?= e($r['notes']) ?></div><?php endif; ?></td>
                        <td class="nowrap"><?= e($r['phone']) ?></td>
                        <td class="nowrap small"><?= e(fmt_date($r['from_date'])) ?> → <?= e(fmt_date($r['to_date'])) ?></td>
                        <td><?= $statusBadge($r['status']) ?></td>
                        <td class="num"><?= e(money($r['amount'])) ?></td>
                        <td class="small"><?= e($r['payment_ref'] ?: '—') ?></td>
                        <td class="small"><?= e($r['issued_by_name'] ?: '—') ?></td>
                        <td><button type="button" class="act" title="Copy key" data-copy="<?= e($r['license_key']) ?>"><?= icon('copy') ?></button></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            <?php endif; ?>
        </div>
    </div>
    <?php endif; ?>

    <div class="card card-flush">
        <div class="card-header"><div class="card-title"><?= icon('clock') ?> Activation history (this installation)</div>
            <a class="btn btn-sm btn-excel" href="<?= e(url('license/export', ['type' => 'activations'])) ?>"><?= icon('excel') ?> Export to Excel</a></div>
        <div class="card-body table-wrap">
            <?php if (!$activations): ?><div class="empty"><?= icon('key') ?><strong>No key activated yet</strong><?= $status['state'] === 'trial' ? 'Running on the trial.' : '' ?></div><?php else: ?>
            <table class="table table-compact">
                <thead><tr><th>Activated</th><th>Phone</th><th>Valid</th><th>Status</th><th>By</th></tr></thead>
                <tbody>
                <?php foreach ($activations as $r): ?>
                    <tr>
                        <td class="nowrap small"><?= e(fmt_datetime($r['activated_at'])) ?></td>
                        <td class="nowrap"><?= e($r['phone']) ?></td>
                        <td class="nowrap small"><?= e(fmt_date($r['from_date'])) ?> → <?= e(fmt_date($r['to_date'])) ?></td>
                        <td><?= $statusBadge($r['status']) ?></td>
                        <td class="small"><?= e($r['activated_by_name'] ?: '—') ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            <?php endif; ?>
        </div>
    </div>
    <script>
    (function () {
        function copy(text) {
            (navigator.clipboard ? navigator.clipboard.writeText(text) : Promise.reject()).then(function () { APP.toast('Key copied.', 'success'); }, function () {
                window.prompt('Copy the key:', text);
            });
        }
        document.querySelectorAll('[data-copy]').forEach(function (b) { b.addEventListener('click', function () { copy(b.getAttribute('data-copy')); }); });
        var ck = document.getElementById('copy-key');
        if (ck) ck.addEventListener('click', function () { copy(document.getElementById('new-key').textContent.trim()); });
        document.querySelectorAll('[data-months]').forEach(function (b) {
            b.addEventListener('click', function () {
                var from = document.getElementById('gen-from').value;
                var d = new Date((from || new Date().toISOString().slice(0, 10)) + 'T00:00:00');
                d.setMonth(d.getMonth() + parseInt(b.getAttribute('data-months'), 10));
                d.setDate(d.getDate() - 1);
                document.getElementById('gen-to').value = d.getFullYear() + '-' + String(d.getMonth() + 1).padStart(2, '0') + '-' + String(d.getDate()).padStart(2, '0');
            });
        });
    })();
    </script>
<?php endif; ?>
<?php if (!$inLayout): ?></div></div><?php endif; ?>
