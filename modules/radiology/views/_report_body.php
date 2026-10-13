<?php
/** @var array $report @var array $addenda */
?>
<div class="card"><div class="card-header"><div class="card-title"><?= icon('file-text') ?> Report <?= status_chip($report['status']) ?><?= $report['is_critical_finding'] ? ' <span class="badge badge-danger">Critical finding</span>' : '' ?></div>
    <span class="muted small"><?= e($report['reported_by_name']) ?> · <?= e(fmt_datetime($report['finalized_at'] ?: $report['reported_at'])) ?></span></div>
    <div class="card-body report-body">
        <?php if ($report['technique']): ?><h4>Technique</h4><p><?= nl2br(e($report['technique'])) ?></p><?php endif; ?>
        <?php if ($report['findings']): ?><h4>Findings</h4><p><?= nl2br(e($report['findings'])) ?></p><?php endif; ?>
        <h4>Impression</h4><p><strong><?= nl2br(e($report['impression'])) ?></strong></p>
        <?php foreach ($addenda as $a): ?><div class="addendum"><h4>Addendum — <?= e(fmt_datetime($a['added_at'])) ?>, <?= e($a['added_by_name']) ?></h4><p><?= nl2br(e($a['addendum_text'])) ?></p></div><?php endforeach; ?>
    </div></div>
