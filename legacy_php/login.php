<?php
require_once __DIR__ . '/includes/bootstrap.php';
if (current_user()) redirect('dashboard.php');

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $user = q('SELECT * FROM users WHERE username=? AND is_active=1', [trim($_POST['username'] ?? '')])->fetch();
    if ($user && password_verify($_POST['password'] ?? '', $user['password_hash'])) {
        session_regenerate_id(true);
        $_SESSION['uid'] = $user['id'];
        redirect($user['role'] === 'procurement' ? 'procurement_dashboard.php' : 'dashboard.php');
    }
    $error = 'Username atau password salah.';
}
$pageTitle = 'Login';
require __DIR__ . '/includes/header.php';
?>
<div class="row justify-content-center">
    <div class="col-sm-8 col-md-5 col-lg-4">
        <div class="text-center mb-4">
            <i class="bi bi-tools display-4 text-primary"></i>
            <h4 class="mt-2"><?= e(APP_NAME) ?></h4>
        </div>
        <div class="card"><div class="card-body p-4">
            <?php if ($error): ?><div class="alert alert-danger"><?= e($error) ?></div><?php endif; ?>
            <form method="post">
                <?= csrf_field() ?>
                <div class="mb-3">
                    <label class="form-label">Username</label>
                    <input name="username" class="form-control" required autofocus value="<?= e($_POST['username'] ?? '') ?>">
                </div>
                <div class="mb-3">
                    <label class="form-label">Password</label>
                    <input name="password" type="password" class="form-control" required>
                </div>
                <button class="btn btn-primary w-100">Masuk</button>
            </form>
        </div></div>

        <?php
        // Akun demo untuk expedite testing antar role
        $quickAccounts = [
            ['admin', 'admin123', 'Administrator', 'bi-shield-lock'],
            ['noc', 'noc123', 'NOC', 'bi-broadcast'],
            ['procurement', 'procurement123', 'Procurement', 'bi-clipboard-data'],
            ['inventory', 'inventory123', 'Inventory', 'bi-box-seam'],
            ['foc', 'foc123', 'FOC', 'bi-clipboard-check'],
            ['nam', 'nam123', 'NAM', 'bi-diagram-3'],
            ['vendor1', 'vendor123', 'Vendor 1', 'bi-tools'],
            ['vendor2', 'vendor123', 'Vendor 2', 'bi-tools'],
            ['vendor3', 'vendor123', 'Vendor 3', 'bi-tools'],
            ['vendor4', 'vendor123', 'Vendor 4', 'bi-tools'],
        ];
        ?>
        <div class="card mt-3">
            <div class="card-body p-3">
                <div class="d-flex align-items-center mb-2">
                    <i class="bi bi-lightning-charge-fill text-warning me-2"></i>
                    <span class="fw-semibold">Login Cepat (Testing)</span>
                </div>
                <p class="small text-muted mb-2">Klik role untuk langsung masuk tanpa mengetik.</p>
                <div class="row g-2">
                    <?php foreach ($quickAccounts as [$un, $pw, $label, $icon]): ?>
                        <div class="col-6">
                            <button type="button" class="btn btn-outline-secondary btn-sm w-100 text-start quick-login"
                                    data-username="<?= e($un) ?>" data-password="<?= e($pw) ?>">
                                <i class="bi <?= e($icon) ?> me-1"></i><?= e($label) ?>
                                <span class="d-block small text-muted fw-normal"><?= e($un) ?></span>
                            </button>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
    </div>
</div>
<script>
document.querySelectorAll('.quick-login').forEach(function (btn) {
    btn.addEventListener('click', function () {
        var form = document.querySelector('form[method="post"]');
        form.querySelector('input[name="username"]').value = btn.dataset.username;
        form.querySelector('input[name="password"]').value = btn.dataset.password;
        form.submit();
    });
});
</script>
<?php require __DIR__ . '/includes/footer.php'; ?>
