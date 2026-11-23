<?php
/**
 * Uji otomatis fitur region + limit workload per region.
 * Jalankan: php tools/test_region.php
 */
require_once __DIR__ . '/../includes/bootstrap.php';

$pass = 0; $fail = 0;
function ok($cond, $msg) {
    global $pass, $fail;
    if ($cond) { $pass++; echo "  OK   $msg\n"; }
    else { $fail++; echo "  FAIL $msg\n"; }
}
function cityId(string $n): int { return (int)q('SELECT id FROM cities WHERE name=?', [$n])->fetchColumn(); }
function regionId(string $n): int { return (int)q('SELECT id FROM regions WHERE name=?', [$n])->fetchColumn(); }
function mkTicket(int $cityId, string $no): int {
    q("INSERT INTO tickets (ticket_no, source, city_id, problem_type, priority, status, reported_at, sla_minutes)
       VALUES (?,'manual',?,'Test Region','medium','open',NOW(),180)", [$no, $cityId]);
    return (int)db()->lastInsertId();
}
// Baseline jumlah tiket SEBELUM test -> untuk assert data kembali utuh setelah test.
$baselineTickets = (int)q('SELECT COUNT(*) FROM tickets')->fetchColumn();

echo "== Data region ==\n";
$jakarta = cityId('Jakarta'); $bandung = cityId('Bandung'); $surabaya = cityId('Surabaya'); $semarang = cityId('Semarang');
ok($jakarta && $bandung && $surabaya && $semarang, 'kota seed tersedia');
ok(city_region_id($jakarta) === regionId('Central'), 'Jakarta -> region Central');
ok(city_region_id($bandung) === regionId('Central'), 'Bandung -> region Central');
ok(city_region_id($surabaya) === regionId('East'), 'Surabaya -> region East');
ok(city_region_id($semarang) === regionId('East'), 'Semarang -> region East');

echo "== Pendaftaran vendor ke region ==\n";
ok(vendor_serves_region(4, regionId('Central')), 'vendor 4 terdaftar di Central');
ok(vendor_serves_region(4, regionId('East')), 'vendor 4 terdaftar di East (2 region)');
ok(!vendor_serves_region(6, regionId('Central')), 'vendor 6 (Surabaya) tidak terdaftar di Central');
ok(vendor_region_limits(4)[regionId('East')] === 0, 'limit vendor 4 di East = 0 (unlimited)');
ok(vendor_region_limits(4)[regionId('Central')] === 5, 'limit vendor 4 di Central = 5');

echo "== Kandidat vendor per region ==\n";
$t = mkTicket($jakarta, 'TEST-REG-JKT-1');
$cands = array_map(fn($v) => (int)$v['id'], candidate_vendors($t));
ok(!in_array(6, $cands, true), 'vendor 6 tidak jadi kandidat untuk tiket Jakarta (region Central)');
ok(in_array(4, $cands, true), 'vendor 4 jadi kandidat untuk tiket Jakarta');
foreach ($cands as $id) {
    ok(vendor_serves_region($id, regionId('Central')), "kandidat #$id terdaftar di region Central");
}

$t2 = mkTicket($surabaya, 'TEST-REG-SBY-1');
$cands2 = array_map(fn($v) => (int)$v['id'], candidate_vendors($t2));
ok(in_array(4, $cands2, true), 'vendor 4 jadi kandidat untuk tiket Surabaya (region East)');
ok(!in_array(1, $cands2, true), 'vendor 1 (khusus Central) bukan kandidat untuk Surabaya');

echo "== Penugasan lintas region ditolak ==\n";
ok(assign_vendor($t, 6, null) === false, 'assign_vendor menolak vendor 6 ke tiket region Central');
ok(assign_vendor($t2, 1, null) === false, 'assign_vendor menolak vendor 1 ke tiket region East');

echo "== Limit per region (vendor 4: Central 5, East unlimited) ==\n";
$baselineCentral = vendor_workload(4, regionId('Central'))['active']; // tiket lama yang sudah ada
$assignedCentral = 0;
for ($i = 1; $i <= 7; $i++) {
    $tt = mkTicket($bandung, 'TEST-REG-BDG-' . $i);
    if (assign_vendor($tt, 4, null)) $assignedCentral++;
}
$expectCentral = max(0, 5 - $baselineCentral);
ok($assignedCentral === $expectCentral, "hanya $expectCentral tiket region Central yang bisa ditugaskan ke vendor 4 (terassign: $assignedCentral, baseline: $baselineCentral)");

$assignedEast = 0;
for ($i = 1; $i <= 8; $i++) {
    $tt = mkTicket($semarang, 'TEST-REG-SMG-' . $i);
    if (assign_vendor($tt, 4, null)) $assignedEast++;
}
ok($assignedEast === 8, "region East unlimited: 8 tiket tertugaskan (terassign: $assignedEast)");

$w = vendor_workload(4, regionId('Central'));
ok($w['active'] === 5 && $w['limit'] === 5 && $w['full'] === true, 'workload vendor 4 di Central = 5/5 penuh');
$w2 = vendor_workload(4, regionId('East'));
ok($w2['active'] === 8 && $w2['unlimited'] === true, 'workload vendor 4 di East = 8 tanpa batas');

echo "== Kandidat mengecualikan vendor yang limit-nya penuh ==\n";
$t3 = mkTicket($bandung, 'TEST-REG-BDG-CAND');
$c3 = array_map(fn($v) => (int)$v['id'], candidate_vendors($t3));
ok(!in_array(4, $c3, true), 'vendor 4 tidak muncul sebagai kandidat (Central penuh)');

echo "== Slot dilepas ketika tiket ditutup ==\n";
$first = (int)q("SELECT id FROM tickets WHERE ticket_no='TEST-REG-BDG-1'")->fetchColumn();
q("UPDATE tickets SET status='closed' WHERE id=?", [$first]);
$w3 = vendor_workload(4, regionId('Central'));
ok($w3['active'] === 4 && $w3['full'] === false, 'setelah 1 tiket ditutup, beban Central turun ke 4/5');
$t4 = mkTicket($bandung, 'TEST-REG-BDG-RE');
ok(assign_vendor($t4, 4, null) === true, 'vendor 4 bisa menerima tiket lagi setelah slot dilepas');

echo "== Auto assign ==\n";
$t5 = mkTicket($bandung, 'TEST-REG-AUTO');
$vid = auto_assign($t5, null);
ok($vid !== null, 'auto_assign memilih vendor di region yang sesuai');
if ($vid) ok(vendor_serves_region($vid, regionId('Central')), "vendor auto (#$vid) terdaftar di region Central");

echo "== Bersihkan data uji ==\n";
q("DELETE FROM tickets WHERE ticket_no LIKE 'TEST-REG-%'");
q("DELETE FROM ticket_logs WHERE ticket_id NOT IN (SELECT id FROM tickets)");
q("DELETE FROM notifications WHERE ticket_id IS NOT NULL AND ticket_id NOT IN (SELECT id FROM tickets)");
q("DELETE FROM noc_outbox WHERE ticket_id IS NOT NULL AND ticket_id NOT IN (SELECT id FROM tickets)");
ok((int)q("SELECT COUNT(*) FROM tickets WHERE ticket_no LIKE 'TEST-REG-%'")->fetchColumn() === 0, 'semua tiket uji dihapus');
ok((int)q('SELECT COUNT(*) FROM tickets')->fetchColumn() === $baselineTickets, "jumlah tiket kembali seperti semula ($baselineTickets)");

echo "\n=== HASIL: $pass OK, $fail FAIL ===\n";
exit($fail > 0 ? 1 : 0);
