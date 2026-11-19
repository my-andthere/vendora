<?php
/**
 * Master Option — daftar pilihan yang dipakai di form laporan vendor:
 *   - damage_type      : Jenis Kerusakan
 *   - repair_action    : Tindakan Perbaikan
 *
 * Role: Admin & Procurement boleh menambah / menonaktifkan / menghapus opsi.
 */
require_once __DIR__ . '/includes/bootstrap.php';
$u = require_role('admin', 'procurement');
$canManage = true;

const OPTION_CATEGORIES = [
    'damage_type'   => ['Jenis Kerusakan', 'bi-exclamation-diamond'],
    'repair_action' => ['Tindakan Perbaikan', 'bi-tools'],
];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $canManage) {
    csrf_check();
    $act = $_POST['act'] ?? '';

    if ($act === 'add_option') {
        $cat = $_POST['category'] ?? '';
        $name = trim($_POST['name'] ?? '');
        $desc = trim($_POST['description'] ?? '');
        if (!isset(OPTION_CATEGORIES[$cat])) {
            flash('danger', 'Kategori master tidak valid.');
        } elseif ($name === '') {
            flash('danger', 'Nama opsi wajib diisi.');
        } else {
            $dup = q('SELECT id FROM master_options WHERE category=? AND name=?', [$cat, $name])->fetch();
            if ($dup) {
                q('UPDATE master_options SET is_active=1, description=? WHERE id=?', [$desc ?: null, (int)$dup['id']]);
                flash('success', 'Opsi "' . $name . '" diaktifkan kembali.');
            } else {
                q('INSERT INTO master_options (category, name, description) VALUES (?,?,?)', [$cat, $name, $desc ?: null]);
                flash('success', 'Opsi "' . $name . '" ditambahkan.');
            }
        }
    } elseif ($act === 'del_option') {
        $oid = (int)($_POST['option_id'] ?? 0);
        $opt = q('SELECT * FROM master_options WHERE id=?', [$oid])->fetch();
        if (!$opt) {
            flash('danger', 'Opsi tidak ditemukan.');
        } else {
            q('DELETE FROM master_options WHERE id=?', [$oid]);
            flash('success', 'Opsi "' . $opt['name'] . '" dihapus.');
        }
    } elseif ($act === 'toggle_option') {
        $oid = (int)($_POST['option_id'] ?? 0);
        q('UPDATE master_options SET is_active = 1 - is_active WHERE id=?', [$oid]);
        flash('success', 'Status opsi diperbarui.');
    }
    redirect('master_options.php');
}

$options = q('SELECT * FROM master_options ORDER BY category, name')->fetchAll();
$byCat = [];
foreach ($options as $o) $byCat[$o['category']][] = $o;

// Berapa kali tiap opsi terpakai (untuk info).
$usage = [];
foreach (['damage_type' => 'ticket_reports', 'repair_action' => 'ticket_reports'] as $cat => $tbl) {
    $col = $cat;
    foreach (q("SELECT $col v, COUNT(*) c FROM $tbl WHERE $col IS NOT NULL AND $col<>'' GROUP BY $col")->fetchAll() as $r) {
        $usage[$cat][(string)$r['v']] = (int)$r['c'];
    }
}

$pageTitle = 'Master Option';
require __DIR__ . '/includes/header.php';
?>

<?= page_head('Master Option', 'list-check', 'Pilihan yang dipakai di form laporan vendor: jenis kerusakan dan tindakan perbaikan',
    '<a href="' . url('vendors.php') . '" class="btn btn-sm btn-outline-secondary"><i class="bi bi-arrow-left"></i> Kembali ke Vendor</a>') ?>

