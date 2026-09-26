<?php
/** @var string $content */
use App\Core\View;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= e(($title ?? 'Welcome') . ' · ' . setting('org_name', config('app.name'))) ?></title>
    <link rel="icon" href="<?= e(asset('img/favicon.svg')) ?>" type="image/svg+xml">
    <link rel="stylesheet" href="<?= e(asset('css/app.css')) ?>">
    <?= View::partial('theme') ?>
</head>
<body>
<?= $content ?>
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
</body>
</html>
