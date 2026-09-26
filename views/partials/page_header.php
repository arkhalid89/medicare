<?php
/**
 * @var string $title
 * @var string|null $description
 * @var array|null $breadcrumbs  [['Label', 'url'], ['Current']]
 * @var string|null $actions     HTML (buttons)
 */
?>
<div class="page-header">
    <div>
        <nav class="breadcrumb" aria-label="Breadcrumb">
            <a href="<?= e(url('dashboard')) ?>"><?= icon('home') ?></a>
            <?php foreach (($breadcrumbs ?? []) as $crumb): ?>
                <span class="sep">/</span>
                <?php if (!empty($crumb[1])): ?>
                    <a href="<?= e($crumb[1]) ?>"><?= e($crumb[0]) ?></a>
                <?php else: ?>
                    <span><?= e($crumb[0]) ?></span>
                <?php endif; ?>
            <?php endforeach; ?>
        </nav>
        <h1 class="page-title"><?= e($title) ?></h1>
        <?php if (!empty($description)): ?><p class="page-desc"><?= e($description) ?></p><?php endif; ?>
    </div>
    <?php if (!empty($actions)): ?><div class="page-actions"><?= $actions ?></div><?php endif; ?>
</div>
