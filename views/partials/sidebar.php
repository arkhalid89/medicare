<?php
use App\Core\Auth;
use App\Core\Router;

$current = Router::current();
if ($current === '') {
    $current = Auth::homeRoute();
}
// Generic master form belongs to the master being edited.
if (in_array($current, ['masters/form'], true) && isset($_GET['type'])) {
    $current = 'masters/' . preg_replace('/[^a-z_]/', '', (string) $_GET['type']);
}
if ($current === 'reports/fees-patient') {
    $current = 'reports/fees';
}

$routes = Router::routes();
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
$visibleItem = static fn (array $item): bool => Router::allowed($item['perm']) && (!isset($item['route']) || isset($routes[$item['route']]));

$logo = setting('org_logo', '');
$orgName = (string) setting('org_name', 'MediCare Clinic');
$initials = org_initials();

// The user's workspaces (Doc 8 §4.2), grouped by section.
$workspaces = Auth::workspaces();
$wsRoutes = array_column($workspaces, 'route');
$wsSections = [];
foreach ($workspaces as $w) {
    $wsSections[$w['section']][] = $w;
}

// Build visible menu (drop empty sections and items already shown as workspaces).
$visible = [];
$pendingSection = null;
foreach (config('menu') as $item) {
    if (isset($item['section'])) {
        $pendingSection = $item;
        continue;
    }
    if (!$visibleItem($item)) {
        continue;
    }
    if (isset($item['route']) && in_array($item['route'], $wsRoutes, true) && $item['route'] !== 'dashboard') {
        continue;
    }
    if (isset($item['children'])) {
        $item['children'] = array_values(array_filter($item['children'], static fn ($c) => $visibleItem($c) && !in_array($c['route'], $wsRoutes, true)));
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
        <?php foreach ($wsSections as $section => $items): ?>
            <div class="nav-section"><?= e($section) ?></div>
            <?php foreach ($items as $w): ?>
                <a href="<?= e(url($w['route'])) ?>" title="<?= e($w['name']) ?>"
                   class="nav-link<?= $current === $w['route'] || str_starts_with($current, $w['route'] . '/') && !in_array($current, $wsRoutes, true) ? ' active' : '' ?><?= (int) $w['is_default'] ? ' highlight-soft' : '' ?>">
                    <?= icon($w['icon']) ?><span><?= e($w['name']) ?></span>
                </a>
            <?php endforeach; ?>
        <?php endforeach; ?>
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
