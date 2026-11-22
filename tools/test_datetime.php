<?php
/**
 * Uji otomatis proteksi input tanggal/waktu (anti error MySQL 1366
 * "Incorrect datetime value").
 * Jalankan: php tools/test_datetime.php
 */
require_once __DIR__ . '/../includes/bootstrap.php';

$pass = 0; $fail = 0;
function ok($cond, $msg) {
    global $pass, $fail;
    if ($cond) { $pass++; echo "  OK   $msg\n"; }
    else { $fail++; echo "  FAIL $msg\n"; }
}

echo "== Helper parse_dt ==\n";
ok(parse_dt('2026-11-22 10:30:00') === '2026-11-22 10:30:00', 'format DB tidak berubah');
ok(parse_dt('2026-11-22T10:30') === '2026-11-22 10:30:00', 'format datetime-local dari browser');
ok(parse_dt('gkggf') === null, 'sampah "gkggf" -> null');
ok(parse_dt('22/11/2026') === '2026-11-22 00:00:00', 'format Indonesia 22/11/2026');
ok(parse_dt('22/11/2026 14:05') === '2026-11-22 14:05:00', 'format Indonesia dengan jam');
ok(parse_dt('22-11-2026') === '2026-11-22 00:00:00', 'format Indonesia dengan tanda hubung');
ok(parse_dt('31/02/2026') === null, 'tanggal tidak ada di kalender -> null');
ok(parse_dt('22/13/2026') === null, 'bulan tidak valid -> null');
ok(parse_dt('') === null, 'string kosong -> null');
ok(parse_dt(null) === null, 'null -> null');
ok(parse_dt('0000-00-00 00:00:00') === null, 'zero date -> null');

echo "== Sanitize field *_at ==\n";
$s = sanitize_dt_fields(['reported_at' => 'gkggf', 'noc_ref' => 'NOC-1', 'arrived_at' => '2026-01-02 03:04:05']);
ok($s['reported_at'] === null, 'reported_at tidak valid -> null');
ok($s['noc_ref'] === 'NOC-1', 'field non-tanggal tidak diubah');
ok($s['arrived_at'] === '2026-01-02 03:04:05', 'arrived_at valid tetap');

echo "== Sanitize berdasarkan tipe kolom DB ==\n";
$types = table_column_types('tickets');
ok(isset($types['reported_at']) && $types['reported_at'] === 'datetime', 'tipe kolom tickets.reported_at = datetime');
ok(!isset($types['ticket_no']) || $types['ticket_no'] === 'varchar', 'tipe kolom tickets.ticket_no bukan date');
$row = sanitize_row('tickets', ['ticket_no' => 'T-1', 'reported_at' => 'gkggf', 'sla_minutes' => 180]);
ok($row['reported_at'] === null, 'kolom datetime dengan nilai sampah -> null');
ok($row['ticket_no'] === 'T-1', 'kolom varchar tidak diubah');
ok($row['sla_minutes'] === 180, 'kolom int tidak diubah');

echo "== Insert tiket dengan tanggal sampah tidak fatal ==\n";
$cityId = (int)q('SELECT id FROM cities ORDER BY id LIMIT 1')->fetchColumn();
$before = (int)q('SELECT COUNT(*) FROM tickets')->fetchColumn();
$res = create_ticket([
    'noc_ref' => 'TEST-DT-' . date('YmdHis'),
    'city_id' => $cityId,
    'problem_type' => 'Test datetime',
    'location' => 'Test',
    'priority' => 'medium',
    'reported_at' => 'gkggf',           // nilai tidak valid
], 'manual', null);
ok($res['id'] > 0, 'tiket tetap dibuat walau reported_at tidak valid');
$stored = q('SELECT reported_at FROM tickets WHERE id=?', [$res['id']])->fetchColumn();
ok($stored !== 'gkggf', 'nilai yang tersimpan bukan string mentah');
ok(strtotime((string)$stored) !== false, 'nilai tersimpan adalah tanggal valid');
$after = (int)q('SELECT COUNT(*) FROM tickets')->fetchColumn();
ok($after === $before + 1, 'tepat satu tiket baru dibuat');

echo "== Pengaman lapis query (sanitize_params) ==\n";
$p = sanitize_params('INSERT INTO tickets (ticket_no, reported_at, status) VALUES (?,?,?)', ['T-9', 'gkggf', 'open']);
ok($p[1] === null, 'INSERT dengan tanggal sampah -> parameter jadi null');
ok($p[0] === 'T-9', 'kolom varchar tetap utuh');
$p2 = sanitize_params('INSERT INTO tickets (ticket_no, reported_at) VALUES (?,?)', ['T-9', '2026-01-02 03:04:05']);
ok($p2[1] === '2026-01-02 03:04:05', 'tanggal valid tidak diubah');
$p3 = sanitize_params('SELECT * FROM tickets WHERE id=?', [1]);
ok($p3 === [1], 'query SELECT tidak di intervensi');
$p4 = sanitize_params('UPDATE tickets SET reported_at=? WHERE id=?', ['gkggf', 1]);
ok($p4 === ['gkggf', 1], 'query UPDATE tidak di intervensi (hanya INSERT)');

echo "== Tabel dengan kolom *_at tambahan (mis. imported_at) ==\n";
$types = table_column_types('tickets');
ok(!isset($types['imported_at']), 'tabel lokal tidak punya imported_at (deployment lain punya)');
ok(in_array('datetime', $types, true), 'normalisasi tetap bekerja untuk kolom datetime lain');

echo "== API menolak tanggal tidak valid ==\n";
$apiSrc = file_get_contents(__DIR__ . '/../api/tickets.php');
ok(str_contains($apiSrc, 'parse_dt'), 'API memakai parse_dt untuk reported_at');
ok(str_contains($apiSrc, 'reported_at tidak valid'), 'API memberi pesan error yang jelas');

echo "== Halaman create tiket memvalidasi tanggal ==\n";
$src = file_get_contents(__DIR__ . '/../ticket_create.php');
ok(str_contains($src, 'Waktu Gangguan Dilaporkan tidak valid'), 'form menampilkan pesan validasi tanggal');

echo "== Kebersihan data uji ==\n";
q('DELETE FROM tickets WHERE id=?', [$res['id']]);
q('DELETE FROM ticket_logs WHERE ticket_id=?', [$res['id']]);
$final = (int)q('SELECT COUNT(*) FROM tickets')->fetchColumn();
ok($final === $before, 'jumlah tiket kembali seperti semula (' . $before . ')');

echo "\n$pass OK, $fail FAIL\n";
exit($fail > 0 ? 1 : 0);
