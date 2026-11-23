<?php
/**
 * Helper pemeriksaan dokumentasi foto (2026-11-19).
 *
 * Konsep: "harus bisa dicek blur atau tidak, jika blur -> upload lagi".
 *
 * Metode: variance of Laplacian (diagonal) - nilai kecil = fotoblur.
 * Angka < 100 dianggap blur. Ambang bisa diubah lewat config (BLUR_THRESHOLD).
 */

require_once __DIR__ . '/bootstrap.php';

/** Ambang skor blur (variance of Laplacian). Makin kecil = makin blur. */
const BLUR_THRESHOLD = 90.0;

/** Ukuran sisi gambar yang dianalisa (makin kecil = makin cepat, cukup untuk deteksi blur). */
const BLUR_ANALYZE_SIZE = 320;

/**
 * Hitung skor blur (variance of Laplacian) dari sebuah file gambar.
 * @return float|null null bila file bukan gambar / GD tidak bisa membaca
 */
function image_blur_score(string $absPath): ?float
{
    if (!is_file($absPath) || !function_exists('imagecreatefromjpeg')) return null;
    $info = @getimagesize($absPath);
    if (!$info) return null;
    [$w, $h] = $info;
    if ($w < 8 || $h < 8) return null;

    $img = null;
    switch ($info[2]) {
        case IMAGETYPE_JPEG: $img = @imagecreatefromjpeg($absPath); break;
        case IMAGETYPE_PNG:  $img = @imagecreatefrompng($absPath); break;
        case IMAGETYPE_WEBP:
            if (function_exists('imagecreatefromwebp')) $img = @imagecreatefromwebp($absPath);
            break;
        default: $img = null;
    }
    if (!$img) return null;

    // Perkecil supaya cepat & tidak boros memori.
    $tw = BLUR_ANALYZE_SIZE;
    $th = max(1, (int)round($h * ($tw / max(1, $w))));
    $small = imagecreatetruecolor($tw, $th);
    imagealphablending($small, false);
    imagesavealpha($small, true);
    imagecopyresampled($small, $img, 0, 0, 0, 0, $tw, $th, $w, $h);
    imagedestroy($img);

    // Hitung luminance lalu Laplacian diagonal (kernel 0 -1 0 / -1 4 -1 / 0 -1 0).
    $lum = [];
    for ($y = 0; $y < $th; $y++) {
        for ($x = 0; $x < $tw; $x++) {
            $rgb = imagecolorat($small, $x, $y);
            $lum[$y][$x] = 0.299 * (($rgb >> 16) & 0xFF) + 0.587 * (($rgb >> 8) & 0xFF) + 0.114 * ($rgb & 0xFF);
        }
    }

    $sum = 0.0;
    $sumSq = 0.0;
    $n = 0;
    for ($y = 1; $y < $th - 1; $y++) {
        for ($x = 1; $x < $tw - 1; $x++) {
            $lap = 4 * $lum[$y][$x] - $lum[$y - 1][$x] - $lum[$y + 1][$x] - $lum[$y][$x - 1] - $lum[$y][$x + 1];
            $sum += $lap;
            $sumSq += $lap * $lap;
            $n++;
        }
    }
    imagedestroy($small);
    if ($n === 0) return null;

    $mean = $sum / $n;
    return round($sumSq / $n - ($mean * $mean), 4);
}

/**
 * Apakah foto ini dianggap blur?
 * @return array{blurry:bool,score:?float,reason:string}
 */
function image_blur_check(string $absPath, ?float $threshold = null): array
{
    $threshold ??= BLUR_THRESHOLD;
    $score = image_blur_score($absPath);
    if ($score === null) {
        return ['blurry' => false, 'score' => null, 'reason' => 'Foto tidak bisa dianalisa (format tidak didukung / terlalu kecil).'];
    }
    if ($score < $threshold) {
        return ['blurry' => true, 'score' => $score,
            'reason' => 'Foto terblur (skor ' . number_format($score, 1) . ' < ' . number_format($threshold, 0) . '). Silakan foto ulang dengan kamera fokus & upload ulang.'];
    }
    return ['blurry' => false, 'score' => $score, 'reason' => ''];
}

/** Badge kecil untuk foto yang terflag blur. */
function blur_badge(?int $isBlurry, ?float $score = null): string
{
    if (!$isBlurry) return '';
    $tip = $score !== null ? 'Skor ketajaman ' . number_format((float)$score, 1) : '';
    return '<span class="badge bg-danger" title="' . e($tip) . '"><i class="bi bi-exclamation-triangle"></i> Blur</span>';
}

/**
 * Validasi jumlah pemakaian material terhadap batas per tiket.
 * Konsep: "jumlah harus dicek, kalau lewat threshold tertentu tidak bisa dibuat
 *          (mis. kabel jenis tertentu + 500 meter => panjang max 3)".
 *
 * @return array{ok:bool,error?:string,max:float}
 */
function material_usage_check(int $materialId, float $qty): array
{
    $m = q('SELECT id, name, unit, max_use_per_ticket FROM materials WHERE id=?', [$materialId])->fetch();
    if (!$m) return ['ok' => false, 'error' => 'Material tidak ditemukan.', 'max' => 0];
    $max = (float)$m['max_use_per_ticket'];
    if ($max > 0 && $qty > $max) {
        return ['ok' => false, 'max' => $max,
            'error' => 'Jumlah ' . $m['name'] . ' melebihi batas per tiket (maks ' . fmt_qty($max) . ' ' . $m['unit'] . '). '
                . 'Mohon konfirmasi ke CGS sebelum memakai material tersebut.'];
    }
    return ['ok' => true, 'max' => $max];
}

/**
 * Total pemakaian material pada sebuah tiket (semua baris), dipakai untuk cek batas.
 */
function material_used_total(int $ticketId, int $materialId, int $excludeLineId = 0): float
{
    return (float)q('SELECT COALESCE(SUM(qty_used),0) FROM ticket_materials
                     WHERE ticket_id=? AND material_id=? AND id<>?', [$ticketId, $materialId, $excludeLineId])->fetchColumn();
}
