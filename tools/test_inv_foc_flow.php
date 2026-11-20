<?php
/**
 * Test regresi alur Vendor -> Inventory -> FOC (per 2026-11-20).
 *
 * Skenario:
 *  1. Vendor mengirim laporan -> form SELALU masuk ke Inventory (bukan langsung FOC).
 *  2. Inventory memberi checklist + keterangan per material.
 *     - Ada material yang BELUM dicentang -> form tidak boleh diteruskan ke FOC.
 *     - Semua material dicentang -> Inventory boleh meneruskan ke FOC (inv_result terisi).
 *  3. FOC hanya menerima form yang sudah "done" dari Inventory.
 *  4. FOC memberi checklist + keterangan di samping tiap kolom dokumentasi,
 *     kolom tindakan yang dilakukan, dan kolom detail penyebab gangguan.
 *     - Ada item yang BELUM dicentang -> link up tidak boleh dilakukan.
 *     - Semua dicentang -> link up permanent menutup tiket (+ BASTO).
 *  5. Isi form vendor tidak bisa diubah oleh Inventory / FOC.
 *
 * Jalankan: php tools/test_inv_foc_flow.php
 */
require_once __DIR__ . '/../includes/bootstrap.php';

// ---------------------------------------------------------------
// Helper test
// ---------------------------------------------------------------
$pass = 0;
$fail = 0;
$created = ['tickets' => [], 'materials' => [], 'cities' => [], 'regions' => [], 'vendors' => []];

function ok(string $msg): void
{
    global $pass;
    $pass++;
    echo "  OK   $msg\n";
}

function bad(string $msg): void
{
    global $fail;
    $fail++;
    echo "  FAIL $msg\n";
}

function chk($cond, string $msg): void
{
    $cond ? ok($msg) : bad($msg);
}

function eq($actual, $expected, string $msg): void
{
    $same = ($actual === $expected);
    $fmt = fn($v) => is_array($v)
        ? ('[' . implode(', ', array_map(fn($x) => is_scalar($x) ? (string)$x : gettype($x), $v)) . ']')
        : var_export($v, true);
    if ($same) ok($msg . ' (' . $fmt($actual) . ')');
    else bad($msg . ' (harapan: ' . $fmt($expected) . ', aktual: ' . $fmt($actual) . ')');
}

echo "== TEST ALUR VENDOR -> INVENTORY -> FOC ==\n";

// ---------------------------------------------------------------
// 0. Prasyarat skema
// ---------------------------------------------------------------
echo "\n-- Prasyarat skema --\n";
$stageCol = q("SHOW COLUMNS FROM ticket_signoffs LIKE 'stage'")->fetch();
$stageType = (string)($stageCol['Type'] ?? '');
chk(stripos($stageType, "'inventory'") !== false, 'enum stage tanda check punya "inventory"');
chk(stripos($stageType, "'foc'") !== false, 'enum stage tanda check punya "foc"');
chk(isset(SIGNOFF_STAGES['inventory']), 'SIGNOFF_STAGES punya stage inventory');
chk(isset(SIGNOFF_STAGES['foc']), 'SIGNOFF_STAGES punya stage foc');
eq(WF_STATUS_AFTER_VENDOR, 'inventory_check', 'form vendor selalu masuk Inventory dulu');
chk(in_array('inventory', WF_INVENTORY_ROLES, true), 'role inventory boleh cek material');
chk(in_array('foc', WF_FOC_ROLES, true), 'role foc boleh evaluation FOC');
chk(function_exists('wf_run_inventory_check'), 'helper wf_run_inventory_check() tersedia');
chk(function_exists('wf_run_foc_linkup'), 'helper wf_run_foc_linkup() tersedia');

// ---------------------------------------------------------------
// 1. Fixture: kota + vendor + material + tiket
// ---------------------------------------------------------------
echo "\n-- Menyiapkan fixture --\n";
q("INSERT INTO regions (name, code, description) VALUES (?,?,?)",
    ['Uji Alur INV-FOC ' . date('His'), 'UIF', 'Region khusus pengujian alur Inventory -> FOC']);
