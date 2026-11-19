<?php
/**
 * Form laporan pekerjaan vendor.
 * Bagian atas otomatis terisi dari informasi tiket NOC. Vendor mengisi tindakan, foto, dan material terpakai.
 */
require_once __DIR__ . '/includes/bootstrap.php';
$u = require_role('vendor');
$t = get_ticket((int)($_GET['id'] ?? 0));
if (!$t || (int)$t['vendor_id'] !== (int)$u['vendor_id']) {
    http_response_code(404);
    exit('Tiket tidak ditemukan.');
}
$id = (int)$t['id'];
$self = 'vendor_report.php?id=' . $id;
if (!in_array($t['status'], ['on_progress', 'revision'], true)) {
    flash('warning', 'Form laporan hanya bisa diisi saat tiket berstatus Dikerjakan / Revisi.');
    redirect('ticket_view.php?id=' . $id);
}

$report = q('SELECT * FROM ticket_reports WHERE ticket_id=?', [$id])->fetch() ?: [];
$loadLines = fn() => q('SELECT tm.*, m.code, m.name, m.unit FROM ticket_materials tm JOIN materials m ON m.id=tm.material_id WHERE tm.ticket_id=? ORDER BY tm.id', [$id])->fetchAll();
$errors = [];
$isMs = is_ms_vendor((int)$t['vendor_id']);
$materialList = q('SELECT * FROM materials WHERE is_active=1 ORDER BY name')->fetchAll();
$damageTypes = master_options('damage_type');
$repairActions = master_options('repair_action');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $mode = $_POST['mode'] ?? 'draft';

    if ($mode === 'delete_photo') {
        $p = q('SELECT * FROM ticket_photos WHERE id=? AND ticket_id=?', [(int)$_POST['photo_id'], $id])->fetch();
        if ($p) {
            q('DELETE FROM ticket_photos WHERE id=?', [$p['id']]);
            $file = __DIR__ . '/' . $p['file_path'];
            if (is_file($file)) unlink($file);
            flash('success', 'Foto dihapus.');
        }
        redirect($self);
    }

    // Vendor MS: simpan material dari form CHECKLIST (MATERIAL A/B/C + jumlah + satuan fixed).
    if ($mode === 'save_materials_ms' && $isMs) {
        $items = [];
        foreach ((array)($_POST['mat_use'] ?? []) as $mid => $qty) {
            $qty = (float)$qty;
            $checked = !empty($_POST['mat_pick'][$mid]);
            // "if jumlah material diisi, then material pasti dipilih"
            if (!$checked && $qty > 0) {
                flash('danger', 'Material dengan jumlah terisi harus dicentang lebih dulu (pastikan centang dipakai).');
                redirect($self);
            }
            if (!$checked || $qty <= 0) continue;
            $items[] = ['material_id' => (int)$mid, 'qty' => $qty, 'note' => $_POST['mat_note_new'][$mid] ?? ''];
        }
        if (!$items) {
            flash('warning', 'Tidak ada material yang dicentang / jumlah diisi.');
            redirect($self);
        }
        $r = ms_add_materials($id, $items, (int)$u['id']);
        if (!$r['ok']) {
            flash('danger', $r['error'] . ($r['saved'] ? ' (' . $r['saved'] . ' material sudah tersimpan)' : ''));
        } else {
            flash('success', $r['saved'] . ' material dicatat langsung. Stok berkurang otomatis.');
        }
        redirect($self);
    }

    // Vendor MS: tambah material langsung dari form (tanpa request ke inventory dulu).
    if ($mode === 'add_material_ms' && $isMs) {
        $r = ms_add_material($id, (int)($_POST['material_id'] ?? 0), (float)($_POST['qty'] ?? 0), (int)$u['id']);
        if (!$r['ok']) {
            flash('danger', $r['error']);
        } else {
            $m = q('SELECT name, unit FROM materials WHERE id=?', [(int)($_POST['material_id'] ?? 0)])->fetch();
            flash('success', 'Material ' . ($m['name'] ?? '') . ' dicatat langsung. Stok berkurang ' . fmt_qty((float)($_POST['qty'] ?? 0)) . ' ' . ($m['unit'] ?? '') . '.');
        }
        redirect($self);
    }

    if ($mode === 'remove_material_ms' && $isMs) {
        $r = ms_remove_material($id, (int)($_POST['line_id'] ?? 0));
        if (!$r['ok']) flash('danger', $r['error']);
        else flash('success', 'Material dibatalkan, stok dikembalikan.');
        redirect($self);
    }

    $data = [
        'arrived_at' => !empty($_POST['arrived_at']) ? date('Y-m-d H:i:s', strtotime($_POST['arrived_at'])) : null,
        // "Waktu Selesai Dikerjakan" TIDAK diisi vendor lagi - dicatat otomatis saat
        // vendor menekan tombol "Kirim Laporan" (waktu pengiriman laporan).
        'finished_at' => null,
        'root_cause' => trim($_POST['root_cause'] ?? ''),
        'action_taken' => trim($_POST['action_taken'] ?? ''),
        'damage_type' => trim($_POST['damage_type'] ?? '') ?: null,
        'repair_action' => trim($_POST['repair_action'] ?? '') ?: null,
        'notes' => trim($_POST['notes'] ?? ''),
    ];

    // Saat KIRIM LAPORAN: waktu selesai dicatat otomatis (tahunya waktu klik tombol).
    if ($mode === 'submit') {
        $data['finished_at'] = now();
    } elseif (!empty($report['finished_at'])) {
        // Draft: pertahankan waktu selesai yang sudah tercatat (mis. saat revisi).
        $data['finished_at'] = $report['finished_at'];
    }

    db()->beginTransaction();
    try {
        // simpan / update laporan (draft)
        if ($report) {
            q('UPDATE ticket_reports SET arrived_at=?, finished_at=?, root_cause=?, action_taken=?, notes=?,
                damage_type=?, repair_action=? WHERE ticket_id=?',
                [$data['arrived_at'], $data['finished_at'], $data['root_cause'], $data['action_taken'], $data['notes'],
                 $data['damage_type'], $data['repair_action'], $id]);
        } else {
            q('INSERT INTO ticket_reports (ticket_id, arrived_at, finished_at, root_cause, action_taken, notes,
                damage_type, repair_action, submitted_by) VALUES (?,?,?,?,?,?,?,?,?)',
                [$id, $data['arrived_at'], $data['finished_at'], $data['root_cause'], $data['action_taken'], $data['notes'],
                 $data['damage_type'], $data['repair_action'], $u['id']]);
        }

        // material terpakai (hanya baris yang sudah keluar & belum dikembalikan)
        foreach ((array)($_POST['used'] ?? []) as $lineId => $val) {
            $line = q('SELECT * FROM ticket_materials WHERE id=? AND ticket_id=? AND qty_issued > 0 AND qty_returned IS NULL AND is_direct=0', [(int)$lineId, $id])->fetch();
            if (!$line || $val === '') continue;
            $val = (float)$val;
            if ($val < 0 || $val > (float)$line['qty_issued']) {
                throw new RuntimeException('Jumlah material terpakai tidak boleh melebihi jumlah yang diterima dari inventory.');
            }
            q('UPDATE ticket_materials SET qty_used=? WHERE id=?', [$val, $line['id']]);
        }

        // upload bukti jenis kerusakan
        $dmg = files_list($_FILES['damage_photo'] ?? null);
        if ($dmg) {
            $path = save_upload($dmg[0], $id);
            if (!empty($report['damage_photo'])) {
                $old = __DIR__ . '/' . $report['damage_photo'];
                if (is_file($old)) unlink($old);
            }
            q('UPDATE ticket_reports SET damage_photo=? WHERE ticket_id=?', [$path, $id]);
        }

        // upload foto + keterangan (keterangan diisi PIC Region / vendor)
        // Cek blur: foto yang terblur DITOLAK, vendor diminta upload ulang.
        $blurryRejected = [];
        foreach (PHOTO_CATEGORIES as $cat => $label) {
            $cap = trim((string)($_POST['photo_caption'][$cat] ?? ''));
            foreach (files_list($_FILES['photos_' . $cat] ?? null) as $file) {
                $path = save_upload($file, $id);
                $abs = __DIR__ . '/' . $path;
                $chk = image_blur_check($abs);
                if ($chk['blurry']) {
                    // Buang file & catat sebagai gagal upload.
                    if (is_file($abs)) unlink($abs);
                    $blurryRejected[] = $label . ' (' . ($file['name'] ?? 'foto') . ')';
                    continue;
                }
                q('INSERT INTO ticket_photos (ticket_id, category, file_path, caption, is_blurry, blur_score, uploaded_by)
                   VALUES (?,?,?,?,0,?,?)',
                    [$id, $cat, $path, $cap ?: null, $chk['score'], $u['id']]);
            }
            // Update keterangan foto yang sudah ada (bila kategori punya foto lama).
            if ($cap !== '') {
                q('UPDATE ticket_photos SET caption=? WHERE ticket_id=? AND category=? AND (caption IS NULL OR caption=\'\')',
                    [$cap, $id, $cat]);
            }
        }
        if ($blurryRejected) {
            throw new RuntimeException('Foto terblur ditolak: ' . implode(', ', $blurryRejected)
                . '. Silakan foto ulang (pastikan fokus & tidak goyang) lalu upload kembali.');
        }

        // Keterangan per baris material
        foreach ((array)($_POST['mat_note'] ?? []) as $lineId => $note) {
            $note = trim((string)$note);
            if ($note === '') continue;
            q('UPDATE ticket_materials SET note=? WHERE id=? AND ticket_id=?', [$note, (int)$lineId, $id]);
        }

        if ($mode === 'submit') {
            // validasi kelengkapan
            if ($data['root_cause'] === '') $errors[] = 'Penyebab gangguan wajib diisi.';
            if ($data['action_taken'] === '') $errors[] = 'Tindakan perbaikan wajib diisi.';
            if (!$data['arrived_at']) $errors[] = 'Waktu tiba di lokasi wajib diisi.';
            elseif ($data['arrived_at'] > $data['finished_at']) $errors[] = 'Waktu tiba tidak boleh setelah waktu selesai dikerjakan.';
            $photoCount = [];
            foreach (q('SELECT category, COUNT(*) n FROM ticket_photos WHERE ticket_id=? GROUP BY category', [$id])->fetchAll() as $r) $photoCount[$r['category']] = (int)$r['n'];
            foreach (PHOTO_REQUIRED as $reqCat) {
                if (empty($photoCount[$reqCat])) {
                    $errors[] = 'Minimal 1 ' . (PHOTO_CATEGORIES[$reqCat] ?? $reqCat) . ' wajib diunggah.';
                }
            }
            $lines = $loadLines();
            foreach ($lines as $l) {
                if ($l['qty_issued'] === null) $errors[] = "Permintaan material {$l['name']} belum diproses inventory (tunggu atau batalkan permintaan).";
                elseif ((float)$l['qty_issued'] > 0 && $l['qty_returned'] === null && $l['qty_used'] === null) $errors[] = "Jumlah terpakai material {$l['name']} wajib diisi.";
            }

            if (!$errors) {
                $first = !$t['submitted_at'];
                q('UPDATE ticket_reports SET submitted_at=NOW(), submitted_by=? WHERE ticket_id=?', [$u['id'], $id]);
                if ($first) {
                    // Laporan pertama dicatat, TETAPI verdict SLA tidak di sini:
                    // SLA baru dihitung sampai tiket dinyatakan PERMANENT.
                    q('UPDATE tickets SET submitted_at=NOW() WHERE id=?', [$id]);
                }
                $needReturn = array_filter($lines, fn($l) => (float)$l['qty_issued'] > 0 && $l['qty_returned'] === null && (int)($l['is_direct'] ?? 0) === 0);
                $next = $needReturn ? 'inventory_check' : 'foc_review';
                q('UPDATE tickets SET status=? WHERE id=?', [$next, $id]);
                $rev = $t['status'] === 'revision' ? ' (perbaikan ulang)' : '';
                $lateNote = '';
                if ($first && $t['sla_deadline'] && time() > strtotime($t['sla_deadline'])) {
                    $lateNote = ' - sudah lewat batas SLA (verdict final menunggu PERMANENT)';
                }
                log_ticket($id, 'report_submitted', 'Vendor mengirim laporan pekerjaan' . $rev
                    . ' - selesai dikerjakan ' . fmt_dt($data['finished_at']) . $lateNote);
                if ($next === 'inventory_check') {
                    notify_role('inventory', "Tiket {$t['ticket_no']}: vendor selesai, terima kembalian material & cek pemakaian.", $id);
                } else {
                    notify_role('foc', "Tiket {$t['ticket_no']} siap direview FOC{$rev}.", $id);
                }
                notify_role('noc', "Tiket {$t['ticket_no']}: vendor melaporkan pekerjaan selesai{$rev}.", $id);
                noc_notify($id, 'work_submitted', ['revision' => (int)$t['revision_count']]);
                db()->commit();
                flash('success', 'Laporan terkirim.' . ($next === 'inventory_check' ? ' Silakan kembalikan sisa material ke inventory.' : ' Menunggu review FOC.'));
                redirect('ticket_view.php?id=' . $id);
            }
        }
        db()->commit();
        if (!$errors) {
            flash('success', 'Draft laporan disimpan.');
            redirect($self);
        }
        $report = array_merge($report, $data);
    } catch (RuntimeException $ex) {
        db()->rollBack();
        $errors[] = $ex->getMessage();
        $report = array_merge($report, $data);
    }
}

