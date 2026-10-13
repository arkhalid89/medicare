<?php
/** @var string $route @var string $from @var string $to */
?>
<div class="card mb-2"><div class="card-body"><form class="filters" method="get" action="<?= e(form_action($route)) ?>"><?= route_field($route) ?>
    <div class="field"><label>Date From</label><input class="input" type="date" name="from" value="<?= e($from) ?>"></div>
    <div class="field"><label>Date To</label><input class="input" type="date" name="to" value="<?= e($to) ?>"></div>
    <div class="btns"><button class="btn btn-primary"><?= icon('filter') ?> Generate</button></div></form></div></div>