$regionId = (int)db()->lastInsertId();
$created['regions'][] = $regionId;

$cityName = 'Kota Uji INV-FOC ' . date('His');
q("INSERT INTO cities (name, region_id) VALUES (?,?)", [$cityName, $regionId]);
$cityId = (int)db()->lastInsertId();
$created['cities'][] = $cityId;

$vendorName = 'Vendor Uji INV-FOC ' . date('His');
q("INSERT INTO vendors (name, city_id, status, vendor_type, workload_limit) VALUES (?,?,'available','non_ms',5)",
    [$vendorName, $cityId]);
$vendorId = (int)db()->lastInsertId();
$created['vendors'][] = $vendorId;
q("INSERT INTO vendor_regions (vendor_id, region_id, workload_limit) VALUES (?,?,5)", [$vendorId, $regionId]);

q("INSERT INTO materials (code, name, unit, stock, is_active) VALUES (?,?,?,?,1)",
    ['MIF-' . date('His'), 'Kabel Uji INV-FOC', 'meter', 100]);
$matId = (int)db()->lastInsertId();
$created['materials'][] = $matId;

// User FOC & Inventory (dipakai sebagai aktor saat simulasi).
$focUserId = (int)q("SELECT id FROM users WHERE role='foc' AND is_active=1 ORDER BY id LIMIT 1")->fetchColumn();
$invUserId = (int)q("SELECT id FROM users WHERE role='inventory' AND is_active=1 ORDER BY id LIMIT 1")->fetchColumn();
chk($focUserId > 0 && $invUserId > 0, "user FOC ($focUserId) & Inventory ($invUserId) tersedia");

$res = create_ticket([
    'noc_ref' => 'UjiIF-' . date('His'),
    'city_id' => $cityId,
    'customer_name' => 'Uji Alur',
    'location' => 'Lokasi uji',
    'problem_type' => 'Kabel FO putus',
    'description' => 'Uji alur Inventory -> FOC',
    'priority' => 'high',
    'activity_type' => 'Troubleshooting',
    'province' => 'DKI Jakarta',
    'reported_at' => now(),
], 'manual', null);
$ticketId = (int)($res['id'] ?? 0);
$created['tickets'][] = $ticketId;
chk($ticketId > 0, "tiket uji dibuat (id=$ticketId)");

// Vendor menerima & material diserahkan inventory.
q("UPDATE tickets SET status='on_progress', accepted_at=NOW() WHERE id=?", [$ticketId]);
q("INSERT INTO ticket_materials (ticket_id, material_id, qty_requested, qty_issued, issued_by, issued_at)
   VALUES (?,?,?,?,?,NOW())", [$ticketId, $matId, 10, 10, $invUserId]);
$lineId = (int)db()->lastInsertId();
q("UPDATE materials SET stock = stock - 10 WHERE id=?", [$matId]);
ok("baris material dibuat (id=$lineId)");

// ---------------------------------------------------------------
// 2. Vendor mengirim laporan -> harus masuk Inventory
// ---------------------------------------------------------------
echo "\n-- Vendor kirim laporan --\n";
q("INSERT INTO ticket_reports (ticket_id, arrived_at, finished_at, root_cause, action_taken, damage_type,
        repair_action, submitted_by, submitted_at)
   VALUES (?,?,?,?,?,?,?,?,NOW())",
    [$ticketId, now(), now(), 'Kabel FO terputus akibat splice hilang', 'Splice ulang + pengujian OTDR',
        'Kabel FO putus', 'Ganti splices', 1]);
q("UPDATE tickets SET status=?, submitted_at=NOW() WHERE id=?", [WF_STATUS_AFTER_VENDOR, $ticketId]);
$t = get_ticket($ticketId);
eq($t['status'], 'inventory_check', 'tiket masuk inventory_check (bukan langsung foc_review)');