$lines = $loadLines();
$photos = q('SELECT * FROM ticket_photos WHERE ticket_id=? ORDER BY id', [$id])->fetchAll();
$dtVal = fn($v) => $v ? date('Y-m-d\TH:i', strtotime($v)) : '';

$pageTitle = 'Form Laporan ' . $t['ticket_no'];
require __DIR__ . '/includes/header.php';
?>
<a href="<?= url('ticket_view.php?id=' . $id) ?>" class="small text-decoration-none d-inline-block mb-2"><i class="bi bi-arrow-left"></i> Kembali ke tiket</a>
<?= page_head('Form Laporan Pekerjaan', 'clipboard2-check', '', '<div>' . vendor_type_badge($t['vendor_type']) . ' ' . sla_badge($t) . '</div>') ?>
<?php if ($isMs): ?>
<div class="alert alert-primary small">
    <i class="bi bi-lightning-charge-fill"></i>
    <b>Vendor MS (Managed Service)</b> — Anda bisa langsung mencatat material yang dipakai
    (stok otomatis berkurang) tanpa perlu request ke inventory lebih dulu.
    BASTO (catatan penggunaan material) akan otomatis terbentuk dari form ini setelah pekerjaan selesai.
</div>
<?php endif; ?>
<?php if ($errors): ?><div class="alert alert-danger"><b>Laporan belum bisa dikirim:</b><ul class="mb-0"><?php foreach ($errors as $er): ?><li><?= e($er) ?></li><?php endforeach; ?></ul></div><?php endif; ?>

