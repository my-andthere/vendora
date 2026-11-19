<?php
/**
 * Dashboard Procurement.
 *
 * Berbeda dari dashboard umum (dashboard.php) yang dipakai Admin/NOC,
 * halaman ini khusus role `procurement`: fokus pada procurement & performance vendor
 * (SLA, beban kerja vendor,-region, material) + board kanban progres tiket.
 */
require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/kanban.php';
require_once __DIR__ . '/includes/ticket_table.php';
$u = require_role('procurement');

// Procurement melihat SEMUA tiket (bukan hanya miliknya seperti vendor).
[$where, $params] = ticket_scope($u);

// ------------------------------------------------------------------
// Angka-angka ringkas
// ------------------------------------------------------------------
$counts = array_fill_keys(array_keys(TICKET_STATUS), 0);
foreach (q("SELECT status, COUNT(*) n FROM tickets t WHERE $where GROUP BY status", $params)->fetchAll() as $r) {
    $counts[$r['status']] = (int)$r['n'];
}
$activeTickets = $counts['open'] + $counts['assigned'] + $counts['on_progress']
    + $counts['inventory_check'] + $counts['foc_review'] + $counts['revision'];

// Kepatuhan SLA: hanya tiket yang sudah PERMANENT (lihat procurement-sla.md)
$sla = q("SELECT
    SUM(CASE WHEN t.status='closed' AND t.repair_result='permanent'
              AND COALESCE(t.work_finished_at, t.completed_at, t.updated_at) <= t.sla_deadline THEN 1 ELSE 0 END) ontime,
    SUM(CASE WHEN t.status='closed' AND t.repair_result='permanent'
              AND COALESCE(t.work_finished_at, t.completed_at, t.updated_at) >  t.sla_deadline THEN 1 ELSE 0 END) late,
    SUM(CASE WHEN t.status NOT IN ('closed','cancelled') AND t.sla_deadline IS NOT NULL AND NOW() > t.sla_deadline THEN 1 ELSE 0 END) running_late
    FROM tickets t WHERE $where", $params)->fetch();
$slaTotal = (int)$sla['ontime'] + (int)$sla['late'];
$slaPct = $slaTotal ? round($sla['ontime'] / $slaTotal * 100) : 0;
$slaRunningLate = (int)$sla['running_late'];

// Tiket yang butuh perhatian: belum ada vendor, atau sudah lewat SLA
$needAttention = q("SELECT t.*, c.name city_name, v.name vendor_name
    FROM tickets t JOIN cities c ON c.id=t.city_id LEFT JOIN vendors v ON v.id=t.vendor_id
    WHERE $where AND t.status NOT IN ('closed','cancelled')
      AND (t.vendor_id IS NULL OR (t.sla_deadline IS NOT NULL AND NOW() > t.sla_deadline))
    ORDER BY t.vendor_id IS NULL DESC, t.sla_deadline IS NULL, t.sla_deadline ASC, t.id DESC
    LIMIT 15", $params)->fetchAll();

// Rekap per region
$regionStats = q("SELECT r.name region_name,
        COUNT(*) total,
        SUM(CASE WHEN t.status NOT IN ('closed','cancelled') THEN 1 ELSE 0 END) aktif,
        SUM(CASE WHEN " . sla_case_sql('t') . "='late' THEN 1 ELSE 0 END) late
    FROM tickets t JOIN cities c ON c.id=t.city_id LEFT JOIN regions r ON r.id=c.region_id
    WHERE $where GROUP BY r.id, r.name ORDER BY total DESC", $params)->fetchAll();

// Rekap vendor: beban kerja + performa SLA
$vendorStats = q("SELECT v.id, v.name, c.name city_name, v.status,
        (SELECT COUNT(*) FROM tickets t WHERE t.vendor_id=v.id AND t.status IN ('assigned','on_progress','revision')) beban,
        (SELECT COUNT(*) FROM tickets t WHERE t.vendor_id=v.id AND t.status='closed') selesai,
        (SELECT COUNT(*) FROM tickets t WHERE t.vendor_id=v.id AND " . sla_case_sql('t') . "='late') late,
        (SELECT COUNT(*) FROM tickets t WHERE t.vendor_id=v.id AND " . sla_case_sql('t') . " IS NOT NULL) total_sla
    FROM vendors v JOIN cities c ON c.id=v.city_id
    ORDER BY late DESC, beban DESC, v.name")->fetchAll();

// Material: stok menipis (dikurangi dari material yang sudah keluar)
$lowStock = q('SELECT id, code, name, unit, stock FROM materials
    WHERE is_active=1 AND stock <= 10 ORDER BY stock ASC, name LIMIT 8')->fetchAll();
$invMismatch = (int)q("SELECT COUNT(*) FROM tickets t WHERE $where AND t.inv_result='tidak_sesuai'", $params)->fetchColumn();

$actionTitle = 'Tiket yang perlu perhatian (belum ada vendor / lewat SLA)';
$pageTitle = 'Dashboard Procurement';
require __DIR__ . '/includes/header.php';
?>

<?= page_head('Dashboard Procurement', 'clipboard-data', 'Monitoring SLA, beban kerja vendor, dan progres pengerjaan tiket.',
    '<a href="' . url('vendors.php') . '" class="btn btn-sm"><i class="bi bi-truck"></i> Data Vendor</a>'
    . '<a href="' . url('ticket_create.php') . '" class="btn btn-sm btn-primary"><i class="bi bi-plus-circle"></i> Buat Tiket</a>') ?>

<?php /* ---------- KPI ---------- */ ?>
<div class="row g-3 mb-4">
    <div class="col-6 col-xl-3">
        <div class="card h-100 kpi kpi-green"><div class="card-body">
            <div class="d-flex justify-content-between align-items-start">
                <div>
                    <div class="kpi-label"><i class="bi bi-hourglass-split"></i> Tiket Aktif</div>
                    <div class="kpi-value"><?= $activeTickets ?></div>
                </div>
                <span class="kpi-icon"><i class="bi bi-hourglass-split"></i></span>
            </div>
            <div class="kpi-note">dari <?= array_sum($counts) ?> tiket total</div>
            <div class="kpi-note mt-1">
                <span class="badge">Menunggu <?= $counts['open'] ?></span>
                <span class="badge">Dikerjakan <?= $counts['on_progress'] ?></span>
                <span class="badge">Review <?= $counts['foc_review'] + $counts['inventory_check'] ?></span>
            </div>
        </div></div>
    </div>

    <div class="col-6 col-xl-3">
        <div class="card h-100 kpi kpi-teal"><div class="card-body">
            <div class="d-flex justify-content-between align-items-start">
                <div>
                    <div class="kpi-label"><i class="bi bi-speedometer2"></i> Kepatuhan SLA</div>
                    <div class="kpi-value"><?= $slaPct ?>%</div>
                </div>
                <span class="kpi-icon"><i class="bi bi-speedometer2"></i></span>
            </div>
            <div class="kpi-note">SLA maks <?= fmt_duration(SLA_MINUTES) ?> · dihitung sampai PERMANENT</div>
            <div class="kpi-note mt-1">
                <?= (int)$sla['ontime'] ?> sesuai · <?= (int)$sla['late'] ?> lewat
            </div>
            <div class="progress mt-1" style="height:6px">
                <div class="progress-bar" style="width:<?= $slaPct ?>%;background:#fff"></div>
                <div class="progress-bar" style="width:<?= $slaTotal ? 100 - $slaPct : 0 ?>%;background:rgba(255,255,255,.45)"></div>
            </div>
        </div></div>
    </div>

    <div class="col-6 col-xl-3">
        <div class="card h-100 kpi kpi-lime"><div class="card-body">
            <div class="d-flex justify-content-between align-items-start">
                <div>
                    <div class="kpi-label"><i class="bi bi-exclamation-triangle"></i> Lewat SLA (Berjalan)</div>
                    <div class="kpi-value"><?= $slaRunningLate ?></div>
                </div>
                <span class="kpi-icon"><i class="bi bi-exclamation-triangle"></i></span>
            </div>
            <div class="kpi-note">tiket masih berjalan &amp; sudah melewati batas SLA</div>
            <?php if ($slaRunningLate): ?>
                <a href="<?= url('tickets.php?sla=late') ?>" class="small text-white text-decoration-none">Lihat semua &rarr;</a>
            <?php endif; ?>
        </div></div>
    </div>

    <div class="col-6 col-xl-3">
        <div class="card h-100 kpi kpi-emerald"><div class="card-body">
            <div class="d-flex justify-content-between align-items-start">
                <div>
                    <div class="kpi-label"><i class="bi bi-box-seam"></i> Material Tidak Sesuai</div>
                    <div class="kpi-value"><?= $invMismatch ?></div>
                </div>
                <span class="kpi-icon"><i class="bi bi-box-seam"></i></span>
            </div>
            <div class="kpi-note">selisih pemakaian material di laporan vendor vs stok</div>
            <?php if ($lowStock): ?>
                <div class="kpi-note mt-1"><i class="bi bi-exclamation-triangle"></i> <?= count($lowStock) ?> material stok &le; 10</div>
            <?php endif; ?>
        </div></div>
    </div>
</div>

<?php /* ---------- Kanban ---------- */ ?>
<div class="mb-4">
    <h6 class="text-muted mb-2"><i class="bi bi-kanban"></i> Kanban Progres Tiket</h6>
    <?php render_kanban_section($where, $params, ['per_col' => 12]); ?>
</div>

<div class="row g-3 mb-4">
    <?php /* ---------- Tiket perlu perhatian ---------- */ ?>
    <div class="col-xl-7">
        <div class="card h-100">
            <div class="card-header bg-white fw-semibold">
                <i class="bi bi-lightning-charge text-warning"></i> <?= e($actionTitle) ?>
                <span class="badge bg-light text-muted border fw-normal ms-1"><?= count($needAttention) ?></span>
            </div>
            <div class="table-responsive">
                <?php renderTicketTable($needAttention, 'Semua tiket beres - tidak ada yang perlu perhatian.'); ?>
            </div>
        </div>
    </div>

    <?php /* ---------- Performa vendor ---------- */ ?>
    <div class="col-xl-5">
        <div class="card h-100">
            <div class="card-header bg-white fw-semibold"><i class="bi bi-truck"></i> Beban Kerja &amp; Performa SLA Vendor</div>
            <div class="table-responsive">
                <table class="table table-sm table-hover mb-0 small align-middle">
                    <thead class="table-light"><tr>
                        <th>Vendor</th><th class="text-center">Beban</th><th class="text-center">Selesai</th>
                        <th class="text-center">Lewat SLA</th><th class="text-center">Status</th>
                    </tr></thead>
                    <tbody>
                    <?php foreach ($vendorStats as $v): ?>
                        <tr>
                            <td>
                                <div class="fw-semibold"><?= e($v['name']) ?></div>
                                <div class="text-muted" style="font-size:.7rem"><?= e($v['city_name']) ?></div>
                            </td>
                            <td class="text-center num"><?= (int)$v['beban'] ?></td>
                            <td class="text-center num"><?= (int)$v['selesai'] ?></td>
                            <td class="text-center num <?= $v['late'] ? 'text-danger fw-bold' : '' ?>"><?= (int)$v['late'] ?></td>
                            <td class="text-center"><?= vendor_badge($v['status']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (!$vendorStats): ?>
                        <tr><td colspan="5" class="text-center text-muted p-3">Belum ada vendor.</td></tr>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<div class="row g-3">
    <?php /* ---------- Rekap region ---------- */ ?>
    <div class="col-xl-7">
        <div class="card h-100">
            <div class="card-header bg-white fw-semibold"><i class="bi bi-map"></i> Rekap Tiket per Region</div>
            <div class="table-responsive">
                <table class="table table-sm table-hover mb-0 small align-middle">
                    <thead class="table-light"><tr>
                        <th>Region</th><th class="text-center">Total</th><th class="text-center">Aktif</th>
                        <th class="text-center">Lewat SLA</th><th style="width:35%">Komposisi</th>
                    </tr></thead>
                    <tbody>
                    <?php foreach ($regionStats as $r): $tot = (int)$r['total'] ?: 1; ?>
                        <tr>
                            <td class="fw-semibold"><?= e($r['region_name'] ?: '(tanpa region)') ?></td>
                            <td class="text-center num"><?= (int)$r['total'] ?></td>
                            <td class="text-center num"><?= (int)$r['aktif'] ?></td>
                            <td class="text-center num <?= $r['late'] ? 'text-danger fw-bold' : '' ?>"><?= (int)$r['late'] ?></td>
                            <td>
                                <div class="progress" style="height:6px">
                                    <div class="progress-bar bg-danger" style="width:<?= round((int)$r['late'] / $tot * 100) ?>%"></div>
                                    <div class="progress-bar bg-primary" style="width:<?= round((int)$r['aktif'] / $tot * 100) ?>%"></div>
                                    <div class="progress-bar bg-success" style="width:<?= max(0, 100 - round(((int)$r['late'] + (int)$r['aktif']) / $tot * 100)) ?>%"></div>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (!$regionStats): ?>
                        <tr><td colspan="5" class="text-center text-muted p-3">Belum ada tiket.</td></tr>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <?php /* ---------- Stok material menipis ---------- */ ?>
    <div class="col-xl-5">
        <div class="card h-100">
            <div class="card-header bg-white fw-semibold">
                <i class="bi bi-box-seam"></i> Material Stok Menipis
                <span class="badge bg-light text-muted border fw-normal ms-1"><?= count($lowStock) ?></span>
            </div>
            <div class="table-responsive">
                <table class="table table-sm table-hover mb-0 small align-middle">
                    <thead class="table-light"><tr><th>Kode</th><th>Material</th><th class="text-center">Stok</th></tr></thead>
                    <tbody>
                    <?php foreach ($lowStock as $m): ?>
                        <tr>
                            <td class="fw-semibold"><?= e($m['code']) ?></td>
                            <td><?= e($m['name']) ?></td>
                            <td class="text-center num">
                                <span class="badge bg-<?= $m['stock'] <= 0 ? 'danger' : ($m['stock'] <= 5 ? 'warning text-dark' : 'secondary') ?>">
                                    <?= fmt_qty($m['stock']) ?> <?= e($m['unit']) ?>
                                </span>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (!$lowStock): ?>
                        <tr><td colspan="3" class="text-center text-muted p-3">Semua material stok aman.</td></tr>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<?php require __DIR__ . '/includes/footer.php'; ?>
