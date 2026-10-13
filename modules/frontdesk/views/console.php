<?php
/** @var array $queues @var int $queueId @var array $tokens @var array $points */
use App\Core\View;

echo View::partial('page_header', [
    'title'       => 'Token Console',
    'description' => 'Call next by priority (emergency → pregnant / disabled / senior → VIP → normal), recall, skip or transfer. Every action is logged.',
    'breadcrumbs' => [['Front Desk'], ['Token Console']],
]);
$current = null;
foreach ($queues as $q) {
    if ((int) $q['id'] === $queueId) {
        $current = $q;
    }
}
?>
<div class="tabs mb-2">
    <?php foreach ($queues as $q): ?>
        <a href="<?= e(url('frontdesk/tokens', ['queue_id' => $q['id']])) ?>" class="<?= (int) $q['id'] === $queueId ? 'active' : '' ?>"><?= e($q['name']) ?> <span class="badge badge-primary"><?= (int) $q['waiting'] ?></span></a>
    <?php endforeach; ?>
</div>
<?php if (!$current): ?>
    <div class="card"><div class="card-body"><div class="empty"><?= icon('list') ?><strong>No queues yet</strong>Queues are created automatically when the registration desk issues the first token for a doctor.</div></div></div>
<?php else: ?>
<div class="grid grid-main">
    <div class="card card-flush">
        <div class="card-header"><div class="card-title"><?= icon('list') ?> <?= e($current['name']) ?> — today</div>
            <span class="muted small">Auto-refreshes every 10 s</span></div>
        <div class="card-body table-wrap">
            <table class="table" id="tok-table">
                <thead><tr><th>Token</th><th>Patient</th><th>Priority</th><th>Issued / wait</th><th>Status</th><th style="width:1%">Actions</th></tr></thead>
                <tbody></tbody>
            </table>
        </div>
    </div>
    <div>
        <div class="card">
            <div class="card-header"><div class="card-title"><?= icon('volume') ?> Call</div></div>
            <div class="card-body">
                <div class="now-serving" id="now-serving">—</div>
                <div class="field mb-2"><label>Room / counter</label>
                    <select class="input" id="tc-point"><option value="">—</option><?php foreach ($points as $id => $n): ?><option value="<?= $id ?>"><?= e($n) ?></option><?php endforeach; ?></select></div>
                <button class="btn btn-gold btn-lg" style="width:100%" id="tc-next"><?= icon('volume') ?> Call Next</button>
                <label class="check mt-2"><input type="checkbox" id="tc-speak" checked> Announce by voice on this PC</label>
            </div>
        </div>
        <div class="card">
            <div class="card-header"><div class="card-title"><?= icon('send') ?> Transfer to queue</div></div>
            <div class="card-body">
                <select class="input" id="tc-to"><?php foreach ($queues as $q): if ((int) $q['id'] === $queueId) continue; ?><option value="<?= (int) $q['id'] ?>"><?= e($q['name']) ?></option><?php endforeach; ?></select>
                <p class="muted small">Use the <?= icon('send') ?> button on a row: the token closes here and a linked token opens in the selected queue.</p>
            </div>
        </div>
    </div>