<form method="post" enctype="multipart/form-data">
    <?= csrf_field() ?>
    <!-- Header otomatis dari NOC -->
    <div class="card mb-3">
        <div class="card-header bg-white fw-semibold"><i class="bi bi-lock"></i> Informasi Tiket (otomatis dari NOC)</div>
        <div class="card-body"><div class="row g-2 small">
            <?php
            $hdr = [
                'No. Tiket' => $t['ticket_no'], 'No. SPK' => $t['spk_no'] ?: '-', 'Ref. NOC' => $t['noc_ref'] ?: '-',
                'Jenis Kegiatan' => $t['activity_type'] ?: '-', 'Kota' => $t['city_name'],
                'Provinsi' => $t['province'] ?: '-',
                'Pelanggan / Site' => trim(($t['customer_name'] ?? '') . ' ' . ($t['customer_id'] ? '(' . $t['customer_id'] . ')' : '')) ?: '-',
                'Jenis Masalah' => $t['problem_type'], 'Prioritas' => PRIORITIES[$t['priority']],
                'Dilaporkan' => fmt_dt($t['reported_at']), 'Batas SLA' => fmt_dt($t['sla_deadline']),
                'Vendor' => $t['vendor_name'],
            ];
            foreach ($hdr as $k => $v): ?>
                <div class="col-sm-6 col-lg-4"><label class="form-label text-muted mb-0"><?= e($k) ?></label><input class="form-control form-control-sm" value="<?= e($v) ?>" readonly></div>
            <?php endforeach; ?>
            <div class="col-12"><label class="form-label text-muted mb-0">Lokasi</label><textarea class="form-control form-control-sm" rows="2" readonly><?= e($t['location']) ?></textarea></div>
            <div class="col-12"><label class="form-label text-muted mb-0">Deskripsi dari NOC</label><textarea class="form-control form-control-sm" rows="2" readonly><?= e($t['description']) ?></textarea></div>
        </div></div>
    </div>

    <!-- Isian vendor -->
    <div class="card mb-3">
        <div class="card-header bg-white fw-semibold"><i class="bi bi-pencil"></i> Hasil Pekerjaan &amp; Penyelesaian Kerusakan</div>
        <div class="card-body"><div class="row g-3">
            <div class="col-md-6"><label class="form-label">Waktu Tiba di Lokasi <span class="text-danger">*</span></label>
                <input type="datetime-local" name="arrived_at" class="form-control" value="<?= e($dtVal($report['arrived_at'] ?? null)) ?>"></div>
            <div class="col-md-6">
                <label class="form-label">Waktu Selesai Dikerjakan</label>
                <?php if (!empty($report['finished_at'])): ?>
                    <input type="text" class="form-control" value="<?= e(fmt_dt($report['finished_at'])) ?>" readonly>
                <?php else: ?>
                    <input type="text" class="form-control" value="Otomatis dicatat saat Anda menekan &quot;Kirim Laporan&quot;" readonly>
                <?php endif; ?>
                <div class="form-text">Diisi sistem otomatis saat laporan dikirim &mdash; tidak perlu diisi manual.</div>
            </div>
            <div class="col-md-6">
                <label class="form-label">Jenis Kerusakan <span class="text-danger">*</span></label>
                <select name="damage_type" class="form-select" required>
                    <option value="">- pilih jenis kerusakan -</option>
                    <?php foreach ($damageTypes as $d): ?>
                        <option value="<?= e($d['name']) ?>" <?= ($report['damage_type'] ?? '') === $d['name'] ? 'selected' : '' ?>><?= e($d['name']) ?></option>
                    <?php endforeach; ?>
                    <?php if (!in_array($report['damage_type'] ?? '', array_column($damageTypes, 'name'), true) && ($report['damage_type'] ?? '') !== ''): ?>
                        <option value="<?= e($report['damage_type']) ?>" selected><?= e($report['damage_type']) ?></option>
                    <?php endif; ?>
                </select>
                <div class="form-text">Jenis kerusakan dipilih dari master (dapat dikelola di halaman Vendor &rarr; Master).</div>
            </div>
            <div class="col-md-6">
                <label class="form-label">Tindakan Perbaikan <span class="text-danger">*</span></label>
                <select name="repair_action" class="form-select" required>
                    <option value="">- pilih tindakan perbaikan -</option>
                    <?php foreach ($repairActions as $ra): ?>
                        <option value="<?= e($ra['name']) ?>" <?= ($report['repair_action'] ?? '') === $ra['name'] ? 'selected' : '' ?>><?= e($ra['name']) ?></option>
                    <?php endforeach; ?>
                    <?php if (!in_array($report['repair_action'] ?? '', array_column($repairActions, 'name'), true) && ($report['repair_action'] ?? '') !== ''): ?>
                        <option value="<?= e($report['repair_action']) ?>" selected><?= e($report['repair_action']) ?></option>
                    <?php endif; ?>
                </select>
            </div>
            <div class="col-12"><label class="form-label">Upload Bukti Jenis Kerusakan</label>
                <?php if (!empty($report['damage_photo'])): ?>
                    <div class="mb-1"><a href="<?= url($report['damage_photo']) ?>" target="_blank"><img src="<?= url($report['damage_photo']) ?>" class="photo-thumb" alt=""></a></div>
                <?php endif; ?>
                <input type="file" name="damage_photo" class="form-control form-control-sm" accept="image/jpeg,image/png,image/webp">
                <div class="form-text">Foto bukti jenis kerusakan (opsional).</div>
            </div>
            <div class="col-12"><label class="form-label">Detail Penyebab Gangguan <span class="text-danger">*</span></label>
                <textarea name="root_cause" class="form-control" rows="2" required><?= e($report['root_cause'] ?? '') ?></textarea></div>
            <div class="col-12"><label class="form-label">Detail Tindakan yang Dilakukan <span class="text-danger">*</span></label>
                <textarea name="action_taken" class="form-control" rows="3" required><?= e($report['action_taken'] ?? '') ?></textarea></div>
            <div class="col-12"><label class="form-label">Catatan Tambahan</label>
                <textarea name="notes" class="form-control" rows="2"><?= e($report['notes'] ?? '') ?></textarea></div>
        </div></div>
    </div>

    <!-- Material -->
    <div class="card mb-3">
        <div class="card-header bg-white fw-semibold d-flex justify-content-between align-items-center">
            <span><i class="bi bi-box-seam"></i> Material Terpakai</span>
            <?php if ($isMs): ?><span class="small text-muted">Centang material yang dipakai &mdash; isi jumlahnya</span><?php endif; ?>
        </div>
        <?php if ($isMs): ?>
            <?php /* Konsep: MATERIAL A/B/C [CHECKLIST] + JUMLAH [ISIAN] + [SATUAN] fixed */ ?>
            <div class="card-body border-bottom">
                <div class="table-responsive">
                    <table class="table table-sm mb-2 align-middle">
                        <thead class="table-light"><tr>
                            <th style="width:34px"><i class="bi bi-check-square"></i></th>
                            <th>Material</th>
                            <th style="width:140px" class="text-end">Jumlah</th>
                            <th style="width:90px">Satuan</th>
                            <th style="width:150px" class="text-end">Batas / Stok</th>
                        </tr></thead>
                        <tbody>
                        <?php foreach ($materialList as $m):
                            $mid = (int)$m['id'];
                            $maxUse = (float)($m['max_use_per_ticket'] ?? 0);
                            $already = material_used_total($id, $mid); ?>
                            <tr>
                                <td class="text-center">
                                    <input class="form-check-input mat-pick" type="checkbox" name="mat_pick[<?= $mid ?>]"
                                           id="mp<?= $mid ?>" form="saveMatMS">
                                </td>
                                <td>
                                    <label for="mp<?= $mid ?>" class="mb-0" style="cursor:pointer">
                                        <?= e($m['code']) ?> &mdash; <?= e($m['name']) ?>
                                        <?php if ($already > 0): ?>
                                            <span class="badge bg-light text-dark border">dipakai <?= fmt_qty($already) ?> <?= e($m['unit']) ?></span>
                                        <?php endif; ?>
                                    </label>
                                </td>
                                <td>
                                    <input type="number" step="0.01" min="0" name="mat_use[<?= $mid ?>]" form="saveMatMS"
                                           class="form-control form-control-sm mat-qty" data-unit="<?= e($m['unit']) ?>"
                                           data-max="<?= $maxUse ?>" placeholder="0" disabled>
                                </td>
                                <td class="small text-muted"><?= e($m['unit']) ?> <span class="text-muted">(fixed)</span></td>
                                <td class="text-end small">
                                    <?php if ($maxUse > 0): ?>
                                        <span class="badge bg-warning text-dark" title="Batas pemakaian per tiket">maks <?= fmt_qty($maxUse) ?></span>
                                    <?php else: ?>
                                        <span class="text-muted">tanpa batas</span>
                                    <?php endif; ?>
                                    <div class="text-muted">stok <?= fmt_qty($m['stock']) ?></div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <div class="d-flex justify-content-between align-items-center flex-wrap gap-2">
                    <div class="small text-muted">
                        <i class="bi bi-info-circle"></i> Jumlah yang diisi otomatis mencentang materialnya.
                        Bila melewati <b>batas per tiket</b>, sistem menolak &mdash; mohon konfirmasi ke CGS.
                    </div>
                    <button type="submit" form="saveMatMS" class="btn btn-sm btn-primary"><i class="bi bi-check2"></i> Simpan Material Terpakai</button>
                </div>
            </div>
        <?php endif; ?>
        <?php if (!$lines): ?>
            <div class="card-body small text-muted">
                <?= $isMs
                    ? 'Belum ada material yang dicatat.'
                    : 'Tidak ada material. Jika butuh material, minta lewat halaman tiket (panel "Minta Material ke Inventory").' ?>
            </div>
        <?php else: ?>
            <div class="table-responsive"><table class="table table-sm mb-0 small">
                <thead class="table-light"><tr>
                    <th>Material</th><th class="text-end">Diterima</th><th style="width:150px">Terpakai</th>
                    <th style="width:180px">Keterangan</th>
                    <th class="text-end">Sisa</th><?php if ($isMs): ?><th></th><?php endif; ?>
                </tr></thead>
                <tbody>
                <?php foreach ($lines as $l):
                    $isDirect = (int)($l['is_direct'] ?? 0) === 1;
                    $editable = (float)$l['qty_issued'] > 0 && $l['qty_returned'] === null;
                    $editableQty = $editable && !$isDirect; ?>
                    <tr>
                        <td><?= e($l['code'] . ' - ' . $l['name']) ?> (<?= e($l['unit']) ?>)
                            <?= $isDirect ? '<span class="badge bg-info text-dark ms-1">MS Langsung</span>' : '' ?></td>
                        <td class="text-end"><?= $l['qty_issued'] === null ? '<span class="badge bg-warning text-dark">Menunggu inventory</span>' : fmt_qty($l['qty_issued']) ?></td>
                        <td><?php if ($editableQty): ?>
                                <input type="number" step="0.01" min="0" max="<?= (float)$l['qty_issued'] ?>" name="used[<?= $l['id'] ?>]" class="form-control form-control-sm used-input"
                                       data-issued="<?= (float)$l['qty_issued'] ?>" value="<?= $l['qty_used'] !== null ? (float)$l['qty_used'] : '' ?>">
                            <?php else: ?><?= fmt_qty($l['qty_used']) ?> <?= $isDirect ? '<span class="text-muted">(langsung dipakai)</span>' : ($l['qty_returned'] !== null ? '<span class="text-muted">(sudah dicek)</span>' : '') ?><?php endif; ?></td>
                        <td>
                            <?php if ($editable): ?>
                                <input type="text" name="mat_note[<?= $l['id'] ?>]" class="form-control form-control-sm" value="<?= e($l['note'] ?? '') ?>" placeholder="keterangan">
                            <?php else: ?><?= e($l['note'] ?: '-') ?><?php endif; ?>
                        </td>
                        <td class="text-end remain"><?= $l['qty_used'] !== null && $l['qty_issued'] !== null ? fmt_qty((float)$l['qty_issued'] - (float)$l['qty_used']) : '-' ?></td>
                        <?php if ($isMs): ?>
                            <td class="text-end">
                                <?php if ($l['qty_returned'] === null): ?>
                                    <button type="submit" form="rm<?= $l['id'] ?>" class="btn btn-link btn-sm text-danger p-0" title="Batalkan material"><i class="bi bi-x-circle"></i></button>
                                <?php endif; ?>
                            </td>
                        <?php endif; ?>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table></div>
        <?php endif; ?>
    </div>

    <!-- Foto -->
    <div class="card mb-3">
        <div class="card-header bg-white fw-semibold"><i class="bi bi-camera"></i> Dokumentasi Foto <span class="small text-muted fw-normal">(JPG/PNG/WEBP, maks <?= MAX_UPLOAD_MB ?>MB per foto)</span></div>
        <div class="card-body">
            <?php foreach (PHOTO_CATEGORIES as $cat => $label):
                $list = array_filter($photos, fn($p) => $p['category'] === $cat); ?>
                <div class="mb-3">
                    <label class="form-label fw-semibold"><?= e($label) ?> <?= in_array($cat, PHOTO_REQUIRED, true) ? '<span class="text-danger">*</span>' : '' ?></label>
                    <?php if ($list): ?>
                        <div class="row g-2 mb-2">
                            <?php foreach ($list as $p): ?>
                                <div class="col-4 col-md-2 position-relative">
                                    <a href="<?= url($p['file_path']) ?>" target="_blank"><img src="<?= url($p['file_path']) ?>" class="photo-thumb" alt=""></a>
                                    <?= blur_badge((int)($p['is_blurry'] ?? 0), $p['blur_score'] ?? null) ?>
                                    <button type="submit" form="del<?= $p['id'] ?>" class="btn btn-danger btn-sm position-absolute top-0 end-0 m-1 py-0 px-1" title="Hapus"><i class="bi bi-x"></i></button>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                    <input type="file" name="photos_<?= $cat ?>[]" class="form-control" accept="image/jpeg,image/png,image/webp" capture="environment" multiple>
                    <div class="form-text compress-hint">Foto otomatis dikompres sebelum upload. Foto yang <b>terblur</b> akan ditolak &mdash; silakan foto ulang.</div>
                    <?php $existingCap = ''; foreach ($list as $ph) { if (!empty($ph['caption'])) { $existingCap = $ph['caption']; break; } } ?>
                    <input type="text" name="photo_caption[<?= $cat ?>]" class="form-control form-control-sm mt-1"
                           placeholder="Keterangan <?= e($label) ?>" value="<?= e($existingCap) ?>">
                </div>
            <?php endforeach; ?>
        </div>
    </div>

    <div class="d-flex justify-content-end gap-2 mb-4">
        <button name="mode" value="draft" class="btn btn-outline-secondary"><i class="bi bi-save"></i> Simpan Draft</button>
        <button name="mode" value="submit" class="btn btn-primary" onclick="return confirm('Kirim laporan? Setelah dikirim, laporan akan dicek inventory/FOC.')"><i class="bi bi-send"></i> Kirim Laporan</button>
    </div>
