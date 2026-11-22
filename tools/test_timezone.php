<?php
/**
 * Uji otomatis zona waktu region & kota (WIB / WITA / WIT).
 * Jalankan: php tools/test_timezone.php
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

echo "== Helper zona waktu ==\n";
ok(array_keys(app_timezones()) === ['WIB', 'WITA', 'WIT'], 'daftar zona waktu: WIB, WITA, WIT');
ok(tz_offset('WIB') === 7 && tz_offset('WITA') === 8 && tz_offset('WIT') === 9, 'offset UTC: WIB=7, WITA=8, WIT=9');
ok(tz_offset('ngawur') === 7, 'offset tidak dikenal -> fallback WIB');
ok(region_default_timezone('East') === 'WIT', 'default region East = WIT');
ok(region_default_timezone('West') === 'WIB', 'default region West = WIB');
ok(region_default_timezone('Central') === 'WITA', 'default region Central = WITA');
ok(region_default_timezone('Wilayah Uji') === null, 'region tanpa pola -> tidak ada default');

echo "== Zona waktu region (dari database) ==\n";
$central = regionId('Central'); $east = regionId('East'); $west = regionId('West');
ok($central && $east && $west, 'tiga region utama tersedia');
ok(region_timezone($east) === 'WIT', 'region East = WIT');
ok(region_timezone($west) === 'WIB', 'region West = WIB');
ok(region_timezone($central) === 'WITA', 'region Central = WITA');
ok(region_timezone(null) === DEFAULT_TIMEZONE, 'region null -> timezone default');

echo "== Zona waktu kota ==\n";
ok(city_timezone(cityId('Jakarta')) === 'WIB', 'kota Jakarta = WIB');
ok(city_timezone(cityId('Bandung')) === 'WIB', 'kota Bandung = WIB');
ok(city_timezone(cityId('Medan')) === 'WIB', 'kota Medan = WIB');
ok(city_timezone(cityId('Semarang')) === 'WIB', 'kota Semarang = WIB');
ok(city_timezone(cityId('Surabaya')) === 'WITA', 'kota Surabaya = WITA');
ok(city_timezone(0) === DEFAULT_TIMEZONE, 'kota tidak ada -> timezone default');

echo "== Semua kota punya zona waktu valid ==\n";
$bad = q("SELECT id, name FROM cities WHERE timezone IS NULL OR timezone NOT IN ('WIB','WITA','WIT')")->fetchAll();
ok(!$bad, 'tidak ada kota tanpa zona waktu valid' . ($bad ? ': ' . json_encode($bad) : ''));
$badRegion = q("SELECT id, name FROM regions WHERE timezone IS NOT NULL AND timezone NOT IN ('WIB','WITA','WIT')")->fetchAll();
ok(!$badRegion, 'tidak ada region dengan zona waktu tidak valid');

echo "== Badge & format waktu ==\n";
ok(str_contains(tz_badge('WIB'), 'WIB'), 'badge WIB memuat label WIB');
ok(str_contains(tz_badge('WITA'), 'UTC+8'), 'badge WITA memuat UTC+8');
ok(str_contains(tz_badge('WIT'), 'UTC+9'), 'badge WIT memuat UTC+9');
ok(str_contains(tz_badge(''), 'text-muted'), 'badge kosong -> tanda minus');
ok(str_contains(city_tz_badge(cityId('Surabaya')), 'WITA'), 'badge kota Surabaya = WITA');
ok(str_contains(region_tz_badge($east), 'WIT'), 'badge region East = WIT');

ok(fmt_dt_tz('2026-01-01 08:00:00', 'WIB') === '01/01/2026 08:00 WIB', 'waktu WIB tidak digeser');
ok(fmt_dt_tz('2026-01-01 08:00:00', 'WITA') === '01/01/2026 09:00 WITA', 'waktu WITA digeser +1 jam');
ok(fmt_dt_tz('2026-01-01 08:00:00', 'WIT') === '01/01/2026 10:00 WIT', 'waktu WIT digeser +2 jam');
ok(fmt_dt_tz(null, 'WIB') === '-', 'waktu kosong -> -');

echo "== Fallback kota tanpa zona waktu sendiri ==\n";
ok(resolve_city_timezone(null, 'Kota Uji TZ', $east) === 'WIT', 'kota tanpa timezone -> mengikuti zona waktu region (East = WIT)');
ok(resolve_city_timezone(null, 'Surabaya', $east) === 'WITA', 'kota tanpa timezone -> pakai lokasi geografis (Surabaya = WITA)');
ok(resolve_city_timezone('WIB', 'Surabaya', $east) === 'WIB', 'kolom kota manual mengalahkan geografi & region');
ok(resolve_city_timezone(null, 'Kota Tanpa Region', null) === DEFAULT_TIMEZONE, 'tanpa region -> timezone default');

echo "== Migrasi idempoten ==\n";
seed_timezones();
seed_timezones();
$after = q("SELECT COUNT(*) FROM cities WHERE timezone IS NULL")->fetchColumn();
ok((int)$after === 0, 'seed timezone idempoten (tidak ada kota NULL setelah dua kali seed)');

echo "\n$pass OK, $fail FAIL\n";
exit($fail > 0 ? 1 : 0);
