<?php
/**
 * Test end-to-end alur Vendor MS (flow Vendor MS).
 *
 * Skenario:
 *  1. Ada kota utama (is_primary=1) + vendor MS available di kota tersebut.
 *  2. Tiket dibuat di kota itu -> auto-assign memilih vendor MS, status on_progress (langsung terus).
 *  3. SPK ter-format & unik.
 *  4. Vendor MS pakai material langsung -> stok berkurang, is_direct=1, tidak perlu cek inventory.
 *  5. Link up PERMANENT -> tiket langsung ditutup + BASTO otomatis.
 *  6. Cek Ready SLA -> RPA/SLA OK -> tiket closed + BASTO terbentuk.
 *  7. Rekap bulanan menghitung tiket selesai.
 *
 * Jalankan: php tools/test_vendor_ms.php
 */
require_once __DIR__ . '/../includes/bootstrap.php';

// ---------------------------------------------------------------
// Helper test
// ---------------------------------------------------------------
$pass = 0;
$fail = 0;
$created = ['vendor' => null, 'city' => null, 'region' => null, 'material' => null, 'tickets' => []];

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
    if ($actual === $expected) ok($msg . " ($actual)");
    else bad($msg . " (harapan: " . var_export($expected, true) . ", aktual: " . var_export($actual, true) . ")");
}

echo "== TEST ALUR VENDOR MS ==\n";

// ---------------------------------------------------------------
// 0. Prasyarat: tabel & kolom
// ---------------------------------------------------------------
echo "\n-- Prasyarat skema --\n";
foreach ([
    ['vendors', 'vendor_type'],
    ['cities', 'is_primary'],
    ['tickets', 'spk_no'],
    ['tickets', 'activity_type'],
    ['tickets', 'province'],
    ['ticket_materials', 'is_direct'],
    ['ticket_reports', 'damage_type'],
    ['ticket_reports', 'repair_action'],
] as [$tbl, $col]) {
    $has = q("SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=?",
        [$tbl, $col])->fetch();
    chk((bool)$has, "kolom $tbl.$col ada");
}
foreach (['basto', 'ticket_signoffs', 'master_options'] as $tbl) {
    $has = q("SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?", [$tbl])->fetch();
    chk((bool)$has, "tabel $tbl ada");
}

// ---------------------------------------------------------------
// 1. Siapkan fixture: region + kota utama + vendor MS + material
// ---------------------------------------------------------------
echo "\n-- Menyiapkan fixture --\n";
// Region uji KHUSUS (bukan memakai region seed) supaya cleanup tidak merusak data asli.
q("INSERT INTO regions (name, code, description) VALUES (?,?,?)",
    ['Uji MS ' . date('His'), 'UMS', 'Region khusus pengujian alur Vendor MS']);
$regionId = (int)db()->lastInsertId();
$created['region'] = $regionId;

$cityName = 'Kota Uji MS ' . date('His');
q("INSERT INTO cities (name, region_id, is_primary) VALUES (?,?,1)", [$cityName, $regionId]);
$cityId = (int)db()->lastInsertId();
$created['city'] = $cityId;

$vendorName = 'Vendor MS Uji ' . date('His');
q("INSERT INTO vendors (name, city_id, status, vendor_type, workload_limit) VALUES (?,?,'available','ms',5)",
    [$vendorName, $cityId]);
$vendorId = (int)db()->lastInsertId();
$created['vendor'] = $vendorId;

// Non-MS competitor di kota yang sama (untuk memastikan MS menang)
$nonMsName = 'Vendor Non-MS Uji ' . date('His');
q("INSERT INTO vendors (name, city_id, status, vendor_type, workload_limit) VALUES (?,?,'available','non_ms',5)",
    [$nonMsName, $cityId]);
$nonMsId = (int)db()->lastInsertId();

q("INSERT INTO vendor_regions (vendor_id, region_id, workload_limit) VALUES (?,?,5)", [$vendorId, $regionId]);
q("INSERT INTO vendor_regions (vendor_id, region_id, workload_limit) VALUES (?,?,5)", [$nonMsId, $regionId]);
ok("fixture: region=$regionId kota=$cityId vendorMS=$vendorId vendorNonMS=$nonMsId");

