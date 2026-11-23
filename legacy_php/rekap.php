<?php
/**
 * Rekap bulanan (kotak 9 & 10 diagram: "SUDAH 1 BULAN" -> "REKAP").
 *
 * Rekap pekerjaan selesai per bulan: SLA, vendor, region, dan BASTO.
 */
require_once __DIR__ . '/includes/bootstrap.php';
$u = require_role('admin', 'noc', 'procurement', 'foc', 'nam');

$month = trim($_GET['month'] ?? date('Y-m'));
if (!preg_match('/^\d{4}-\d{2}$/', $month)) $month = date('Y-m');
$from = $month . '-01 00:00:00';
$to = date('Y-m-t 23:59:59', strtotime($from));

// Tiket selesai (PERMANENT) dalam periode ini.
$done = q("SELECT t.*, c.name city_name, r.name region_name, v.name vendor_name, v.vendor_type
            FROM tickets t JOIN cities c ON c.id=t.city_id LEFT JOIN regions r ON r.id=c.region_id
            LEFT JOIN vendors v ON v.id=t.vendor_id
            WHERE t.status='closed' AND t.repair_result='permanent'
              AND COALESCE(t.completed_at, t.updated_at) BETWEEN ? AND ?
            ORDER BY t.completed_at DESC", [$from, $to])->fetchAll();

$total = count($done);
$ontime = 0; $late = 0; $bastoCount = 0;
foreach ($done as $r) {
    $s = sla_state($r);
    if ($s['verdict'] === 'ontime') $ontime++;
    elseif ($s['verdict'] === 'late') $late++;
    if (q('SELECT 1 FROM basto WHERE ticket_id=?', [(int)$r['id']])->fetch()) $bastoCount++;
}
$pct = ($ontime + $late) ? round($ontime / ($ontime + $late) * 100) : 0;

// Rekap per vendor
$byVendor = [];
foreach ($done as $r) {
    $k = $r['vendor_name'] ?: '(tanpa vendor)';
    $byVendor[$k] ??= ['n' => 0, 'ontime' => 0, 'late' => 0, 'type' => $r['vendor_type'] ?? 'non_ms'];
    $byVendor[$k]['n']++;
    $s = sla_state($r);
    if ($s['verdict'] === 'ontime') $byVendor[$k]['ontime']++;
    elseif ($s['verdict'] === 'late') $byVendor[$k]['late']++;
}
uasort($byVendor, fn($a, $b) => $b['n'] <=> $a['n']);

// Rekap per region
$byRegion = [];
foreach ($done as $r) {
    $k = $r['region_name'] ?: '(tanpa region)';
    $byRegion[$k] ??= ['n' => 0, 'late' => 0];
    $byRegion[$k]['n']++;
    if (sla_state($r)['verdict'] === 'late') $byRegion[$k]['late']++;
}
uasort($byRegion, fn($a, $b) => $b['n'] <=> $a['n']);

// Rekap per jenis kegiatan
$byActivity = [];
foreach ($done as $r) {
    $k = $r['activity_type'] ?: '(tidak diisi)';
    $byActivity[$k] = ($byActivity[$k] ?? 0) + 1;
}
arsort($byActivity);

// Daftar bulan yang tersedia (12 bulan terakhir)
$months = [];
for ($i = 0; $i < 12; $i++) $months[] = date('Y-m', strtotime("-$i months"));

$pageTitle = 'Rekap Bulanan';
require __DIR__ . '/includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">
    <div>
        <h4 class="mb-0"><i class="bi bi-bar-chart-line"></i> Rekap Bulanan</h4>
        <div class="small text-muted">Rekap pekerjaan selesai (PERMANENT) per bulan.</div>
    </div>
    <div class="d-flex gap-2">
        <form method="get" class="d-flex gap-2">
            <select name="month" class="form-select form-select-sm" onchange="this.form.submit()">
                <?php foreach ($months as $m): ?>
                    <option value="<?= $m ?>" <?= $m === $month ? 'selected' : '' ?>><?= e(date('F Y', strtotime($m . '-01'))) ?></option>
                <?php endforeach; ?>
            </select>
        </form>
        <a href="<?= url('rekap.php?month=' . $month . '&export=csv') ?>" class="btn btn-sm btn-outline-secondary"><i class="bi bi-download"></i> CSV</a>
    </div>
</div>

<?php if (isset($_GET['export']) && $_GET['export'] === 'csv'):
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="rekap-' . $month . '.csv"');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF"); // BOM untuk Excel
    fputcsv($out, ['No Tiket', 'No SPK', 'Selesai', 'Kota', 'Region', 'Vendor', 'Tipe Vendor', 'Jenis Kegiatan',
        'Jenis Kerusakan', 'Tindakan', 'SLA', 'BASTO']);
    foreach ($done as $r) {
        $s = sla_state($r);
        fputcsv($out, [
            $r['ticket_no'], $r['spk_no'], fmt_dt($r['completed_at'] ?: $r['updated_at']), $r['city_name'], $r['region_name'],
            $r['vendor_name'], ($r['vendor_type'] ?? '') === 'ms' ? 'MS' : 'Non-MS',
            $r['activity_type'], $r['damage_type'] ?? '', $r['repair_action'] ?? '',
            $s['verdict'] === 'late' ? 'Lewat SLA' : ($s['verdict'] === 'ontime' ? 'Sesuai SLA' : '-'),
            (string)(q('SELECT no_basto FROM basto WHERE ticket_id=?', [(int)$r['id']])->fetchColumn() ?: ''),
        ]);
    }
    fclose($out);
    exit;
endif; ?>

<div class="row g-3 mb-4">
    <div class="col-6 col-xl-3"><div class="card h-100"><div class="card-body">
        <div class="text-muted small">Tiket Selesai</div>
        <div class="display-6"><?= $total ?></div>
        <div class="small text-muted">PERMANENT dalam <?= e(date('F Y', strtotime($from))) ?></div>
    </div></div></div>
    <div class="col-6 col-xl-3"><div class="card h-100"><div class="card-body">
        <div class="text-muted small">Kepatuhan SLA</div>
        <div class="display-6 <?= $pct < 80 && $total ? 'text-danger' : '' ?>"><?= $pct ?>%</div>
        <div class="small"><span class="text-success"><?= $ontime ?> sesuai</span> · <span class="text-danger"><?= $late ?> lewat</span></div>
    </div></div></div>
    <div class="col-6 col-xl-3"><div class="card h-100"><div class="card-body">
        <div class="text-muted small">Lewat SLA</div>
        <div class="display-6 <?= $late ? 'text-danger' : '' ?>"><?= $late ?></div>
        <div class="small text-muted">dari <?= $total ?> tiket selesai</div>
    </div></div></div>
    <div class="col-6 col-xl-3"><div class="card h-100"><div class="card-body">
        <div class="text-muted small">BASTO Terbentuk</div>
        <div class="display-6"><?= $bastoCount ?></div>
        <div class="small text-muted">dari <?= $total ?> tiket selesai</div>
    </div></div></div>
</div>

<div class="row g-3 mb-4">
    <div class="col-xl-7">
        <div class="card h-100">
            <div class="card-header bg-white fw-semibold"><i class="bi bi-truck"></i> Rekap per Vendor</div>
            <div class="table-responsive"><table class="table table-sm table-hover mb-0 small align-middle">
                <thead class="table-light"><tr><th>Vendor</th><th class="text-center">Tipe</th><th class="text-center">Selesai</th>
                    <th class="text-center">Sesuai</th><th class="text-center">Lewat</th></tr></thead>
                <tbody>
                <?php foreach ($byVendor as $name => $v): ?>
                    <tr>
                        <td class="fw-semibold"><?= e($name) ?></td>
                        <td class="text-center"><?= vendor_type_badge($v['type']) ?></td>
                        <td class="text-center num"><?= $v['n'] ?></td>
                        <td class="text-center num text-success"><?= $v['ontime'] ?></td>
                        <td class="text-center num <?= $v['late'] ? 'text-danger fw-bold' : '' ?>"><?= $v['late'] ?></td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$byVendor): ?><tr><td colspan="5" class="text-center text-muted p-3">Tidak ada pekerjaan selesai bulan ini.</td></tr><?php endif; ?>
                </tbody>
            </table></div>
        </div>
    </div>
    <div class="col-xl-5">
        <div class="card h-100">
            <div class="card-header bg-white fw-semibold"><i class="bi bi-map"></i> Rekap per Region</div>
            <div class="table-responsive"><table class="table table-sm table-hover mb-0 small align-middle">
                <thead class="table-light"><tr><th>Region</th><th class="text-center">Selesai</th><th class="text-center">Lewat SLA</th></tr></thead>
                <tbody>
                <?php foreach ($byRegion as $name => $v): ?>
                    <tr>
                        <td class="fw-semibold"><?= e($name) ?></td>
                        <td class="text-center num"><?= $v['n'] ?></td>
                        <td class="text-center num <?= $v['late'] ? 'text-danger fw-bold' : '' ?>"><?= $v['late'] ?></td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$byRegion): ?><tr><td colspan="3" class="text-center text-muted p-3">-</td></tr><?php endif; ?>
                </tbody>
            </table></div>
        </div>
    </div>
