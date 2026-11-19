<?php
/**
 * Test suite: Waktu vendor menekan tombol "Terima & Mulai Kerjakan".
 *
 * Jalankan: php tools/test_accept_time.php
 */
require_once __DIR__ . '/../includes/bootstrap.php';

$ok = 0; $fail = 0;
function ok(bool $cond, string $msg): void
{
    global $ok, $fail;
    if ($cond) { $ok++; echo "  OK   $msg\n"; }
    else { $fail++; echo "  FAIL $msg\n"; }
}

$cityId = (int)q('SELECT id FROM cities ORDER BY id LIMIT 1')->fetchColumn();
$testNo = 'TEST-ACC-' . date('YmdHis');

function mkTicket(int $cityId, string $no): int
{
    q("INSERT INTO tickets (ticket_no, source, city_id, problem_type, priority, status, reported_at, sla_minutes)
       VALUES (?,'manual',?,'Test Accept','medium','open',NOW(),180)", [$no, $cityId]);
    return (int)db()->lastInsertId();
}

echo "== Before vendor menerima ==\n";
$t = mkTicket($cityId, $testNo . '-A');
$row = q('SELECT * FROM tickets WHERE id=?', [$t])->fetch();
ok($row['accepted_at'] === null, 'accepted_at NULL saat tiket open');
$a = accept_info($row);
ok($a['accepted'] === null, 'accept_info: belum diterima');
ok($a['accepted_label'] === 'Belum diterima', 'label: Belum diterima');
ok($a['delay'] === null, 'delay null');
ok(strpos(work_time_badge($row), '') !== false, 'work_time_badge tidak error');
ok(strpos(accept_badge($row), 'Belum diterima') !== false, 'badge menampilkan "Belum diterima"');

echo "== Setelah vendor menekan tombol ==\n";
ok(assign_vendor($t, 1, null) === true, 'vendor ditugaskan');
$row = q('SELECT * FROM tickets WHERE id=?', [$t])->fetch();
ok($row['assigned_at'] !== null, 'assigned_at terisi');
ok($row['accepted_at'] === null, 'accepted_at masih NULL sebelum vendor menerima');

// Simulasikan vendor menekan tombol (sama seperti action=accept)
q("UPDATE tickets SET status='on_progress', accepted_at=NOW() WHERE id=?", [$t]);
$row = q('SELECT * FROM tickets WHERE id=?', [$t])->fetch();
ok($row['accepted_at'] !== null, 'accepted_at terisi setelah vendor menerima');
ok($row['status'] === 'on_progress', 'status jadi on_progress');

$a = accept_info($row);
ok($a['accepted'] !== null, 'accept_info: accepted terisi');
ok($a['delay'] !== null && $a['delay'] >= 0, 'delay dihitung dari assigned_at');
ok($a['accepted_label'] === fmt_dt($row['accepted_at']), 'label berisi tanggal lengkap');
ok(strpos(accept_badge($row), 'Diterima') !== false, 'badge menampilkan "Diterima"');
ok(strpos(accept_badge($row), 'bg-success') !== false, 'badge berwarna hijau');

echo "== Delay aktual ==\n";
// Tiket yang ditugaskan 2 menit lalu lalu diterima sekarang
$t2 = mkTicket($cityId, $testNo . '-B');
assign_vendor($t2, 1, null);
q("UPDATE tickets SET assigned_at=DATE_SUB(NOW(), INTERVAL 2 MINUTE) WHERE id=?", [$t2]);
q("UPDATE tickets SET accepted_at=NOW() WHERE id=?", [$t2]);
$row2 = q('SELECT * FROM tickets WHERE id=?', [$t2])->fetch();
$a2 = accept_info($row2);
ok($a2['delay'] >= 120 && $a2['delay'] <= 130, "delay ~2 menit (terukur: {$a2['delay']} detik)");

echo "== Tiket belum diterima (assigned, belum accept) ==\n";
$t3 = mkTicket($cityId, $testNo . '-C');
assign_vendor($t3, 1, null);
$row3 = q('SELECT * FROM tickets WHERE id=?', [$t3])->fetch();
$a3 = accept_info($row3);
ok($a3['accepted'] === null, 'ditugaskan tapi belum diterima -> accepted null');
ok($a3['delay_label'] === 'Menunggu vendor menerima', 'delay_label: Menunggu vendor menerima');
ok(strpos(accept_badge($row3), 'Belum diterima') !== false, 'badge "Belum diterima"');

echo "== Bersihkan data uji ==\n";
$ids = q("SELECT id FROM tickets WHERE ticket_no LIKE ?", [$testNo . '%'])->fetchAll();
foreach ($ids as $r) {
    q('DELETE FROM ticket_logs WHERE ticket_id=?', [(int)$r['id']]);
    q('DELETE FROM notifications WHERE ticket_id=?', [(int)$r['id']]);
    q('DELETE FROM noc_outbox WHERE ticket_id=?', [(int)$r['id']]);
    q('DELETE FROM tickets WHERE id=?', [(int)$r['id']]);
}
$left = (int)q("SELECT COUNT(*) FROM tickets WHERE ticket_no LIKE ?", [$testNo . '%'])->fetchColumn();
ok($left === 0, 'semua tiket uji dihapus');

echo "\n=== HASIL: $ok OK, $fail FAIL ===\n";
exit($fail > 0 ? 1 : 0);
