<?php
/**
 * Uji otomatis proteksi input angka & klasifikasi error (anti error MySQL 1366
 * "Incorrect decimal value" yang sebelumnya salah ditampilkan sebagai
 * "Format tanggal/waktu tidak valid").
 *
 * Jalankan: php tools/test_input_error.php
 */
require_once __DIR__ . '/../includes/bootstrap.php';

$pass = 0; $fail = 0;
function ok($cond, $msg) {
    global $pass, $fail;
    if ($cond) { $pass++; echo "  OK   $msg\n"; }
    else { $fail++; echo "  FAIL $msg\n"; }
}

echo "== Helper parse_num ==\n";
ok(parse_num('-6.2') === -6.2, 'latitude negatif valid');
ok(parse_num('106.8') === 106.8, 'longitude positif valid');
ok(parse_num('0') === 0.0, 'nol valid');
ok(parse_num('') === null, 'string kosong -> null');
ok(parse_num(null) === null, 'null -> null');
ok(parse_num('sdgsg') === null, 'sampah "sdgsg" -> null');
ok(parse_num('gkggf') === null, 'sampah "gkggf" -> null');
ok(parse_num('12abc') === null, 'angka huruf campuran -> null');
ok(parse_num('-6,2') === -6.2, 'koma sebagai desimal (format Indonesia)');
ok(parse_num('1e5') === null, 'notasi ilmiah ditolak');

echo "== Klasifikasi error MariaDB 22007 ==\n";
$eDec = new PDOException("SQLSTATE[22007]: Invalid datetime format: 1366 Incorrect decimal value: 'sdgsg' for column `procurement_db`.`tickets`.`latitude` at row 1");
ok(is_numeric_error($eDec) === true, 'error decimal terdeteksi sebagai error angka');
ok(is_datetime_error($eDec) === false, 'error decimal BUKAN error tanggal');
$info = column_error_info($eDec);
ok($info !== null && $info['column'] === 'latitude', 'kolom salah terbaca: latitude');
ok($info !== null && $info['table'] === 'tickets', 'tabel terbaca: tickets');
ok($info !== null && $info['value'] === 'sdgsg', 'nilai salah terbaca: sdgsg');
$eDt = new PDOException("SQLSTATE[22007]: Invalid datetime format: 1366 Incorrect datetime value: 'gkggf' for column `tickets`.`reported_at` at row 1");
ok(is_datetime_error($eDt) === true, 'error datetime terdeteksi sebagai error tanggal');
ok(is_numeric_error($eDt) === false, 'error datetime BUKAN error angka');
$eOther = new RuntimeException('boom');
ok(is_datetime_error($eOther) === false, 'exception biasa bukan error tanggal');
ok(is_numeric_error($eOther) === false, 'exception biasa bukan error angka');

echo "== Parser INSERT (literal di tengah VALUES) ==\n";
$p = parse_insert_statement('INSERT INTO ticket_materials (ticket_id, material_id, qty_requested, qty_used, is_direct, issued_by, issued_at, note) VALUES (?,?,?,?,1,?,NOW(),?)');
ok($p !== null, 'INSERT dengan literal 1 dan NOW() ter-parse');
ok($p !== null && count($p[1]) === 8 && count($p[2]) === 8, 'jumlah kolom == jumlah nilai');
// Uji langsung pemetaan via sanitize_params: kolom `note` (idx ke-6 param) harus tetap teks.
$params = sanitize_params(
    'INSERT INTO ticket_materials (ticket_id, material_id, qty_requested, qty_used, is_direct, issued_by, issued_at, note) VALUES (?,?,?,?,1,?,NOW(),?)',
    [10, 20, 3, 3, 7, 'catatan vendor']
);
ok($params[0] === 10, 'ticket_id tidak berubah');
ok($params[1] === 20, 'material_id tidak berubah');
ok($params[4] === 7, 'issued_by (setelah literal 1) tidak bergeser');
ok($params[5] === 'catatan vendor', 'note (setelah NOW()) tidak bergeser');

echo "== Sanitize kolom numerik ==\n";
$row = sanitize_row('tickets', ['ticket_no' => 'T-1', 'latitude' => 'sdgsg', 'longitude' => '106.8']);
ok($row['latitude'] === null, 'latitude sampah -> null');
ok((float)$row['longitude'] === 106.8, 'longitude valid -> angka');
$row2 = sanitize_row('tickets', ['latitude' => '-6.2', 'longitude' => '']);
ok((float)$row2['latitude'] === -6.2, 'latitude valid -> angka');
ok($row2['longitude'] === null, 'longitude kosong -> null');

