<?php
/** @var array $boards @var array $queues @var array $links */
use App\Core\View;

echo View::partial('page_header', [
    'title'       => 'Display Boards',
    'description' => 'Waiting-room screens: open the board link on any TV / PC browser on the hospital network (no login; the link contains a secret key).',
    'breadcrumbs' => [['Front Desk'], ['Display Boards']],
]);
$byBoard = [];
foreach ($links as $l) {
    $byBoard[(int) $l['display_board_id']][] = (int) $l['token_queue_id'];
}
$boards[] = ['id' => 0, 'name' => '', 'location_text' => '', 'refresh_seconds' => 5, 'show_urdu' => 1, 'is_active' => 1, 'access_key' => ''];
?>
<div class="grid grid-2">
<?php foreach ($boards as $b): ?>
    <form class="card" method="post" action="<?= e(url('frontdesk/board-save')) ?>">
        <?= csrf_field() ?><input type="hidden" name="id" value="<?= (int) $b['id'] ?>">
        <div class="card-header"><div class="card-title"><?= icon('monitor') ?> <?= $b['id'] ? e($b['name']) : 'New board' ?></div>
            <?php if ($b['id']): ?><a class="btn btn-sm btn-gold" target="_blank" href="<?= e(url('board', ['key' => $b['access_key']])) ?>"><?= icon('eye') ?> Open board</a><?php endif; ?></div>
        <div class="card-body">
            <div class="form-grid">
                <div class="field col-6"><label>Name</label><input class="input" name="name" value="<?= e($b['name']) ?>" required maxlength="150"></div>
                <div class="field col-6"><label>Location</label><input class="input" name="location_text" value="<?= e($b['location_text']) ?>" maxlength="150"></div>
                <div class="field col-4"><label>Refresh (seconds)</label><input class="input" type="number" min="3" max="60" name="refresh_seconds" value="<?= (int) $b['refresh_seconds'] ?>"></div>
                <div class="field col-8"><label>&nbsp;</label><div class="flex flex-wrap">
                    <label class="check"><input type="checkbox" name="show_urdu" value="1"<?= checked((int) $b['show_urdu']) ?>> Urdu labels</label>
                    <label class="check"><input type="checkbox" name="is_active" value="1"<?= checked((int) $b['is_active']) ?>> Active</label>
                    <?php if ($b['id']): ?><label class="check"><input type="checkbox" name="new_key" value="1"> New secret link</label><?php endif; ?></div></div>
                <div class="field col-12"><label>Queues shown (none = all)</label>
                    <div class="check-list"><?php foreach ($queues as $qid => $qn): ?>
                        <label class="check"><input type="checkbox" name="queues[]" value="<?= $qid ?>"<?= checked(in_array((int) $qid, $byBoard[(int) $b['id']] ?? [], true)) ?>> <?= e($qn) ?></label>
                    <?php endforeach; ?></div></div>
                <?php if ($b['id']): ?><div class="field col-12"><label>Board link</label><input class="input" readonly value="<?= e(url('board', ['key' => $b['access_key']])) ?>" onclick="this.select()"></div><?php endif; ?>
            </div>
        </div>
        <div class="card-footer"><button class="btn btn-primary"><?= icon('save') ?> Save</button></div>
    </form>
<?php endforeach; ?>
</div>
