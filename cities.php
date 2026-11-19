<?php
require_once __DIR__ . '/includes/bootstrap.php';
$u = require_role('admin');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $name = trim($_POST['name'] ?? '');
    $id = (int)($_POST['id'] ?? 0);
    $regionId = (int)($_POST['region_id'] ?? 0);
    $isPrimary = isset($_POST['is_primary']) ? 1 : 0;
    if (($_POST['act'] ?? '') === 'delete') {
        $used = q('SELECT (SELECT COUNT(*) FROM vendors WHERE city_id=?) + (SELECT COUNT(*) FROM tickets WHERE city_id=?)', [$id, $id])->fetchColumn();
        if ($used) flash('danger', 'Kota masih dipakai vendor/tiket, tidak bisa dihapus.');
        else { q('DELETE FROM cities WHERE id=?', [$id]); flash('success', 'Kota dihapus.'); }
    } elseif ($name === '') {
        flash('danger', 'Nama kota wajib diisi.');
    } elseif (q('SELECT 1 FROM cities WHERE name=? AND id<>?', [$name, $id])->fetch()) {
        flash('danger', 'Kota sudah ada.');
    } elseif ($id) {
        q('UPDATE cities SET name=?, region_id=?, is_primary=? WHERE id=?', [$name, $regionId ?: null, $isPrimary, $id]);
        flash('success', 'Kota diperbarui.');
    } else {
        q('INSERT INTO cities (name, region_id, is_primary) VALUES (?,?,?)', [$name, $regionId ?: null, $isPrimary]);
        flash('success', 'Kota ditambahkan.');
    }
    redirect('cities.php');
}
$regions = q('SELECT * FROM regions ORDER BY name')->fetchAll();
$rows = q("SELECT c.*, r.name region_name, (SELECT COUNT(*) FROM vendors v WHERE v.city_id=c.id) vendors,
           (SELECT COUNT(*) FROM vendors v WHERE v.city_id=c.id AND v.status='available') avail,
           (SELECT COUNT(*) FROM vendors v WHERE v.city_id=c.id AND v.vendor_type='ms') ms_vendors,
           (SELECT COUNT(*) FROM tickets t WHERE t.city_id=c.id) tickets FROM cities c
           LEFT JOIN regions r ON r.id=c.region_id ORDER BY r.name, c.is_primary DESC, c.name")->fetchAll();
$pageTitle = 'Kota';
require __DIR__ . '/includes/header.php';
?>
<?= page_head('Kota / Area', 'buildings', 'Daftar kota yang dilayani beserta regionnya') ?>
<div class="row g-3">
    <div class="col-md-4"><div class="card card-body">
        <form method="post"><?= csrf_field() ?>
            <label class="form-label">Tambah Kota</label>
            <div class="input-group"><input name="name" class="form-control" required><button class="btn btn-primary">Simpan</button></div>
            <div class="form-text">Nama kota harus sama dengan yang dikirim sistem NOC (tidak case-sensitive).</div>
            <div class="mt-2"><label class="form-label">Region</label>
                <select name="region_id" class="form-select form-select-sm">
                    <option value="">- tanpa region -</option>
                    <?php foreach ($regions as $r): ?><option value="<?= $r['id'] ?>"><?= e($r['name']) ?></option><?php endforeach; ?>
                </select>
                <div class="form-text">Kota menentukan vendor mana yang bisa menerima tiket (lihat halaman Region).</div>
            </div>
            <div class="form-check mt-2">
                <input class="form-check-input" type="checkbox" name="is_primary" value="1" id="newIsPrimary">
                <label class="form-check-label" for="newIsPrimary">Kota Utama (ibu kota wilayah)</label>
            </div>
        </form>
    </div></div>
    <div class="col-md-8"><div class="card"><table class="table mb-0 small">
        <thead class="table-light"><tr><th>Kota</th><th>Region</th><th class="text-center">Kota Utama</th><th class="text-center">Vendor</th><th class="text-center">Vendor MS</th><th class="text-center">Available</th><th class="text-center">Tiket</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($rows as $c): ?>
            <tr>
                <td><form method="post" class="d-flex gap-1"><?= csrf_field() ?><input type="hidden" name="id" value="<?= $c['id'] ?>">
                    <input name="name" value="<?= e($c['name']) ?>" class="form-control form-control-sm"><button class="btn btn-sm btn-outline-secondary"><i class="bi bi-check"></i></button></form></td>
                <td><form method="post" class="d-flex gap-1"><?= csrf_field() ?><input type="hidden" name="id" value="<?= $c['id'] ?>">
                    <input type="hidden" name="name" value="<?= e($c['name']) ?>">
                    <select name="region_id" class="form-select form-select-sm">
                        <option value="">- tanpa region -</option>
                        <?php foreach ($regions as $r): ?>
                            <option value="<?= $r['id'] ?>" <?= (int)$c['region_id'] === (int)$r['id'] ? 'selected' : '' ?>><?= e($r['name']) ?></option>
                        <?php endforeach; ?>
                    </select><button class="btn btn-sm btn-outline-secondary"><i class="bi bi-check"></i></button></form></td>
                <td class="text-center"><form method="post" class="d-inline"><?= csrf_field() ?><input type="hidden" name="id" value="<?= $c['id'] ?>">
                    <input type="hidden" name="name" value="<?= e($c['name']) ?>">
                    <input type="hidden" name="region_id" value="<?= (int)($c['region_id'] ?? 0) ?>">
                    <input type="hidden" name="is_primary" value="<?= $c['is_primary'] ? 0 : 1 ?>">
                    <button class="btn btn-sm btn-<?= $c['is_primary'] ? 'primary' : 'outline-secondary' ?>" title="<?= $c['is_primary'] ? 'Kota utama' : 'Jadikan kota utama' ?>">
                        <i class="bi bi-<?= $c['is_primary'] ? 'star-fill' : 'star' ?>"></i></button></form></td>
                <td class="text-center"><?= $c['vendors'] ?></td>
                <td class="text-center"><?= $c['ms_vendors'] ? '<span class="badge bg-primary text-dark">' . $c['ms_vendors'] . '</span>' : '-' ?></td>
                <td class="text-center <?= $c['avail'] ? '' : 'text-danger fw-bold' ?>"><?= $c['avail'] ?></td>
                <td class="text-center"><?= $c['tickets'] ?></td>
                <td class="text-end"><form method="post" data-confirm="Hapus kota?"><?= csrf_field() ?><input type="hidden" name="act" value="delete"><input type="hidden" name="id" value="<?= $c['id'] ?>">
                    <button class="btn btn-sm btn-outline-danger"><i class="bi bi-trash"></i></button></form></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table></div></div>
</div>
<?php require __DIR__ . '/includes/footer.php'; ?>