</div>
<script>
(function () {
    var queueId = <?= (int) $queueId ?>;
    var body = document.querySelector('#tok-table tbody');
    var pr = <?= json_encode(array_map(static fn ($p) => $p[0], hospital('priorities'))) ?>;
    function speak(label) {
        try {
            var ctx = new (window.AudioContext || window.webkitAudioContext)(), o = ctx.createOscillator(), g = ctx.createGain();
            o.connect(g); g.connect(ctx.destination); o.frequency.value = 880; g.gain.value = .15; o.start(); o.stop(ctx.currentTime + .35);
        } catch (e) {}
        if (!document.getElementById('tc-speak').checked || !window.speechSynthesis) return;
        var room = document.getElementById('tc-point');
        var text = 'Token ' + label.split('').join(' ') + (room.value ? ', please go to ' + room.selectedOptions[0].text : '');
        setTimeout(function () { speechSynthesis.speak(new SpeechSynthesisUtterance(text)); }, 400);
    }
    function act(action, tokenId, extra) {
        var body = Object.assign({ action: action, token_id: tokenId, queue_id: queueId, service_point_id: document.getElementById('tc-point').value }, extra || {});
        return APP.ajax(APP.url('frontdesk/token-action'), { method: 'POST', body: body }).then(function (d) {
            if (!d.ok) { APP.toast(d.message || 'Action failed', 'error'); return d; }
            if (d.message) APP.toast(d.message, 'success');
            if ((action === 'call_next' || action === 'call') && d.token) speak(d.token.token_label);
            load();
            return d;
        });
    }
    function row(t) {
        var b = [];
        if (t.status === 'waiting') b.push(['call', 'volume', 'Call']);
        if (t.status === 'called') { b.push(['call', 'repeat', 'Recall']); b.push(['start', 'check', 'In room']); b.push(['skip', 'skip', 'Skip (no show)']); }
        if (t.status === 'serving') b.push(['complete', 'check', 'Done']);
        if (t.status === 'no_show') { b.push(['requeue', 'refresh', 'Back to queue']); b.push(['call', 'volume', 'Call']); }
        if (['waiting', 'called', 'serving'].indexOf(t.status) > -1) b.push(['transfer', 'send', 'Transfer']);
        if (['waiting', 'called', 'no_show'].indexOf(t.status) > -1) b.push(['cancel', 'x', 'Cancel']);
        var icons = <?= json_encode(['volume' => icon('volume'), 'repeat' => icon('repeat'), 'check' => icon('check'), 'skip' => icon('skip'), 'refresh' => icon('refresh'), 'send' => icon('send'), 'x' => icon('x')]) ?>;
        return '<tr class="' + (t.status === 'called' || t.status === 'serving' ? 'row-active' : '') + '"><td><strong class="tok-label">' + APP.esc(t.label) + '</strong></td>' +
            '<td><strong>' + APP.esc(t.patient) + '</strong><div class="muted small">' + APP.esc(t.mrn) + ' · ' + APP.esc(t.gender) + ' ' + APP.esc(t.age) + '</div></td>' +
            '<td>' + (t.priority !== 'normal' ? '<span class="badge badge-gold">' + APP.esc(pr[t.priority] || t.priority) + '</span>' : '<span class="muted">Normal</span>') + '</td>' +
            '<td class="nowrap small">' + APP.esc(t.issued) + (t.wait !== null ? '<div class="muted">' + t.wait + ' min</div>' : '') + '</td>' +
            '<td><span class="badge badge-muted">' + APP.esc(t.status.replace('_', ' ')) + '</span>' + (t.room ? '<div class="muted small">' + APP.esc(t.room) + '</div>' : '') + '</td>' +
            '<td><div class="actions">' + b.map(function (x) { return '<button class="act" title="' + x[2] + '" data-a="' + x[0] + '" data-id="' + t.id + '">' + icons[x[1]] + '</button>'; }).join('') + '</div></td></tr>';
    }
    function load() {
        APP.ajax(APP.url('frontdesk/queue-data', { queue_id: queueId })).then(function (d) {
            if (!d.ok) return;
            body.innerHTML = d.tokens.length ? d.tokens.map(row).join('') : '<tr><td colspan="6" class="muted">No tokens today.</td></tr>';
            var now = d.tokens.filter(function (t) { return t.status === 'called' || t.status === 'serving'; })[0];
            document.getElementById('now-serving').textContent = now ? now.label + ' — ' + now.patient : '—';
        });
    }
    body.addEventListener('click', function (e) {
        var b = e.target.closest('[data-a]'); if (!b) return;
        var a = b.dataset.a, id = b.dataset.id;
        if (a === 'cancel') { APP.confirm('Cancel this token?', 'Cancel token').then(function (ok) { if (ok) act('cancel', id, { reason: 'Cancelled at console' }); }); return; }
        if (a === 'transfer') { act('transfer', id, { to_queue_id: document.getElementById('tc-to').value }); return; }
        act(a, id);
    });
    document.getElementById('tc-next').addEventListener('click', function () { act('call_next', 0); });
    load();
    setInterval(load, 10000);
})();
</script>
<?php endif; ?>
