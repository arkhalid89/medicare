<?php
/** @var array $user @var array $workspaces @var array $tasks @var array $kpis */
use App\Core\View;

echo View::partial('page_header', [
    'title'       => 'Good ' . (date('H') < 12 ? 'morning' : (date('H') < 17 ? 'afternoon' : 'evening')) . ', ' . explode(' ', $user['name'])[0],
    'description' => user_role_name($user) . ' · ' . fmt_date(today()),
    'breadcrumbs' => [['Dashboard']],
]);
?>
<?php if ($kpis): ?>
<div class="grid grid-4 mb-2">
    <?php foreach ($kpis as $k): ?>
        <div class="stat"><div class="stat-icon"><?= icon($k['icon']) ?></div><div><p class="label"><?= e($k['label']) ?></p>
            <div class="value"><?= $k['money'] ? e(money($k['value'])) : number_format($k['value']) ?></div></div></div>
    <?php endforeach; ?>
</div>
<?php endif; ?>
<div class="grid grid-main">
    <div class="card">
        <div class="card-header"><div class="card-title"><?= icon('layers') ?> My workspaces</div></div>
        <div class="card-body">
            <?php if (!$workspaces): ?><div class="empty"><strong>No workspace assigned</strong>Ask the administrator to assign a role with a workspace.</div><?php endif; ?>
            <div class="quick-actions">
                <?php foreach ($workspaces as $w): ?>
                    <a class="quick" href="<?= e(url($w['route'])) ?>"><?= icon($w['icon']) ?><strong><?= e($w['name']) ?></strong><span><?= e($w['section']) ?></span></a>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
    <div class="card card-flush">
        <div class="card-header"><div class="card-title"><?= icon('bell') ?> Waiting for you</div></div>
        <div class="card-body">
            <?php if (!$tasks): ?><div class="empty"><?= icon('check') ?><strong>All clear</strong>Nothing is waiting for you right now.</div><?php endif; ?>
            <ul class="list">
                <?php foreach ($tasks as $t): ?>
                    <li><?= icon($t['icon']) ?><div class="grow"><a class="title" href="<?= e($t['url']) ?>"><?= e($t['label']) ?></a></div><span class="badge badge-warning"><?= (int) $t['count'] ?></span></li>
                <?php endforeach; ?>
            </ul>
        </div>
    </div>
</div>
