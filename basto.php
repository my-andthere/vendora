<?php
/**
 * Halaman BASTO - catatan penggunaan material.
 *
 * BASTO (Bahan Baku Operasional) OTOMATIS terbentuk dari form vendor yang sudah diisi
 * (kotak 8 diagram: "BASTO OTOMATIS TERBENTUK DARI FORM VENDOR YANG SUDAH DIISI").
 * Dibuat saat tiket dinyatakan selesai pada tahap Cek Ready for SLA.
 */
require_once __DIR__ . '/includes/bootstrap.php';
$u = require_login();

$ticketId = (int)($_GET['ticket_id'] ?? 0);
$basto = $ticketId ? get_basto($ticketId) : null;
$t = $ticketId ? get_ticket($ticketId) : null;
if ($ticketId && (!$basto || !$t || !can_view_ticket($u, $t))) {
    http_response_code(404);
    exit('BASTO tidak ditemukan.');
}

// Daftar semua BASTO (untuk user non-vendor / admin).
$bastoList = [];
if (!$ticketId && $u['role'] !== 'vendor') {
    $bastoList = q("SELECT b.id, b.no_basto, b.generated_at, t.ticket_no, t.id ticket_id,
                           c.name city_name, v.name vendor_name
                    FROM basto b JOIN tickets t ON t.id=b.ticket_id
                    JOIN cities c ON c.id=t.city_id LEFT JOIN vendors v ON v.id=t.vendor_id
                    ORDER BY b.id DESC LIMIT 200")->fetchAll();
}

$pageTitle = $basto ? 'BASTO ' . $basto['no_basto'] : 'BASTO';
require __DIR__ . '/includes/header.php';
?>

<?php if (!$basto): ?>
    <?= page_head('BASTO', 'file-earmark-check', 'Dokumen selesai pekerjaan yang terbentuk otomatis dari form vendor') ?>
    <div class="alert alert-info small">
        <i class="bi bi-info-circle"></i>
        BASTO terbentuk otomatis dari form vendor yang sudah diisi, ketika tiket dinyatakan selesai
        pada tahap <b>Cek Ready for SLA</b>. Buka halaman detail tiket untuk melihat / mencetak BASTO.
    </div>
    <div class="card"><div class="table-responsive"><table class="table table-hover mb-0 small">
        <thead class="table-light"><tr><th>No. BASTO</th><th>No. Tiket</th><th>Kota</th><th>Vendor</th><th>Dibentuk</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($bastoList as $b): ?>
            <tr>
                <td class="fw-semibold"><?= e($b['no_basto']) ?></td>
                <td><a href="<?= url('ticket_view.php?id=' . (int)$b['ticket_id']) ?>"><?= e($b['ticket_no']) ?></a></td>
                <td><?= e($b['city_name']) ?></td>
                <td><?= e($b['vendor_name'] ?: '-') ?></td>
                <td><?= fmt_dt($b['generated_at']) ?></td>
                <td class="text-end"><a href="<?= url('basto.php?ticket_id=' . (int)$b['ticket_id']) ?>" class="btn btn-sm btn-outline-secondary">Lihat</a></td>
            </tr>
        <?php endforeach; ?>
        <?php if (!$bastoList): ?>
            <tr><td colspan="6" class="text-center text-muted p-4">Belum ada BASTO.</td></tr>
        <?php endif; ?>
        </tbody>
    </table></div></div>
<?php else: ?>
    <?php $d = $basto['data']; ?>
    <a href="<?= url('ticket_view.php?id=' . $ticketId) ?>" class="small text-decoration-none d-inline-block mb-2"><i class="bi bi-arrow-left"></i> Kembali ke tiket</a>
    <?= page_head('BASTO ' . $basto['no_basto'], 'file-earmark-check', 'Otomatis terbentuk dari form vendor yang sudah diisi · ' . fmt_dt($basto['generated_at']),
        '<button class="btn btn-sm" onclick="window.print()"><i class="bi bi-printer"></i> Cetak</button>') ?>

    <div class="card mb-3">
        <div class="card-header bg-white fw-semibold">Identitas</div>
        <div class="card-body"><div class="row g-2 small">
            <?php
            $rows = [
                'No. BASTO' => $d['no_basto'] ?? '-',
                'No. Tiket' => $d['ticket_no'] ?? '-',
                'No. SPK' => $d['spk_no'] ?? '-',
                'Ref. NOC' => $d['noc_ref'] ?? '-',
                'Jenis Kegiatan' => $d['activity_type'] ?? '-',
                'Jenis Masalah' => $d['problem_type'] ?? '-',
                'Kota' => $d['city'] ?? '-',
                'Provinsi' => $d['province'] ?? '-',
                'Pelanggan' => $d['customer'] ?? '-',
                'Vendor' => ($d['vendor'] ?? '-') . ' (' . (($d['vendor_type'] ?? '') === 'ms' ? 'MS' : 'Non-MS') . ')',
                'Lokasi' => $d['location'] ?? '-',
                'Tiba di Lokasi' => fmt_dt($d['arrived_at'] ?? null),
                'Selesai Dikerjakan' => fmt_dt($d['finished_at'] ?? null),
            ];
            foreach ($rows as $k => $v): ?>
                <div class="col-sm-6 col-lg-4"><label class="form-label text-muted mb-0"><?= e($k) ?></label>
                    <div class="border rounded px-2 py-1"><?= e((string)$v) ?></div></div>
            <?php endforeach; ?>
        </div></div>
    </div>

    <div class="card mb-3">
        <div class="card-header bg-white fw-semibold">Penyelesaian Kerusakan</div>
        <div class="card-body small">
            <div class="mb-2"><b>Jenis Kerusakan:</b> <?= e($d['damage_type'] ?: '-') ?></div>
            <div class="mb-2"><b>Tindakan Perbaikan:</b> <?= e($d['repair_action'] ?: '-') ?></div>
            <div class="mb-2"><b>Detail Penyebab:</b> <div class="readonly-box"><?= nl2br(e($d['root_cause'] ?? '-')) ?></div></div>
            <div class="mb-2"><b>Detail Tindakan:</b> <div class="readonly-box"><?= nl2br(e($d['action_taken'] ?? '-')) ?></div></div>
        </div>
    </div>

    <div class="card mb-3">
        <div class="card-header bg-white fw-semibold">Penggunaan Material</div>
        <div class="table-responsive"><table class="table table-sm mb-0 small">
            <thead class="table-light"><tr><th>Kode</th><th>Material</th><th class="text-end">Jumlah</th><th>Keterangan</th></tr></thead>
            <tbody>
            <?php foreach (($d['materials'] ?? []) as $m): ?>
                <tr>
                    <td><?= e($m['code'] ?: '-') ?></td>
                    <td><?= e($m['name'] ?: '-') ?></td>
                    <td class="text-end"><?= fmt_qty($m['qty']) ?> <?= e($m['unit'] ?: '') ?></td>
                    <td><?= e($m['note'] ?: '-') ?></td>
                </tr>
            <?php endforeach; ?>
            <?php if (empty($d['materials'])): ?>
                <tr><td colspan="4" class="text-center text-muted p-3">Tidak ada material tercatat.</td></tr>
            <?php endif; ?>
            </tbody>
        </table></div>
    </div>

    <div class="card mb-3">
        <div class="card-header bg-white fw-semibold">Dokumentasi</div>
        <div class="card-body">
            <?php $labels = PHOTO_CATEGORIES; $any = false; ?>
            <?php foreach (($d['photos'] ?? []) as $p): $any = true; ?>
                <div class="small fw-semibold mb-1"><?= e($labels[$p['category']] ?? $p['category']) ?></div>
                <div class="row g-2 mb-3">
                    <div class="col-6 col-md-3"><a href="<?= url($p['path']) ?>" target="_blank"><img src="<?= url($p['path']) ?>" class="photo-thumb" alt=""></a></div>
                </div>
            <?php endforeach; ?>
            <?php if (!$any): ?><div class="text-muted small">Tidak ada foto.</div><?php endif; ?>
        </div>
    </div>
<?php endif; ?>
<?php require __DIR__ . '/includes/footer.php'; ?>
