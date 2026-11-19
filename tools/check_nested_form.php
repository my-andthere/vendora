<?php
/**
 * Memindai halaman untuk menemukan <form> di dalam <form> (nested form).
 * HTML tidak mengizinkan nested form: browser mengabaikan form di dalam,
 * sehingga field-nya terkirim ke form luAR - biasanya menyebabkan aksi yang salah.
 *
 * Jalankan: php tools/check_nested_form.php
 */
$files = array_merge(glob(__DIR__ . '/../*.php'), glob(__DIR__ . '/../includes/*.php'), glob(__DIR__ . '/../api/*.php'));
$found = 0;

foreach ($files as $f) {
    $src = file_get_contents($f);
    // Ambil posisi tag <form ...> dan </form>
    preg_match_all('/<form\b[^>]*>/i', $src, $open, PREG_OFFSET_CAPTURE);
    preg_match_all('/<\/form\s*>/i', $src, $close, PREG_OFFSET_CAPTURE);

    $events = [];
    foreach ($open[0] as $m)  $events[] = [$m[1], 1, $m[0]];
    foreach ($close[0] as $m) $events[] = [$m[1], -1, $m[0]];
    usort($events, fn($a, $b) => $a[0] <=> $b[0]);

    $depth = 0;
    $stack = [];
    foreach ($events as [$off, $delta, $tag]) {
        if ($delta === 1) {
            $depth++;
            if ($depth > 1) {
                $line = substr_count(substr($src, 0, $off), "\n") + 1;
                echo "NESTED  " . basename($f) . " baris $line : " . trim($tag) . "\n";
                $found++;
            }
            $stack[] = $off;
        } else {
            $depth--;
            array_pop($stack);
        }
    }
    if ($depth !== 0) {
        echo "UNBALANCED " . basename($f) . " : sisa depth $depth\n";
        $found++;
    }
}

echo $found === 0 ? "OK  tidak ada nested form\n" : "\n$found masalah nested form ditemukan\n";
exit($found === 0 ? 0 : 1);
