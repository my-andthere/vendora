<?php
/**
 * Test fitur Konsep Form Vendor MS (2026-11-19):
 *
 *  A. Material checklist (MATERIAL A/B/C) - form bulk + aturan "jumlah diisi => material dipilih"
 *  B. Batas maksimal pemakaian per tiket (konsep: "kabel jenis tertentu + 500m => panjang max 3")
 *  C. Deteksi foto blur (harus bisa dicek blur atau tidak, jika blur -> upload lagi)
 *  D. Dua tahap tanda check: PIC Region (fase 1) & Evaluasi internal CGS (fase 2)
 *  E. Role NAM (FOC -> NAM -> NOC)
 *
 * Jalankan: php tools/test_form_concept.php
 */
require_once __DIR__ . '/../includes/bootstrap.php';

$pass = 0;
$fail = 0;
$created = ['materials' => [], 'tickets' => [], 'photos' => []];

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

echo "== TEST KONSEP FORM VENDOR MS ==\n";

// ---------------------------------------------------------------
// A. Prasyarat skema
// ---------------------------------------------------------------
echo "\n-- Prasyarat skema --\n";
foreach ([
    ['materials', 'max_use_per_ticket'],
    ['ticket_photos', 'is_blurry'],
    ['ticket_photos', 'blur_score'],
    ['ticket_signoffs', 'stage'],
] as [$tbl, $col]) {
    $has = q("SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=?",
        [$tbl, $col])->fetch();
    chk((bool)$has, "kolom $tbl.$col ada");
}
$idx = q("SHOW INDEX FROM ticket_signoffs WHERE Key_name='uniq_signoff'")->fetchAll();
chk(count($idx) === 4, 'unique key tanda check memuat stage (4 kolom)');

// ---------------------------------------------------------------
// B. Fixture: 2 material (satu ada batasnya), 1 tiket
// ---------------------------------------------------------------
echo "\n-- Menyiapkan fixture --\n";
$cityId = (int)q("SELECT id FROM cities ORDER BY id LIMIT 1")->fetchColumn();
q("INSERT INTO materials (code, name, unit, stock, max_use_per_ticket, is_active) VALUES (?,?,?,?,?,1)",
    ['UJI-LIMIT-' . date('His'), 'Kabel Uji 500m', 'meter', 100, 3]);
$matLimited = (int)db()->lastInsertId();
$created['materials'][] = $matLimited;

q("INSERT INTO materials (code, name, unit, stock, max_use_per_ticket, is_active) VALUES (?,?,?,?,?,1)",
    ['UJI-FREE-' . date('His'), 'Material Uji Tanpa Batas', 'pcs', 100, 0]);
$matFree = (int)db()->lastInsertId();
$created['materials'][] = $matFree;

$vendorId = (int)q("SELECT id FROM vendors WHERE city_id=? AND status='available' ORDER BY id LIMIT 1", [$cityId])->fetchColumn();
q("INSERT INTO tickets (ticket_no, spk_no, source, city_id, customer_name, problem_type, priority, reported_at, sla_minutes, status, vendor_id, created_by)
   VALUES (?,?,'nac',?,'Uji Konsep','Test',  'medium', NOW(), 180, 'on_progress', ?, 1)",
    ['TKT-UJI-' . date('His') . '-A', 'SPK-UJI-' . date('His') . '-A', $cityId, $vendorId]);
$ticketId = (int)db()->lastInsertId();
$created['tickets'][] = $ticketId;
ok("fixture: tiket=$ticketId material limited=$matLimited free=$matFree");

// ---------------------------------------------------------------
// C. Batas maksimal pemakaian per tiket
// ---------------------------------------------------------------
echo "\n-- Batas maksimal per tiket --\n";
$chk = material_usage_check($matLimited, 2);
chk($chk['ok'], 'jumlah di bawah batas diterima');
eq($chk['max'], 3.0, 'batas terbaca 3');
$chk = material_usage_check($matLimited, 4);
chk(!$chk['ok'], 'jumlah melebihi batas ditolak');
chk(strpos((string)$chk['error'], 'CGS') !== false, 'pesan error menyebut konfirmasi ke CGS');
$chk = material_usage_check($matFree, 999);
chk($chk['ok'], 'material tanpa batas menerima jumlah besar');