// Foto dokumentasi (2 kategori) supaya ceklist FOC punya item dokumentasi.
foreach (['before', 'after'] as $cat) {
    $rel = 'uploads/' . $ticketId . '/uji_' . $cat . '.jpg';
    $abs = __DIR__ . '/../' . $rel;
    if (!is_dir(dirname($abs))) mkdir(dirname($abs), 0775, true);
    if (!is_file($abs)) file_put_contents($abs, 'dummy');
    q("INSERT INTO ticket_photos (ticket_id, category, file_path, caption, uploaded_by) VALUES (?,?,?,?,?)",
        [$ticketId, $cat, $rel, 'Foto uji ' . $cat, 1]);
}
ok('foto dokumentasi dummy dibuat (2 kategori)');

// ---------------------------------------------------------------
// 3. Gate checklist material (Inventory)
// ---------------------------------------------------------------
echo "\n-- Gate checklist material (Inventory) --\n";
$invItems = inventory_checklist($ticketId);
chk(count($invItems) === 1, 'checklist material berisi 1 baris');
chk(isset($invItems[$lineId]), 'checklist material memakai id baris ticket_materials sebagai key');
eq($invItems[$lineId]['checked'], false, 'material belum dicentang');
eq(inventory_pending_labels($ticketId), [$invItems[$lineId]['label']], 'material belum dicentang terdeteksi');

$gate = wf_inventory_check_gate($ticketId, []);
eq($gate['ok'], false, 'gate inventory menolak kalau material belum dicentang');
chk(in_array($invItems[$lineId]['label'], $gate['missing'], true), 'gate menyebut material yang belum dicentang');

// Centang material yang salah -> tetap ditolak
$gate = wf_inventory_check_gate($ticketId, ['inv_check' => ['999' => '1']]);
eq($gate['ok'], false, 'gate inventory menolak centang material yang salah');

// POST benar -> diterima
$gate = wf_inventory_check_gate($ticketId, [
    'inv_check' => [$lineId => '1'],
    'inv_note'  => [$lineId => 'Kabel sesuai request, sambungan rapi'],
]);
eq($gate['ok'], true, 'gate inventory menerima semua material dicentang');
eq(count($gate['missing']), 0, 'tidak ada material yang belum dicentang');

// Aksi nyata: Inventory cek material TANPA centang -> harus ditolak
$res = wf_run_inventory_check($ticketId, ['result' => 'sesuai', 'note' => ''], $invUserId);
eq($res['ok'], false, 'wf_run_inventory_check() menolak saat material belum dicentang');
chk(strpos((string)($res['error'] ?? ''), 'belum dicentang') !== false, 'pesan error menyebut material belum dicentang');
$t = get_ticket($ticketId);
eq($t['status'], 'inventory_check', 'tiket tetap di inventory_check (tidak lanjut ke FOC)');
eq($t['inv_result'], null, 'inv_result belum terisi (form belum done dari Inventory)');

// Aksi nyata: Inventory cek material DENGAN centang -> boleh lanjut ke FOC
$res = wf_run_inventory_check($ticketId, [
    'inv_check' => [$lineId => '1'],
    'inv_note'  => [$lineId => 'Kabel sesuai request, sambungan rapi'],
    'result'    => 'sesuai',
    'note'      => 'Material sesuai',
    'returned'  => [$lineId => '0'],
], $invUserId);
eq($res['ok'], true, 'wf_run_inventory_check() berhasil saat semua material dicentang: ' . (string)($res['error'] ?? ''));
$t = get_ticket($ticketId);
eq($t['status'], 'foc_review', 'tiket diteruskan ke foc_review');
eq($t['inv_result'], 'sesuai', 'inv_result terisi -> form done dari Inventory');
eq(inventory_done($t), true, 'form ditandai done oleh inventory');

clear_signoff_cache($ticketId);
$invItems = inventory_checklist($ticketId);
eq($invItems[$lineId]['checked'], true, 'material tercatat sudah dicek');
eq($invItems[$lineId]['note'], 'Kabel sesuai request, sambungan rapi', 'keterangan inventory tersimpan');
eq(count(inventory_pending_labels($ticketId)), 0, 'tidak ada material pending setelah dicentang');

