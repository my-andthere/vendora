<?php
/**
 * Daftar pesan/event yang dikirim ke sistem NOC.
 * Selama NOC_WEBHOOK_URL belum diisi, pesan tersimpan sebagai 'pending' dan bisa dikirim ulang setelah integrasi siap.
 */
require_once __DIR__ . '/includes/bootstrap.php';
$u = require_role('admin', 'noc');

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $u['role'] === 'admin') {
    csrf_check();
    if (NOC_WEBHOOK_URL === '') {
        flash('warning', 'NOC_WEBHOOK_URL belum diisi di config.php.');
    } else {
        $ids = q("SELECT id FROM noc_outbox WHERE send_status IN ('pending','failed') ORDER BY id LIMIT 200")->fetchAll(PDO::FETCH_COLUMN);
        $ok = 0;
        foreach ($ids as $i) $ok += noc_send((int)$i) ? 1 : 0;
        flash('info', "Terkirim {$ok} dari " . count($ids) . ' pesan.');
    }
    redirect('noc_outbox.php');
}

$rows = q('SELECT o.*, t.ticket_no FROM noc_outbox o LEFT JOIN tickets t ON t.id=o.ticket_id ORDER BY o.id DESC LIMIT 200')->fetchAll();
$eventLabel = [
    'ticket_received' => 'Tiket diterima', 'vendor_assigned' => 'Vendor ditugaskan', 'no_vendor_available' => 'Tidak ada vendor',
    'vendor_accepted' => 'Vendor mulai kerja', 'work_submitted' => 'Vendor lapor selesai', 'temporary_fix' => 'Perbaikan temporary',
    'temporary_closed' => 'SELESAI TEMPORER', 'permanent_closed' => 'SELESAI PERMANENT', 'cancelled' => 'Dibatalkan',
];
$pageTitle = 'Notifikasi ke NOC';
require __DIR__ . '/includes/header.php';
?>
<?= page_head('Notifikasi ke Sistem NOC', 'broadcast', 'Outbox webhook ke NOC (retry manual untuk yang pending/gagal)',
    $u['role'] === 'admin'
        ? '<form method="post">' . csrf_field() . '<button class="btn btn-sm btn-outline-primary"><i class="bi bi-arrow-repeat"></i> Kirim ulang pending/gagal</button></form>'
        : '') ?>
<div class="alert alert-<?= NOC_WEBHOOK_URL === '' ? 'warning' : 'success' ?> small">
    <?php if (NOC_WEBHOOK_URL === ''): ?>
        Integrasi NOC <b>belum disambungkan</b> (NOC_WEBHOOK_URL kosong di config.php). Semua event tetap dicatat di sini dan bisa dikirim ulang setelah disambungkan.
    <?php else: ?>
        Webhook NOC aktif: <code><?= e(NOC_WEBHOOK_URL) ?></code>
    <?php endif; ?>
</div>
<div class="card"><div class="table-responsive"><table class="table table-sm mb-0 small">
    <thead class="table-light"><tr><th>Waktu</th><th>Tiket</th><th>Event</th><th>Status</th><th>Payload</th></tr></thead>
    <tbody>
    <?php foreach ($rows as $r): ?>
        <tr>
            <td class="text-nowrap"><?= fmt_dt($r['created_at']) ?></td>
            <td><?= $r['ticket_id'] ? '<a href="' . url('ticket_view.php?id=' . $r['ticket_id']) . '">' . e($r['ticket_no']) . '</a>' : '-' ?></td>
            <td><?= e($eventLabel[$r['event']] ?? $r['event']) ?></td>
            <td><span class="badge bg-<?= ['pending' => 'secondary', 'sent' => 'success', 'failed' => 'danger'][$r['send_status']] ?>"><?= e($r['send_status']) ?></span>
                <?php if ($r['send_status'] === 'failed'): ?><div class="text-danger" style="max-width:200px"><?= e(substr((string)$r['response'], 0, 120)) ?></div><?php endif; ?></td>
            <td><details><summary class="text-primary">lihat</summary><pre class="mb-0 small"><?= e(json_encode(json_decode($r['payload']), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) ?></pre></details></td>
        </tr>
    <?php endforeach; ?>
    <?php if (!$rows): ?><tr><td colspan="5" class="text-center text-muted p-4">Belum ada event.</td></tr><?php endif; ?>
    </tbody>
</table></div></div>
<?php require __DIR__ . '/includes/footer.php'; ?>
