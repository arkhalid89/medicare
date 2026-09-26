<?php
use App\Core\Session;

$icons = ['success' => 'check', 'error' => 'alert', 'danger' => 'alert', 'warning' => 'alert', 'info' => 'info'];
foreach (Session::pullFlashes() as $f):
    $type = $f['type'] === 'error' ? 'danger' : $f['type']; ?>
    <div class="alert alert-<?= e($type) ?>" data-flash>
        <?= icon($icons[$f['type']] ?? 'info') ?>
        <div><?= e($f['message']) ?></div>
        <button type="button" class="close" data-dismiss aria-label="Close"><?= icon('x') ?></button>
    </div>
<?php endforeach; ?>
<?php if (!empty($errors) && is_array($errors)): ?>
    <div class="alert alert-danger">
        <?= icon('alert') ?>
        <div>
            <strong>Please correct the following:</strong>
            <ul><?php foreach ($errors as $err): ?><li><?= e($err) ?></li><?php endforeach; ?></ul>
        </div>
    </div>
<?php endif; ?>