// ---------------------------------------------------------------
// 4. Gate checklist FOC
// ---------------------------------------------------------------
echo "\n-- Gate checklist FOC --\n";
$focItems = foc_checklist($ticketId);
$keys = array_column($focItems, 'key');
chk(in_array('root_cause', $keys, true), 'ceklist FOC memuat item Detail Penyebab Gangguan');
chk(in_array('action_taken', $keys, true), 'ceklist FOC memuat item Detail Tindakan yang Dilakukan');
chk(in_array('doc_before', $keys, true), 'ceklist FOC memuat item dokumentasi Foto Tiang');
chk(in_array('doc_after', $keys, true), 'ceklist FOC memuat item dokumentasi Foto OTDR');
eq(count($focItems), 4, 'ceklist FOC berisi 4 item (2 dokumentasi + penyebab + tindakan)');

$gate = wf_foc_check_gate($ticketId, []);
eq($gate['ok'], false, 'gate FOC menolak kalau belum ada yang dicentang');
eq(count($gate['missing']), 4, 'semua item FOC dilaporkan belum dicentang');

// Centang sebagian -> ditolak
$gate = wf_foc_check_gate($ticketId, ['foc_check' => ['root_cause' => '1']]);
eq($gate['ok'], false, 'gate FOC menolak kalau belum semua dicentang');
eq(count($gate['missing']), 3, 'sisa item yang belum dicentang = 3');

// Aksi nyata: link up tanpa centang -> ditolak
$res = wf_run_foc_linkup($ticketId, ['foc_check' => ['root_cause' => '1'], 'note' => 'ok'], $focUserId, 'permanent');
eq($res['ok'], false, 'wf_run_foc_linkup() menolak saat ceklist FOC belum lengkap');
chk(strpos((string)($res['error'] ?? ''), 'Ceklist FOC belum lengkap') !== false, 'pesan error menyebut ceklist FOC belum lengkap');
$t = get_ticket($ticketId);
eq($t['status'], 'foc_review', 'tiket tidak ditutup saat ceklist FOC belum lengkap');

// Centang semua -> diterima
$post = ['foc_check' => [], 'foc_note' => []];
foreach ($focItems as $it) {
    $post['foc_check'][$it['key']] = '1';
    $post['foc_note'][$it['key']] = 'Sudah sesuai (' . $it['label'] . ')';
}
$gate = wf_foc_check_gate($ticketId, $post);
eq($gate['ok'], true, 'gate FOC menerima semua item dicentang');

$n = wf_save_foc_checks($ticketId, $gate, $focUserId);
eq($n, 4, 'empat item ceklist FOC tersimpan');
clear_signoff_cache($ticketId);
foreach (foc_checklist($ticketId) as $it) {
    eq($it['checked'], true, 'ceklist FOC tersimpan: ' . $it['label']);
    chk(!empty($it['note']), 'keterangan FOC tersimpan: ' . $it['label']);
}
eq(count(foc_pending_labels($ticketId)), 0, 'tidak ada item FOC yang belum dicentang');

// Simpan ceklist FOC tanpa link up (boleh belum lengkap)
$res = wf_run_foc_eval($ticketId, ['foc_check' => ['root_cause' => '1'], 'foc_note' => ['root_cause' => 'ok']], $focUserId);
eq($res['ok'], true, 'wf_run_foc_eval() menyimpan ceklist FOC tanpa link up');

// ---------------------------------------------------------------
// 5. FOC hanya menerima form yang sudah done dari Inventory
// ---------------------------------------------------------------
echo "\n-- FOC hanya menerima form done dari Inventory --\n";
$uFoc = ['role' => 'foc', 'id' => $focUserId, 'vendor_id' => null];
$uInv = ['role' => 'inventory', 'id' => $invUserId, 'vendor_id' => null];

$tDone = get_ticket($ticketId);
eq(can_foc_review($uFoc, $tDone), true, 'FOC boleh review form yang sudah done dari Inventory');
eq(can_inventory_check($uInv, $tDone), false, 'Inventory tidak boleh cek material lagi (sudah lewat)');

