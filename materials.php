<?php
require_once __DIR__ . '/includes/bootstrap.php';
$u = require_role('admin', 'inventory', 'procurement');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $act = $_POST['act'] ?? '';
    $id = (int)($_POST['id'] ?? 0);
    if ($act === 'save') {
        $code = trim($_POST['code'] ?? '');
        $name = trim($_POST['name'] ?? '');
        $unit = trim($_POST['unit'] ?? 'pcs') ?: 'pcs';
        $maxUse = max(0, (float)($_POST['max_use_per_ticket'] ?? 0));
        $active = isset($_POST['is_active']) ? 1 : 0;
        if ($code === '' || $name === '') {
            flash('danger', 'Kode dan nama wajib diisi.');
        } elseif (q('SELECT 1 FROM materials WHERE code=? AND id<>?', [$code, $id])->fetch()) {
            flash('danger', 'Kode material sudah dipakai.');
        } elseif ($id) {
            q('UPDATE materials SET code=?, name=?, unit=?, max_use_per_ticket=?, is_active=? WHERE id=?',
                [$code, $name, $unit, $maxUse, $active, $id]);
            flash('success', 'Material diperbarui.');
        } else {
            q('INSERT INTO materials (code, name, unit, stock, max_use_per_ticket, is_active) VALUES (?,?,?,?,?,?)',
                [$code, $name, $unit, max(0, (float)($_POST['stock'] ?? 0)), $maxUse, $active]);
            flash('success', 'Material ditambahkan.');
        }
    } elseif ($act === 'adjust') {
        $delta = (float)($_POST['delta'] ?? 0);
        $m = q('SELECT * FROM materials WHERE id=?', [$id])->fetch();
        if (!$m || $delta == 0 || (float)$m['stock'] + $delta < 0) {
            flash('danger', 'Penyesuaian stok tidak valid.');
        } else {
            q('UPDATE materials SET stock = stock + ? WHERE id=?', [$delta, $id]);
            flash('success', 'Stok ' . $m['name'] . ' ' . ($delta > 0 ? 'ditambah ' : 'dikurangi ') . fmt_qty(abs($delta)) . '.');
        }
    }
    redirect('materials.php');
}

$rows = q("SELECT m.*,
        (SELECT COALESCE(SUM(tm.qty_issued - COALESCE(tm.qty_returned,0)),0) FROM ticket_materials tm JOIN tickets t ON t.id=tm.ticket_id
          WHERE tm.material_id=m.id AND tm.qty_returned IS NULL AND tm.qty_issued > 0 AND tm.is_direct=0) AS at_vendor
    FROM materials m ORDER BY m.is_active DESC, m.name")->fetchAll();

$pageTitle = 'Material';
require __DIR__ . '/includes/header.php';
?>
<?= page_head('Material / Stok', 'box-seam', 'Kelola material, stok gudang, dan batas pemakaian per tiket',
    '<button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#matModal" onclick="editMat({is_active:1})"><i class="bi bi-plus-circle"></i> Tambah Material</button>') ?>
<div class="card"><div class="table-responsive">
    <table class="table table-hover mb-0 small">
        <thead class="table-light"><tr><th>Kode</th><th>Nama</th><th>Satuan</th><th class="text-end">Stok Gudang</th><th class="text-end">Sedang di Vendor</th><th class="text-end">Batas/Tiket</th><th>Status</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($rows as $m): ?>
            <tr class="<?= $m['is_active'] ? '' : 'text-muted' ?>">
                <td><?= e($m['code']) ?></td><td class="fw-semibold"><?= e($m['name']) ?></td><td><?= e($m['unit']) ?></td>
                <td class="text-end"><?= fmt_qty($m['stock']) ?></td>
                <td class="text-end"><?= fmt_qty($m['at_vendor']) ?></td>
                <td class="text-end"><?= (float)$m['max_use_per_ticket'] > 0
                    ? '<span class="badge bg-warning text-dark">maks ' . fmt_qty((float)$m['max_use_per_ticket']) . '</span>'
                    : '<span class="text-muted">&infin;</span>' ?></td>
                <td><?= $m['is_active'] ? '<span class="badge bg-success">Aktif</span>' : '<span class="badge bg-secondary">Nonaktif</span>' ?></td>
                <td class="text-end text-nowrap">
                    <button class="btn btn-sm btn-outline-secondary" data-bs-toggle="modal" data-bs-target="#matModal" onclick='editMat(<?= e(json_encode($m)) ?>)'><i class="bi bi-pencil"></i></button>
                    <form method="post" class="d-inline-flex gap-1">
                        <?= csrf_field() ?><input type="hidden" name="act" value="adjust"><input type="hidden" name="id" value="<?= $m['id'] ?>">
                        <input type="number" step="0.01" name="delta" class="form-control form-control-sm" style="width:90px" placeholder="+/- stok" required>
                        <button class="btn btn-sm btn-outline-primary" title="Sesuaikan stok"><i class="bi bi-plus-slash-minus"></i></button>
                    </form>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div></div>

<div class="modal fade" id="matModal" tabindex="-1"><div class="modal-dialog"><form method="post" class="modal-content">
    <?= csrf_field() ?><input type="hidden" name="act" value="save"><input type="hidden" name="id" id="m_id">
    <div class="modal-header"><h5 class="modal-title">Material</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
    <div class="modal-body">
        <div class="row g-2">
            <div class="col-4"><label class="form-label">Kode *</label><input name="code" id="m_code" class="form-control" required></div>
            <div class="col-8"><label class="form-label">Nama *</label><input name="name" id="m_name" class="form-control" required></div>
            <div class="col-6"><label class="form-label">Satuan</label><input name="unit" id="m_unit" class="form-control" list="units">
                <datalist id="units"><option>pcs</option><option>meter</option><option>unit</option><option>batang</option><option>roll</option></datalist></div>
            <div class="col-6" id="stockBox"><label class="form-label">Stok Awal</label><input type="number" step="0.01" min="0" name="stock" class="form-control" value="0"></div>
            <div class="col-6"><label class="form-label">Batas Pemakaian / Tiket</label>
                <input type="number" step="0.01" min="0" name="max_use_per_ticket" id="m_max" class="form-control" value="0">
                <div class="form-text">0 = tanpa batas. Contoh: kabel 500 meter &rarr; panjang max 3.</div>
            </div>
        </div>
        <div class="form-check mt-3"><input class="form-check-input" type="checkbox" name="is_active" id="m_active"><label class="form-check-label" for="m_active">Aktif</label></div>
    </div>
    <div class="modal-footer"><button class="btn btn-primary">Simpan</button></div>
</form></div></div>
<script>
function editMat(m) {
    document.getElementById('m_id').value = m.id || '';
    document.getElementById('m_code').value = m.code || '';
    document.getElementById('m_name').value = m.name || '';
    document.getElementById('m_unit').value = m.unit || 'pcs';
    document.getElementById('m_max').value = m.max_use_per_ticket != null ? m.max_use_per_ticket : 0;
    document.getElementById('m_active').checked = m.is_active == 1;
    document.getElementById('stockBox').style.display = m.id ? 'none' : '';
}
</script>
<?php require __DIR__ . '/includes/footer.php'; ?>
