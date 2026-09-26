<?php
use App\Core\Router;

$current = Router::current();
if ($current === '') {
    $current = 'dashboard';
}
// Generic master form belongs to the master being edited.
if (in_array($current, ['masters/form'], true) && isset($_GET['type'])) {
    $current = 'masters/' . preg_replace('/[^a-z_]/', '', (string) $_GET['type']);
}
if ($current === 'reports/fees-patient') {
    $current = 'reports/fees';
}

$isActive = static function (array $item) use ($current): bool {
    if (($item['route'] ?? null) === $current) {
        return true;
    }
    foreach ($item['match'] ?? [] as $prefix) {
        if ($current === $prefix || str_starts_with($current, $prefix . '/')) {
            return true;
        }
    }
    return false;
};

$logo = setting('org_logo', '');
$orgName = (string) setting('org_name', 'MediCare Clinic');
$initials = org_initials();

// Build visible menu (drop empty sections).
$visible = [];
$pendingSection = null;
foreach (config('menu') as $item) {
    if (isset($item['section'])) {
        $pendingSection = $item;
        continue;
    }
    if (!can($item['perm'])) {
        continue;
    }
    if (isset($item['children'])) {
        $item['children'] = array_values(array_filter($item['children'], static fn ($c) => can($c['perm'])));
        if (!$item['children']) {
            continue;
        }
    }
    if ($pendingSection) {
        $visible[] = $pendingSection;
        $pendingSection = null;
    }
    $visible[] = $item;
}
?>
<aside class="sidebar" id="sidebar">
    <div class="sidebar-brand">
        <div class="brand-mark"><?php if ($logo): ?><img src="<?= e(upload_url($logo)) ?>" alt=""><?php else: ?><?= e($initials) ?><?php endif; ?></div>
        <div class="brand-text">
            <strong><?= e(config('app.short_name', 'MediCare')) ?></strong>
            <span><?= e(mb_strimwidth($orgName, 0, 28, '…')) ?></span>
        </div>
    </div>
    <nav class="sidebar-nav">
        <?php foreach ($visible as $item): ?>
            <?php if (isset($item['section'])): ?>
                <div class="nav-section"><?= e($item['section']) ?></div>
            <?php elseif (isset($item['children'])):
                $open = false;
                foreach ($item['children'] as $child) {
                    if ($isActive($child)) {
                        $open = true;
                    }
                } ?>
                <div class="nav-group<?= $open ? ' open' : '' ?>">
                    <button type="button" class="nav-link<?= $open ? ' active' : '' ?>" data-nav-toggle title="<?= e($item['label']) ?>">
                        <?= icon($item['icon']) ?><span><?= e($item['label']) ?></span><?= icon('chevron-down', 'chev') ?>
                    </button>
                    <div class="nav-children">
                        <?php foreach ($item['children'] as $child): ?>
                            <a href="<?= e(url($child['route'])) ?>" class="<?= $isActive($child) ? 'active' : '' ?>"><?= e($child['label']) ?></a>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php else: ?>
                <a href="<?= e(url($item['route'])) ?>" title="<?= e($item['label']) ?>"
                   class="nav-link<?= $isActive($item) ? ' active' : '' ?><?= !empty($item['highlight']) ? ' highlight' : '' ?>">
                    <?= icon($item['icon']) ?><span><?= e($item['label']) ?></span>
                </a>
            <?php endif; ?>
        <?php endforeach; ?>
    </nav>
    <div class="sidebar-foot"><?= e(config('app.name')) ?> v<?= e(config('app.version')) ?></div>
</aside>
