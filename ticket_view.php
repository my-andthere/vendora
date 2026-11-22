<?php
require_once __DIR__ . '/includes/bootstrap.php';
$u = require_login();
$t = get_ticket((int)($_GET['id'] ?? 0));
if (!$t || !can_view_ticket($u, $t)) {
    http_response_code(404);
    exit('Tiket tidak ditemukan.');
}
$id = (int)$t['id'];
$tRegionId = city_region_id((int)($t['city_id'] ?? 0));
$tRegionName = region_name($tRegionId);
$cityTz = city_timezone((int)($t['city_id'] ?? 0));
$workTime = work_time($t);
$role = $u['role'];
$st = $t['status'];

$materials = q('SELECT tm.*, m.code, m.name, m.unit, m.stock FROM ticket_materials tm JOIN materials m ON m.id=tm.material_id
                WHERE tm.ticket_id=? ORDER BY tm.id', [$id])->fetchAll();
$report = q('SELECT * FROM ticket_reports WHERE ticket_id=?', [$id])->fetch();
$photos = q('SELECT * FROM ticket_photos WHERE ticket_id=? ORDER BY category, id', [$id])->fetchAll();
$invChecks = q('SELECT i.*, u.full_name FROM inventory_checks i JOIN users u ON u.id=i.checked_by WHERE ticket_id=? ORDER BY i.id DESC', [$id])->fetchAll();
$focReviews = q('SELECT f.*, u.full_name FROM foc_reviews f JOIN users u ON u.id=f.reviewer_id WHERE ticket_id=? ORDER BY f.id DESC', [$id])->fetchAll();
$logs = q('SELECT l.*, u.full_name, u.role FROM ticket_logs l LEFT JOIN users u ON u.id=l.user_id WHERE ticket_id=? ORDER BY l.id DESC', [$id])->fetchAll();
$rejections = q('SELECT r.*, v.name FROM ticket_rejections r JOIN vendors v ON v.id=r.vendor_id WHERE ticket_id=? ORDER BY r.id', [$id])->fetchAll();
$allMaterials = q('SELECT * FROM materials WHERE is_active=1 ORDER BY name')->fetchAll();

$isMyTicket = $role === 'vendor' && (int)$t['vendor_id'] === (int)$u['vendor_id'];
$isMsVendor = is_ms_vendor((int)$t['vendor_id']);
$active = !in_array($st, ['closed', 'cancelled'], true);
$pendingReturn = array_filter($materials, fn($m) => (float)$m['qty_issued'] > 0 && $m['qty_returned'] === null && (int)($m['is_direct'] ?? 0) === 0);
$pendingIssue = array_filter($materials, fn($m) => $m['qty_issued'] === null);

// Alur Vendor -> Inventory -> FOC: checklist per tahap.
$invChecklist = inventory_checklist($id);            // [line_id => checklist material]
$canInvCheck  = can_inventory_check($u, $t);         // boleh centang material? (Inventory, saat cek material)
$focItems     = foc_checklist($id);                  // item checklist FOC (dokumentasi + penyebab + tindakan)
$focState     = array_column($focItems, null, 'key'); // [key => item]
$canFocEval   = can_foc_review($u, $t);              // boleh centang evaluation FOC?

$pageTitle = $t['ticket_no'];
require __DIR__ . '/includes/header.php';
?>
<a href="<?= url('tickets.php') ?>" class="small text-decoration-none d-inline-block mb-2"><i class="bi bi-arrow-left"></i> Daftar tiket</a>
<?= page_head($t['ticket_no'], 'ticket-perforated', '', '<div class="text-end d-flex flex-wrap gap-2 align-items-center justify-content-end">'
        . status_badge($st)
        . '<span class="small text-white-50">SLA ' . fmt_duration((int)$t['sla_minutes']) . '</span>'
        . ($st === 'cancelled' ? '' : sla_badge($t))
        . ((int)$t['revision_count'] ? '<span class="badge bg-white text-danger">Revisi ke-' . (int)$t['revision_count'] . '</span>' : '')
        . accept_badge($t)
        . '</div>') ?>

<div class="row g-3">
    <div class="col-lg-8">
        <!-- Informasi tiket dari NOC -->
        <div class="card mb-3">
            <div class="card-header bg-white fw-semibold"><i class="bi bi-broadcast"></i> Informasi Tiket dari NOC</div>
            <div class="card-body">
                <table class="table table-sm header-info mb-0">
                    <tr><th>Ref. NOC</th><td><?= e($t['noc_ref'] ?: '-') ?> <span class="badge bg-light text-dark border"><?= $t['source'] === 'api' ? 'via API NOC' : 'input manual' ?></span></td></tr>
                    <tr><th>No. SPK</th><td><?= e($t['spk_no'] ?: '-') ?></td></tr>
                    <tr><th>Jenis Kegiatan</th><td><?= e($t['activity_type'] ?: '-') ?></td></tr>
                    <tr><th>Kota</th><td><?= e($t['city_name']) ?> <?= $t['city_is_primary'] ? '<span class="badge bg-primary text-dark">Kota Utama</span>' : '' ?></td></tr>
                    <tr><th>Provinsi</th><td><?= e($t['province'] ?: '-') ?></td></tr>
                    <tr><th>Region</th><td><?= $tRegionName !== '-' ? e($tRegionName) : '<span class="text-muted">-</span>' ?>
                        <?= $tRegionId ? ' ' . region_tz_badge($tRegionId) : '' ?></td></tr>
                    <tr><th>Zona Waktu</th><td><?= city_tz_badge((int)($t['city_id'] ?? 0)) ?>
                        <span class="small text-muted">waktu lokal kota</span></td></tr>
                    <tr><th>Pelanggan / Site</th><td><?= e($t['customer_name'] ?: '-') ?> <?= $t['customer_id'] ? '(' . e($t['customer_id']) . ')' : '' ?></td></tr>
                    <tr><th>Jenis Masalah</th><td><?= e($t['problem_type']) ?> <?= priority_badge($t['priority']) ?></td></tr>
                    <tr><th>Lokasi</th><td><?= nl2br(e($t['location'])) ?>
                        <?php if ($t['latitude'] && $t['longitude']): ?>
                            <a target="_blank" rel="noopener" href="https://www.google.com/maps?q=<?= e($t['latitude'] . ',' . $t['longitude']) ?>" class="ms-1 small"><i class="bi bi-geo-alt"></i> Maps</a>
                        <?php endif; ?></td></tr>
                    <tr><th>Deskripsi</th><td><?= nl2br(e($t['description'] ?: '-')) ?></td></tr>
                    <tr><th>Dilaporkan</th><td><?= fmt_dt_tz($t['reported_at'], $cityTz) ?></td></tr>
                    <tr><th>Vendor</th><td><?= $t['vendor_name'] ? e($t['vendor_name']) . ' ' . vendor_type_badge($t['vendor_type']) . ($t['vendor_pic'] ? ' · PIC ' . e($t['vendor_pic']) : '') . ($t['vendor_phone'] ? ' · ' . e($t['vendor_phone']) : '') : '<span class="text-danger">Belum ada vendor</span>' ?></td></tr>
                    <tr><th>Ditugaskan ke Vendor</th><td><?= fmt_dt($t['assigned_at']) ?></td></tr>
                    <tr><th>Diterima Vendor</th><td>
                        <?php if ($t['accepted_at']): ?>
                            <i class="bi bi-check2-circle text-success"></i>
                            <b><?= fmt_dt_tz($t['accepted_at'], $cityTz) ?></b>
                            <span class="text-muted">— vendor menekan tombol <b>Terima &amp; Mulai Kerjakan</b></span>
                        <?php else: ?>
                            <span class="text-muted">Belum ada vendor yang menekan tombol <b>Terima &amp; Mulai Kerjakan</b></span>
                        <?php endif; ?>
                    </td></tr>
                    <tr><th>Respons Vendor</th><td>
                        <?php if ($t['accepted_at'] && $t['assigned_at']): ?>
                            <b><?= fmt_duration((int)round((strtotime($t['accepted_at']) - strtotime($t['assigned_at'])) / 60)) ?></b>
                            <span class="text-muted">setelah tiket ditugaskan</span>
                        <?php else: ?>
                            <span class="text-muted">-</span>
                        <?php endif; ?>
                    </td></tr>
                    <tr><th>Batas SLA</th><td><?= fmt_dt($t['sla_deadline']) ?>
                        <span class="small text-muted">— SLA diukur sampai pekerjaan <b>PERMANENT</b></span>
                    </td></tr>
                    <tr><th>Status SLA</th><td>
                        <?= sla_badge($t) ?>
                        <span class="small text-muted">— <?= e(sla_note($t)) ?></span>
                    </td></tr>
                    <tr><th>Waktu Pengerjaan</th><td>
                        <?= work_time_badge($t) ?>
                        <span class="small text-muted">— berjalan terus sejak <?= fmt_dt($workTime['start']) ?>, berhenti hanya saat <b>PERMANENT</b><?php if ($workTime['permanent']): ?> (<?= fmt_dt($t['completed_at']) ?>)<?php endif; ?></span>
                    </td></tr>
                    <tr><th>Laporan Dikirim</th><td><?= fmt_dt_tz($t['submitted_at'], $cityTz) ?>
                        <?php if ($t['submitted_at'] && $t['sla_deadline']):
                            $diff = (int)round((strtotime($t['submitted_at']) - strtotime($t['sla_deadline'])) / 60); ?>
                            <span class="small text-muted">(<?= $diff > 0 ? 'terlambat ' . fmt_duration($diff) : 'lebih cepat ' . fmt_duration(-$diff) ?> dari batas SLA)</span>
                        <?php endif; ?></td></tr>
                    <tr><th>BASTO</th><td>
                        <?php $basto = get_basto($id); ?>
                        <?php if ($basto): ?>
                            <span class="badge bg-success"><i class="bi bi-file-earmark-check"></i> <?= e($basto['no_basto']) ?></span>
                            <span class="small text-muted">— terbentuk otomatis dari form vendor</span>
                        <?php else: ?>
                            <span class="text-muted small">Belum terbentuk (otomatis setelah tiket dinyatakan PERMANENT)</span>
                        <?php endif; ?>
                    </td></tr>
                    <tr><th>Selesai</th><td><?= fmt_dt_tz($t['completed_at'], $cityTz) ?></td></tr>
                </table>
            </div>
        </div>

        <!-- Material -->
        <div class="card mb-3">
            <div class="card-header bg-white fw-semibold d-flex justify-content-between">
                <span><i class="bi bi-box-seam"></i> Material</span>
                <?php if ($t['inv_result']): ?>
                    <span class="badge <?= $t['inv_result'] === 'sesuai' ? 'bg-success' : 'bg-danger' ?>">Cek inventory: <?= $t['inv_result'] === 'sesuai' ? 'Sesuai' : 'Tidak Sesuai' ?></span>
                <?php endif; ?>
            </div>
            <?php if (!$materials): ?>
                <div class="card-body text-muted small">Belum ada material yang diminta / dikeluarkan.</div>
            <?php else: ?>
                <?php $signoffs = ticket_signoffs($id); $canSign = has_role('noc', 'admin', 'procurement', 'foc'); ?>
                <div class="table-responsive"><table class="table table-sm mb-0 small">
                    <thead class="table-light"><tr><th>Material</th><th class="text-end">Diminta</th><th class="text-end">Dikeluarkan</th>
                        <th class="text-end">Dipakai (vendor)</th>
                        <th class="text-end">Dikembalikan</th><th class="text-end">Selisih</th>
                        <th class="text-center" style="min-width:170px">Cek Inventory<br><span class="text-muted" style="font-size:.62rem">checklist + keterangan</span></th>
                        <?php if ($canSign): ?><th class="text-center" style="min-width:150px">Tanda Check<br><span class="text-muted" style="font-size:.62rem">PIC Region &rarr; Eval CGS</span></th><?php endif; ?><th></th></tr></thead>
                    <tbody>
                    <?php foreach ($materials as $m):
                        $diff = null;
                        if ($m['qty_returned'] !== null && $m['qty_used'] !== null) {
                            $diff = (float)$m['qty_issued'] - (float)$m['qty_returned'] - (float)$m['qty_used'];
                        }
                        $so = $signoffs['material'][(int)$m['id']] ?? [];
                        $invItem = $invChecklist[(int)$m['id']] ?? null; ?>
                        <tr>
                            <td><?= e($m['code'] . ' - ' . $m['name']) ?> <span class="text-muted">(<?= e($m['unit']) ?>)</span>
                                <?php if ((int)($m['is_direct'] ?? 0) === 1): ?><span class="badge bg-info text-dark ms-1">MS Langsung</span><?php endif; ?>
                                <?php if (!empty($m['note'])): ?><div class="text-muted" style="font-size:.68rem"><?= e($m['note']) ?></div><?php endif; ?>
                            </td>
                            <td class="text-end"><?= fmt_qty($m['qty_requested']) ?></td>
                            <td class="text-end"><?= $m['qty_issued'] === null ? '<span class="badge bg-warning text-dark">Menunggu</span>' : fmt_qty($m['qty_issued']) ?></td>
                            <td class="text-end"><?= fmt_qty($m['qty_used']) ?></td>
                            <td class="text-end"><?= fmt_qty($m['qty_returned']) ?></td>
                            <td class="text-end"><?= $diff === null ? '-' : ($diff == 0 ? '<span class="text-success">0</span>' : '<span class="text-danger fw-bold">' . fmt_qty($diff) . '</span>') ?></td>
                            <td class="text-center">
                                <?php if ($invItem): ?>
                                    <?= wf_check_cell('invCheckForm', 'inv', (string)(int)$m['id'], $invItem['checked'], $invItem['note'], $canInvCheck) ?>
                                <?php else: ?>
                                    <span class="text-muted small">-</span>
                                <?php endif; ?>
                            </td>
                            <?php if ($canSign): ?>
                                <td class="text-center">
                                    <?= signoff_cell($signoffs, 'material', (int)$m['id'], $m['code'] . ' - ' . $m['name']) ?>
                                </td>
                            <?php endif; ?>
                            <td class="text-end">
                                <?php if ($isMyTicket && $m['qty_issued'] === null): ?>
                                    <form method="post" action="<?= url('ticket_action.php') ?>" class="d-inline" data-confirm="Batalkan permintaan material ini?">
                                        <?= csrf_field() ?><input type="hidden" name="ticket_id" value="<?= $id ?>"><input type="hidden" name="line_id" value="<?= $m['id'] ?>">
                                        <button name="action" value="cancel_request" class="btn btn-link btn-sm text-danger p-0" title="Batalkan"><i class="bi bi-x-circle"></i></button>
                                    </form>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table></div>
                <div class="card-footer bg-white small text-muted">
                    Selisih = Dikeluarkan − Dikembalikan − Dipakai. Harus 0 agar sesuai.
                    <br><i class="bi bi-info-circle"></i> Kolom <b>Cek Inventory</b> diisi oleh Inventory (checklist + keterangan per material).
                    Semua material wajib dicentang sebelum form diteruskan ke FOC. Isi form vendor <b>tidak dapat diubah</b> oleh Inventory/FOC.
                </div>
            <?php endif; ?>
        </div>

        <!-- Laporan Vendor -->
        <?php $signoffs = $signoffs ?? ticket_signoffs($id); $canSign = $canSign ?? has_role('noc', 'admin', 'procurement', 'foc'); ?>
        <div class="card mb-3">
            <div class="card-header bg-white fw-semibold d-flex justify-content-between flex-wrap gap-2">
                <span><i class="bi bi-file-earmark-text"></i> Form Laporan Vendor</span>
                <span class="d-flex align-items-center gap-2 flex-wrap">
                    <?php if ($report && $report['submitted_at']): ?>
                        <span class="badge bg-light text-dark border" title="Isi form vendor tidak dapat diubah oleh Inventory / FOC">
                            <i class="bi bi-lock"></i> Read-only
                        </span>
                        <span class="small">Tanda Check Penyelesaian: <?= signoff_badge($signoffs['penyelesaian'][0]['status'] ?? null, $signoffs['penyelesaian'][0]['note'] ?? null) ?></span>
                    <?php endif; ?>
                </span>
            </div>
            <div class="card-body">
                <?php if (!$report || !$report['submitted_at']): ?>
                    <div class="text-muted small">Vendor belum mengirim laporan.</div>
                <?php else: ?>
                    <div class="row small mb-3">
                        <div class="col-sm-6"><b>Tiba di lokasi:</b> <?= fmt_dt($report['arrived_at']) ?></div>
                        <div class="col-sm-6"><b>Selesai dikerjakan:</b> <?= fmt_dt($report['finished_at']) ?></div>
                    </div>
                    <?php if (!empty($report['damage_type'])): ?>
                        <div class="mb-2"><b class="small">Jenis Kerusakan</b><div class="readonly-box small"><?= e($report['damage_type']) ?></div></div>
                    <?php endif; ?>
                    <?php if (!empty($report['repair_action'])): ?>
                        <div class="mb-2"><b class="small">Tindakan Perbaikan</b><div class="readonly-box small"><?= e($report['repair_action']) ?></div></div>
                    <?php endif; ?>
                    <?php if (!empty($report['damage_photo'])): ?>
                        <div class="mb-2"><b class="small">Upload Bukti Jenis Kerusakan</b><br>
                            <a href="<?= url($report['damage_photo']) ?>" target="_blank"><img src="<?= url($report['damage_photo']) ?>" class="photo-thumb" alt=""></a></div>
                    <?php endif; ?>
                    <div class="mb-2">
                        <b class="small">Detail Penyebab Gangguan</b>
                        <div class="row g-2">
                            <div class="col-md-7"><div class="readonly-box small"><?= nl2br(e($report['root_cause'])) ?></div></div>
                            <div class="col-md-5">
                                <div class="small text-muted mb-1"><i class="bi bi-clipboard-check"></i> Ceklist FOC</div>
                                <?= wf_check_cell('focEvalForm', 'foc', 'root_cause',
                                        $focState['root_cause']['checked'] ?? false,
                                        $focState['root_cause']['note'] ?? null, $canFocEval) ?>
                            </div>
                        </div>
                    </div>
                    <div class="mb-2">
                        <b class="small">Detail Tindakan yang Dilakukan</b>
                        <div class="row g-2">
                            <div class="col-md-7"><div class="readonly-box small"><?= nl2br(e($report['action_taken'])) ?></div></div>
                            <div class="col-md-5">
                                <div class="small text-muted mb-1"><i class="bi bi-clipboard-check"></i> Ceklist FOC</div>
                                <?= wf_check_cell('focEvalForm', 'foc', 'action_taken',
                                        $focState['action_taken']['checked'] ?? false,
                                        $focState['action_taken']['note'] ?? null, $canFocEval) ?>
                            </div>
                        </div>
                    </div>
                    <?php if ($report['notes']): ?><div class="mb-2"><b class="small">Catatan</b><div class="readonly-box small"><?= nl2br(e($report['notes'])) ?></div></div><?php endif; ?>
                    <div class="small text-muted">Dikirim: <?= fmt_dt($report['submitted_at']) ?></div>
                    <?php if ($canSign): ?>
                        <div class="mt-2">
                            <div class="small text-muted mb-1">Tanda Check &amp; Evaluasi</div>
                            <?= signoff_cell($signoffs, 'penyelesaian', 0, 'Penyelesaian Kerusakan') ?>
                        </div>
                    <?php endif; ?>
                <?php endif; ?>
                <?php if ($photos): ?>
                    <hr>
                    <div class="small text-muted mb-2">
                        <i class="bi bi-camera"></i> Dokumentasi foto &mdash; kolom <b>Ceklist FOC</b> diisi FOC
                        (samping tiap kolom dokumentasi). Semua dokumentasi wajib dicentang sebelum link up.
                    </div>
                    <?php foreach (PHOTO_CATEGORIES as $cat => $label):
                        $list = array_filter($photos, fn($p) => $p['category'] === $cat);
                        if (!$list) continue;
                        $firstId = (int)reset($list)['id'];
                        $focKey = 'doc_' . $cat; ?>
                        <div class="small fw-semibold mb-1"><?= e($label) ?></div>
                        <div class="row g-2 mb-3">
                            <?php foreach ($list as $p): ?>
                                <div class="col-6 col-md-3"><a href="<?= url($p['file_path']) ?>" target="_blank"><img src="<?= url($p['file_path']) ?>" class="photo-thumb" alt="" loading="lazy"></a>
                                    <?= blur_badge((int)($p['is_blurry'] ?? 0), $p['blur_score'] ?? null) ?>
                                    <div class="text-muted" style="font-size:.7rem"><?= fmt_dt($p['uploaded_at']) ?></div>
                                    <?php if (!empty($p['caption'])): ?><div class="text-muted fst-italic" style="font-size:.68rem"><?= e($p['caption']) ?></div><?php endif; ?>
                                </div>
                            <?php endforeach; ?>
                        </div>
                        <?php if ($canSign): ?>
                            <div class="mb-2"><?= signoff_cell($signoffs, 'dokumentasi', $firstId, $label) ?></div>
                        <?php endif; ?>
                        <div class="mb-3">
                            <div class="small text-muted mb-1"><i class="bi bi-clipboard-check"></i> Ceklist FOC &mdash; <?= e($label) ?></div>
                            <?= wf_check_cell('focEvalForm', 'foc', $focKey,
                                    $focState[$focKey]['checked'] ?? false,
                                    $focState[$focKey]['note'] ?? null, $canFocEval) ?>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>

        <!-- Riwayat cek inventory & review FOC -->
        <?php if ($invChecks || $focReviews): ?>
            <div class="card mb-3">
                <div class="card-header bg-white fw-semibold"><i class="bi bi-clipboard-check"></i> Riwayat Pengecekan</div>
                <ul class="list-group list-group-flush small">
                    <?php foreach ($focReviews as $r): ?>
                        <li class="list-group-item"><span class="badge <?= $r['result'] === 'permanent' ? 'bg-success' : 'bg-danger' ?>">FOC: <?= ucfirst($r['result']) ?></span>
                            <?= e($r['full_name']) ?> · <?= fmt_dt($r['created_at']) ?><?php if ($r['note']): ?><div class="mt-1"><?= nl2br(e($r['note'])) ?></div><?php endif; ?></li>
                    <?php endforeach; ?>
                    <?php foreach ($invChecks as $r): ?>
                        <li class="list-group-item"><span class="badge <?= $r['result'] === 'sesuai' ? 'bg-success' : 'bg-danger' ?>">Inventory: <?= $r['result'] === 'sesuai' ? 'Sesuai' : 'Tidak Sesuai' ?></span>
                            <?= e($r['full_name']) ?> · <?= fmt_dt($r['created_at']) ?><?php if ($r['note']): ?><div class="mt-1"><?= nl2br(e($r['note'])) ?></div><?php endif; ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
        <?php endif; ?>
    </div>

    <!-- =================== PANEL AKSI =================== -->
    <div class="col-lg-4">
        <?php $actionUrl = url('ticket_action.php'); $hidden = csrf_field() . '<input type="hidden" name="ticket_id" value="' . $id . '">'; ?>

        <?php /* ---------- VENDOR ---------- */ if ($isMyTicket && $st === 'assigned'): ?>
            <div class="card mb-3 border-primary"><div class="card-body">
                <h6>Tiket baru untuk Anda</h6>
                <p class="small text-muted">Terima tiket untuk mulai bekerja. <?= SLA_START === 'accepted' ? 'SLA mulai dihitung saat Anda menerima tiket.' : 'SLA sudah berjalan sejak tiket ditugaskan.' ?></p>
                <form method="post" action="<?= $actionUrl ?>" class="mb-2"><?= $hidden ?>
                    <button name="action" value="accept" class="btn btn-success w-100"><i class="bi bi-check2-circle"></i> Terima & Mulai Kerjakan</button>
                </form>
                <form method="post" action="<?= $actionUrl ?>" data-confirm="Tolak tiket ini? Tiket akan dialihkan ke vendor lain."><?= $hidden ?>
                    <textarea name="reason" class="form-control form-control-sm mb-2" placeholder="Alasan menolak" required></textarea>
                    <button name="action" value="reject" class="btn btn-outline-danger btn-sm w-100">Tolak Tiket</button>
                </form>
            </div></div>
        <?php endif; ?>

        <?php if ($isMyTicket && in_array($st, ['on_progress', 'revision'], true)): ?>
            <div class="card mb-3 border-primary"><div class="card-body">
                <?php if ($st === 'revision'): ?>
                    <div class="alert alert-danger small">Perbaikan dinilai <b>TEMPORARY</b> oleh FOC. Lakukan perbaikan permanen lalu kirim ulang laporan.
                        <?php if ($focReviews && $focReviews[0]['note']): ?><br><b>Catatan FOC:</b> <?= e($focReviews[0]['note']) ?><?php endif; ?></div>
                <?php endif; ?>
                <a href="<?= url('vendor_report.php?id=' . $id) ?>" class="btn btn-primary w-100 mb-3"><i class="bi bi-pencil-square"></i> Isi Form Laporan & Foto</a>
                <?php if ($isMsVendor): ?>
                    <div class="alert alert-info small mb-0">
                        <i class="bi bi-info-circle"></i> Anda adalah <b>Vendor MS</b>. Material dipakai <b>langsung</b> dari form laporan
                        (stok otomatis berkurang) &mdash; tidak perlu request ke inventory.
                    </div>
                <?php else: ?>
                    <h6 class="small fw-semibold">Minta Material ke Inventory</h6>
                    <form method="post" action="<?= $actionUrl ?>"><?= $hidden ?>
                        <select name="material_id" class="form-select form-select-sm mb-2" required>
                            <option value="">- pilih material -</option>
                            <?php foreach ($allMaterials as $m): ?><option value="<?= $m['id'] ?>"><?= e($m['code'] . ' - ' . $m['name'] . ' (' . $m['unit'] . ')') ?></option><?php endforeach; ?>
                        </select>
                        <div class="input-group input-group-sm">
                            <input type="number" step="0.01" min="0.01" name="qty" class="form-control" placeholder="Jumlah" required>
                            <button name="action" value="request_material" class="btn btn-outline-primary">Minta</button>
                        </div>
                    </form>
                <?php endif; ?>
            </div></div>
        <?php endif; ?>

        <?php /* ---------- INVENTORY ---------- */ if (has_role('inventory', 'admin') && $active && $t['vendor_id']): ?>
            <?php if ($pendingIssue): ?>
                <div class="card mb-3 border-warning"><div class="card-body">
                    <h6><i class="bi bi-box-arrow-right"></i> Serahkan Material ke Vendor</h6>
                    <form method="post" action="<?= $actionUrl ?>"><?= $hidden ?>
                        <?php foreach ($pendingIssue as $m): ?>
                            <label class="form-label small mb-0"><?= e($m['name']) ?> <span class="text-muted">(diminta <?= fmt_qty($m['qty_requested']) ?> <?= e($m['unit']) ?>, stok <?= fmt_qty($m['stock']) ?>)</span></label>
                            <input type="number" step="0.01" min="0" name="issue[<?= $m['id'] ?>]" value="<?= (float)$m['qty_requested'] ?>" class="form-control form-control-sm mb-2">
                        <?php endforeach; ?>
                        <div class="form-text mb-2">Isi 0 untuk menolak permintaan.</div>
                        <button name="action" value="issue_material" class="btn btn-warning btn-sm w-100">Serahkan Material</button>
                    </form>
                </div></div>
            <?php endif; ?>

            <?php if (in_array($st, ['assigned', 'on_progress', 'revision'], true)): ?>
                <div class="card mb-3"><div class="card-body">
                    <h6 class="small fw-semibold">Tambah & Serahkan Material Langsung</h6>
                    <form method="post" action="<?= $actionUrl ?>"><?= $hidden ?>
                        <select name="material_id" class="form-select form-select-sm mb-2" required>
                            <option value="">- pilih material -</option>
                            <?php foreach ($allMaterials as $m): ?><option value="<?= $m['id'] ?>"><?= e($m['name'] . ' (stok ' . fmt_qty($m['stock']) . ' ' . $m['unit'] . ')') ?></option><?php endforeach; ?>
                        </select>
                        <div class="input-group input-group-sm">
                            <input type="number" step="0.01" min="0.01" name="qty" class="form-control" placeholder="Jumlah" required>
                            <button name="action" value="issue_direct" class="btn btn-outline-warning">Serahkan</button>
                        </div>
                    </form>
                </div></div>
            <?php endif; ?>

            <?php if ($st === 'inventory_check'): ?>
                <div class="card mb-3 border-warning"><div class="card-body">
                    <h6><i class="bi bi-box-arrow-in-left"></i> Cek Material (Inventory)</h6>
                    <p class="small text-muted">
                        Form laporan vendor bisa dilihat, tapi <b>isi form vendor tidak dapat diubah</b>.
                        <?php if ($pendingReturn): ?>
                            Masukkan jumlah material yang dikembalikan vendor (dibandingkan dengan material terpakai di laporan vendor).
                        <?php else: ?>
                            Tidak ada material yang perlu dikembalikan.
                        <?php endif; ?>
                    </p>
                    <div class="alert alert-warning small py-2">
                        <i class="bi bi-exclamation-triangle"></i>
                        Centang <b>semua material</b> di tabel <b>Material</b> (kolom <b>Cek Inventory</b> &mdash; checklist + keterangan).
                        <?php $pendingInv = inventory_pending_labels($id); ?>
                        <?php if ($pendingInv): ?>
                            <br><b>Belum dicentang (<?= count($pendingInv) ?>):</b> <?= e(implode(', ', $pendingInv)) ?>
                        <?php else: ?>
                            <br><span class="text-success">Semua material sudah dicentang.</span>
                        <?php endif; ?>
                    </div>
                    <form method="post" action="<?= $actionUrl ?>" id="invCheckForm"><?= $hidden ?>
                        <?php foreach ($pendingReturn as $m): ?>
                            <div class="border rounded p-2 mb-2 small inv-line" data-issued="<?= (float)$m['qty_issued'] ?>" data-used="<?= (float)$m['qty_used'] ?>">
                                <div class="fw-semibold"><?= e($m['name']) ?> (<?= e($m['unit']) ?>)</div>
                                <div>Keluar: <?= fmt_qty($m['qty_issued']) ?> · Dipakai (laporan vendor): <?= fmt_qty($m['qty_used']) ?></div>
                                <div class="input-group input-group-sm mt-1">
                                    <span class="input-group-text">Kembali</span>
                                    <input type="number" step="0.01" min="0" max="<?= (float)$m['qty_issued'] ?>" name="returned[<?= $m['id'] ?>]" class="form-control inv-ret" required>
                                    <span class="input-group-text inv-diff">-</span>
                                </div>
                            </div>
                        <?php endforeach; ?>
                        <label class="form-label small mt-2 mb-1">Hasil Pengecekan</label>
                        <select name="result" class="form-select form-select-sm mb-2" id="invResult" required>
                            <option value="sesuai">Sesuai</option>
                            <option value="tidak_sesuai">Tidak Sesuai</option>
                        </select>
                        <textarea name="note" class="form-control form-control-sm mb-2" placeholder="Catatan (wajib jika tidak sesuai)"></textarea>
                        <button name="action" value="inventory_check" class="btn btn-warning btn-sm w-100">Simpan Cek &amp; Teruskan ke FOC</button>
                    </form>
                </div></div>
                <script>
                document.querySelectorAll('.inv-ret').forEach(function (inp) {
                    inp.addEventListener('input', function () {
                        var mismatch = false;
                        document.querySelectorAll('.inv-line').forEach(function (line) {
                            var r = line.querySelector('.inv-ret'), d = line.querySelector('.inv-diff');
                            if (r.value === '') { d.textContent = '-'; return; }
                            var diff = +line.dataset.issued - +r.value - +line.dataset.used;
                            diff = Math.round(diff * 100) / 100;
                            d.textContent = 'Selisih ' + diff;
                            d.className = 'input-group-text inv-diff ' + (diff === 0 ? 'text-success' : 'text-danger fw-bold');
                            if (diff !== 0) mismatch = true;
                        });
                        document.getElementById('invResult').value = mismatch ? 'tidak_sesuai' : 'sesuai';
                    });
                });
                </script>
            <?php endif; ?>
        <?php endif; ?>

        <?php /* ---------- FOC / NOC: LINK UP ---------- */ if (has_role('foc', 'admin', 'noc', 'nam') && $st === 'foc_review'): ?>
            <div class="card mb-3 border-success"><div class="card-body">
                <h6><i class="bi bi-clipboard-check"></i> Link Up (FOC / NOC)</h6>
                <?php if (!inventory_done($t)): ?>
                    <div class="alert alert-danger small py-2">
                        <i class="bi bi-exclamation-triangle"></i> Form <b>belum selesai dicek Inventory</b> &mdash; FOC hanya menerima form yang sudah <b>done</b> dari Inventory.
                    </div>
                <?php else: ?>
                    <p class="small text-muted">
                        Form vendor sudah <b>done</b> oleh Inventory dan <b>tidak dapat diedit</b> oleh FOC.
                        Centang <b>semua</b> item ceklist FOC (di samping kolom dokumentasi, tindakan yang dilakukan,
                        dan detail penyebab gangguan) sebelum melanjutkan ke tahap selanjutnya.
                    </p>
                    <?php $pendingFoc = foc_pending_labels($id); ?>
                    <?php if ($pendingFoc): ?>
                        <div class="alert alert-warning small py-2">
                            <i class="bi bi-exclamation-triangle"></i>
                            <b>Belum dicentang (<?= count($pendingFoc) ?>):</b> <?= e(implode(', ', $pendingFoc)) ?>
                        </div>
                    <?php else: ?>
                        <div class="alert alert-success small py-2">
                            <i class="bi bi-check2-circle"></i> Semua item FOC sudah dicentang.
                        </div>
                    <?php endif; ?>
                    <?php if ($t['inv_result'] === 'tidak_sesuai'): ?><div class="alert alert-danger small py-2">Perhatian: hasil cek inventory <b>TIDAK SESUAI</b>.</div><?php endif; ?>
                    <form method="post" action="<?= $actionUrl ?>" id="focEvalForm"><?= $hidden ?>
                        <textarea name="note" class="form-control form-control-sm mb-2" rows="3" placeholder="Catatan review (wajib jika temporer)"></textarea>
                        <div class="d-grid gap-2">
                            <button name="action" value="save_foc_eval" class="btn btn-outline-secondary btn-sm">
                                <i class="bi bi-save"></i> Simpan Ceklist FOC
                            </button>
                            <button name="action" value="foc_permanent" class="btn btn-success"
                                onclick="return confirm('Nyatakan LINK UP PERMANEN? Tiket akan ditutup & BASTO terbentuk otomatis.')"><i class="bi bi-check-circle"></i> LINK UP | PERMANEN</button>
                            <button name="action" value="foc_temporary" class="btn btn-outline-danger"
                                onclick="return confirm('Nyatakan LINK UP TEMPORER? Form dikembalikan ke vendor.')"><i class="bi bi-arrow-counterclockwise"></i> LINK UP | TEMPORER</button>
                        </div>
                    </form>
                <?php endif; ?>
            </div></div>
        <?php endif; ?>

        <?php /* ---------- NOC / ADMIN ---------- */ if (has_role('admin', 'noc', 'procurement') && $active && in_array($st, ['open', 'assigned', 'on_progress', 'revision'], true)):
            $cands = ms_first_candidates($id, false);
            $candSkills = all_vendor_skills(); ?>
            <div class="card mb-3"><div class="card-body">
                <h6><i class="bi bi-person-check"></i> Penugasan Vendor</h6>
                <?php if ($st === 'open'): ?><div class="alert alert-warning small py-2">Belum ada vendor. Tunjuk manual atau coba auto-assign lagi.</div><?php endif; ?>
                <form method="post" action="<?= $actionUrl ?>" class="mb-2" data-confirm="Tugaskan ke vendor ini? SLA akan dihitung ulang."><?= $hidden ?>
                    <select name="vendor_id" class="form-select form-select-sm mb-2" required>
                        <option value="">- vendor available di <?= e($t['city_name']) ?><?= $tRegionName !== '-' ? ' (region ' . e($tRegionName) . ')' : '' ?> -</option>
                        <?php foreach ($cands as $v): if ((int)$v['id'] === (int)$t['vendor_id']) continue;
                            $lim = (int)$v['limit'];
                            $sk = [];
                            foreach (($candSkills[(int)$v['id']] ?? []) as $s) $sk[] = $s['name']; ?>
                            <option value="<?= $v['id'] ?>"><?= ($v['vendor_type'] ?? 'non_ms') === 'ms' ? '[MS] ' : '' ?><?= e($v['name']) ?><?= $sk ? ' [' . e(implode(', ', $sk)) . ']' : '' ?> (<?= (int)$v['load_jobs'] ?>/<?= $lim > 0 ? $lim : '∞' ?> pekerjaan aktif)</option>
                        <?php endforeach; ?>
                    </select>
                    <button name="action" value="assign" class="btn btn-outline-primary btn-sm w-100"><?= $t['vendor_id'] ? 'Alihkan ke Vendor Ini' : 'Tugaskan' ?></button>
                </form>
                <?php if ($st === 'open'): ?>
                    <form method="post" action="<?= $actionUrl ?>"><?= $hidden ?>
                        <button name="action" value="auto_assign" class="btn btn-outline-secondary btn-sm w-100"><i class="bi bi-shuffle"></i> Coba Auto-Assign Lagi</button>
                    </form>
                <?php endif; ?>
                <?php if (!$cands): ?>
                    <div class="small text-danger mt-2">Tidak ada vendor available di kota ini
                        <?= $tRegionName !== '-' ? 'untuk region ' . e($tRegionName) : '' ?>
                        (semua vendor freeze/blacklist atau sudah mencapai limit workload di wilayah tersebut).</div>
                <?php endif; ?>
            </div></div>
        <?php endif; ?>

        <?php if (has_role('admin', 'noc') && $active): ?>
            <div class="card mb-3"><div class="card-body">
                <form method="post" action="<?= $actionUrl ?>" data-confirm="Batalkan tiket ini?"><?= $hidden ?>
                    <input name="reason" class="form-control form-control-sm mb-2" placeholder="Alasan pembatalan" required>
                    <button name="action" value="cancel" class="btn btn-outline-dark btn-sm w-100">Batalkan Tiket</button>
                </form>
            </div></div>
        <?php endif; ?>

        <?php if ($rejections && $role !== 'vendor'): ?>
            <div class="card mb-3"><div class="card-body small">
                <h6 class="small fw-semibold">Vendor yang menolak</h6>
                <?php foreach ($rejections as $r): ?><div><b><?= e($r['name']) ?></b> · <?= fmt_dt($r['created_at']) ?><br><span class="text-muted"><?= e($r['reason']) ?></span></div><?php endforeach; ?>
            </div></div>
        <?php endif; ?>

        <!-- Timeline -->
        <div class="card"><div class="card-body">
            <h6><i class="bi bi-clock-history"></i> Riwayat</h6>
            <ul class="timeline small mb-0">
                <?php foreach ($logs as $l): ?>
                    <li><div class="fw-semibold"><?= e($l['note'] ?: $l['action']) ?></div>
                        <div class="text-muted"><?= fmt_dt($l['created_at']) ?> · <?= e($l['full_name'] ?? 'Sistem') ?><?= $l['role'] ? ' (' . e(ROLES[$l['role']]) . ')' : '' ?></div></li>
                <?php endforeach; ?>
            </ul>
        </div></div>
    </div>
</div>

<?php if (has_role('noc', 'admin', 'procurement', 'foc')): ?>
<div class="modal fade" id="signoffModal" tabindex="-1"><div class="modal-dialog modal-sm"><form method="post" action="<?= url('ticket_action.php') ?>" class="modal-content">
    <?= csrf_field() ?><input type="hidden" name="ticket_id" value="<?= $id ?>">
    <input type="hidden" name="action" value="signoff">
    <input type="hidden" name="scope" id="so_scope">
    <input type="hidden" name="ref_id" id="so_ref" value="0">
    <input type="hidden" name="stage" id="so_stage" value="region">
    <div class="modal-header"><h5 class="modal-title" id="so_title">Tanda Check PIC Region</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
    <div class="card-body">
        <div class="small text-muted mb-2">Item: <b id="so_label">-</b></div>
        <div class="mb-2"><label class="form-label small mb-1">Status Tanda Check</label>
            <select name="mark" id="so_mark" class="form-select form-select-sm">
                <option value="ok">OK — sesuai</option>
                <option value="marked">TANDAIN — perlu perhatian</option>
                <option value="pending">Reset — belum dicek</option>
            </select></div>
        <div class="mb-2"><label class="form-label small mb-1">Keterangan</label>
            <textarea name="note" id="so_note" class="form-control form-control-sm" rows="2" placeholder="Catatan"></textarea></div>
    </div>
    <div class="modal-footer"><button class="btn btn-primary btn-sm">Simpan</button></div>
</form></div></div>
<script>
document.getElementById('signoffModal').addEventListener('show.bs.modal', function (ev) {
    var b = ev.relatedTarget;
    document.getElementById('so_scope').value = b.dataset.scope || '';
    document.getElementById('so_ref').value = b.dataset.ref || '0';
    document.getElementById('so_stage').value = b.dataset.stage || 'region';
    document.getElementById('so_label').textContent = b.dataset.label || '-';
    document.getElementById('so_mark').value = b.dataset.status || 'pending';
    document.getElementById('so_note').value = b.dataset.note || '';
    var title = document.getElementById('so_title');
    if (title) {
        title.textContent = (b.dataset.stage === 'cgs' ? 'Evaluasi Internal CGS' : 'Tanda Check PIC Region')
            + ' - ' + (b.dataset.label || '-');
    }
});
</script>
<?php endif; ?>
<?php require __DIR__ . '/includes/footer.php'; ?>
