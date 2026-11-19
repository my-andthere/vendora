<?php
/**
 * Master vendor + pengaturan status (available / freeze / blacklist) secara manual.
 * Admin: kelola penuh. Procurement: tambah & edit vendor (+ region/limit/skill).
 * NOC: lihat saja.
 */
require_once __DIR__ . '/includes/bootstrap.php';
$u = require_role('admin', 'noc', 'procurement');
$isAdmin = $u['role'] === 'admin';
// Procurement boleh menambah & mengubah data vendor (bukan mengubah status).
$canManage = $u['role'] === 'admin' || $u['role'] === 'procurement';
$cities = q('SELECT * FROM cities ORDER BY name')->fetchAll();
$regions = q('SELECT * FROM regions ORDER BY name')->fetchAll();
$skills = q('SELECT * FROM skills WHERE is_active=1 ORDER BY name')->fetchAll();
$skillMap = [];
foreach ($skills as $s) $skillMap[(int)$s['id']] = $s;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $canManage) {
    csrf_check();
    $act = $_POST['act'] ?? '';
    $vid = (int)($_POST['id'] ?? 0);

    if ($act === 'save') {
        $f = array_map('trim', [
            'name' => $_POST['name'] ?? '', 'city_id' => $_POST['city_id'] ?? '', 'pic_name' => $_POST['pic_name'] ?? '',
            'phone' => $_POST['phone'] ?? '', 'email' => $_POST['email'] ?? '', 'address' => $_POST['address'] ?? '',
        ]);
        $wlimit = (int)($_POST['workload_limit'] ?? DEFAULT_WORKLOAD_LIMIT);
        if ($wlimit < 0) $wlimit = 0;
        $vType = in_array($_POST['vendor_type'] ?? '', ['ms', 'non_ms'], true) ? $_POST['vendor_type'] : 'non_ms';
        $skillIds = array_values(array_unique(array_filter(array_map('intval', (array)($_POST['skill_ids'] ?? [])))));
        // Region + limit workload per region (checkbox menentukan region mana yang aktif)
        $regionLimits = [];
        foreach (array_keys((array)($_POST['region_id'] ?? [])) as $rid) {
            $rid = (int)$rid;
            if ($rid <= 0) continue;
            $regionLimits[$rid] = max(0, (int)($_POST['region_limit'][$rid] ?? DEFAULT_WORKLOAD_LIMIT));
        }
        if ($f['name'] === '' || !q('SELECT 1 FROM cities WHERE id=?', [(int)$f['city_id']])->fetch()) {
            flash('danger', 'Nama vendor dan kota wajib diisi.');
        } elseif ($vid) {
            q('UPDATE vendors SET name=?, city_id=?, pic_name=?, phone=?, email=?, address=?, workload_limit=?, vendor_type=? WHERE id=?',
                [...array_values($f), $wlimit, $vType, $vid]);
            sync_vendor_skills($vid, $skillIds);
            if (isset($_POST['has_regions'])) sync_vendor_regions($vid, $regionLimits);
            flash('success', 'Vendor diperbarui.');
        } else {
            q('INSERT INTO vendors (name, city_id, pic_name, phone, email, address, workload_limit, vendor_type, status_changed_at) VALUES (?,?,?,?,?,?,?,?,NOW())',
                [...array_values($f), $wlimit, $vType]);
            $newId = (int)db()->lastInsertId();
            sync_vendor_skills($newId, $skillIds);
            if ($regionLimits) sync_vendor_regions($newId, $regionLimits);
            flash('success', 'Vendor ditambahkan (status Available).');
        }
    } elseif ($act === 'add_skill') {
        if (!$isAdmin) {
            flash('danger', 'Hanya Admin yang bisa menambah master skill.');
            redirect('vendors.php');
        }
        $sname = trim($_POST['skill_name'] ?? '');
        $sdesc = trim($_POST['skill_desc'] ?? '');
        if ($sname === '') {
            flash('danger', 'Nama skill wajib diisi.');
        } else {
            try {
                q('INSERT INTO skills (name, description) VALUES (?,?)', [$sname, $sdesc ?: null]);
                flash('success', 'Skill "' . $sname . '" ditambahkan.');
            } catch (PDOException $ex) {
                flash('danger', 'Skill "' . $sname . '" sudah ada.');
            }
        }
    } elseif ($act === 'del_skill') {
        if (!$isAdmin) {
            flash('danger', 'Hanya Admin yang bisa menghapus master skill.');
            redirect('vendors.php');
        }
        $sid = (int)($_POST['skill_id'] ?? 0);
        $used = (int)q('SELECT COUNT(*) FROM vendor_skills WHERE skill_id=?', [$sid])->fetchColumn();
        if ($used > 0) {
            flash('danger', 'Skill masih dipakai ' . $used . ' vendor. Hapus dari vendor dulu.');
        } else {
            q('DELETE FROM skills WHERE id=?', [$sid]);
            flash('success', 'Skill dihapus.');
        }
    } elseif ($act === 'status') {
        if (!$isAdmin) {
            flash('danger', 'Hanya Admin yang bisa mengubah status vendor.');
            redirect('vendors.php');
        }
        $status = $_POST['status'] ?? '';
        $note = trim($_POST['status_note'] ?? '');
        $until = null;
        if (!isset(VENDOR_STATUS[$status])) {
            flash('danger', 'Status tidak valid.');
            redirect('vendors.php');
        }
        if ($status === 'freeze') {
            $until = ($_POST['freeze_until'] ?? '') !== '' ? date('Y-m-d H:i:s', strtotime($_POST['freeze_until'])) : null;
            if (!$until || strtotime($until) <= time()) {
                flash('danger', 'Status freeze wajib punya tanggal berakhir di masa depan.');
                redirect('vendors.php');
            }
        }
        if ($status === 'blacklist' && $note === '') {
            flash('danger', 'Alasan blacklist wajib diisi.');
            redirect('vendors.php');
        }
        q('UPDATE vendors SET status=?, freeze_until=?, status_note=?, status_changed_at=NOW() WHERE id=?', [$status, $until, $note ?: null, $vid]);
        $active = (int)q("SELECT COUNT(*) FROM tickets WHERE vendor_id=? AND status IN ('assigned','on_progress','revision')", [$vid])->fetchColumn();
        flash('success', 'Status vendor diubah menjadi ' . VENDOR_STATUS[$status][0] . '.'
            . ($status !== 'available' && $active ? " Vendor ini masih memiliki {$active} tiket aktif — alihkan manual jika perlu." : ''));
    }
    redirect('vendors.php');
}