echo "== Sanitize_params kolom DECIMAL ==\n";
$p1 = sanitize_params('INSERT INTO tickets (ticket_no, latitude, longitude, status) VALUES (?,?,?,?)', ['T-1', 'sdgsg', '106.8', 'open']);
ok($p1[1] === null, 'INSERT latitude sampah -> parameter null');
ok((float)$p1[2] === 106.8, 'INSERT longitude valid -> angka');
ok($p1[0] === 'T-1', 'kolom varchar tetap utuh');
$p2 = sanitize_params('SELECT * FROM tickets WHERE id=?', [1]);
ok($p2 === [1], 'query SELECT tidak di intervensi');
$p3 = sanitize_params('UPDATE tickets SET latitude=? WHERE id=?', ['sdgsg', 1]);
ok($p3 === ['sdgsg', 1], 'query UPDATE tidak di intervensi (hanya INSERT)');

echo "== Insert tiket dengan latitude sampah tidak fatal ==\n";
$cityId = (int)q('SELECT id FROM cities ORDER BY id LIMIT 1')->fetchColumn();
$before = (int)q('SELECT COUNT(*) FROM tickets')->fetchColumn();
$res = create_ticket([
    'noc_ref' => 'TEST-NUM-' . date('YmdHis'),
    'city_id' => $cityId,
    'problem_type' => 'Test numeric',
    'location' => 'Test',
    'priority' => 'medium',
    'reported_at' => '2026-11-23 10:30',
    'latitude' => 'sdgsg',
    'longitude' => '106.8',
], 'manual', null);
ok($res['id'] > 0, 'tiket tetap dibuat walau latitude tidak valid');
$stored = q('SELECT latitude, longitude, reported_at FROM tickets WHERE id=?', [$res['id']])->fetch();
ok($stored['latitude'] === null, 'latitude sampler tersimpan sebagai NULL');
ok((float)$stored['longitude'] === 106.8, 'longitude tersimpan sebagai angka');
ok($stored['reported_at'] === '2026-11-23 10:30:00', 'reported_at tersimpan valid');
$after = (int)q('SELECT COUNT(*) FROM tickets')->fetchColumn();
ok($after === $before + 1, 'tepat satu tiket baru dibuat');

echo "== Helper dt_local_value (input datetime-local) ==\n";
ok(dt_local_value('2026-11-23T10:30') === '2026-11-23T10:30', 'format datetime-local dipertahankan');
ok(dt_local_value('2026-11-23 10:30:00') === '2026-11-23T10:30', 'format DB -> datetime-local');
ok(dt_local_value('sdgsg') === date('Y-m-d\TH:i'), 'nilai sampah -> waktu sekarang');
ok(dt_local_value('') === date('Y-m-d\TH:i'), 'kosong -> waktu sekarang');

echo "== Halaman create tiket memvalidasi koordinat & tanggal ==\n";
$src = file_get_contents(__DIR__ . '/../ticket_create.php');
ok(str_contains($src, 'Waktu Gangguan Dilaporkan tidak valid'), 'form memvalidasi waktu');
ok(str_contains($src, 'Latitude harus berupa angka'), 'form memvalidasi latitude');
ok(str_contains($src, 'Longitude harus berupa angka'), 'form memvalidasi longitude');
ok(str_contains($src, 'type="number" step="any" name="latitude"'), 'input latitude bertipe number');
$apiSrc = file_get_contents(__DIR__ . '/../api/tickets.php');
ok(str_contains($apiSrc, 'latitude harus berupa angka'), 'API memvalidasi latitude');

echo "== Kebersihan data uji ==\n";
q('DELETE FROM tickets WHERE id=?', [$res['id']]);
q('DELETE FROM ticket_logs WHERE ticket_id=?', [$res['id']]);
$final = (int)q('SELECT COUNT(*) FROM tickets')->fetchColumn();
ok($final === $before, 'jumlah tiket kembali seperti semula (' . $before . ')');

echo "\n$pass OK, $fail FAIL\n";
exit($fail > 0 ? 1 : 0);
