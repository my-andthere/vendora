<?php
/**
 * Installer: membuat database, tabel, dan data contoh.
 * Jalankan sekali via browser (http://localhost/procurement/install.php) atau CLI (php install.php).
 * Jika database sudah ada isinya, tambahkan ?force=1 (browser) / --force (CLI) untuk RESET TOTAL.
 */
require_once __DIR__ . '/config.php';

$isCli = PHP_SAPI === 'cli';
$force = $isCli ? in_array('--force', $argv ?? [], true) : isset($_GET['force']);

if (!$isCli && !in_array($_SERVER['REMOTE_ADDR'] ?? '', ['127.0.0.1', '::1'], true)) {
    http_response_code(403);
    exit('Installer hanya bisa dijalankan dari localhost.');
}

function out($msg) { global $isCli; echo $isCli ? $msg . PHP_EOL : htmlspecialchars($msg) . '<br>'; }

try {
    $pdo = new PDO('mysql:host=' . DB_HOST . ';charset=utf8mb4', DB_USER, DB_PASS, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $pdo->exec('CREATE DATABASE IF NOT EXISTS `' . DB_NAME . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
    $pdo->exec('USE `' . DB_NAME . '`');

    $hasData = false;
    try { $hasData = (int)$pdo->query('SELECT COUNT(*) FROM users')->fetchColumn() > 0; } catch (PDOException $e) {}
    if ($hasData && !$force) {
        out('Database sudah terinstall. Tidak ada perubahan. (Gunakan ?force=1 untuk reset total - SEMUA DATA HILANG)');
        exit;
    }

    $sql = file_get_contents(__DIR__ . '/schema.sql');
    $sql = preg_replace('/^--.*$/m', '', $sql);
    foreach (array_filter(array_map('trim', explode(';', $sql))) as $stmt) {
        $pdo->exec($stmt);
    }
    out('Tabel berhasil dibuat.');

    // ---------- Data contoh ----------
    // Region (wilayah) -> 1 region berisi beberapa kota
    $regions = [
        ['Central', 'CENTRAL', 'Jawa Barat, DKI Jakarta dan sekitarnya'],
        ['East', 'EAST', 'Jawa Tengah dan Jawa Timur'],
        ['West', 'WEST', 'Sumatera (Medan dan sekitarnya)'],
    ];
    $st = $pdo->prepare('INSERT INTO regions (name, code, description) VALUES (?,?,?)');
    foreach ($regions as $r) $st->execute($r);
    $regionId = [];
    foreach ($pdo->query('SELECT id, name FROM regions')->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $regionId[$r['name']] = (int)$r['id'];
    }

    // name, region
    $cities = [
        ['Jakarta', 'Central'],
        ['Bandung', 'Central'],
        ['Surabaya', 'East'],
        ['Medan', 'West'],
        ['Semarang', 'East'],
    ];
    $st = $pdo->prepare('INSERT INTO cities (name, region_id) VALUES (?,?)');
    foreach ($cities as $c) $st->execute([$c[0], $regionId[$c[1]]]);

    $vendors = [
        // name, city_id, pic, phone, status, workload_limit (batas global / cadangan)
        ['PT Fiber Nusantara', 1, 'Budi', '081200000001', 'available', 5],
        ['CV Jaringan Prima', 1, 'Andi', '081200000002', 'available', 3],
        ['PT Sinar Optik', 1, 'Rudi', '081200000003', 'blacklist', 5],
        ['CV Parahyangan Teknik', 2, 'Asep', '081200000004', 'available', 0],
        ['PT Bandung Link', 2, 'Dedi', '081200000005', 'freeze', 5],
        ['PT Surya Fiber', 3, 'Joko', '081200000006', 'available', 2],
        ['CV Deli Network', 4, 'Rizal', '081200000007', 'available', 5],
    ];
    $st = $pdo->prepare('INSERT INTO vendors (name, city_id, pic_name, phone, status, workload_limit, freeze_until, status_note, status_changed_at) VALUES (?,?,?,?,?,?,?,?,NOW())');
    foreach ($vendors as $v) {
        $until = $v[4] === 'freeze' ? date('Y-m-d H:i:s', strtotime('+7 days')) : null;
        $note = ['available' => null, 'freeze' => 'Contoh: freeze 7 hari karena sering lewat SLA', 'blacklist' => 'Contoh: pelanggaran berat'][$v[4]];
        $st->execute([$v[0], $v[1], $v[2], $v[3], $v[4], $v[5], $until, $note]);
    }

    // Penugasan vendor ke region + limit workload per region.
    // Contoh: CV Parahyangan Teknik (id 4) melayani 2 region dengan batas berbeda:
    //   Central = 5, East = 0 (tanpa batas).
    $vendorRegions = [
        // vendor_id, region, workload_limit
        [1, 'Central', 5],
        [2, 'Central', 3],
        [3, 'Central', 5],
        [4, 'Central', 5],
        [4, 'East', 0],   // tanpa batas
        [5, 'Central', 5],
        [6, 'East', 5],
        [7, 'West', 5],
    ];
    $st = $pdo->prepare('INSERT INTO vendor_regions (vendor_id, region_id, workload_limit) VALUES (?,?,?)');
    foreach ($vendorRegions as $vr) {
        $st->execute([$vr[0], $regionId[$vr[1]], $vr[2]]);
    }

    // Master Technical Skill
    $skills = [
        ['Aktivasi', 'Aktivasi layanan / skill ODP pelanggan'],
        ['Troubleshooting', 'Pencarian & perbaikan gangguan'],
        ['Maintenance', 'Perawatan berkala'],
        ['Project', 'Pekerjaan proyek / penambahan jaringan'],
        ['Instalasi', 'Instalasi jaringan baru'],
        ['Jaringan', 'Pekerjaan jaringan (pulling, splicing)'],
        ['Survey Lokasi', 'Survei & pengukuran lokasi'],
        ['Handling Keluhan', 'Penanganan keluhan pelanggan'],
    ];
    $st = $pdo->prepare('INSERT INTO skills (name, description) VALUES (?,?)');
    foreach ($skills as $s) $st->execute($s);
    $skillId = [];
    foreach ($pdo->query('SELECT id, name FROM skills')->fetchAll(PDO::FETCH_ASSOC) as $s) {
        $skillId[$s['name']] = (int)$s['id'];
    }

    // Skill tiap vendor
    $vendorSkills = [
        1 => ['Aktivasi', 'Troubleshooting', 'Instalasi'],
        2 => ['Jaringan', 'Maintenance'],
        3 => ['Project', 'Instalasi'],
        4 => ['Troubleshooting', 'Maintenance', 'Survey Lokasi', 'Instalasi', 'Jaringan'],
        5 => ['Maintenance', 'Handling Keluhan'],
        6 => ['Instalasi', 'Aktivasi'],
        7 => ['Troubleshooting', 'Survey Lokasi', 'Handling Keluhan'],
    ];
    $st = $pdo->prepare('INSERT INTO vendor_skills (vendor_id, skill_id) VALUES (?,?)');
    foreach ($vendorSkills as $vid => $list) {
        foreach ($list as $sn) $st->execute([$vid, $skillId[$sn]]);
    }

    $users = [
        ['admin', 'admin123', 'Administrator', 'admin', null],
        ['noc', 'noc123', 'Petugas NOC', 'noc', null],
        ['procurement', 'procurement123', 'Petugas Procurement', 'procurement', null],
        ['inventory', 'inventory123', 'Petugas Inventory', 'inventory', null],
        ['foc', 'foc123', 'Petugas FOC', 'foc', null],
        ['nam', 'nam123', 'NAM (Network Area Manager)', 'nam', null],
        ['vendor1', 'vendor123', 'Teknisi Fiber Nusantara', 'vendor', 1],
        ['vendor2', 'vendor123', 'Teknisi Jaringan Prima', 'vendor', 2],
        ['vendor3', 'vendor123', 'Teknisi Parahyangan', 'vendor', 4],
        ['vendor4', 'vendor123', 'Teknisi Surya Fiber', 'vendor', 6],
    ];
    $st = $pdo->prepare('INSERT INTO users (username, password_hash, full_name, role, vendor_id) VALUES (?,?,?,?,?)');
    foreach ($users as $u) $st->execute([$u[0], password_hash($u[1], PASSWORD_DEFAULT), $u[2], $u[3], $u[4]]);

    $materials = [
        ['MTR-001', 'Kabel FO Drop Core 1 Core', 'meter', 5000],
        ['MTR-002', 'Kabel FO 12 Core', 'meter', 2000],
        ['MTR-003', 'Patch Cord SC-UPC 3m', 'pcs', 200],
        ['MTR-004', 'Fast Connector SC', 'pcs', 500],
        ['MTR-005', 'Splitter 1:8', 'pcs', 100],
        ['MTR-006', 'Closure Joint 24 Core', 'unit', 40],
        ['MTR-007', 'Sleeve Protector', 'pcs', 1000],
        ['MTR-008', 'ODP Pole 16 Port', 'unit', 30],
        ['MTR-009', 'Clamp / S-Clamp', 'pcs', 800],
        ['MTR-010', 'Pipa Conduit 20mm', 'batang', 150],
    ];
    $st = $pdo->prepare('INSERT INTO materials (code, name, unit, stock) VALUES (?,?,?,?)');
    foreach ($materials as $m) $st->execute($m);

    out('Data contoh berhasil dibuat.');
    out('Login: admin/admin123, noc/noc123, procurement/procurement123, inventory/inventory123, foc/foc123, vendor1..vendor4/vendor123');
    if (!$isCli) echo '<p><a href="login.php">Ke halaman login &raquo;</a></p>';
} catch (Throwable $ex) {
    http_response_code(500);
    out('ERROR: ' . $ex->getMessage());
}
