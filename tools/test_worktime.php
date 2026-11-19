<?php
/**
 * Test suite: Waktu Pengerjaan tiket.
 * Aturan: timer berjalan sejak tiket ditugaskan ke vendor dan BERHENTI
 * hanya saat tiket dinyatakan PERMANENT (tidak berhenti saat cek inventory,
 * review FOC, maupun revisi/temporary).
 *
 * Jalankan: php tools/test_worktime.php
 */
require_once __DIR__ . '/../includes/bootstrap.php';

$ok = 0; $fail = 0;
function ok(bool $cond, string $msg): void
{
    global $ok, $fail;
    if ($cond) { $ok++; echo "  OK   $msg\n"; }
    else { $fail++; echo "  FAIL $msg\n"; }
}

$city = q("SELECT id FROM cities ORDER BY id LIMIT 1")->fetchColumn();
$cityId = (int)$city;
$testNo = 'TEST-WT-' . date('YmdHis');

function mkTicket(int $cityId, string $no): int
{
    q("INSERT INTO tickets (ticket_no, source, city_id, problem_type, priority, status, reported_at, sla_minutes)
       VALUES (?,'manual',?,'Test Worktime','medium','open',NOW(),180)", [$no, $cityId]);
    return (int)db()->lastInsertId();
}

echo "== Timer mulai saat vendor ditugaskan ==\n";
$t = mkTicket($cityId, $testNo . '-A');
$row = q('SELECT work_started_at FROM tickets WHERE id=?', [$t])->fetch();
ok($row['work_started_at'] === null, 'tiket open belum punya waktu pengerjaan');
ok(work_time(q('SELECT * FROM tickets WHERE id=?', [$t])->fetch())['started'] === false, 'work_time belum started untuk tiket open');

ok(assign_vendor($t, 1, null) === true, 'vendor berhasil ditugaskan');
$row = q('SELECT work_started_at, assigned_at FROM tickets WHERE id=?', [$t])->fetch();
ok($row['work_started_at'] !== null, 'work_started_at terisi setelah assignment');
ok($row['work_started_at'] === $row['assigned_at'], 'work_started_at = assigned_at');

echo "== Timer TIDAK berhenti saat tahap antara ==\n";
$before = work_time(q('SELECT * FROM tickets WHERE id=?', [$t])->fetch())['seconds'];
q("UPDATE tickets SET status='on_progress' WHERE id=?", [$t]);
$w1 = work_time(q('SELECT * FROM tickets WHERE id=?', [$t])->fetch());
ok($w1['running'] === true, 'timer masih berjalan di status on_progress');
ok($w1['end'] === null, 'work_finished_at masih NULL di on_progress');

q("UPDATE tickets SET status='inventory_check' WHERE id=?", [$t]);
$w2 = work_time(q('SELECT * FROM tickets WHERE id=?', [$t])->fetch());
ok($w2['running'] === true, 'timer TIDAK berhenti saat cek inventory');

q("UPDATE tickets SET status='foc_review' WHERE id=?", [$t]);
$w3 = work_time(q('SELECT * FROM tickets WHERE id=?', [$t])->fetch());
ok($w3['running'] === true, 'timer TIDAK berhenti saat review FOC');

q("UPDATE tickets SET status='revision', revision_count=1 WHERE id=?", [$t]);
$w4 = work_time(q('SELECT * FROM tickets WHERE id=?', [$t])->fetch());
ok($w4['running'] === true, 'timer TIDAK berhenti saat revisi (temporary)');

sleep(2);
$w5 = work_time(q('SELECT * FROM tickets WHERE id=?', [$t])->fetch());
ok($w5['seconds'] > $before, 'durasi bertambah seiring waktu (timer benar-benar berjalan)');

echo "== Timer BERHENTI saat PERMANENT ==\n";
q("UPDATE tickets SET status='closed', repair_result='permanent', completed_at=NOW(),
    work_finished_at=NOW() WHERE id=?", [$t]);
$w6 = work_time(q('SELECT * FROM tickets WHERE id=?', [$t])->fetch());
ok($w6['running'] === false, 'timer berhenti setelah PERMANENT');
ok($w6['permanent'] === true, 'ditandai permanent');
ok($w6['end'] !== null, 'work_finished_at terisi');
$fixed = $w6['seconds'];
sleep(2);
$w7 = work_time(q('SELECT * FROM tickets WHERE id=?', [$t])->fetch());
ok($w7['seconds'] === $fixed, 'durasi tidak bertambah lagi setelah permanent');

echo "== Tiket dibatalkan juga membekukan timer ==\n";
$t2 = mkTicket($cityId, $testNo . '-B');
assign_vendor($t2, 1, null);
q("UPDATE tickets SET status='cancelled', work_finished_at=NOW() WHERE id=?", [$t2]);
$w8 = work_time(q('SELECT * FROM tickets WHERE id=?', [$t2])->fetch());
ok($w8['running'] === false, 'timer berhenti saat tiket dibatalkan');
ok($w8['end'] !== null, 'work_finished_at terisi saat dibatalkan');

echo "== Badge & format ==\n";
ok(strpos(work_time_badge(q('SELECT * FROM tickets WHERE id=?', [$t])->fetch()), 'bg-success') !== false, 'badge permanent berwarna hijau');
ok(strpos(work_time_badge(q('SELECT * FROM tickets WHERE id=?', [$t2])->fetch()), 'bg-secondary') !== false, 'badge cancelled abu-abu');
ok(strpos(work_time_badge(q('SELECT * FROM tickets WHERE id=?', [$t])->fetch()), 'work-timer') === false, 'badge permanent tidak punya class work-timer (tidak live)');
$running = q('SELECT * FROM tickets WHERE id=?', [$t2])->fetch();
q("UPDATE tickets SET status='on_progress', work_finished_at=NULL WHERE id=?", [$t2]);
ok(strpos(work_time_badge(q('SELECT * FROM tickets WHERE id=?', [$t2])->fetch()), 'work-timer') !== false, 'badge yang berjalan punya class work-timer (live counter)');
ok(fmt_dur_seconds(3427) === '57m 07d', 'fmt_dur_seconds(3427) = 57m 07d');
ok(fmt_dur_seconds(7380) === '2j 03m', 'fmt_dur_seconds(7380) = 2j 03m');
ok(fmt_dur_seconds(45) === '45d', 'fmt_dur_seconds(45) = 45d');

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
