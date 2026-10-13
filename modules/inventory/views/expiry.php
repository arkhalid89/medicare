<?php
/** @var array $rows @var array $recalls @var array $patients */
use App\Core\View;

echo View::partial('page_header', [
    'title' => 'Expiry Watchlist & Recalls', 'description' => 'Batches expiring within the alert window (90 / 60 / 30 days). Expired stock is moved to Expired Hold every night. Recalls quarantine the batch and list the patients who received it.',
    'breadcrumbs' => [['Store', url('inventory')], ['Expiry & recalls']],
]);
?>
<?php if ($patients): ?>
<div class="card card-flush"><div class="card-header"><div class="card-title"><?= icon('phone') ?> Patients who received the recalled batch — contact them</div></div>
    <div class="card-body"><table class="table table-compact"><thead><tr><th>Patient</th><th>MRN</th><th>Phone</th><th>Given</th><th>Via</th></tr></thead><tbody>
        <?php foreach ($patients as $p): ?><tr><td><a href="<?= e(url('patients/view', ['id' => $p['id']])) ?>"><?= e($p['name']) ?></a></td><td><?= e($p['mrn']) ?></td><td><?= e($p['phone']) ?></td><td class="small"><?= e(fmt_datetime($p['given_at'])) ?></td><td><?= e($p['via']) ?></td></tr><?php endforeach; ?>
    </tbody></table></div></div>
<?php endif; ?>
<div class="grid grid-main">
    <div class="card card-flush"><div class="card-header"><div class="card-title"><?= icon('clock') ?> Expiring stock</div></div>
        <div class="card-body table-wrap"><table class="table table-compact"><thead><tr><th>Item</th><th>Batch</th><th>Location</th><th>Expiry</th><th class="num">Days</th><th class="num">Qty</th></tr></thead><tbody>
            <?php foreach ($rows as $r): $d = (int) $r['days']; ?><tr><td><a href="<?= e(url('inventory/card', ['item_id' => $r['item_id']])) ?>"><?= e($r['item_name']) ?></a></td><td><?= e($r['batch_no']) ?></td>
                <td class="small"><?= e($r['location_name']) ?></td><td><?= e(fmt_date($r['expiry_date'])) ?></td>
                <td class="num"><span class="badge <?= $d < 0 ? 'badge-danger' : ($d <= 30 ? 'badge-danger' : ($d <= 60 ? 'badge-warning' : 'badge-info')) ?>"><?= $d < 0 ? 'expired' : $d ?></span></td>
                <td class="num"><?= e(num($r['qty_on_hand'])) ?></td></tr><?php endforeach; ?>
            <?php if (!$rows): ?><tr><td colspan="6" class="muted">Nothing expiring in the alert window.</td></tr><?php endif; ?>
        </tbody></table></div></div>
    <div>
        <?php if (can('pharmacy.writeoff.approve') || can('inventory.adjust.approve')): ?>
        <form class="card" method="post" action="<?= e(url('inventory/recall')) ?>" data-confirm="Recall this batch? All its stock moves to quarantine immediately." data-confirm-ok="Recall">
            <?= csrf_field() ?><input type="hidden" name="item_id" id="rc-item">
            <div class="card-header"><div class="card-title"><?= icon('alert') ?> Register a recall</div></div>
            <div class="card-body">
                <div class="field mb-1"><label>Item</label><div class="search-box"><?= icon('search') ?><input class="input" id="rc-search" autocomplete="off"><div class="search-results"></div></div></div>
                <div class="field mb-1"><label>Batch number</label><input class="input" name="batch_no" required maxlength="60"></div>
                <div class="field mb-1"><label>Source</label><select class="input" name="source"><option>DRAP</option><option>Manufacturer</option><option>Internal</option></select></div>
                <div class="field mb-1"><label>Reference</label><input class="input" name="reference" maxlength="100" placeholder="Recall letter no."></div>
                <div class="field"><label>Notes</label><input class="input" name="notes" maxlength="500"></div>
            </div>
            <div class="card-footer"><button class="btn btn-danger">Recall batch</button></div>
        </form>
        <script>
        APP.liveSearch(document.getElementById('rc-search'), { minLength: 2,
            fetch: function (q) { return APP.ajax(APP.url('inventory/item-search', { q: q })).then(function (d) { return d.items || []; }); },
            render: function (i) { return '<div class="grow"><div class="t">' + APP.esc(i.name) + '</div><div class="s">' + APP.esc(i.sku) + '</div></div>'; },
            onSelect: function (i) { document.getElementById('rc-item').value = i.id; document.getElementById('rc-search').value = i.name; } });
        </script>
        <?php endif; ?>
        <div class="card card-flush"><div class="card-header"><div class="card-title"><?= icon('list') ?> Recalls</div></div>
            <div class="card-body"><ul class="list"><?php foreach ($recalls as $r): ?><li><div class="grow"><div class="title"><?= e($r['item_name']) ?> · batch <?= e($r['batch_no']) ?></div>
                <div class="meta"><?= e($r['source']) ?> <?= e($r['reference']) ?> · <?= e(fmt_date($r['issued_at'])) ?></div></div><?= status_chip($r['action_status']) ?></li><?php endforeach; ?>
                <?php if (!$recalls): ?><li class="muted small">No recalls.</li><?php endif; ?></ul></div></div>
    </div>
</div>