$res = ms_add_material($ticketId, $matLimited, 4, null);
chk(!$res['ok'], 'ms_add_material() menolak melebihi batas');
$res = ms_add_material($ticketId, $matLimited, 3, null);
chk($res['ok'], 'ms_add_material() menerima tepat di batas: ' . ($res['error'] ?? ''));
eq((float)q('SELECT stock FROM materials WHERE id=?', [$matLimited])->fetchColumn(), 97.0, 'stok berkurang 3');

// ---------------------------------------------------------------
// D. Form checklist (bulk) + aturan "jumlah diisi => material dipilih"
// ---------------------------------------------------------------
echo "\n-- Form checklist material --\n";
$res = ms_add_materials($ticketId, [
    ['material_id' => $matFree, 'qty' => 2, 'note' => 'dari checklist'],
    ['material_id' => $matLimited, 'qty' => 0],   // tidak dicentang -> dilewati
], null);
chk($res['ok'], 'ms_add_materials() menyimpan 1 material: ' . ($res['error'] ?? ''));
eq($res['saved'], 1, 'hanya 1 material tersimpan (qty 0 dilewati)');
$row = q('SELECT * FROM ticket_materials WHERE ticket_id=? AND material_id=? AND qty_used=2', [$ticketId, $matFree])->fetch();
chk((bool)$row, 'baris material checklist tersimpan');
eq($row['note'] ?? '', 'dari checklist', 'keterangan baris checklist tersimpan');

// Batas dihitung TERHADAP total pemakaian tiket, bukan per baris.
$res = ms_add_materials($ticketId, [['material_id' => $matLimited, 'qty' => 1]], null);
chk(!$res['ok'], 'batas dihitung total pemakaian tiket (3 + 1 > 3)');
eq(material_used_total($ticketId, $matLimited), 3.0, 'total pemakaian material terbatas = 3');

// ---------------------------------------------------------------
// E. Deteksi foto blur
// ---------------------------------------------------------------
echo "\n-- Deteksi foto blur --\n";
$dir = __DIR__ . '/../uploads/_test_blur';
@mkdir($dir, 0777, true);

// Foto TAJAM: noise acak + garis tajam -> skor tinggi.
$sharp = imagecreatetruecolor(400, 300);
imagefill($sharp, 0, 0, imagecolorallocate($sharp, 20, 20, 20));
for ($i = 0; $i < 400; $i++) {
    $c = imagecolorallocate($sharp, random_int(0, 255), random_int(0, 255), random_int(0, 255));
    imagesetpixel($sharp, random_int(0, 399), random_int(0, 299), $c);
}
imagejpeg($sharp, $dir . '/sharp.jpg', 90);
imagedestroy($sharp);

// Foto BLUR: gradient halus -> skor rendah.
$blur = imagecreatetruecolor(400, 300);
for ($y = 0; $y < 300; $y++) {
    $c = imagecolorallocate($blur, (int)($y / 3), (int)($y / 3), (int)($y / 3));
    imageline($blur, 0, $y, 399, $y, $c);
}
imagejpeg($blur, $dir . '/blur.jpg', 90);
imagedestroy($blur);

$scoreSharp = image_blur_score($dir . '/sharp.jpg');
$scoreBlur = image_blur_score($dir . '/blur.jpg');
chk($scoreSharp !== null, 'skor foto tajam terbaca: ' . var_export($scoreSharp, true));
chk($scoreBlur !== null, 'skor foto blur terbaca: ' . var_export($scoreBlur, true));
if ($scoreSharp !== null && $scoreBlur !== null) {
    chk($scoreSharp > $scoreBlur, 'foto tajam skornya lebih tinggi dari foto blur');
}
$chkSharp = image_blur_check($dir . '/sharp.jpg');
chk(!$chkSharp['blurry'], 'foto tajam tidak dianggap blur');
$chkBlur = image_blur_check($dir . '/blur.jpg');
chk($chkBlur['blurry'], 'foto blur terdeteksi');
chk($chkBlur['reason'] !== '', 'alasan penolakan blur tersedia');
chk(strpos(blur_badge(1, $scoreBlur), 'Blur') !== false, 'blur_badge() menghasilkan badge');
chk(blur_badge(0, null) === '', 'blur_badge() kosong untuk foto normal');
chk(image_blur_score($dir . '/tidak-ada.jpg') === null, 'file hilang tidak error');

