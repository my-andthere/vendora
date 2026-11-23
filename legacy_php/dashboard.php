<?php
require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/kanban.php';
$u = require_login();
// Role procurement punya dashboard sendiri (procurement_dashboard.php).
if ($u['role'] === 'procurement') redirect('procurement_dashboard.php');
[$where, $params] = ticket_scope($u);

$counts = array_fill_keys(array_keys(TICKET_STATUS), 0);
foreach (q("SELECT status, COUNT(*) n FROM tickets t WHERE $where GROUP BY status", $params)->fetchAll() as $r) {
    $counts[$r['status']] = (int)$r['n'];
}
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
$invMismatch = (int)q("SELECT COUNT(*) FROM tickets t WHERE $where AND inv_result='tidak_sesuai'", $params)->fetchColumn();

$actions = action_tickets($u);
$recent = q("SELECT t.*, c.name city_name, v.name vendor_name FROM tickets t JOIN cities c ON c.id=t.city_id
             LEFT JOIN vendors v ON v.id=t.vendor_id WHERE $where ORDER BY t.id DESC LIMIT 10", $params)->fetchAll();

$actionTitle = [
    'admin' => 'Tiket tanpa vendor (perlu penunjukan manual)',
    'noc' => 'Tiket tanpa vendor (perlu penunjukan manual)',
    'vendor' => 'Pekerjaan aktif Anda',
    'inventory' => 'Permintaan material & pengecekan material',
    'foc' => 'Menunggu review FOC',
][$u['role']];

$pageTitle = 'Dashboard';
require __DIR__ . '/includes/header.php';
?>
<div class="d-flex justify-content-between align-items-center mb-3">
    <h4 class="mb-0">Dashboard</h4>
    <?php if (has_role('admin', 'noc')): ?>
        <a href="<?= url('ticket_create.php') ?>" class="btn btn-primary"><i class="bi bi-plus-circle"></i> Buat Tiket</a>
    <?php endif; ?>
</div>

<?php if ($u['role'] === 'vendor' && $u['vendor_status'] !== 'available'): ?>
    <div class="alert alert-warning">Status vendor Anda saat ini <?= vendor_badge($u['vendor_status']) ?> — Anda tidak akan menerima tiket baru.</div>
<?php endif; ?>

<?php if ($u['role'] === 'vendor' && $u['vendor_id']):
    $myRegions = q("SELECT vr.region_id, vr.workload_limit, r.name region_name,
        (SELECT COUNT(*) FROM tickets t JOIN cities c ON c.id=t.city_id
          WHERE t.vendor_id=vr.vendor_id AND c.region_id=vr.region_id
            AND t.status IN ('assigned','on_progress','revision')) used
        FROM vendor_regions vr JOIN regions r ON r.id=vr.region_id
        WHERE vr.vendor_id=? ORDER BY r.name", [(int)$u['vendor_id']])->fetchAll();
?>
    <?php if ($myRegions): ?>
        <div class="alert alert-info">
            <div class="fw-semibold mb-1"><i class="bi bi-speedometer"></i> Beban kerja Anda per region:</div>
            <?php foreach ($myRegions as $mr):
                $lim = (int)$mr['workload_limit']; $used = (int)$mr['used'];
                $full = $lim > 0 && $used >= $lim; ?>
                <div class="small">
                    <b><?= e($mr['region_name']) ?></b>:
                    <b><?= $used ?>/<?= $lim > 0 ? $lim : '∞' ?></b>
                    <?php if ($full): ?><span class="badge bg-danger">Penuh</span> — tidak bisa menerima pekerjaan baru di region ini.
                    <?php elseif ($lim > 0): ?>— sisa <b><?= $lim - $used ?></b> slot.
                    <?php else: ?>— tanpa batas.
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
        </div>
    <?php else: ?>
        <?php $wl = vendor_workload((int)$u['vendor_id']); ?>
        <div class="alert <?= $wl['full'] ? 'alert-danger' : 'alert-info' ?>">
            <span><i class="bi bi-speedometer"></i> Beban kerja Anda:
                <b><?= $wl['active'] ?>/<?= $wl['unlimited'] ? '∞' : (int)$wl['limit'] ?></b>
                <?php if ($wl['unlimited']): ?>(tanpa batas)
                <?php elseif ($wl['full']): ?>— <b>penuh</b>.
                <?php else: ?>— sisa <b><?= (int)$wl['free'] ?></b> slot pekerjaan baru.
                <?php endif; ?>
            </span>
        </div>
    <?php endif; ?>
<?php endif; ?>

<?php /* ---------- Kanban progres (khusus Admin) ---------- */ if ($u['role'] === 'admin'):
    render_kanban_section($where, $params, ['per_col' => 12]);
endif; ?>

<div class="row g-3 mb-4">
    <div class="col-md-6">
        <div class="card h-100"><div class="card-body">
            <h6 class="text-muted">Kepatuhan SLA (maks <?= fmt_duration(SLA_MINUTES) ?>)</h6>
            <div class="d-flex align-items-end gap-3">
                <div class="display-6"><?= $slaPct ?>%</div>
                <div class="small pb-2"><span class="text-success"><?= (int)$sla['ontime'] ?> sesuai SLA</span> ·
                    <span class="text-danger"><?= (int)$sla['late'] ?> lewat SLA</span></div>
            </div>
            <div class="progress mt-2" style="height:8px">
                <div class="progress-bar bg-success" style="width:<?= $slaPct ?>%"></div>
                <div class="progress-bar bg-danger" style="width:<?= $slaTotal ? 100 - $slaPct : 0 ?>%"></div>
            </div>
            <div class="small text-muted mt-2">Dihitung dari tiket yang sudah <b>PERMANENT</b> (SLA diukur sampai selesai).</div>
            <?php if ($slaRunningLate): ?>
                <div class="small text-danger mt-1"><i class="bi bi-exclamation-triangle"></i>
                    <?= $slaRunningLate ?> tiket masih berjalan &amp; sudah lewat batas SLA.</div>
            <?php endif; ?>
        </div></div>
    </div>
    <div class="col-md-6">
        <div class="card h-100"><div class="card-body">
            <h6 class="text-muted">Material tidak sesuai (hasil cek inventory)</h6>
            <div class="display-6 <?= $invMismatch ? 'text-danger' : '' ?>"><?= $invMismatch ?></div>
            <div class="small text-muted">Tiket dengan selisih antara material terpakai (laporan vendor) dan material keluar - kembali.</div>
        </div></div>
    </div>
</div>

<div class="card mb-4">
    <div class="card-header bg-white fw-semibold"><i class="bi bi-lightning-charge text-warning"></i> <?= e($actionTitle) ?></div>
    <div class="table-responsive">
        <?php include __DIR__ . '/includes/ticket_table.php'; renderTicketTable($actions, 'Tidak ada yang perlu ditindaklanjuti.'); ?>
    </div>
</div>

<div class="card">
    <div class="card-header bg-white fw-semibold">Tiket Terbaru</div>
    <div class="table-responsive"><?php renderTicketTable($recent, 'Belum ada tiket.'); ?></div>
</div>
<?php require __DIR__ . '/includes/footer.php'; ?>
