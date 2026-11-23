<?php
require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/ticket_table.php';
$u = require_login();
[$where, $params] = ticket_scope($u);

$f = [
    'q' => trim($_GET['q'] ?? ''),
    'status' => $_GET['status'] ?? '',
    'city' => (int)($_GET['city'] ?? 0),
    'sla' => $_GET['sla'] ?? '',
    'from' => $_GET['from'] ?? '',
    'to' => $_GET['to'] ?? '',
    'repair' => $_GET['repair'] ?? '',
];
if ($f['q'] !== '') {
    $where .= ' AND (t.ticket_no LIKE ? OR t.noc_ref LIKE ? OR t.customer_name LIKE ? OR t.location LIKE ? OR t.problem_type LIKE ?)';
    array_push($params, ...array_fill(0, 5, '%' . $f['q'] . '%'));
}
if (isset(TICKET_STATUS[$f['status']])) { $where .= ' AND t.status=?'; $params[] = $f['status']; }
if (in_array($f['repair'], ['permanent', 'temporary'], true)) { $where .= ' AND t.repair_result=?'; $params[] = $f['repair']; }
if ($f['city']) { $where .= ' AND t.city_id=?'; $params[] = $f['city']; }
if (in_array($f['sla'], ['ontime', 'late'], true)) {
    // SLA dihitung sampai PERMANENT -> pakai ekspresi live, bukan kolom cache.
    $where .= ' AND ' . sla_case_sql('t') . '=?';
    $params[] = $f['sla'];
}
if ($f['from']) { $where .= ' AND DATE(t.created_at) >= ?'; $params[] = $f['from']; }
if ($f['to']) { $where .= ' AND DATE(t.created_at) <= ?'; $params[] = $f['to']; }

$page = max(1, (int)($_GET['page'] ?? 1));
$per = 25;
$total = (int)q("SELECT COUNT(*) FROM tickets t WHERE $where", $params)->fetchColumn();
$rows = q("SELECT t.*, c.name city_name, v.name vendor_name FROM tickets t JOIN cities c ON c.id=t.city_id
           LEFT JOIN vendors v ON v.id=t.vendor_id WHERE $where ORDER BY t.id DESC LIMIT $per OFFSET " . (($page - 1) * $per), $params)->fetchAll();
$cities = q('SELECT * FROM cities ORDER BY name')->fetchAll();

$pageTitle = 'Tiket';
require __DIR__ . '/includes/header.php';
?>
<?= page_head('Daftar Tiket', 'ticket-detailed', 'Kelola dan pantau seluruh tiket perbaikan jaringan',
    has_role('admin', 'noc')
        ? '<a href="' . url('ticket_create.php') . '" class="btn btn-primary"><i class="bi bi-plus-circle"></i> Buat Tiket</a>'
        : '') ?>
<form class="card card-body mb-3">
    <div class="row g-2">
        <div class="col-md-3"><input name="q" class="form-control form-control-sm" placeholder="Cari no tiket / ref NOC / pelanggan / lokasi" value="<?= e($f['q']) ?>"></div>
        <div class="col-6 col-md-2"><select name="status" class="form-select form-select-sm">
            <option value="">Semua status</option>
            <?php foreach (TICKET_STATUS as $k => [$l]): ?><option value="<?= $k ?>" <?= $f['status'] === $k ? 'selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?>
        </select></div>
        <div class="col-6 col-md-2"><select name="repair" class="form-select form-select-sm">
            <option value="">Semua hasil</option>
            <option value="permanent" <?= $f['repair'] === 'permanent' ? 'selected' : '' ?>>Permanent</option>
            <option value="temporary" <?= $f['repair'] === 'temporary' ? 'selected' : '' ?>>Temporary</option>
        </select></div>
        <div class="col-6 col-md-2"><select name="city" class="form-select form-select-sm">
            <option value="">Semua kota</option>
            <?php foreach ($cities as $c): ?><option value="<?= $c['id'] ?>" <?= $f['city'] == $c['id'] ? 'selected' : '' ?>><?= e($c['name']) ?></option><?php endforeach; ?>
        </select></div>
        <div class="col-6 col-md-1"><select name="sla" class="form-select form-select-sm">
            <option value="">SLA</option>
            <option value="ontime" <?= $f['sla'] === 'ontime' ? 'selected' : '' ?>>Sesuai</option>
            <option value="late" <?= $f['sla'] === 'late' ? 'selected' : '' ?>>Lewat</option>
        </select></div>
        <div class="col-6 col-md-1"><input type="date" name="from" class="form-control form-control-sm" value="<?= e($f['from']) ?>" title="Dari tanggal"></div>
        <div class="col-6 col-md-1"><input type="date" name="to" class="form-control form-control-sm" value="<?= e($f['to']) ?>" title="Sampai tanggal"></div>
        <div class="col-6 col-md-2 d-flex gap-1">
            <button class="btn btn-sm btn-primary flex-fill"><i class="bi bi-search"></i> Filter</button>
            <a href="<?= url('tickets.php') ?>" class="btn btn-sm btn-outline-secondary">Reset</a>
        </div>
    </div>
</form>
<div class="card">
    <div class="table-responsive"><?php renderTicketTable($rows, 'Tidak ada tiket yang cocok.'); ?></div>
    <?php if ($total > $per): $pages = (int)ceil($total / $per); $qs = $_GET; ?>
        <div class="card-footer bg-white d-flex justify-content-between align-items-center small">
            <span><?= $total ?> tiket</span>
            <ul class="pagination pagination-sm mb-0">
                <?php for ($i = 1; $i <= $pages; $i++): $qs['page'] = $i; ?>
                    <li class="page-item <?= $i === $page ? 'active' : '' ?>"><a class="page-link" href="?<?= e(http_build_query($qs)) ?>"><?= $i ?></a></li>
                <?php endfor; ?>
            </ul>
        </div>
    <?php endif; ?>
</div>
<?php require __DIR__ . '/includes/footer.php'; ?>