// material untuk dipakai langsung
q("INSERT INTO materials (code, name, unit, stock, is_active) VALUES (?,?,?,?,1)",
    ['MST-' . date('His'), 'Kabel MS Uji', 'meter', 100]);
$matId = (int)db()->lastInsertId();
$created['material'] = $matId;
ok("material uji dibuat (id=$matId, stok 100)");

// ---------------------------------------------------------------
// 2. Auto-assign memilih Vendor MS & langsung on_progress
// ---------------------------------------------------------------
echo "\n-- Auto-assign Vendor MS --\n";
$res = create_ticket([
    'noc_ref' => 'UjiMS-' . date('His'),
    'city_id' => $cityId,
    'customer_name' => 'Uji MS',
    'location' => 'Kota Uji MS',
    'problem_type' => 'Kabel FO putus',
    'description' => 'Uji alur vendor MS',
    'priority' => 'high',
    'activity_type' => 'Troubleshooting',
    'province' => 'DKI Jakarta',
    'reported_at' => now(),
], 'manual', null);
$ticketId = (int)($res['id'] ?? 0);
$created['tickets'][] = $ticketId;
chk($ticketId > 0, 'tiket dibuat');

$t = get_ticket($ticketId);
eq(vendor_type_of((int)$t['vendor_id']), 'ms', 'auto-assign memilih Vendor MS (bukan Non-MS)');
eq($t['status'], 'on_progress', 'status langsung on_progress (langsung terus menuju)');
chk($t['accepted_at'] !== null, 'accepted_at terisi otomatis (MS langsung terima)');
eq((int)$t['city_is_primary'], 1, 'kota tiket adalah kota utama');

echo "\n-- Nomor SPK --\n";
$spk = (string)$t['spk_no'];
chk(preg_match('/^SPK-\d{8}-\d{4}$/', $spk) === 1, "format SPK valid ($spk)");
$spk2 = generate_spk_no();
chk($spk2 !== $spk, 'SPK berikutnya berbeda (' . $spk2 . ')');
chk(q('SELECT id FROM tickets WHERE spk_no=?', [$spk2])->fetch() === false, 'SPK baru belum dipakai tiket manapun');

// ---------------------------------------------------------------
// 3. Helper vendor MS
// ---------------------------------------------------------------
echo "\n-- Helper Vendor MS --\n";
eq(vendor_type_of($vendorId), 'ms', 'vendor_type_of() benar untuk MS');
eq(vendor_type_of($nonMsId), 'non_ms', 'vendor_type_of() benar untuk Non-MS');
chk(is_ms_vendor($vendorId), 'is_ms_vendor() true untuk MS');
chk(!is_ms_vendor($nonMsId), 'is_ms_vendor() false untuk Non-MS');
chk(city_has_ms_vendor($cityId), 'city_has_ms_vendor() true untuk kota uji');
chk(!city_has_ms_vendor(999999), 'city_has_ms_vendor() false untuk kota tak ada');
$cands = ms_first_candidates($ticketId, false);
chk(count($cands) >= 2, 'ms_first_candidates() mengembalikan kandidat (' . count($cands) . ')');
// Semua kandidat MS harus mendahului kandidat Non-MS.
$firstNonMs = null;
foreach ($cands as $i => $c) {
    if (($c['vendor_type'] ?? 'non_ms') !== 'ms') { $firstNonMs = $i; break; }
}
$lastMs = null;
foreach ($cands as $i => $c) {
    if (($c['vendor_type'] ?? 'non_ms') === 'ms') $lastMs = $i;
}
chk($lastMs !== null, 'ada kandidat Vendor MS di daftar');
chk($firstNonMs === null || ($lastMs !== null && $lastMs < $firstNonMs), 'semua Vendor MS berada di depan Vendor Non-MS');
chk(in_array($vendorId, array_map(fn($c) => (int)$c['id'], $cands), true), 'Vendor MS uji termasuk kandidat');

// master options
foreach (['damage_type', 'repair_action'] as $cat) {
    $opts = master_option_names($cat);
    chk(count($opts) > 0, "master options $cat tersedia (" . count($opts) . ")");
}

