<?php
/** @var string $content */
use App\Core\View;

$user = auth();
$pageTitle = ($title ?? 'Dashboard') . ' · ' . setting('org_name', config('app.name'));
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= e($pageTitle) ?></title>
    <link rel="icon" href="<?= e(asset('img/favicon.svg')) ?>" type="image/svg+xml">
    <link rel="stylesheet" href="<?= e(asset('css/app.css')) ?>">
    <?= View::partial('theme') ?>
    <script>try{if(localStorage.getItem('mc_sidebar')==='collapsed'&&innerWidth>991)document.documentElement.classList.add('sidebar-collapsed')}catch(e){}</script>
</head>
<body>
<div class="app">
    <?= View::partial('sidebar', ['user' => $user]) ?>
    <div class="sidebar-backdrop" data-sidebar-close></div>
    <div class="main">
        <?= View::partial('header', ['user' => $user]) ?>
        <main class="content">
            <?= View::partial('flash') ?>
            <?= $content ?>
        </main>
        <?= View::partial('footer') ?>
    </div>
</div>
<?= View::partial('common_ui') ?>
<script>
window.APP = {
    base: <?= json_encode(base_url()) ?>,
    pretty: <?= config('app.pretty_urls') ? 'true' : 'false' ?>,
    csrf: <?= json_encode(csrf_token()) ?>,
    currency: <?= json_encode((string) setting('currency_symbol', 'Rs.')) ?>
};
</script>
<script src="<?= e(asset('js/app.js')) ?>"></script>
<?php foreach (($scripts ?? []) as $script): ?>
<script src="<?= e(asset($script)) ?>"></script>
<?php endforeach; ?>
</body>
</html>
