<?php
require_once __DIR__ . '/includes/bootstrap.php';
$u = require_login();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $row = q('SELECT password_hash FROM users WHERE id=?', [$u['id']])->fetch();
    if (!password_verify($_POST['old'] ?? '', $row['password_hash'])) {
        flash('danger', 'Password lama salah.');
    } elseif (strlen($_POST['new'] ?? '') < 6) {
        flash('danger', 'Password baru minimal 6 karakter.');
    } elseif ($_POST['new'] !== ($_POST['confirm'] ?? '')) {
        flash('danger', 'Konfirmasi password tidak sama.');
    } else {
        q('UPDATE users SET password_hash=? WHERE id=?', [password_hash($_POST['new'], PASSWORD_DEFAULT), $u['id']]);
        flash('success', 'Password berhasil diganti.');
    }
    redirect('profile.php');
}
$pageTitle = 'Ganti Password';
require __DIR__ . '/includes/header.php';
?>
<h4 class="mb-3">Ganti Password</h4>
<div class="card" style="max-width:480px"><div class="card-body">
    <form method="post">
        <?= csrf_field() ?>
        <div class="mb-3"><label class="form-label">Password Lama</label><input type="password" name="old" class="form-control" required></div>
        <div class="mb-3"><label class="form-label">Password Baru</label><input type="password" name="new" class="form-control" minlength="6" required></div>
        <div class="mb-3"><label class="form-label">Ulangi Password Baru</label><input type="password" name="confirm" class="form-control" required></div>
        <button class="btn btn-primary">Simpan</button>
    </form>
</div></div>
<?php require __DIR__ . '/includes/footer.php'; ?>