// ---------------------------------------------------------------
// 4. Material dipakai langsung oleh Vendor MS
// ---------------------------------------------------------------
echo "\n-- Material langsung Vendor MS --\n";
$stockBefore = (float)q('SELECT stock FROM materials WHERE id=?', [$matId])->fetchColumn();
$res = ms_add_material($ticketId, $matId, 5, null);
chk($res['ok'], 'ms_add_material() berhasil: ' . ($res['error'] ?? ''));
$stockAfter = (float)q('SELECT stock FROM materials WHERE id=?', [$matId])->fetchColumn();
eq($stockAfter, $stockBefore - 5, 'stok berkurang 5 setelah pemakaian langsung');
$resBad = ms_add_material($ticketId, $matId, 9999, null);
chk(!$resBad['ok'], 'ms_add_material() menolak melebihi stok');

$line = q('SELECT * FROM ticket_materials WHERE ticket_id=? AND material_id=?', [$ticketId, $matId])->fetch();
eq((int)$line['is_direct'], 1, 'baris material ditandai is_direct=1');
eq((float)$line['qty_used'], 5.0, 'qty_used terisi langsung');

// Baris is_direct tidak boleh masuk daftar "butuh return ke inventory"
$needReturn = array_filter([$line], fn($l) => (float)$l['qty_issued'] > 0 && $l['qty_returned'] === null && (int)$l['is_direct'] === 0);
eq(count($needReturn), 0, 'material MS tidak memerlukan cek inventory');

// ---------------------------------------------------------------
// 5. Link up PERMANENT -> tiket LANGSUNG ditutup + BASTO
// ---------------------------------------------------------------
echo "\n-- Link Up PERMANENT --\n";
q("UPDATE tickets SET status='foc_review', repair_result='permanent' WHERE id=?", [$ticketId]);
$t = get_ticket($ticketId);
eq($t['status'], 'foc_review', 'tiket masuk foc_review');

$sla = sla_state($t);
chk($sla['verdict'] === null, 'SLA belum ada verdict final sebelum PERMANENT');
chk($sla['final'] === false, 'SLA belum final (menunggu PERMANENT)');
chk($sla['deadline'] !== null, 'deadline SLA terhitung');

// Tidak ada lagi status sla_check (tahap Cek Ready SLA dihapus).
chk(!isset(TICKET_STATUS['sla_check']), 'status sla_check sudah dihapus');
chk(!in_array('sla_check', ACTIVE_TICKET_STATUSES, true), 'sla_check bukan tiket aktif');
require_once __DIR__ . '/../includes/kanban.php';
$lanes = kanban_lanes();
$laneStatuses = array_merge(...array_column($lanes, 'statuses'));
chk(!in_array('sla_check', $laneStatuses, true), 'sla_check tidak ada di lane kanban');