</div>

<div class="row g-3 mb-4">
    <div class="col-xl-5">
        <div class="card h-100">
            <div class="card-header bg-white fw-semibold"><i class="bi bi-diagram-3"></i> Rekap per Jenis Kegiatan</div>
            <div class="table-responsive"><table class="table table-sm table-hover mb-0 small align-middle">
                <thead class="table-light"><tr><th>Jenis Kegiatan</th><th class="text-center">Jumlah</th></tr></thead>
                <tbody>
                <?php foreach ($byActivity as $name => $n): ?>
                    <tr><td><?= e($name) ?></td><td class="text-center num"><?= $n ?></td></tr>
                <?php endforeach; ?>
                <?php if (!$byActivity): ?><tr><td colspan="2" class="text-center text-muted p-3">-</td></tr><?php endif; ?>
                </tbody>
            </table></div>
        </div>
    </div>
    <div class="col-xl-7">
        <div class="card h-100">
            <div class="card-header bg-white fw-semibold"><i class="bi bi-list-check"></i> Daftar Tiket Selesai</div>
            <div class="table-responsive" style="max-height:420px;overflow:auto"><table class="table table-sm table-hover mb-0 small">
                <thead class="table-light" style="position:sticky;top:0;z-index:1"><tr>
                    <th>Tiket</th><th>SPK</th><th>Selesai</th><th>Vendor</th><th>SLA</th><th>BASTO</th>
                </tr></thead>
                <tbody>
                <?php foreach ($done as $r): $s = sla_state($r); ?>
                    <tr>
                        <td><a href="<?= url('ticket_view.php?id=' . (int)$r['id']) ?>"><?= e($r['ticket_no']) ?></a></td>
                        <td class="text-muted"><?= e($r['spk_no'] ?: '-') ?></td>
                        <td><?= fmt_dt($r['completed_at'] ?: $r['updated_at']) ?></td>
                        <td><?= e($r['vendor_name'] ?: '-') ?></td>
                        <td><?= $s['verdict'] === 'late' ? '<span class="badge bg-danger">Lewat</span>' : ($s['verdict'] === 'ontime' ? '<span class="badge bg-success">Sesuai</span>' : '-') ?></td>
                        <td><?php $bn = q('SELECT no_basto FROM basto WHERE ticket_id=?', [(int)$r['id']])->fetchColumn();
                            echo $bn ? '<a href="' . url('basto.php?ticket_id=' . (int)$r['id']) . '">' . e($bn) . '</a>' : '-'; ?></td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$done): ?><tr><td colspan="6" class="text-center text-muted p-4">Tidak ada tiket selesai pada bulan ini.</td></tr><?php endif; ?>
                </tbody>
            </table></div>
        </div>
    </div>
</div>
<?php require __DIR__ . '/includes/footer.php'; ?>
