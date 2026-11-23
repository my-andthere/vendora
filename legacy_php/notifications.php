<?php
require_once __DIR__ . '/includes/bootstrap.php';
$u = require_login();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    q('UPDATE notifications SET is_read=1 WHERE user_id=?', [$u['id']]);
    redirect('notifications.php');
}
if (isset($_GET['open'])) {
    $n = q('SELECT * FROM notifications WHERE id=? AND user_id=?', [(int)$_GET['open'], $u['id']])->fetch();
    if ($n) {
        q('UPDATE notifications SET is_read=1 WHERE id=?', [$n['id']]);
        redirect($n['ticket_id'] ? 'ticket_view.php?id=' . $n['ticket_id'] : 'notifications.php');
    }
}
$rows = q('SELECT * FROM notifications WHERE user_id=? ORDER BY id DESC LIMIT 100', [$u['id']])->fetchAll();
$pageTitle = 'Notifikasi';
require __DIR__ . '/includes/header.php';
?>
<div class="d-flex justify-content-between align-items-center mb-3">
    <h4 class="mb-0">Notifikasi</h4>
    <form method="post"><?= csrf_field() ?><button class="btn btn-sm btn-outline-secondary"><i class="bi bi-check2-all"></i> Tandai semua dibaca</button></form>
</div>
<div class="card"><div class="list-group list-group-flush">
    <?php foreach ($rows as $n): ?>
        <a href="<?= url('notifications.php?open=' . $n['id']) ?>" class="list-group-item list-group-item-action <?= $n['is_read'] ? '' : 'fw-semibold bg-primary-subtle' ?>">
            <div class="d-flex justify-content-between"><span><?= e($n['message']) ?></span><small class="text-muted text-nowrap ms-2"><?= fmt_dt($n['created_at']) ?></small></div>
        </a>
    <?php endforeach; ?>
    <?php if (!$rows): ?><div class="p-4 text-center text-muted">Belum ada notifikasi.</div><?php endif; ?>
</div></div>
<?php require __DIR__ . '/includes/footer.php'; ?>