$fcity = (int)($_GET['city'] ?? 0);
$fstatus = $_GET['status'] ?? '';
$fregion = (int)($_GET['region'] ?? 0);
$fskill = (int)($_GET['skill'] ?? 0);
$fvtype = $_GET['vtype'] ?? '';
$where = '1=1';
$params = [];
if ($fcity) { $where .= ' AND v.city_id=?'; $params[] = $fcity; }
if (isset(VENDOR_STATUS[$fstatus])) { $where .= ' AND v.status=?'; $params[] = $fstatus; }
if (in_array($fvtype, ['ms', 'non_ms'], true)) { $where .= ' AND v.vendor_type=?'; $params[] = $fvtype; }
if ($fregion) {
    // vendor terdaftar di region tsb, atau (belum punya daftar region) kota-nya di region tsb
    $where .= ' AND (EXISTS (SELECT 1 FROM vendor_regions vr WHERE vr.vendor_id=v.id AND vr.region_id=?)'
        . ' OR (NOT EXISTS (SELECT 1 FROM vendor_regions vr2 WHERE vr2.vendor_id=v.id) AND c.region_id=?))';
    $params[] = $fregion;
    $params[] = $fregion;
}
if ($fskill) {
    $where .= ' AND EXISTS (SELECT 1 FROM vendor_skills vsk WHERE vsk.vendor_id=v.id AND vsk.skill_id=?)';
    $params[] = $fskill;
}
$wlList = "'" . implode("','", WORKLOAD_STATUSES) . "'";
$vendors = q("SELECT v.*, c.name city_name,
        (SELECT COUNT(*) FROM tickets t WHERE t.vendor_id=v.id AND t.status IN ('assigned','on_progress','inventory_check','foc_review','revision')) active_jobs,
        (SELECT COUNT(*) FROM tickets t WHERE t.vendor_id=v.id AND t.status IN ($wlList)) workload_jobs,
        (SELECT COUNT(*) FROM tickets t WHERE t.vendor_id=v.id AND t.status='closed') done_jobs,
        (SELECT COUNT(*) FROM tickets t WHERE t.vendor_id=v.id AND " . sla_case_sql('t') . "='late') late_jobs,
        (SELECT COUNT(*) FROM tickets t WHERE t.vendor_id=v.id AND t.sla_deadline IS NOT NULL AND t.status<>'cancelled') sla_jobs,
        (SELECT COUNT(*) FROM foc_reviews f JOIN tickets t ON t.id=f.ticket_id WHERE t.vendor_id=v.id AND f.result='temporary') temp_count
    FROM vendors v JOIN cities c ON c.id=v.city_id WHERE $where ORDER BY c.name, v.name", $params)->fetchAll();

// Pendaftaran vendor per region (sumber kebenaran limit) + beban terpakai di region tsb
$vrByVendor = [];
foreach (q("SELECT vr.vendor_id, vr.region_id, vr.workload_limit, r.name region_name,
             (SELECT COUNT(*) FROM tickets t JOIN cities c ON c.id=t.city_id
               WHERE t.vendor_id=vr.vendor_id AND c.region_id=vr.region_id
                 AND t.status IN ('assigned','on_progress','revision')) used
             FROM vendor_regions vr JOIN regions r ON r.id=vr.region_id
             ORDER BY r.name")->fetchAll() as $ru) {
    $vrByVendor[(int)$ru['vendor_id']][] = $ru;
}

// Technical Skill tiap vendor
$skillByVendor = all_vendor_skills();

// Berapa vendor yang memakai tiap skill (untuk master skill)
$skillUsage = [];
foreach (q('SELECT skill_id, COUNT(*) c FROM vendor_skills GROUP BY skill_id')->fetchAll() as $su) {
    $skillUsage[(int)$su['skill_id']] = (int)$su['c'];
}

// Kota + region kota tiap vendor (dipakai untuk fallback & pengelompokan)
$cityMeta = [];
foreach (q("SELECT v.id vendor_id, c.id city_id, c.name city_name, c.region_id, r.name region_name,
             (SELECT COUNT(*) FROM tickets t JOIN cities c2 ON c2.id=t.city_id
               WHERE t.vendor_id=v.id AND c2.region_id=c.region_id
                 AND t.status IN ('assigned','on_progress','revision')) used
             FROM vendors v JOIN cities c ON c.id=v.city_id
             LEFT JOIN regions r ON r.id=c.region_id")->fetchAll() as $cm) {
    $cityMeta[(int)$cm['vendor_id']] = $cm;
}

// Susun pengelompokan: Region -> Kota -> Vendor
// Vendor yang terdaftar di 2 region muncul di masing-masing blok region,
// karena limit-nya memang berbeda per region.
$groups = [];
foreach ($vendors as $v) {
    $vid = (int)$v['id'];
    $v['skill_list'] = $skillByVendor[$vid] ?? [];
    $v['skill_ids'] = array_map(fn($s) => (int)$s['id'], $v['skill_list']);
    $cm = $cityMeta[$vid] ?? null;
    $regs = $vrByVendor[$vid] ?? [];
    // Data region vendor (untuk pre-check di form edit)
    $v['region_ids'] = array_map(fn($rr) => (int)$rr['region_id'], $regs);
    $v['region_limits'] = [];
    foreach ($regs as $rr) {
        $v['region_limits'][(int)$rr['region_id']] = (int)$rr['workload_limit'];
    }
    $entries = [];
    if ($regs) {
        foreach ($regs as $rr) {
            $entries[] = [
                'region_id' => (int)$rr['region_id'],
                'region_name' => $rr['region_name'],
                'limit' => (int)$rr['workload_limit'],
                'used' => (int)$rr['used'],
                'fallback' => false,
            ];
        }
    } else {
        $entries[] = [
            'region_id' => ($cm && $cm['region_id']) ? (int)$cm['region_id'] : 0,
            'region_name' => ($cm && $cm['region_name']) ? $cm['region_name'] : 'Tanpa Region',
            'limit' => (int)$v['workload_limit'],
            'used' => $cm ? (int)$cm['used'] : 0,
            'fallback' => true,
        ];
    }
    foreach ($entries as $en) {
        $rkey = $en['region_id'] > 0 ? 'r' . $en['region_id'] : 'none';
        $ckey = 'c' . (int)$v['city_id'];
        if (!isset($groups[$rkey])) {
            $groups[$rkey] = ['id' => $en['region_id'], 'name' => $en['region_name'], 'cities' => [],
                'vendors' => 0, 'limit' => 0, 'used' => 0, 'unlimited' => false];
        }
        if (!isset($groups[$rkey]['cities'][$ckey])) {
            $groups[$rkey]['cities'][$ckey] = ['name' => $v['city_name'], 'vendors' => []];
        }
        $groups[$rkey]['cities'][$ckey]['vendors'][] = ['vendor' => $v] + $en;
        $groups[$rkey]['vendors']++;
        $groups[$rkey]['used'] += $en['used'];
        if ($en['limit'] > 0) { $groups[$rkey]['limit'] += $en['limit']; } else { $groups[$rkey]['unlimited'] = true; }
    }
}
// Urutkan: regionbynama, "Tanpa Region" di akhir
uasort($groups, function ($a, $b) {
    if ($a['name'] === 'Tanpa Region') return $b['name'] === 'Tanpa Region' ? 0 : 1;
    if ($b['name'] === 'Tanpa Region') return -1;
    return strcmp($a['name'], $b['name']);
});
foreach ($groups as &$g) {
    uasort($g['cities'], function ($a, $b) { return strcmp($a['name'], $b['name']); });
}
unset($g);

$pageTitle = 'Vendor';
require __DIR__ . '/includes/header.php';
?>
<?= page_head('Vendor', 'truck', 'Mitraulknerasa, status, skill & batas beban kerja per region',
    $canManage
        ? '<button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#vendorModal" onclick="editVendor({})"><i class="bi bi-plus-circle"></i> Tambah Vendor</button>'
        : '') ?>

<div class="row g-2 mb-3 small">
    <?php foreach (VENDOR_STATUS as $k => [$l, $c, $desc]): ?>
        <div class="col-md-4"><div class="card card-body py-2"><div><?= vendor_badge($k) ?> <span class="text-muted"><?= e($desc) ?></span></div></div></div>
    <?php endforeach; ?>
</div>

<form class="card card-body mb-3"><div class="row g-2">
    <div class="col-md-3"><select name="region" class="form-select form-select-sm"><option value="">Semua region</option>
        <?php foreach ($regions as $r): ?><option value="<?= $r['id'] ?>" <?= $fregion == $r['id'] ? 'selected' : '' ?>><?= e($r['name']) ?></option><?php endforeach; ?></select></div>
    <div class="col-md-3"><select name="city" class="form-select form-select-sm"><option value="">Semua kota</option>
        <?php foreach ($cities as $c): ?><option value="<?= $c['id'] ?>" <?= $fcity == $c['id'] ? 'selected' : '' ?>><?= e($c['name']) ?></option><?php endforeach; ?></select></div>
    <div class="col-md-3"><select name="status" class="form-select form-select-sm"><option value="">Semua status</option>
        <?php foreach (VENDOR_STATUS as $k => [$l]): ?><option value="<?= $k ?>" <?= $fstatus === $k ? 'selected' : '' ?>><?= $l ?></option><?php endforeach; ?></select></div>
    <div class="col-md-3"><select name="skill" class="form-select form-select-sm"><option value="">Semua skill</option>
        <?php foreach ($skills as $s): ?><option value="<?= $s['id'] ?>" <?= $fskill == $s['id'] ? 'selected' : '' ?>><?= e($s['name']) ?></option><?php endforeach; ?></select></div>
    <div class="col-md-2"><select name="vtype" class="form-select form-select-sm"><option value="">Semua tipe</option>
        <option value="ms" <?= $fvtype === 'ms' ? 'selected' : '' ?>>Vendor MS</option>
        <option value="non_ms" <?= $fvtype === 'non_ms' ? 'selected' : '' ?>>Vendor Non-MS</option>
    </select></div>
    <div class="col-md-2"><button class="btn btn-sm btn-primary w-100">Filter</button></div>
    <div class="col-md-1 d-flex align-items-center justify-content-end text-muted small"><i class="bi bi-diagram-3 me-1"></i>Group</div>
</div></form>

<style>
.vtable { font-size:.8125rem; }
.vtable td, .vtable th { vertical-align:top; padding:.35rem .45rem; line-height:1.35; }
.vtable .num { white-space:nowrap; }
.vtable .vgrp-no { background:#fff3c4; font-weight:700; text-align:center; vertical-align:top; }
.vtable .vgrp-region { background:#dce8f8; text-align:center; vertical-align:top; }
.vtable .vgrp-city { background:#eef3fa; font-weight:600; vertical-align:top; }
.vtable .vgrp-meta { font-size:.68rem; color:#6c757d; font-weight:400; line-height:1.3; white-space:nowrap; }
.vtable .vgrp-title { font-weight:700; line-height:1.2; }
.vtable .vendor-name { font-weight:600; }
.skill-badge { background:#eef7ee; color:#2f6f3e; border:1px solid #cfe6d3; font-weight:500; font-size:.66rem; margin:0 2px 2px 0; white-space:nowrap; }
.skill-badge .skill-count { background:#dcecdc; color:#2f6f3e; border-radius:.5rem; padding:0 .35rem; font-size:.62rem; }
.skill-badge .btn-close { filter:none; opacity:.5; }
.skill-badge .btn-close:hover { opacity:1; }
.skill-picker { max-height:170px; overflow-y:auto; border:1px solid #dee2e6; border-radius:.375rem; padding:.5rem; background:#fff; }
.skill-picker label { display:flex; align-items:center; gap:.4rem; font-size:.85rem; padding:.15rem .1rem; }
.skill-picker label:hover { background:#f1f5f9; border-radius:.25rem; }
.skill-picker .region-row { display:flex; align-items:center; gap:.5rem; padding:.15rem .1rem; }
.skill-picker .region-row:hover { background:#f1f5f9; border-radius:.25rem; }
.vtable tbody tr:hover > td.vgrp-no { background:#ffeb9c !important; }
.vtable tbody tr:hover > td.vgrp-region { background:#c9dcf5 !important; }
.vtable tbody tr:hover > td.vgrp-city { background:#e2ebf7 !important; }
</style>

<div class="card"><div class="table-responsive">
    <table class="table table-bordered table-hover mb-0 align-middle vtable">
        <colgroup>
            <col style="width:38px"><col style="width:100px"><col style="width:112px"><col style="width:148px">
            <col style="width:112px"><col style="width:124px"><col style="width:54px"><col style="width:62px">
            <col style="width:94px"><col style="width:74px"><col style="width:150px"><?php if ($canManage): ?><col style="width:74px"><?php endif; ?>
        </colgroup>
        <thead class="table-light">
            <tr>
                <th class="text-center">No</th>
                <th class="text-center">Region</th>
                <th class="text-center">Kota</th>
                <th>Vendor</th>
                <th>Kontak</th>
                <th>Tipe</th>
                <th>Status</th>
                <th class="text-center">Aktif</th>
                <th class="text-center">Selesai</th>
                <th class="text-center" title="Batas pekerjaan aktif di region ini (ditugaskan / dikerjakan / perlu revisi). Jika mencapai batas, vendor tidak bisa diberi pekerjaan baru di region tsb.">Limit<br>Workload</th>
                <th class="text-center">Temporary</th>
                <th class="text-center" title="Jumlah pekerjaan yang lewat batas SLA (SLA diukur sampai pekerjaan PERMANENT).">Lewat<br>SLA</th>
                <th class="text-center" title="Technical Skill yang dikuasai vendor">Technical<br>Skill</th>
                <?php if ($canManage): ?><th class="text-end">Aksi</th><?php endif; ?>
            </tr>
        </thead>
        <tbody>
        <?php $rowNo = 0; foreach ($groups as $g):
            $gCities = $g['cities'];
            $regSpan = 0; $cityAgg = [];
            foreach ($gCities as $ck => $cg) {
                $n = count($cg['vendors']); $regSpan += $n;
                $cl = 0; $cu = 0; $cun = false;
                foreach ($cg['vendors'] as $e) {
                    $cu += (int)$e['used'];
                    if ((int)$e['limit'] > 0) { $cl += (int)$e['limit']; } else { $cun = true; }
                }
                $cityAgg[$ck] = ['used' => $cu, 'limit' => $cun ? 0 : $cl, 'unlimited' => $cun, 'count' => $n];
            }
            $regRow = true;
            foreach ($gCities as $ck => $cg):
                $rowNo++;
                $ca = $cityAgg[$ck];
                $cityRow = true;
                foreach ($cg['vendors'] as $e):
                    $v = $e['vendor'];
                    $lim = (int)$e['limit'];
                    $used = (int)$e['used'];
                    $full = $lim > 0 && $used >= $lim;
                    $regCount = count($vrByVendor[(int)$v['id']] ?? []);
                    ?>
                    <tr>
                        <?php if ($cityRow): ?>
                            <td class="vgrp-no" rowspan="<?= (int)$ca['count'] ?>"><?= $rowNo ?></td>
                        <?php endif; ?>
                        <?php if ($regRow): ?>
                            <td class="vgrp-region" rowspan="<?= (int)$regSpan ?>">
                                <div class="vgrp-title"><?= e($g['name']) ?></div>
                                <div class="vgrp-meta"><?= (int)$g['vendors'] ?> vendor</div>
                                <div class="vgrp-meta">lim <?= $g['unlimited'] ? '∞' : (int)$g['limit'] ?> &middot; pakai <?= (int)$g['used'] ?></div>
                            </td>
                        <?php endif; ?>
                        <?php if ($cityRow): ?>
                            <td class="vgrp-city" rowspan="<?= (int)$ca['count'] ?>">
                                <div class="vgrp-title"><?= e($cg['name']) ?></div>
                                <div class="vgrp-meta"><?= (int)$ca['count'] ?> vendor</div>
                                <div class="vgrp-meta">lim <?= $ca['unlimited'] ? '∞' : (int)$ca['limit'] ?> &middot; pakai <?= (int)$ca['used'] ?></div>
                            </td>
                        <?php endif; ?>
                        <td class="vendor-name"><?= e($v['name']) ?>
                            <?php if ($regCount > 1): ?><span class="badge bg-light text-muted border fw-normal ms-1" style="font-size:.62rem"><?= $regCount ?> region</span><?php endif; ?>
                        </td>
                        <td><?= e($v['pic_name']) ?><div class="vgrp-meta"><?= e($v['phone']) ?></div></td>
                        <td><?= vendor_type_badge($v['vendor_type'] ?? 'non_ms') ?></td>
                        <td><?= vendor_badge($v['status']) ?>
                            <?php if ($v['status'] === 'freeze' && $v['freeze_until']): ?><div class="vgrp-meta">s/d <?= fmt_dt($v['freeze_until']) ?></div><?php endif; ?>
                            <?php if ($v['status_note']): ?><div class="vgrp-meta fst-italic"><?= e($v['status_note']) ?></div><?php endif; ?></td>
                        <td class="text-center num"><?= (int)$v['active_jobs'] ?></td>
                        <td class="text-center num"><?= (int)$v['done_jobs'] ?></td>
                        <td class="text-center">
                            <div class="fw-semibold num <?= $full ? 'text-danger' : '' ?>"><?= $used ?>/<?= $lim > 0 ? $lim : '∞' ?></div>
                            <?php if ($full): ?><span class="badge bg-danger">Penuh</span>
                            <?php elseif ($lim > 0): ?><div class="vgrp-meta">sisa <?= $lim - $used ?></div>
                            <?php else: ?><div class="vgrp-meta">tanpa batas</div><?php endif; ?>
                            <?php if ($e['fallback']): ?><div class="vgrp-meta fst-italic">limit cadangan</div><?php endif; ?>
                        </td>
                        <td class="text-center num <?= $v['temp_count'] ? 'text-danger' : '' ?>"><?= (int)$v['temp_count'] ?></td>
                        <td class="text-center num <?= $v['late_jobs'] ? 'text-danger' : '' ?>">
                            <?= (int)$v['late_jobs'] ?>
                            <?php if ($v['sla_jobs']): ?><div class="vgrp-meta">dari <?= (int)$v['sla_jobs'] ?></div><?php endif; ?>
                        </td>
                        <td>
                            <?php if ($v['skill_list']): ?>
                                <?php foreach ($v['skill_list'] as $sk): ?>
                                    <span class="badge skill-badge"><?= e($sk['name']) ?></span>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <span class="text-muted fst-italic" style="font-size:.7rem">belum ada skill</span>
                            <?php endif; ?>
                        </td>
                        <?php if ($canManage): ?>
                            <td class="text-end num">
                                <button class="btn btn-sm btn-outline-secondary py-0 px-1" title="Edit vendor" data-bs-toggle="modal" data-bs-target="#vendorModal" onclick='editVendor(<?= e(json_encode($v)) ?>)'><i class="bi bi-pencil"></i></button>
                                <?php if ($isAdmin): ?>
                                <button class="btn btn-sm btn-outline-warning py-0 px-1" title="Ubah status" data-bs-toggle="modal" data-bs-target="#statusModal" onclick='editStatus(<?= e(json_encode($v)) ?>)'><i class="bi bi-flag"></i></button>
                                <?php endif; ?>
                            </td>
                        <?php endif; ?>
                    </tr>
                    <?php $cityRow = false; $regRow = false; ?>
                <?php endforeach; ?>
            <?php endforeach; ?>
        <?php endforeach; ?>
                    <?php if (!$groups): ?><tr><td colspan="<?= $canManage ? 13 : 12 ?>" class="text-center text-muted p-4">Belum ada vendor.</td></tr><?php endif; ?>
        </tbody>
    </table>
</div></div>

<?php if ($canManage): ?>
<div class="modal fade" id="vendorModal" tabindex="-1"><div class="modal-dialog"><form method="post" class="modal-content">
    <?= csrf_field() ?><input type="hidden" name="act" value="save"><input type="hidden" name="id" id="v_id">
    <input type="hidden" name="has_regions" value="1">
    <div class="modal-header"><h5 class="modal-title" id="v_title">Vendor</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
    <div class="modal-body">
        <div class="mb-2"><label class="form-label">Nama Vendor *</label><input name="name" id="v_name" class="form-control" required></div>
        <div class="row g-2">
            <div class="col-6"><label class="form-label">Kota *</label><select name="city_id" id="v_city" class="form-select" required>
                <option value="">- pilih -</option><?php foreach ($cities as $c): ?><option value="<?= $c['id'] ?>"><?= e($c['name']) ?></option><?php endforeach; ?></select></div>
            <div class="col-6"><label class="form-label">Tipe Vendor *</label><select name="vendor_type" id="v_type" class="form-select">
                <?php foreach (VENDOR_TYPES as $k => [$lbl, $cls, $desc]): ?>
                    <option value="<?= $k ?>" <?= $k === 'non_ms' ? 'selected' : '' ?>><?= e($lbl) ?></option>
                <?php endforeach; ?>
            </select>
            <div class="form-text">Vendor MS (Managed Service) untuk kota dengan vendor MS: alur berbeda — langsung kerjakan tanpa request material, BASTO otomatis.</div></div>
        </div>
        <div class="row g-2">
            <div class="col-6"><label class="form-label">PIC</label><input name="pic_name" id="v_pic" class="form-control"></div>
            <div class="col-6"><label class="form-label">Telepon</label><input name="phone" id="v_phone" class="form-control"></div>
        </div>
        <div class="mb-2 mt-2"><label class="form-label">Email</label><input type="email" name="email" id="v_email" class="form-control"></div>
        <div class="mb-2"><label class="form-label">Alamat</label><textarea name="address" id="v_address" class="form-control" rows="2"></textarea></div>
        <div class="mb-2">
            <label class="form-label">Limit Workload cadangan <span class="text-muted">(jika kota vendor belum punya region)</span></label>
            <input type="number" name="workload_limit" id="v_workload_limit" class="form-control" min="0" max="99" value="<?= DEFAULT_WORKLOAD_LIMIT ?>">
            <div class="form-text">0 = tanpa batas. Limit utama per region diatur di halaman <b>Region</b>
                (menu Region &rarr; daftar vendor per region + limit masing-masing).</div>
        </div>
        <div class="mb-2">
            <label class="form-label">Region Dilayani &amp; Limit Workload <span class="text-muted">(bisa pilih lebih dari satu)</span></label>
            <div class="skill-picker">
                <?php if ($regions): ?>
                    <?php foreach ($regions as $r): ?>
                        <div class="region-row">
                            <label class="d-flex align-items-center gap-2 flex-grow-1 m-0">
                                <input class="form-check-input m-0" type="checkbox" name="region_id[<?= (int)$r['id'] ?>]"
                                    value="<?= (int)$r['id'] ?>" data-region-check>
                                <span><?= e($r['name']) ?></span>
                            </label>
                            <input type="number" name="region_limit[<?= (int)$r['id'] ?>]" class="form-control form-control-sm"
                                style="width:90px" min="0" max="99" value="<?= DEFAULT_WORKLOAD_LIMIT ?>" title="Limit workload di region ini (0 = tanpa batas)">
                        </div>
                    <?php endforeach; ?>
                <?php else: ?>
                    <div class="text-muted small">Belum ada region. Buat di halaman <b>Region</b> terlebih dahulu.</div>
                <?php endif; ?>
            </div>
            <div class="form-text">Vendor hanya bisa ditugaskan tiket di region yang dicentang. 0 = tanpa batas.</div>
        </div>
        <div class="mb-2">
            <label class="form-label">Technical Skill <span class="text-muted">(bisa pilih lebih dari satu)</span></label>
            <div class="skill-picker">
                <?php if ($skills): ?>
                    <?php foreach ($skills as $s): ?>
                        <label>
                            <input class="form-check-input m-0" type="checkbox" name="skill_ids[]" value="<?= (int)$s['id'] ?>"
                                data-skill-check<?= $canManage ? '' : ' disabled' ?>>
                            <span><?= e($s['name']) ?></span>
                            <?php if (!empty($s['description'])): ?><span class="text-muted" style="font-size:.72rem">— <?= e($s['description']) ?></span><?php endif; ?>
                        </label>
                    <?php endforeach; ?>
                <?php else: ?>
                    <div class="text-muted small">Belum ada skill. Tambahkan dulu di bawah (Master Technical Skill).</div>
                <?php endif; ?>
            </div>
            <div class="form-text">Skill dipakai untuk memfilter & memilih vendor yang tepat saat penugasan tiket.</div>
        </div>
    </div>
    <div class="modal-footer"><button class="btn btn-primary">Simpan</button></div>
</form></div></div>

<?php if ($isAdmin): ?>
<div class="modal fade" id="statusModal" tabindex="-1"><div class="modal-dialog"><form method="post" class="modal-content">
    <?= csrf_field() ?><input type="hidden" name="act" value="status"><input type="hidden" name="id" id="s_id">
    <div class="modal-header"><h5 class="modal-title">Ubah Status: <span id="s_name"></span></h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
    <div class="modal-body">
        <?php foreach (VENDOR_STATUS as $k => [$l, $c, $desc]): ?>
            <div class="form-check mb-2">
                <input class="form-check-input" type="radio" name="status" value="<?= $k ?>" id="st_<?= $k ?>" onchange="toggleFreeze()">
                <label class="form-check-label" for="st_<?= $k ?>"><?= vendor_badge($k) ?> <span class="small text-muted"><?= e($desc) ?></span></label>
            </div>
        <?php endforeach; ?>
        <div id="freezeBox" class="mb-2 d-none"><label class="form-label">Freeze sampai *</label><input type="datetime-local" name="freeze_until" id="s_until" class="form-control">
            <div class="form-text">Setelah tanggal ini, vendor otomatis kembali Available.</div></div>
        <div class="mb-2"><label class="form-label">Alasan / Catatan</label><textarea name="status_note" id="s_note" class="form-control" rows="2"></textarea></div>
    </div>
    <div class="modal-footer"><button class="btn btn-warning">Simpan Status</button></div>
</form></div></div>
<?php endif; ?>
<script>
function editVendor(v) {
    document.getElementById('v_title').textContent = v.id ? 'Edit Vendor' : 'Tambah Vendor';
    ['id', 'name', 'phone', 'email', 'address'].forEach(function (k) { document.getElementById('v_' + k).value = v[k] || ''; });
    document.getElementById('v_city').value = v.city_id || '';
    document.getElementById('v_type').value = v.vendor_type || 'non_ms';
    document.getElementById('v_pic').value = v.pic_name || '';
    document.getElementById('v_workload_limit').value = (v.workload_limit === undefined || v.workload_limit === null) ? <?= DEFAULT_WORKLOAD_LIMIT ?> : v.workload_limit;
    var picked = v.skill_ids || [];
    document.querySelectorAll('[data-skill-check]').forEach(function (cb) {
        cb.checked = picked.indexOf(parseInt(cb.value, 10)) !== -1;
    });
    // Region + limit workload
    var rids = v.region_ids || [];
    var rlim = v.region_limits || {};
    document.querySelectorAll('[data-region-check]').forEach(function (cb) {
        var rid = parseInt(cb.value, 10);
        cb.checked = rids.indexOf(rid) !== -1;
        var lim = document.querySelector('[name="region_limit[' + rid + ']"]');
        if (lim) lim.value = (rlim[rid] === undefined || rlim[rid] === null) ? <?= DEFAULT_WORKLOAD_LIMIT ?> : rlim[rid];
    });
}
function editStatus(v) {
    document.getElementById('s_id').value = v.id;
    document.getElementById('s_name').textContent = v.name;
    document.getElementById('st_' + v.status).checked = true;
    document.getElementById('s_until').value = v.freeze_until ? v.freeze_until.replace(' ', 'T').slice(0, 16) : '';
    document.getElementById('s_note').value = v.status_note || '';
    toggleFreeze();
}
function toggleFreeze() {
    document.getElementById('freezeBox').classList.toggle('d-none', !document.getElementById('st_freeze').checked);
}
</script>

<div class="card card-body mb-3">
    <div class="d-flex justify-content-between align-items-center mb-2">
        <div>
            <div class="fw-semibold"><i class="bi bi-tools me-1"></i>Master Technical Skill</div>
            <div class="text-muted small">
                Daftar skill yang bisa dipilih saat input/edit vendor, dipakai untuk memfilter vendor
                dan memilih vendor yang tepat saat penugasan tiket.
                Angka di dalam badge = jumlah vendor yang memakai skill tersebut.
            </div>
        </div>
    </div>
    <div class="row g-3">
        <div class="col-lg-7">
            <div class="d-flex flex-wrap gap-1">
                <?php if ($skills): ?>
                    <?php foreach ($skills as $s):
                        $usedN = (int)($skillUsage[(int)$s['id']] ?? 0); ?>
                        <span class="badge skill-badge d-inline-flex align-items-center gap-1">
                            <?= e($s['name']) ?>
                            <span class="skill-count"><?= $usedN ?></span>
                            <?php if ($usedN === 0 && $isAdmin): ?>
                                <button type="button" class="btn-close p-0 ms-1" style="font-size:.6rem" title="Hapus skill"
                                    onclick="delSkill(<?= (int)$s['id'] ?>, '<?= e(addslashes($s['name'])) ?>')"></button>
                            <?php endif; ?>
                        </span>
                    <?php endforeach; ?>
                <?php else: ?>
                    <span class="text-muted small">Belum ada skill.</span>
                <?php endif; ?>
            </div>
        </div>
        <div class="col-lg-5">
            <?php if ($isAdmin): ?>
            <form method="post" class="row g-2 align-items-end">
                <?= csrf_field() ?><input type="hidden" name="act" value="add_skill">
                <div class="col-5"><label class="form-label small mb-1">Nama Skill</label>
                    <input name="skill_name" class="form-control form-control-sm" placeholder="cth: splicing" required></div>
                <div class="col-5"><label class="form-label small mb-1">Keterangan</label>
                    <input name="skill_desc" class="form-control form-control-sm" placeholder="opsional"></div>
                <div class="col-2"><button class="btn btn-sm btn-outline-primary w-100" title="Tambah skill"><i class="bi bi-plus-lg"></i></button></div>
            </form>
            <?php else: ?>
            <div class="small text-muted"><i class="bi bi-info-circle"></i> Daftar skill dikelola Admin.
                Petugas Procurement tetap bisa memilih skill saat menambah / edit vendor.</div>
            <a href="<?= url('master_options.php') ?>" class="btn btn-sm btn-outline-secondary mt-2"><i class="bi bi-list-check"></i> Master Option (jenis kerusakan, tindakan, status material)</a>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php if ($isAdmin): ?>
<form method="post" id="delSkillForm" class="d-none"><?= csrf_field() ?>
    <input type="hidden" name="act" value="del_skill"><input type="hidden" name="skill_id" id="del_skill_id">
</form>
<script>
function delSkill(id, name) {
    if (!confirm('Hapus skill "' + name + '"?')) return;
    document.getElementById('del_skill_id').value = id;
    document.getElementById('delSkillForm').submit();
}
</script>
<?php endif; ?>
<?php endif; ?>
<?php require __DIR__ . '/includes/footer.php'; ?>