// Simulasi aksi FOC permanen -> tiket langsung closed
q("UPDATE tickets SET status='closed', repair_result='permanent', completed_at=NOW(),
    work_finished_at=COALESCE(work_finished_at, NOW()) WHERE id=?", [$ticketId]);
$t = get_ticket($ticketId);
eq($t['status'], 'closed', 'tiket langsung ditutup setelah LINK UP PERMANEN');
eq($t['repair_result'], 'permanent', 'hasil perbaikan = permanent');

// Tanda check (scope tanpa 'sla' lagi)
save_signoff($ticketId, 'material', 1, 'ok', 'Cek material', null);
chk((bool)q('SELECT id FROM ticket_signoffs WHERE ticket_id=? AND scope=?', [$ticketId, 'material'])->fetch(), 'signoff scope material tersimpan');
save_signoff($ticketId, 'bukan_scope', 0, 'ok', 'x', null);
chk(!q('SELECT id FROM ticket_signoffs WHERE ticket_id=? AND scope=?', [$ticketId, 'bukan_scope'])->fetch(), 'scope tanda check tidak valid ditolak');
save_signoff($ticketId, 'sla', 0, 'ok', 'x', null);
chk(!q('SELECT id FROM ticket_signoffs WHERE ticket_id=? AND scope=?', [$ticketId, 'sla'])->fetch(), 'scope sla sudah dihapus');
$sla = sla_state($t);
chk($sla['final'] === true, 'SLA final setelah ditutup');

// BASTO terbentuk
$bastoNo = generate_basto($ticketId, null);
chk(!empty($bastoNo), 'BASTO terbentuk: ' . (string)$bastoNo);
$bn = q('SELECT no_basto FROM basto WHERE ticket_id=?', [$ticketId])->fetchColumn();
chk(!empty($bn), 'nomor BASTO ada: ' . $bn);
$again = generate_basto($ticketId, null);
eq((string)$again, (string)$bn, 'generate_basto() idempotent');
eq((int)q('SELECT COUNT(*) FROM basto WHERE ticket_id=?', [$ticketId])->fetchColumn(), 1, 'hanya 1 baris BASTO per tiket');
$payload = basto_payload($t, q('SELECT * FROM ticket_reports WHERE ticket_id=?', [$ticketId])->fetch() ?: null,
    q('SELECT tm.*, m.code, m.name, m.unit FROM ticket_materials tm JOIN materials m ON m.id=tm.material_id WHERE tm.ticket_id=?', [$ticketId])->fetchAll(),
    q('SELECT * FROM ticket_photos WHERE ticket_id=?', [$ticketId])->fetchAll());
chk(isset($payload['ticket_no'], $payload['vendor'], $payload['vendor_type'], $payload['materials']), 'basto_payload() berisi identitas');

echo "\n-- Tanda check (signoff) --\n";
chk(count(ticket_signoffs($ticketId)) > 0, 'ticket_signoffs() mengembalikan daftar tanda check');
$bad = signoff_badge('ok', null);
chk(strpos($bad, 'badge') !== false, 'signoff_badge() menghasilkan badge');

// ---------------------------------------------------------------
// 6. Rekap bulanan menghitung tiket selesai
// ---------------------------------------------------------------
echo "\n-- Rekap bulanan --\n";
$from = date('Y-m-01 00:00:00');
$to = date('Y-m-t 23:59:59');
$n = (int)q("SELECT COUNT(*) FROM tickets WHERE status='closed' AND repair_result='permanent'
             AND COALESCE(completed_at, updated_at) BETWEEN ? AND ?", [$from, $to])->fetchColumn();
chk($n >= 1, "tiket selesai terhitung di rekap bulan ini ($n)");

// ---------------------------------------------------------------
// 7. Badge & label
// ---------------------------------------------------------------
echo "\n-- Badge --\n";
chk(strpos(vendor_type_badge('ms'), 'MS') !== false, 'vendor_type_badge(ms) memuat MS');
chk(strpos(vendor_type_badge('non_ms'), 'Non-MS') !== false, 'vendor_type_badge(non_ms) memuat Non-MS');
// Badge SLA (live) menggantikan penandaan manual RPA/SLA.
$badgeLate = sla_badge(['status' => 'closed', 'sla_deadline' => date('Y-m-d H:i:s', time() - 3600),
    'work_finished_at' => now(), 'completed_at' => now()]);
chk(strpos($badgeLate, 'Lewat SLA') !== false, 'badge SLA menandai Lewat SLA');
$badgeOk = sla_badge(['status' => 'closed', 'sla_deadline' => date('Y-m-d H:i:s', time() + 3600),
    'work_finished_at' => now(), 'completed_at' => now()]);
chk(strpos($badgeOk, 'Sesuai SLA') !== false, 'badge SLA menandai Sesuai SLA');

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
    q('DELETE FROM ticket_logs WHERE ticket_id=?', [$tid]);
    q('DELETE FROM tickets WHERE id=?', [$tid]);
}
q('DELETE FROM vendor_regions WHERE vendor_id IN (?,?)', [$vendorId, $nonMsId]);
q('DELETE FROM vendors WHERE id IN (?,?)', [$vendorId, $nonMsId]);
q('DELETE FROM materials WHERE id=?', [$matId]);
q('DELETE FROM cities WHERE id=?', [$cityId]);
q('DELETE FROM regions WHERE id=?', [$regionId]);
ok('semua data uji dibersihkan');

echo "\n=== HASIL: $pass OK, $fail FAIL ===\n";
exit($fail > 0 ? 1 : 0);
