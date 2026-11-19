<?php
/**
 * Manajemen Region (wilayah).
 * 1 region berisi beberapa kota; 1 vendor bisa terdaftar di beberapa region,
 * dan tiap pasangan vendor-region punya batas limit workload sendiri (0 = tanpa batas).
 */
require_once __DIR__ . '/includes/bootstrap.php';
$u = require_role('admin');

$regions = q('SELECT * FROM regions ORDER BY name')->fetchAll();
$cities = q('SELECT * FROM cities ORDER BY name')->fetchAll();
$vendors = q('SELECT id, name, city_id, status FROM vendors ORDER BY name')->fetchAll();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $act = $_POST['act'] ?? '';
    $rid = (int)($_POST['region_id'] ?? 0);

    if ($act === 'save_region') {
        $name = trim($_POST['name'] ?? '');
        $code = trim($_POST['code'] ?? '');
        $desc = trim($_POST['description'] ?? '');
        $id = (int)($_POST['id'] ?? 0);
        if ($name === '') {
            flash('danger', 'Nama region wajib diisi.');
        } elseif (q('SELECT 1 FROM regions WHERE name=? AND id<>?', [$name, $id])->fetch()) {
            flash('danger', 'Nama region sudah dipakai.');
        } elseif ($id) {
            q('UPDATE regions SET name=?, code=?, description=? WHERE id=?', [$name, $code ?: null, $desc ?: null, $id]);
            flash('success', 'Region diperbarui.');
        } else {
            q('INSERT INTO regions (name, code, description) VALUES (?,?,?)', [$name, $code ?: null, $desc ?: null]);
            flash('success', 'Region ditambahkan.');
        }
    } elseif ($act === 'delete_region') {
        $used = (int)q('SELECT (SELECT COUNT(*) FROM cities WHERE region_id=?) + (SELECT COUNT(*) FROM vendor_regions WHERE region_id=?)',
            [$rid, $rid])->fetchColumn();
        if ($used) {
            flash('danger', 'Region masih dipakai kota/vendor, tidak bisa dihapus.');
        } else {
            q('DELETE FROM regions WHERE id=?', [$rid]);
            flash('success', 'Region dihapus.');
        }
    } elseif ($act === 'assign_city') {
        $cid = (int)($_POST['city_id'] ?? 0);
        if (!$cid || !$rid) {
            flash('danger', 'Kota dan region wajib dipilih.');
        } else {
            q('UPDATE cities SET region_id=? WHERE id=?', [$rid, $cid]);
            flash('success', 'Kota dipindahkan ke region tersebut.');
        }
    } elseif ($act === 'remove_city') {
        q('UPDATE cities SET region_id=NULL WHERE id=? AND region_id=?', [(int)($_POST['city_id'] ?? 0), $rid]);
        flash('success', 'Kota dikeluarkan dari region.');
    } elseif ($act === 'assign_vendor') {
        $vid = (int)($_POST['vendor_id'] ?? 0);
        $limit = max(0, (int)($_POST['workload_limit'] ?? DEFAULT_WORKLOAD_LIMIT));
        if (!$vid || !$rid) {
            flash('danger', 'Vendor dan region wajib dipilih.');
        } else {
            q('INSERT INTO vendor_regions (vendor_id, region_id, workload_limit) VALUES (?,?,?)
                ON DUPLICATE KEY UPDATE workload_limit=VALUES(workload_limit)', [$vid, $rid, $limit]);
            flash('success', 'Vendor terdaftar di region dengan limit workload ' . ($limit > 0 ? $limit : 'tanpa batas') . '.');
        }
    } elseif ($act === 'update_limit') {
        $vid = (int)($_POST['vendor_id'] ?? 0);
        $limit = max(0, (int)($_POST['workload_limit'] ?? DEFAULT_WORKLOAD_LIMIT));
        q('UPDATE vendor_regions SET workload_limit=? WHERE vendor_id=? AND region_id=?', [$limit, $vid, $rid]);
        flash('success', 'Limit workload vendor di region ini diperbarui.');
    } elseif ($act === 'remove_vendor') {
        q('DELETE FROM vendor_regions WHERE vendor_id=? AND region_id=?', [(int)($_POST['vendor_id'] ?? 0), $rid]);
        flash('success', 'Vendor dikeluarkan dari region.');
    }
    redirect('regions.php');
}

