<?php
/** @var \App\Core\Paginator $pager */
$pages = $pager->pages();
$page = $pager->page;
$window = [];
for ($i = 1; $i <= $pages; $i++) {
    if ($i === 1 || $i === $pages || abs($i - $page) <= 2) {
        $window[] = $i;
    }
}
?>
<div class="table-footer">
    <div class="flex">
        <span>Showing <strong><?= number_format($pager->from()) ?></strong>–<strong><?= number_format($pager->to()) ?></strong> of <strong><?= number_format($pager->total) ?></strong></span>
        <select class="input input-sm" style="width:auto" data-per-page aria-label="Rows per page">
            <?php foreach (\App\Core\Paginator::SIZES as $size): ?>
                <option value="<?= $size ?>"<?= selected($size, $pager->perPage) ?>><?= $size ?> / page</option>
            <?php endforeach; ?>
        </select>
    </div>
    <?php if ($pages > 1): ?>
    <nav class="pagination" aria-label="Pagination">
        <?php if ($page > 1): ?><a href="<?= e(url_with(['page' => $page - 1])) ?>" aria-label="Previous"><?= icon('chevron-left') ?></a><?php endif; ?>
        <?php $prev = 0; foreach ($window as $p): ?>
            <?php if ($prev && $p > $prev + 1): ?><span class="gap">…</span><?php endif; ?>
            <?php if ($p === $page): ?>
                <span class="current"><?= $p ?></span>
            <?php else: ?>
                <a href="<?= e(url_with(['page' => $p])) ?>"><?= $p ?></a>
            <?php endif; ?>
        <?php $prev = $p; endforeach; ?>
        <?php if ($page < $pages): ?><a href="<?= e(url_with(['page' => $page + 1])) ?>" aria-label="Next"><?= icon('chevron-right') ?></a><?php endif; ?>
    </nav>
    <?php endif; ?>
</div>