</form>

<?php foreach ($photos as $p): ?>
    <form method="post" id="del<?= $p['id'] ?>" data-confirm="Hapus foto ini?" class="d-none">
        <?= csrf_field() ?><input type="hidden" name="mode" value="delete_photo"><input type="hidden" name="photo_id" value="<?= $p['id'] ?>">
    </form>
<?php endforeach; ?>
<?php if ($isMs): ?>
    <form method="post" id="saveMatMS" class="d-none">
        <?= csrf_field() ?><input type="hidden" name="mode" value="save_materials_ms">
    </form>
    <?php foreach ($lines as $l): ?>
        <form method="post" id="rm<?= $l['id'] ?>" data-confirm="Batalkan material ini? Stok akan dikembalikan." class="d-none">
            <?= csrf_field() ?><input type="hidden" name="mode" value="remove_material_ms"><input type="hidden" name="line_id" value="<?= $l['id'] ?>">
        </form>
    <?php endforeach; ?>
<?php endif; ?>
<script>
// ---- Checklist material (Vendor MS): centang & jumlah saling mengunci ----
document.querySelectorAll('.mat-pick').forEach(function (cb) {
    var row = cb.closest('tr');
    var qty = row.querySelector('.mat-qty');
    function sync() {
        qty.disabled = !cb.checked;
        if (!cb.checked) qty.value = '';
        else if (qty.value === '') qty.focus();
    }
    cb.addEventListener('change', sync);
    qty.addEventListener('input', function () {
        // "if jumlah material diisi, then material pasti dipilih"
        if (parseFloat(qty.value) > 0 && !cb.checked) { cb.checked = true; }
        var max = parseFloat(qty.dataset.max || '0');
        if (max > 0 && parseFloat(qty.value) > max) {
            qty.classList.add('is-invalid');
            qty.setCustomValidity('Melebihi batas per tiket (maks ' + max + '). Mohon konfirmasi ke CGS.');
        } else {
            qty.classList.remove('is-invalid');
            qty.setCustomValidity('');
        }
    });
    sync();
});