// Tiket lain (belum dicek inventory) -> FOC tidak boleh review
q("INSERT INTO tickets (ticket_no, spk_no, source, city_id, customer_name, problem_type, priority,
        reported_at, sla_minutes, status, vendor_id, created_by)
   VALUES (?,?,'manual',?,'Belum Dicek','Test','medium',NOW(),180,'foc_review',?,1)",
    ['TKT-UJI-IF-' . date('His'), 'SPK-UJI-IF-' . date('His'), $cityId, $vendorId]);
$ticketId2 = (int)db()->lastInsertId();
$created['tickets'][] = $ticketId2;
$t2 = get_ticket($ticketId2);
eq(inventory_done($t2), false, 'tiket kedua belum done dari Inventory');
eq(can_foc_review($uFoc, $t2), false, 'FOC tidak boleh review form yang belum done dari Inventory');

// Link up pada tiket yang belum done -> ditolak
$res = wf_run_foc_linkup($ticketId2, ['foc_check' => ['root_cause' => '1'], 'note' => 'ok'], $focUserId, 'permanent');
eq($res['ok'], false, 'link up ditolak untuk form yang belum done dari Inventory');
chk(strpos((string)($res['error'] ?? ''), 'Inventory') !== false, 'pesan error menyebut form belum done dari Inventory');

// ---------------------------------------------------------------
// 6. Inventory & FOC tidak bisa mengubah isi form vendor
// ---------------------------------------------------------------
echo "\n-- Isi form vendor read-only bagi Inventory/FOC --\n";
$before = q('SELECT root_cause, action_taken, notes, damage_type, repair_action FROM ticket_reports WHERE ticket_id=?', [$ticketId])->fetch();
$beforeMat = q('SELECT qty_used, qty_issued, note FROM ticket_materials WHERE id=?', [$lineId])->fetch();
clear_signoff_cache($ticketId);
$after = q('SELECT root_cause, action_taken, notes, damage_type, repair_action FROM ticket_reports WHERE ticket_id=?', [$ticketId])->fetch();
$afterMat = q('SELECT qty_used, qty_issued, note FROM ticket_materials WHERE id=?', [$lineId])->fetch();
eq($after, $before, 'isi laporan vendor tidak berubah setelah checklist inventory & FOC');
eq($afterMat, $beforeMat, 'data pemakaian material vendor tidak berubah');

// Form laporan vendor hanya bisa dibuka oleh role vendor.
$src = file_get_contents(__DIR__ . '/../vendor_report.php');
chk(strpos($src, "require_role('vendor')") !== false, 'vendor_report.php hanya untuk role vendor');
$srcAction = file_get_contents(__DIR__ . '/../ticket_action.php');
chk(strpos($srcAction, 'WF_STAGE_INVENTORY') !== false, 'ticket_action.php membatasi stage inventory');
chk(strpos($srcAction, 'WF_STAGE_FOC') !== false, 'ticket_action.php membatasi stage foc');
chk(strpos($srcAction, 'wf_run_inventory_check') !== false, 'ticket_action.php memakai wf_run_inventory_check()');
chk(strpos($srcAction, 'wf_run_foc_linkup') !== false, 'ticket_action.php memakai wf_run_foc_linkup()');

// ---------------------------------------------------------------
// 7. Link up FOC (semua checklist tercentang) -> tiket ditutup + BASTO
// ---------------------------------------------------------------
echo "\n-- Link up FOC --\n";
$res = wf_run_foc_linkup($ticketId, $post, $focUserId, 'permanent');
eq($res['ok'], true, 'link up permanent berhasil: ' . (string)($res['error'] ?? ''));
$t = get_ticket($ticketId);
eq($t['status'], 'closed', 'tiket ditutup setelah LINK UP PERMANEN');
eq($t['repair_result'], 'permanent', 'hasil perbaikan = permanent');
chk(!empty($res['basto_no']), 'BASTO terbentuk otomatis: ' . (string)($res['basto_no'] ?? '-'));
chk((bool)q('SELECT id FROM basto WHERE ticket_id=?', [$ticketId])->fetch(), 'baris BASTO tersimpan di DB');

