<?php
/**
 * Test suite: SLA diukur sampai pekerjaan PERMANENT.
 *
 * Aturan:
 * - Batas SLA (sla_deadline) tetap dihitung dari penugasan (SLA_START='assigned').
 * - VERDICT SLA hanya sah setelah pekerjaan PERMANENT:
 *     work_finished_at <= sla_deadline -> ontime, selain itu late.
 * - Tiket yang masih berjalan & sudah lewat deadline -> late (pasti late).
 * - Tiket yang masih berjalan & masih dalam deadline -> belum ada verdict (NULL).
 * - Tiket dibatalkan / tanpa deadline -> tidak dinilai (NULL).
 * - Badge menampilkan hitungan LIVE (sisa waktu / keterlambatan).
 *
 * Jalankan: php tools/test_sla.php
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
$testNo = 'TEST-SLA-' . date('YmdHis');

function mkTicket(int $cityId, string $no): int
{
    q("INSERT INTO tickets (ticket_no, source, city_id, problem_type, priority, status, reported_at, sla_minutes)
       VALUES (?,'manual',?,'Test SLA','medium','open',NOW(),180)", [$no, $cityId]);
    return (int)db()->lastInsertId();
}

/** Set state tiket uji (deadline relatif dari now, dalam menit). */
function setState(int $id, string $status, ?int $deadlineMin, ?int $finishedMin = null, string $repair = 'permanent'): void
{
    $dl = $deadlineMin === null ? null : date('Y-m-d H:i:s', time() + $deadlineMin * 60);
    $fin = $finishedMin === null ? null : date('Y-m-d H:i:s', time() + $finishedMin * 60);
    q("UPDATE tickets SET status=?, repair_result=?, sla_deadline=?, work_finished_at=?, completed_at=?
       WHERE id=?", [$status, $repair, $dl, $fin, $fin, $id]);
}

echo "== Tiket tanpa batas SLA ==\n";
$t = mkTicket($cityId, $testNo . '-A');
setState($t, 'on_progress', null);
$row = q('SELECT * FROM tickets WHERE id=?', [$t])->fetch();
$s = sla_state($row);
ok($s['verdict'] === null, 'tanpa deadline -> verdict NULL');
ok($s['remaining'] === null, 'tanpa deadline -> remaining NULL');
ok(strpos(sla_badge($row), 'text-muted') !== false, 'badge tanpa deadline tampil "-"');

echo "== Tiket berjalan, masih dalam deadline ==\n";
setState($t, 'on_progress', 60);
$row = q('SELECT * FROM tickets WHERE id=?', [$t])->fetch();
$s = sla_state($row);
ok($s['verdict'] === null, 'belum ada verdict sebelum PERMANENT');
ok($s['final'] === false, 'verdict belum final');
ok($s['overdue'] === false, 'belum overdue');
ok($s['remaining'] > 0, 'remaining positif (sisa waktu)');
$badge = sla_badge($row);
ok(strpos($badge, 'sla-countdown') !== false, 'badge punya class sla-countdown (live)');
ok(strpos($badge, 'Sisa') !== false, 'badge menampilkan "Sisa ..."');

echo "== Tiket berjalan, SUDAH lewat deadline ==\n";
setState($t, 'on_progress', -30);
$row = q('SELECT * FROM tickets WHERE id=?', [$t])->fetch();
$s = sla_state($row);
ok($s['verdict'] === 'late', 'sudah lewat deadline -> late');
ok($s['overdue'] === true, 'overdue = true');
ok($s['final'] === false, 'belum final (menunggu PERMANENT)');
ok($s['remaining'] < 0, 'remaining negatif (keterlambatan)');
$badge = sla_badge($row);
ok(strpos($badge, 'bg-danger') !== false, 'badge merah saat lewat SLA');
ok(strpos($badge, 'Lewat SLA') !== false, 'badge menampilkan "Lewat SLA"');

echo "== Tiket PERMANENT, selesai SEBELUM deadline -> ontime ==\n";
setState($t, 'closed', 60, -10);
$row = q('SELECT * FROM tickets WHERE id=?', [$t])->fetch();
$s = sla_state($row);
ok($s['verdict'] === 'ontime', 'permanent tepat waktu -> ontime');
ok($s['final'] === true, 'verdict final = true');
ok($s['overdue'] === false, 'tidak overdue');
$badge = sla_badge($row);
ok(strpos($badge, 'bg-success') !== false, 'badge hijau "Sesuai SLA"');
ok(strpos($badge, 'sla-countdown') === false, 'badge final tidak live');

echo "== Tiket PERMANENT, selesai SESUDAH deadline -> late ==\n";
setState($t, 'closed', -30, 10);
$row = q('SELECT * FROM tickets WHERE id=?', [$t])->fetch();
$s = sla_state($row);
ok($s['verdict'] === 'late', 'permanent lewat waktu -> late');
ok($s['final'] === true, 'verdict final = true');
ok($s['overdue'] === true, 'overdue = true');
$badge = sla_badge($row);
ok(strpos($badge, 'bg-danger') !== false, 'badge merah "Lewat SLA"');

echo "== Tiket DIBATALKAN -> tidak dinilai ==\n";
setState($t, 'cancelled', 60, null, 'permanent');
$row = q('SELECT * FROM tickets WHERE id=?', [$t])->fetch();
$s = sla_state($row);
ok($s['verdict'] === null, 'tiket dibatalkan -> verdict NULL');
ok($s['final'] === false, 'tidak final');
ok(strpos(sla_badge($row), 'text-muted') !== false, 'badge tiket dibatalkan tampil "-"');

echo "== SLA tidak berhenti di laporan pertama ==\n";
// Tiket: deadline sudah lewat, tapi laporan baru dikirim (masih running).
$t2 = mkTicket($cityId, $testNo . '-B');
setState($t2, 'on_progress', -60);
q('UPDATE tickets SET submitted_at=NOW() WHERE id=?', [$t2]);
$row = q('SELECT * FROM tickets WHERE id=?', [$t2])->fetch();
ok($row['submitted_at'] !== null, 'laporan tercatat');
ok(sla_state($row)['verdict'] === 'late', 'tidak ada verdict "ontime" dari laporan pertama');

echo "== Kolom sla_status tersinkron dengan aturan baru ==\n";
sla_sync_ticket($t2);
$row = q('SELECT * FROM tickets WHERE id=?', [$t2])->fetch();
ok($row['sla_status'] === 'late', 'sla_status = late setelah sync');
sla_sync_ticket($t);
$row = q('SELECT * FROM tickets WHERE id=?', [$t])->fetch();
ok($row['sla_status'] === null, 'sla_status NULL untuk tiket dibatalkan');

// Tiket permanent ontime
$t3 = mkTicket($cityId, $testNo . '-C');
setState($t3, 'closed', 60, -5);
sla_sync_ticket($t3);
$row = q('SELECT * FROM tickets WHERE id=?', [$t3])->fetch();
ok($row['sla_status'] === 'ontime', 'sla_status = ontime untuk permanent tepat waktu');

echo "== Ekspresi SQL sla_case_sql() sama dengan sla_state() ==\n";
foreach ([$t2, $t3, $t] as $tid) {
    $row = q('SELECT * FROM tickets WHERE id=?', [$tid])->fetch();
    $sqlVal = q('SELECT ' . sla_case_sql() . ' v FROM tickets WHERE id=?', [$tid])->fetchColumn();
    $phpVal = sla_state($row)['verdict'];
    ok($sqlVal === $phpVal, "SQL verdict = PHP verdict (tiket #$tid: " . var_export($phpVal, true) . ')');
}

echo "== Filter SLA di halaman Tiket (query) ==\n";
$lateCount = (int)q('SELECT COUNT(*) FROM tickets t WHERE ' . sla_case_sql('t') . "='late'")->fetchColumn();
$latePhp = 0;
foreach (q('SELECT * FROM tickets WHERE sla_deadline IS NOT NULL') as $r) {
    if (sla_state($r)['verdict'] === 'late') $latePhp++;
}
ok($lateCount === $latePhp, "jumlah 'late' SQL ($lateCount) = PHP ($latePhp)");

echo "== Badge & catatan ==\n";
$t4 = mkTicket($cityId, $testNo . '-D');
setState($t4, 'closed', 60, -10);
$row = q('SELECT * FROM tickets WHERE id=?', [$t4])->fetch();
ok(strpos(sla_note($row), 'PERMANENT') !== false, 'sla_note menyebut PERMANENT');
$t5 = mkTicket($cityId, $testNo . '-E');
setState($t5, 'on_progress', 60);
$row = q('SELECT * FROM tickets WHERE id=?', [$t5])->fetch();
ok(strpos(sla_note($row), 'Masih berjalan') !== false, 'sla_note: masih berjalan');
ok(strpos(sla_note($row), 'sisa') !== false, 'sla_note: menampilkan sisa waktu');

echo "== Penugasan vendor me-reset verdict SLA ==\n";
$t6 = mkTicket($cityId, $testNo . '-F');
q("UPDATE tickets SET sla_status='late' WHERE id=?", [$t6]);
assign_vendor($t6, 1, null);
$row = q('SELECT * FROM tickets WHERE id=?', [$t6])->fetch();
ok($row['sla_status'] === null, 'sla_status di-reset saat penugasan baru');
ok($row['sla_deadline'] !== null, 'sla_deadline terisi saat penugasan');

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
