<?php
require_once __DIR__ . '/includes/bootstrap.php';
$me = require_role('admin');
$vendors = q('SELECT v.id, v.name, c.name city FROM vendors v JOIN cities c ON c.id=v.city_id ORDER BY c.name, v.name')->fetchAll();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $id = (int)($_POST['id'] ?? 0);
    $username = trim($_POST['username'] ?? '');
    $fullName = trim($_POST['full_name'] ?? '');
    $role = $_POST['role'] ?? '';
    $vendorId = $role === 'vendor' ? (int)($_POST['vendor_id'] ?? 0) : null;
    $active = isset($_POST['is_active']) ? 1 : 0;
    $pass = $_POST['password'] ?? '';

    if ($username === '' || $fullName === '' || !isset(ROLES[$role])) {
        flash('danger', 'Username, nama, dan role wajib diisi.');
    } elseif ($role === 'vendor' && !$vendorId) {
        flash('danger', 'User vendor wajib dihubungkan ke perusahaan vendor.');
    } elseif (q('SELECT 1 FROM users WHERE username=? AND id<>?', [$username, $id])->fetch()) {
        flash('danger', 'Username sudah dipakai.');
    } elseif (!$id && strlen($pass) < 6) {
        flash('danger', 'Password minimal 6 karakter.');
    } elseif ($id === (int)$me['id'] && (!$active || $role !== 'admin')) {
        flash('danger', 'Tidak bisa menonaktifkan / mengubah role akun sendiri.');
    } else {
        if ($id) {
            q('UPDATE users SET username=?, full_name=?, role=?, vendor_id=?, is_active=? WHERE id=?', [$username, $fullName, $role, $vendorId, $active, $id]);
            if ($pass !== '') {
                if (strlen($pass) < 6) { flash('danger', 'Password minimal 6 karakter.'); redirect('users.php'); }
                q('UPDATE users SET password_hash=? WHERE id=?', [password_hash($pass, PASSWORD_DEFAULT), $id]);
            }
            flash('success', 'User diperbarui.');
        } else {
            q('INSERT INTO users (username, password_hash, full_name, role, vendor_id, is_active) VALUES (?,?,?,?,?,?)',
                [$username, password_hash($pass, PASSWORD_DEFAULT), $fullName, $role, $vendorId, $active]);
            flash('success', 'User ditambahkan.');
        }
    }
    redirect('users.php');
}
$users = q("SELECT u.*, v.name vendor_name FROM users u LEFT JOIN vendors v ON v.id=u.vendor_id ORDER BY FIELD(role,'admin','noc','nam','procurement','inventory','foc','vendor'), u.full_name")->fetchAll();
$pageTitle = 'User';
require __DIR__ . '/includes/header.php';
?>
<?= page_head('User', 'people', 'Akun pengguna sistem dan haknya per role',
    '<button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#userModal" onclick="editUser({is_active:1, role:\'vendor\'})"><i class="bi bi-plus-circle"></i> Tambah User</button>') ?>
<div class="card"><div class="table-responsive"><table class="table table-hover mb-0 small">
    <thead class="table-light"><tr><th>Username</th><th>Nama</th><th>Role</th><th>Vendor</th><th>Status</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($users as $x): ?>
        <tr class="<?= $x['is_active'] ? '' : 'text-muted' ?>">
            <td class="fw-semibold"><?= e($x['username']) ?></td><td><?= e($x['full_name']) ?></td>
            <td><span class="badge bg-secondary"><?= e(ROLES[$x['role']]) ?></span></td>
            <td><?= e($x['vendor_name'] ?? '-') ?></td>
            <td><?= $x['is_active'] ? '<span class="badge bg-success">Aktif</span>' : '<span class="badge bg-secondary">Nonaktif</span>' ?></td>
            <td class="text-end"><button class="btn btn-sm btn-outline-secondary" data-bs-toggle="modal" data-bs-target="#userModal"
                onclick='editUser(<?= e(json_encode(['id' => $x['id'], 'username' => $x['username'], 'full_name' => $x['full_name'], 'role' => $x['role'], 'vendor_id' => $x['vendor_id'], 'is_active' => $x['is_active']])) ?>)'><i class="bi bi-pencil"></i></button></td>
        </tr>
    <?php endforeach; ?>
    </tbody>
</table></div></div>

<div class="modal fade" id="userModal" tabindex="-1"><div class="modal-dialog"><form method="post" class="modal-content">
    <?= csrf_field() ?><input type="hidden" name="id" id="u_id">
    <div class="modal-header"><h5 class="modal-title">User</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
    <div class="modal-body">
        <div class="mb-2"><label class="form-label">Username *</label><input name="username" id="u_username" class="form-control" required></div>
        <div class="mb-2"><label class="form-label">Nama Lengkap *</label><input name="full_name" id="u_full_name" class="form-control" required></div>
        <div class="mb-2"><label class="form-label">Role *</label><select name="role" id="u_role" class="form-select" onchange="toggleVendor()">
            <?php foreach (ROLES as $k => $l): ?><option value="<?= $k ?>"><?= $l ?></option><?php endforeach; ?></select></div>
        <div class="mb-2" id="vendorBox"><label class="form-label">Perusahaan Vendor *</label><select name="vendor_id" id="u_vendor_id" class="form-select">
            <option value="">- pilih -</option><?php foreach ($vendors as $v): ?><option value="<?= $v['id'] ?>"><?= e($v['name'] . ' (' . $v['city'] . ')') ?></option><?php endforeach; ?></select></div>
        <div class="mb-2"><label class="form-label">Password <span id="passHint" class="text-muted small"></span></label><input type="password" name="password" class="form-control" autocomplete="new-password"></div>
        <div class="form-check"><input class="form-check-input" type="checkbox" name="is_active" id="u_active"><label class="form-check-label" for="u_active">Aktif</label></div>
    </div>
    <div class="modal-footer"><button class="btn btn-primary">Simpan</button></div>
</form></div></div>
<script>
function editUser(x) {
    ['id', 'username', 'full_name', 'role', 'vendor_id'].forEach(function (k) { document.getElementById('u_' + k).value = x[k] || ''; });
    document.getElementById('u_active').checked = x.is_active == 1;
    document.getElementById('passHint').textContent = x.id ? '(kosongkan jika tidak diganti)' : '* min 6 karakter';
    toggleVendor();
}
function toggleVendor() { document.getElementById('vendorBox').style.display = document.getElementById('u_role').value === 'vendor' ? '' : 'none'; }
</script>
<?php require __DIR__ . '/includes/footer.php'; ?>