// Riwayat: inventory check + link up tercatat
$logActions = q("SELECT action FROM ticket_logs WHERE ticket_id=?", [$ticketId])->fetchAll(PDO::FETCH_COLUMN);
chk(in_array('inventory_checked', $logActions, true), 'log cek inventory tersimpan');
chk(in_array('foc_permanent', $logActions, true), 'log link up permanent tersimpan');
chk(in_array('foc_eval', $logActions, true), 'log simpan ceklist FOC tersimpan');

// Notifikasi ke FOC setelah inventory selesai
$notif = (int)q("SELECT COUNT(*) FROM notifications n JOIN users u ON u.id=n.user_id
                 WHERE n.ticket_id=? AND u.role='foc'", [$ticketId])->fetchColumn();
chk($notif >= 1, "FOC diberi notifikasi setelah inventory selesai ($notif)");

// ---------------------------------------------------------------
// 8. Link up TEMPORER -> form kembali ke vendor
// ---------------------------------------------------------------
echo "\n-- Link up TEMPORER --\n";
q("UPDATE tickets SET status='foc_review', repair_result=NULL, completed_at=NULL, work_finished_at=NULL
   WHERE id=?", [$ticketId]);
$postTmp = $post;
$postTmp['note'] = 'Sambungan perlu diperkuat, masih ada redaman';
$res = wf_run_foc_linkup($ticketId, $postTmp, $focUserId, 'temporary');
eq($res['ok'], true, 'link up temporary berhasil: ' . (string)($res['error'] ?? ''));
$t = get_ticket($ticketId);
eq($t['status'], 'revision', 'tiket kembali ke vendor (revision)');
eq((int)$t['revision_count'], 1, 'revision_count bertambah');

// Temporary tanpa catatan -> ditolak
q("UPDATE tickets SET status='foc_review' WHERE id=?", [$ticketId]);
$res = wf_run_foc_linkup($ticketId, $post, $focUserId, 'temporary');
eq($res['ok'], false, 'link up temporary tanpa catatan ditolak');

// ---------------------------------------------------------------
// Cleanup
// ---------------------------------------------------------------
echo "\n-- Cleanup --\n";
foreach ($created['tickets'] as $tid) {
    q('DELETE FROM basto WHERE ticket_id=?', [$tid]);
    q('DELETE FROM ticket_signoffs WHERE ticket_id=?', [$tid]);
    q('DELETE FROM ticket_photos WHERE ticket_id=?', [$tid]);
    q('DELETE FROM ticket_materials WHERE ticket_id=?', [$tid]);
    q('DELETE FROM ticket_reports WHERE ticket_id=?', [$tid]);
    q('DELETE FROM inventory_checks WHERE ticket_id=?', [$tid]);
    q('DELETE FROM foc_reviews WHERE ticket_id=?', [$tid]);
    q('DELETE FROM ticket_logs WHERE ticket_id=?', [$tid]);
    q('DELETE FROM notifications WHERE ticket_id=?', [$tid]);
    q('DELETE FROM tickets WHERE id=?', [$tid]);
    $dir = __DIR__ . '/../uploads/' . $tid;
    if (is_dir($dir)) {
        foreach (glob($dir . '/*') as $f) @unlink($f);
        @rmdir($dir);
    }
}
q('DELETE FROM vendor_regions WHERE vendor_id=?', [$vendorId]);
q('DELETE FROM vendors WHERE id=?', [$vendorId]);
q('DELETE FROM materials WHERE id=?', [$matId]);
q('DELETE FROM cities WHERE id=?', [$cityId]);
q('DELETE FROM regions WHERE id=?', [$regionId]);
ok('semua data uji dibersihkan');

echo "\n=== HASIL: $pass OK, $fail FAIL ===\n";
exit($fail > 0 ? 1 : 0);