// ---------------------------------------------------------------
// F. Dua tahap tanda check (PIC Region & Evaluasi CGS)
// ---------------------------------------------------------------
echo "\n-- Dua tahap tanda check --\n";
save_signoff($ticketId, 'material', 1, 'ok', 'Dicek PIC Region', 1, 'region');
save_signoff($ticketId, 'material', 1, 'marked', 'Perlu klarifikasi', 2, 'cgs');
$all = ticket_signoffs($ticketId);
eq($all['material'][1]['region']['status'] ?? '', 'ok', 'tanda check PIC Region tersimpan terpisah');
eq($all['material'][1]['cgs']['status'] ?? '', 'marked', 'evaluasi CGS tersimpan terpisah');
eq($all['material'][1]['region']['note'] ?? '', 'Dicek PIC Region', 'keterangan PIC Region terpisah');
eq($all['material'][1]['cgs']['note'] ?? '', 'Perlu klarifikasi', 'keterangan Eval CGS terpisah');

$so = get_signoff($ticketId, 'material', 1, 'cgs');
eq($so['status'] ?? '', 'marked', 'get_signoff() mengambil stage yang benar');

// Menimpa stage yang sama harus update, bukan menambah baris.
save_signoff($ticketId, 'material', 1, 'ok', 'Revisi', 2, 'cgs');
eq((int)q('SELECT COUNT(*) FROM ticket_signoffs WHERE ticket_id=? AND scope=? AND ref_id=?', [$ticketId, 'material', 1])->fetchColumn(),
    2, 'stage berbeda = 2 baris, stage sama = 1 baris');
eq(get_signoff($ticketId, 'material', 1, 'cgs')['status'], 'ok', 'menimpa stage yang sama berhasil');

$summary = signoff_summary($ticketId);
chk(count($summary) >= 2, 'signoff_summary() merangkum tanda check');
$cell = signoff_cell($all, 'material', 1, 'Contoh Material');
chk(strpos($cell, 'PIC Region') !== false, 'signoff_cell() menampilkan label PIC Region');
chk(strpos($cell, 'Eval CGS') !== false, 'signoff_cell() menampilkan label Eval CGS');
chk(strpos($cell, 'Sudah dicek oleh') !== false, 'signoff_cell() menampilkan siapa yang sudah mengecheck');

// Scope tidak valid tetap ditolak.
save_signoff($ticketId, 'scope_ngawur', 0, 'ok', 'x', 1, 'region');
chk(!q('SELECT id FROM ticket_signoffs WHERE ticket_id=? AND scope=?', [$ticketId, 'scope_ngawur'])->fetch(), 'scope tidak valid ditolak');
// Stage tidak valid -> fallback ke region.
save_signoff($ticketId, 'material', 2, 'ok', 'x', 1, 'stage_ngawur');
eq(get_signoff($ticketId, 'material', 2, 'region')['status'] ?? '', 'ok', 'stage tidak valid fallback ke region');

// ---------------------------------------------------------------
// G. Role NAM
// ---------------------------------------------------------------
echo "\n-- Role NAM --\n";
chk(isset(ROLES['nam']), 'role nam terdaftar di ROLES');
eq(ROLES['nam'], 'NAM', 'label role nam = NAM');
chk(in_array('nam', CGS_REVIEW_ROLES, true), 'nam termasuk CGS_REVIEW_ROLES');
$roleType = (string)(q("SHOW COLUMNS FROM users LIKE 'role'")->fetch()['Type'] ?? '');
chk(stripos($roleType, "'nam'") !== false, 'enum users.role memuat nam');
chk(in_array('nam', ['admin', 'noc', 'nam', 'procurement', 'vendor', 'inventory', 'foc'], true), 'daftar role lengkap');

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
    q('DELETE FROM notifications WHERE ticket_id=?', [$tid]);
    q('DELETE FROM tickets WHERE id=?', [$tid]);
}
foreach ($created['materials'] as $mid) q('DELETE FROM materials WHERE id=?', [$mid]);
foreach (glob($dir . '/*') ?: [] as $f) @unlink($f);
@rmdir($dir);
ok('semua data uji dibersihkan');

echo "\n=== HASIL: $pass OK, $fail FAIL ===\n";
exit($fail > 0 ? 1 : 0);