// Data region lengkap: kota + vendor + beban kerja per region
$detail = [];
foreach ($regions as $r) {
    $rid = (int)$r['id'];
    $rc = q('SELECT id, name FROM cities WHERE region_id=? ORDER BY name', [$rid])->fetchAll();
    $rv = q("SELECT vr.vendor_id, vr.workload_limit, v.name, v.status,
                    (SELECT COUNT(*) FROM tickets t JOIN cities c ON c.id=t.city_id
                      WHERE t.vendor_id=vr.vendor_id AND c.region_id=vr.region_id
                        AND t.status IN ('assigned','on_progress','revision')) AS used
             FROM vendor_regions vr JOIN vendors v ON v.id=vr.vendor_id
             WHERE vr.region_id=? ORDER BY v.name", [$rid])->fetchAll();
    $detail[$rid] = ['cities' => $rc, 'vendors' => $rv];
}

$pageTitle = 'Region';
require __DIR__ . '/includes/header.php';
?>
<?= page_head('Region / Wilayah', 'geo-alt', 'Pengelompokan kota & batas limit workload vendor per region') ?>
<p class="text-muted small">
    Ticketing berbasis <b>region</b>: satu ticket Service hanya akan diteruskan ke vendor yang terdaftar
    di region kota tersebut. Batas <b>Limit Workload</b> dihitung per vendor <i>dan</i> per region,
    sehingga satu vendor bisa punya kapasitas berbeda di tiap region (contoh: Region Central limit 5, Region East tanpa batas).
</p>

<div class="row g-3">
    <div class="col-md-4">
        <div class="card card-body">
            <h6 class="mb-2"><i class="bi bi-plus-circle"></i> Tambah Region</h6>
            <form method="post"><?= csrf_field() ?>
                <input type="hidden" name="act" value="save_region">
                <div class="mb-2"><label class="form-label">Nama Region *</label><input name="name" class="form-control" required></div>
                <div class="mb-2"><label class="form-label">Kode</label><input name="code" class="form-control" placeholder="CENTRAL / EAST / WEST"></div>
                <div class="mb-2"><label class="form-label">Deskripsi</label><textarea name="description" class="form-control" rows="2"></textarea></div>
                <button class="btn btn-primary w-100">Simpan Region</button>
            </form>
        </div>
        <div class="card card-body mt-3">
            <h6 class="mb-2">Kota tanpa region</h6>
            <?php
            $noRegion = array_values(array_filter($cities, fn($c) => !$c['region_id']));
            if (!$noRegion): ?><div class="text-muted small">Semua kota sudah punya region.</div><?php endif; ?>
            <?php foreach ($noRegion as $c): ?>
                <form method="post" class="d-flex gap-1 mb-1"><?= csrf_field() ?>
                    <input type="hidden" name="act" value="assign_city"><input type="hidden" name="city_id" value="<?= $c['id'] ?>">
                    <select name="region_id" class="form-select form-select-sm">
                        <?php foreach ($regions as $r): ?><option value="<?= $r['id'] ?>"><?= e($r['name']) ?></option><?php endforeach; ?>
                    </select>
                    <button class="btn btn-sm btn-outline-secondary"><i class="bi bi-check"></i></button>
                </form>
            <?php endforeach; ?>
        </div>
    </div>

    <div class="col-md-8">
        <?php foreach ($regions as $r): $rid = (int)$r['id']; $d = $detail[$rid]; ?>
        <div class="card mb-3">
            <div class="card-header d-flex justify-content-between align-items-center">
                <div><b><?= e($r['name']) ?></b>
                    <?php if ($r['code']): ?><span class="badge bg-secondary ms-1"><?= e($r['code']) ?></span><?php endif; ?>
                    <div class="small text-muted"><?= e($r['description'] ?? '') ?></div>
                </div>
                <div class="text-nowrap">
                    <button class="btn btn-sm btn-outline-secondary" data-bs-toggle="modal" data-bs-target="#editRegionModal"
                        onclick='editRegion(<?= e(json_encode($r)) ?>)'><i class="bi bi-pencil"></i></button>
                    <form method="post" class="d-inline" data-confirm="Hapus region ini?"><?= csrf_field() ?>
                        <input type="hidden" name="act" value="delete_region"><input type="hidden" name="region_id" value="<?= $rid ?>">
                        <button class="btn btn-sm btn-outline-danger"><i class="bi bi-trash"></i></button></form>
                </div>
            </div>
            <div class="card-body">
                <div class="row g-3">
                    <div class="col-md-6">
                        <h6 class="small text-uppercase text-muted">Kota dalam region ini</h6>
                        <?php if (!$d['cities']): ?><div class="small text-muted fst-italic mb-1">Belum ada kota.</div><?php endif; ?>
                        <?php foreach ($d['cities'] as $c): ?>
                            <div class="d-flex justify-content-between align-items-center border-bottom py-1">
                                <span class="small"><?= e($c['name']) ?></span>
                                <form method="post" class="d-inline" data-confirm="Keluarkan kota ini dari region?"><?= csrf_field() ?>
                                    <input type="hidden" name="act" value="remove_city"><input type="hidden" name="region_id" value="<?= $rid ?>">
                                    <input type="hidden" name="city_id" value="<?= $c['id'] ?>">
                                    <button class="btn btn-sm btn-outline-danger py-0 px-1"><i class="bi bi-x"></i></button></form>
                            </div>
                        <?php endforeach; ?>
                        <form method="post" class="mt-2 d-flex gap-1"><?= csrf_field() ?>
                            <input type="hidden" name="act" value="assign_city"><input type="hidden" name="region_id" value="<?= $rid ?>">
                            <select name="city_id" class="form-select form-select-sm">
                                <option value="">+ kota...</option>
                                <?php foreach ($cities as $c): ?>
                                    <?php if ((int)$c['region_id'] === $rid) continue; ?>
                                    <option value="<?= $c['id'] ?>"><?= e($c['name']) ?><?= $c['region_id'] ? ' (pindah dari ' . e(region_name((int)$c['region_id'])) . ')' : '' ?></option>
                                <?php endforeach; ?>
                            </select>
                            <button class="btn btn-sm btn-outline-secondary">Simpan</button>
                        </form>
                    </div>

                    <div class="col-md-6">
                        <h6 class="small text-uppercase text-muted">Vendor &amp; limit workload</h6>
                        <?php if (!$d['vendors']): ?><div class="small text-muted fst-italic mb-1">Belum ada vendor di region ini.</div><?php endif; ?>
                        <?php foreach ($d['vendors'] as $v): ?>
                            <?php $limit = (int)$v['workload_limit']; $used = (int)$v['used']; ?>
                            <div class="border-bottom py-1">
                                <div class="d-flex justify-content-between align-items-center">
                                    <span class="small fw-semibold"><?= e($v['name']) ?></span>
                                    <?= vendor_badge($v['status']) ?>
                                    <form method="post" class="d-inline"><?= csrf_field() ?>
                                        <input type="hidden" name="act" value="remove_vendor"><input type="hidden" name="region_id" value="<?= $rid ?>">
                                        <input type="hidden" name="vendor_id" value="<?= $v['vendor_id'] ?>">
                                        <button class="btn btn-sm btn-outline-danger py-0 px-1"><i class="bi bi-x"></i></button></form>
                                </div>
                                <form method="post" class="d-flex gap-1 align-items-center mt-1"><?= csrf_field() ?>
                                    <input type="hidden" name="act" value="update_limit"><input type="hidden" name="region_id" value="<?= $rid ?>">
                                    <input type="hidden" name="vendor_id" value="<?= $v['vendor_id'] ?>">
                                    <input type="number" name="workload_limit" value="<?= $limit ?>" min="0" max="99" class="form-control form-control-sm" style="width:80px">
                                    <span class="small text-muted">batas</span>
                                    <span class="small <?= ($limit > 0 && $used >= $limit) ? 'text-danger fw-bold' : 'text-muted' ?>">
                                        dipakai <?= $used ?><?= $limit > 0 ? '/' . $limit : '/∞' ?></span>
                                    <button class="btn btn-sm btn-outline-secondary">Simpan</button>
                                </form>
                            </div>
                        <?php endforeach; ?>
                        <form method="post" class="mt-2 d-flex gap-1"><?= csrf_field() ?>
                            <input type="hidden" name="act" value="assign_vendor"><input type="hidden" name="region_id" value="<?= $rid ?>">
                            <select name="vendor_id" class="form-select form-select-sm">
                                <option value="">+ vendor...</option>
                                <?php foreach ($vendors as $v): ?><option value="<?= $v['id'] ?>"><?= e($v['name']) ?></option><?php endforeach; ?>
                            </select>
                            <input type="number" name="workload_limit" value="<?= DEFAULT_WORKLOAD_LIMIT ?>" min="0" max="99" class="form-control form-control-sm" style="width:70px" title="0 = tanpa batas">
                            <button class="btn btn-sm btn-outline-secondary">Tambah</button>
                        </form>
                        <div class="form-text">Isi limit 0 = tanpa batas untuk region ini.</div>
                    </div>
                </div>
            </div>
        </div>
        <?php endforeach; ?>
        <?php if (!$regions): ?><div class="card card-body text-muted">Belum ada region. Tambahkan region terlebih dahulu.</div><?php endif; ?>
    </div>
</div>

<div class="modal fade" id="editRegionModal" tabindex="-1"><div class="modal-dialog"><form method="post" class="modal-content">
    <?= csrf_field() ?><input type="hidden" name="act" value="save_region"><input type="hidden" name="id" id="r_id">
    <div class="modal-header"><h5 class="modal-title">Edit Region</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
    <div class="modal-body">
        <div class="mb-2"><label class="form-label">Nama Region *</label><input name="name" id="r_name" class="form-control" required></div>
        <div class="mb-2"><label class="form-label">Kode</label><input name="code" id="r_code" class="form-control"></div>
        <div class="mb-2"><label class="form-label">Deskripsi</label><textarea name="description" id="r_description" class="form-control" rows="2"></textarea></div>
    </div>
    <div class="modal-footer"><button class="btn btn-primary">Simpan</button></div>
</form></div></div>
<script>
function editRegion(r) {
    document.getElementById('r_id').value = r.id;
    document.getElementById('r_name').value = r.name || '';
    document.getElementById('r_code').value = r.code || '';
    document.getElementById('r_description').value = r.description || '';
}
</script>
<?php require __DIR__ . '/includes/footer.php'; ?>
