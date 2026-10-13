<?php
/** @var array $b @var array $data */
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e($b['name']) ?></title>
<?= \App\Core\View::partial('theme') ?>
<style>
    * { box-sizing: border-box; }
    body { margin: 0; font-family: 'Segoe UI', Arial, sans-serif; background: #071633; color: #fff; min-height: 100vh; }
    header { display: flex; justify-content: space-between; align-items: center; padding: 18px 32px; background: var(--primary, #0A2463); border-bottom: 4px solid var(--secondary, #C9A227); }
    header h1 { margin: 0; font-size: 30px; } header .clock { font-size: 34px; font-weight: 700; }
    .ur { font-family: 'Jameel Noori Nastaleeq', 'Noto Nastaliq Urdu', serif; direction: rtl; }
    main { display: grid; grid-template-columns: repeat(auto-fill, minmax(360px, 1fr)); gap: 20px; padding: 24px 32px; }
    .q { background: #0f2350; border-radius: 16px; padding: 18px 22px; border: 1px solid rgba(255,255,255,.12); }
    .q h2 { margin: 0 0 6px; font-size: 22px; color: #ffd76a; }
    .now { font-size: 84px; font-weight: 800; line-height: 1; letter-spacing: 3px; }
    .now.flash { animation: flash 1s 4; } @keyframes flash { 50% { color: #ffd76a; } }
    .room { font-size: 22px; opacity: .85; margin-top: 6px; }
    .next { margin-top: 14px; display: flex; flex-wrap: wrap; gap: 8px; } .next span { background: rgba(255,255,255,.1); padding: 6px 12px; border-radius: 8px; font-size: 22px; }
    .lbl { font-size: 14px; opacity: .7; text-transform: uppercase; letter-spacing: 1px; }
    footer { position: fixed; bottom: 0; left: 0; right: 0; padding: 10px 32px; font-size: 16px; background: rgba(0,0,0,.35); }
</style>
</head>
<body>
<header>
    <div><h1><?= e(setting('org_name')) ?></h1><div><?= e($b['location_text']) ?></div></div>
    <div class="clock" id="clock"><?= date('h:i A') ?></div>
</header>
<main id="queues"></main>
<footer><?= e(setting('receipt_footer')) ?><?php if ((int) $b['show_urdu']): ?> · <span class="ur"><?= e(setting('receipt_footer_ur')) ?></span><?php endif; ?></footer>
<script>
(function () {
    var urdu = <?= (int) $b['show_urdu'] ? 'true' : 'false' ?>, last = {};
    var dataUrl = <?= json_encode(url('board/data', ['key' => $b['access_key']])) ?>;
    function esc(s) { return String(s == null ? '' : s).replace(/[&<>"]/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]; }); }
    function chime() { try { var c = new (window.AudioContext || window.webkitAudioContext)(), o = c.createOscillator(), g = c.createGain(); o.connect(g); g.connect(c.destination); o.frequency.value = 660; g.gain.value = .2; o.start(); o.frequency.setValueAtTime(880, c.currentTime + .25); o.stop(c.currentTime + .6); } catch (e) {} }
    function render(queues) {
        var changed = false;
        document.getElementById('queues').innerHTML = queues.map(function (q) {
            var key = q.serving + '|' + q.called_at + '|' + q.recall, flash = last[q.id] !== undefined && last[q.id] !== key && q.serving;
            if (flash) changed = true;
            last[q.id] = key;
            return '<div class="q"><h2>' + esc(q.name) + '</h2><div class="lbl">Now serving' + (urdu ? ' · <span class="ur">موجودہ نمبر</span>' : '') + '</div>' +
                '<div class="now' + (flash ? ' flash' : '') + '">' + esc(q.serving || '—') + '</div>' + (q.room ? '<div class="room">' + esc(q.room) + '</div>' : '') +
                '<div class="lbl" style="margin-top:12px">Next' + (urdu ? ' · <span class="ur">اگلے نمبر</span>' : '') + '</div><div class="next">' +
                (q.next.length ? q.next.map(function (n) { return '<span>' + esc(n) + '</span>'; }).join('') : '<span>—</span>') + '</div></div>';
        }).join('');
        if (changed && <?= setting('board_chime', '1') === '1' ? 'true' : 'false' ?>) chime();
    }
    render(<?= json_encode($data, JSON_UNESCAPED_UNICODE) ?>);
    setInterval(function () {
        fetch(dataUrl, { headers: { 'Accept': 'application/json' } }).then(function (r) { return r.json(); }).then(function (d) {
            if (d.ok) { render(d.queues); document.getElementById('clock').textContent = d.time; }
        }).catch(function () {});
    }, <?= max(3, (int) $b['refresh_seconds']) * 1000 ?>);
})();
</script>
</body>
</html>