// ---- Kompres ukuran gambar sebelum upload (konsep: "compress size image") ----
(function () {
    var MAXW = 1600, QUALITY = 0.82;
    function compress(file) {
        return new Promise(function (resolve) {
            if (!file.type || !file.type.match(/^image\//)) return resolve(file);
            var img = new Image(), url = URL.createObjectURL(file);
            img.onload = function () {
                var w = img.naturalWidth, h = img.naturalHeight;
                if (w <= MAXW && file.size < 900 * 1024) { URL.revokeObjectURL(url); return resolve(file); }
                var scale = Math.min(1, MAXW / w), cw = Math.round(w * scale), ch = Math.round(h * scale);
                var c = document.createElement('canvas'); c.width = cw; c.height = ch;
                c.getContext('2d').drawImage(img, 0, 0, cw, ch);
                c.toBlob(function (blob) {
                    URL.revokeObjectURL(url);
                    if (!blob) return resolve(file);
                    resolve(new File([blob], file.name.replace(/\.\w+$/, '') + '.jpg', { type: 'image/jpeg' }));
                }, 'image/jpeg', QUALITY);
            };
            img.onerror = function () { URL.revokeObjectURL(url); resolve(file); };
            img.src = url;
        });
    }
    document.querySelectorAll('input[type=file][name^="photos_"]').forEach(function (input) {
        input.addEventListener('change', function () {
            var files = Array.prototype.slice.call(input.files || []);
            if (!files.length) return;
            var hint = input.parentNode.querySelector('.compress-hint');
            Promise.all(files.map(compress)).then(function (out) {
                var dt = new DataTransfer();
                out.forEach(function (f) { dt.items.add(f); });
                input.files = dt.files;
                if (hint) {
                    var before = files.reduce(function (a, f) { return a + f.size; }, 0);
                    var after = out.reduce(function (a, f) { return a + f.size; }, 0);
                    hint.textContent = 'Foto dikompres: ' + Math.round(before / 1024) + ' KB -> ' + Math.round(after / 1024) + ' KB';
                }
            });
        });
    });
})();
</script>
<?php require __DIR__ . '/includes/footer.php'; ?>