<div class="row g-3">
    <?php foreach (OPTION_CATEGORIES as $cat => [$label, $icon]): ?>
        <div class="col-lg-4">
            <div class="card h-100">
                <div class="card-header bg-white fw-semibold"><i class="bi <?= e($icon) ?>"></i> <?= e($label) ?></div>
                <div class="card-body">
                    <div class="d-flex flex-wrap gap-1 mb-3">
                        <?php foreach (($byCat[$cat] ?? []) as $o):
                            $used = $usage[$cat][(string)$o['name']] ?? 0; ?>
                            <span class="badge <?= $o['is_active'] ? 'bg-light text-dark border' : 'bg-secondary' ?>">
                                <?= e($o['name']) ?>
                                <span class="badge bg-secondary ms-1" title="Dipakai di <?= $used ?> laporan"><?= $used ?></span>
                            </span>
                        <?php endforeach; ?>
                        <?php if (!$byCat[$cat] ?? []): ?><span class="text-muted small">Belum ada opsi.</span><?php endif; ?>
                    </div>

                    <table class="table table-sm mb-0 small">
                        <thead class="table-light"><tr><th>Opsi</th><th class="text-center">Status</th><th class="text-center">Aksi</th></tr></thead>
                        <tbody>
                        <?php foreach (($byCat[$cat] ?? []) as $o): ?>
                            <tr class="<?= $o['is_active'] ? '' : 'text-muted' ?>">
                                <td><?= e($o['name']) ?><?= $o['description'] ? '<div class="text-muted" style="font-size:.68rem">' . e($o['description']) . '</div>' : '' ?></td>
                                <td class="text-center">
                                    <?= $o['is_active']
                                        ? '<span class="badge bg-success">Aktif</span>'
                                        : '<span class="badge bg-secondary">Nonaktif</span>' ?>
                                </td>
                                <td class="text-center">
                                    <form method="post" class="d-inline">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="act" value="toggle_option">
                                        <input type="hidden" name="option_id" value="<?= (int)$o['id'] ?>">
                                        <button class="btn btn-sm btn-outline-secondary py-0 px-1" title="<?= $o['is_active'] ? 'Nonaktifkan' : 'Aktifkan' ?>">
                                            <i class="bi bi-<?= $o['is_active'] ? 'toggle-on' : 'toggle-off' ?>"></i>
                                        </button>
                                    </form>
                                    <form method="post" class="d-inline" data-confirm="Hapus opsi &quot;<?= e($o['name']) ?>&quot;?">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="act" value="del_option">
                                        <input type="hidden" name="option_id" value="<?= (int)$o['id'] ?>">
                                        <button class="btn btn-sm btn-outline-danger py-0 px-1" title="Hapus"><i class="bi bi-trash"></i></button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        <?php if (!$byCat[$cat] ?? []): ?>
                            <tr><td colspan="3" class="text-center text-muted p-3">Belum ada opsi.</td></tr>
                        <?php endif; ?>
                        </tbody>
                    </table>
                </div>
                <div class="card-footer bg-white">
                    <form method="post" class="row g-2 align-items-end">
                        <?= csrf_field() ?>
                        <input type="hidden" name="act" value="add_option">
                        <input type="hidden" name="category" value="<?= e($cat) ?>">
                        <div class="col-7">
                            <label class="form-label small mb-1">Nama Opsi</label>
                            <input name="name" class="form-control form-control-sm" required maxlength="100">
                        </div>
                        <div class="col-3">
                            <label class="form-label small mb-1">Keterangan</label>
                            <input name="description" class="form-control form-control-sm" maxlength="255">
                        </div>
                        <div class="col-2">
                            <button class="btn btn-sm btn-primary w-100" title="Tambah opsi"><i class="bi bi-plus-lg"></i></button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    <?php endforeach; ?>
</div>

<div class="alert alert-info small mt-3">
    <i class="bi bi-info-circle"></i> Opsi yang <b>Nonaktif</b> tetap tersimpan di laporan lama, tapi tidak muncul lagi di pilihan form vendor.
    Angka dalam badge abu-abu = jumlah laporan yang memakai opsi tersebut.
</div>

<?php require __DIR__ . '/includes/footer.php'; ?>
