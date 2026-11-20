<?php
/**
 * Helper flow Vendor MS (Managed Service) - 2026-11-19.
 *
 * Berbeda dengan vendor non-MS:
 *  - Tiket di kota yang punya vendor MS otomatis ditugaskan ke vendor MS.
 *  - Vendor MS "LANGSUNG TERUS MENUJU": tidak perlu request material ke inventory
 *    lebih dulu; material dipakai langsung dicatat dari form vendor (stok otomatis berkurang).
 *  - Saat pekerjaan selesai, BASTO (catatan penggunaan material) OTOMATIS terbentuk
 *    dari form vendor yang sudah diisi.
 *  - Setelah link up permanent -> "Cek Ready for Service Level Agreement" (RPA/SLA)
 *    dengan penandaan OK atau TANDAIN.
 *  - Tanda check & keterangan oleh PIC Region atas material, dokumentasi, dan penyelesaian.
 */

require_once __DIR__ . '/bootstrap.php';

// ------------------------------------------------------------
// Tipe vendor
// ------------------------------------------------------------
const VENDOR_TYPES = [
    'ms'     => ['Vendor MS', 'primary', 'Managed Service - langsung kerjakan, BASTO otomatis'],
    'non_ms' => ['Vendor Non-MS', 'secondary', 'Vendor biasa - request material ke inventory'],
];

/** Badge tipe vendor */
function vendor_type_badge(?string $type): string
{
    $t = VENDOR_TYPES[$type ?? ''] ?? VENDOR_TYPES['non_ms'];
    return '<span class="badge bg-' . $t[1] . ' text-dark" title="' . e($t[2]) . '">' . e($t[0]) . '</span>';
}

/** Tipe vendor dari id (null kalau vendor tidak ada) */
function vendor_type_of(int $vendorId): string
{
    static $cache = [];
    if (!isset($cache[$vendorId])) {
        $v = q('SELECT vendor_type FROM vendors WHERE id=?', [$vendorId])->fetch();
        $cache[$vendorId] = (string)($v['vendor_type'] ?? 'non_ms');
    }
    return $cache[$vendorId];
}

/** Apakah vendor ini tipe MS (Managed Service)? */
function is_ms_vendor(?int $vendorId): bool
{
    return $vendorId !== null && vendor_type_of((int)$vendorId) === 'ms';
}

/**
 * Daftar kota yang punya vendor MS available (kota dengan vendor MS managed service).
 * @return array<int,array{city_id:int,city_name:string,ms_vendors:int,ms_available:int,is_primary:int}>
 */
function ms_cities(): array
{
    return q("SELECT c.id city_id, c.name city_name, c.is_primary,
                COUNT(v.id) ms_vendors,
                SUM(CASE WHEN v.status='available' THEN 1 ELSE 0 END) ms_available
        FROM cities c JOIN vendors v ON v.city_id=c.id AND v.vendor_type='ms'
        GROUP BY c.id, c.name, c.is_primary
        ORDER BY c.is_primary DESC, c.name")->fetchAll();
}

/** Apakah kota ini punya vendor MS available? */
function city_has_ms_vendor(int $cityId): bool
{
    static $cache = [];
    if (!isset($cache[$cityId])) {
        $n = (int)q("SELECT COUNT(*) FROM vendors WHERE city_id=? AND vendor_type='ms' AND status='available'", [$cityId])->fetchColumn();
        $cache[$cityId] = $n > 0;
    }
    return $cache[$cityId];
}

/**
 * Kandidat vendor: vendor MS di kota tersebut diprioritaskan (flow kotak 3 diagram).
 * @return array<int,array> kandidat urut MS dulu
 */
function ms_first_candidates(int $ticketId, bool $excludeRejected = true): array
{
    $cands = candidate_vendors($ticketId, $excludeRejected);
    usort($cands, function ($a, $b) {
        $ams = ($a['vendor_type'] ?? 'non_ms') === 'ms' ? 0 : 1;
        $bms = ($b['vendor_type'] ?? 'non_ms') === 'ms' ? 0 : 1;
        if ($ams !== $bms) return $ams - $bms;              // MS selalu di depan
        return (int)$a['load_jobs'] <=> (int)$b['load_jobs']; // lalu beban terkecil
    });
    return $cands;
}

// ------------------------------------------------------------
// Material dipakai langsung oleh Vendor MS
// ------------------------------------------------------------
/**
 * Catat material yang dipakai LANGSUNG oleh Vendor MS (tanpa request ke inventory).
 * Stok material otomatis berkurang.
 * @return array{ok:bool,error?:string,line_id?:int}
 */
function ms_add_material(int $ticketId, int $materialId, float $qty, ?int $userId = null): array
{
    if ($qty <= 0) return ['ok' => false, 'error' => 'Jumlah harus lebih dari 0.'];
    $m = q('SELECT * FROM materials WHERE id=? AND is_active=1', [$materialId])->fetch();
    if (!$m) return ['ok' => false, 'error' => 'Material tidak ditemukan / tidak aktif.'];
    if ((float)$m['stock'] < $qty) {
        return ['ok' => false, 'error' => 'Stok ' . $m['name'] . ' tidak cukup (sisa ' . fmt_qty((float)$m['stock']) . ').'];
    }
    // Batas maksimal per tiket (konsep: "kalau lewat threshold tidak bisa dibuat").
    $limit = material_usage_check($materialId, $qty);
    if (!$limit['ok']) return ['ok' => false, 'error' => $limit['error']];
    // Batas dihitung terhadap TOTAL pemakaian di tiket ini, bukan per baris.
    if ($limit['max'] > 0) {
        $total = material_used_total($ticketId, $materialId) + $qty;
        if ($total > $limit['max']) {
            $mn = q('SELECT name, unit FROM materials WHERE id=?', [$materialId])->fetch();
            return ['ok' => false, 'error' => 'Total pemakaian ' . ($mn['name'] ?? 'material') . ' pada tiket ini akan menjadi '
                . fmt_qty($total) . ' ' . ($mn['unit'] ?? '') . ', melebihi batas per tiket (maks ' . fmt_qty($limit['max']) . '). '
                . 'Mohon konfirmasi ke CGS sebelum memakai material tersebut.'];
        }
    }

    db()->beginTransaction();
    try {
        q("INSERT INTO ticket_materials (ticket_id, material_id, qty_requested, qty_issued, qty_used, is_direct, issued_by, issued_at, note)
           VALUES (?,?,?,?,?,1,?,NOW(),?)",
            [$ticketId, $materialId, $qty, $qty, $qty, $userId, 'Dipakai langsung oleh Vendor MS (langsung menuju)']);
        $lineId = (int)db()->lastInsertId();
        q('UPDATE materials SET stock = stock - ? WHERE id=?', [$qty, $materialId]);
        db()->commit();
    } catch (Throwable $e) {
        db()->rollBack();
        return ['ok' => false, 'error' => 'Gagal menyimpan material: ' . $e->getMessage()];
    }
    log_ticket($ticketId, 'material_used_ms', 'Material dipakai langsung (Vendor MS): ' . $m['name'] . ' ' . fmt_qty($qty) . ' ' . $m['unit']);
    return ['ok' => true, 'line_id' => $lineId];
}

/**
 * Catat BANYAK material sekaligus dari form checklist (konsep screen Vendor MS:
 * MATERIAL A/B/C [CHECKLIST] + JUMLAH [ISIAN] + [SATUAN] fixed).
 *
 * Aturan:
 *  - jumlah diisi (qty > 0) WAJIB materialnya tercentang (dicek);
 *  - jumlah tidak boleh melebihi batas per tiket (materials.max_use_per_ticket);
 *  - stok tidak boleh kurang.
 *
 * @param array<int,array{material_id:int,qty:float,note?:string}> $items
 * @return array{ok:bool,error?:string,saved?:int}
 */
function ms_add_materials(int $ticketId, array $items, ?int $userId = null): array
{
    $saved = 0;
    foreach ($items as $it) {
        $qty = (float)($it['qty'] ?? 0);
        $mid = (int)($it['material_id'] ?? 0);
        if ($qty <= 0) continue;                       // tidak dicentang / jumlah kosong -> dilewati
        if ($mid <= 0) {
            return ['ok' => false, 'error' => 'Material belum dipilih padahal jumlah diisi.', 'saved' => $saved];
        }
        $res = ms_add_material($ticketId, $mid, $qty, $userId);
        if (!$res['ok']) {
            return ['ok' => false, 'error' => $res['error'], 'saved' => $saved];
        }
        $note = trim((string)($it['note'] ?? ''));
        if ($note !== '') {
            q('UPDATE ticket_materials SET note=? WHERE id=?', [$note, (int)$res['line_id']]);
        }
        $saved++;
    }
    return ['ok' => true, 'saved' => $saved];
}

/**
 * Batalkan material Vendor MS yang dipakai langsung; stok dikembalikan.
 * @return array{ok:bool,error?:string}
 */
function ms_remove_material(int $ticketId, int $lineId): array
{
    $line = q('SELECT tm.*, m.name, m.unit FROM ticket_materials tm JOIN materials m ON m.id=tm.material_id
               WHERE tm.id=? AND tm.ticket_id=? AND tm.is_direct=1', [$lineId, $ticketId])->fetch();
    if (!$line) return ['ok' => false, 'error' => 'Material tidak ditemukan.'];
    if ($line['qty_returned'] !== null) return ['ok' => false, 'error' => 'Material sudah dicek inventory.'];
    db()->beginTransaction();
    try {
        if ($line['qty_issued'] !== null) {
            q('UPDATE materials SET stock = stock + ? WHERE id=?', [(float)$line['qty_issued'], (int)$line['material_id']]);
        }
        q('DELETE FROM ticket_materials WHERE id=?', [(int)$line['id']]);
        db()->commit();
    } catch (Throwable $e) {
        db()->rollBack();
        return ['ok' => false, 'error' => 'Gagal membatalkan material: ' . $e->getMessage()];
    }
    log_ticket($ticketId, 'material_removed_ms', 'Material dibatalkan oleh vendor: ' . $line['name']);
    return ['ok' => true];
}

// ------------------------------------------------------------
// Master options (jenis kerusakan / tindakan perbaikan / status material)
// ------------------------------------------------------------
function master_options(string $category): array
{
    return q('SELECT * FROM master_options WHERE category=? AND is_active=1 ORDER BY name', [$category])->fetchAll();
}

function master_option_names(string $category): array
{
    return array_map(fn($o) => (string)$o['name'], master_options($category));
}

// ------------------------------------------------------------
// Tanda check & keterangan (fase 1: PIC Region, fase 2: Evaluasi internal CGS)
// ------------------------------------------------------------
const SIGNOFF_SCOPES = [
    'material'     => 'Material',
    'dokumentasi'  => 'Dokumentasi',
    'penyelesaian' => 'Penyelesaian Kerusakan',
];

/**
 * Stage tanda check:
 *  - region   = Tanda Check PIC Region (fase 1)
 *  - inventory= Cek Material oleh Inventory (wajib sebelum lanjut ke FOC)
 *  - cgs      = Evaluasi internal CGS (FOC/NAM/NOC)
 *  - foc      = Ceklist evaluation oleh FOC (dokumentasi / penyebab / tindakan)
 */
const SIGNOFF_STAGES = [
    'region'    => 'Tanda Check PIC Region',
    'inventory' => 'Cek Material Inventory',
    'cgs'       => 'Evaluasi CGS',
    'foc'       => 'Ceklist FOC',
];

/**
 * Ambil status tanda check untuk satu tiket.
 * @return array<string,array<int,array<string,array{status:string,note:?string,checked_by:?string,checked_at:?string}>>>
 *         [scope][ref_id][stage] => ...
 */
function ticket_signoffs(int $ticketId): array
{
    if (!isset($GLOBALS['__signoff_cache'])) $GLOBALS['__signoff_cache'] = [];
    if (isset($GLOBALS['__signoff_cache'][$ticketId])) return $GLOBALS['__signoff_cache'][$ticketId];
    $out = [];
    $rows = q('SELECT s.*, u.full_name AS checked_by_name FROM ticket_signoffs s
               LEFT JOIN users u ON u.id = s.checked_by WHERE s.ticket_id=? ORDER BY s.id', [$ticketId])->fetchAll();
    foreach ($rows as $r) {
        $out[$r['scope']][(int)$r['ref_id']][$r['stage']] = [
            'status'     => $r['status'],
            'note'       => $r['note'],
            'checked_by' => $r['checked_by_name'] ?? null,
            'checked_at' => $r['checked_at'],
        ];
    }
    return $GLOBALS['__signoff_cache'][$ticketId] = $out;
}

/** Bersihkan cache tanda check sebuah tiket (dipanggil setelah menyimpan). */
function clear_signoff_cache(?int $ticketId = null): void
{
    if (!isset($GLOBALS['__signoff_cache'])) return;
    if ($ticketId === null) {
        $GLOBALS['__signoff_cache'] = [];
        return;
    }
    unset($GLOBALS['__signoff_cache'][$ticketId]);
}

/**
 * Simpan tanda check.
 * @param string $stage 'region' (PIC Region) atau 'cgs' (evaluasi internal CGS)
 */
function save_signoff(int $ticketId, string $scope, int $refId, string $status, ?string $note, ?int $userId, string $stage = 'region'): void
{
    if (!isset(SIGNOFF_SCOPES[$scope])) return;
    if (!isset(SIGNOFF_STAGES[$stage])) $stage = 'region';
    $refId = max(0, $refId);
    q('INSERT INTO ticket_signoffs (ticket_id, scope, ref_id, stage, status, note, checked_by, checked_at)
       VALUES (?,?,?,?,?,?,?,?)
       ON DUPLICATE KEY UPDATE status=VALUES(status), note=VALUES(note),
         checked_by=VALUES(checked_by), checked_at=VALUES(checked_at)',
        [$ticketId, $scope, $refId, $stage, $status, $note ?: null, $userId, $status === 'pending' ? null : now()]);
    clear_signoff_cache($ticketId);
}

/**
 * Ambil satu tanda check tertentu.
 * @return array{status:string,note:?string,checked_by:?string,checked_at:?string}|null
 */
function get_signoff(int $ticketId, string $scope, int $refId, string $stage = 'region'): ?array
{
    $all = ticket_signoffs($ticketId);
    return $all[$scope][$refId][$stage] ?? null;
}

/**
 * Ringkasan siapa saja yang sudah melakukan evaluation pada sebuah tiket.
 * @return array<int,array{scope:string,ref_id:int,stage:string,status:string,checked_by:?string,checked_at:?string}>
 */
function signoff_summary(int $ticketId): array
{
    $out = [];
    foreach (ticket_signoffs($ticketId) as $scope => $refs) {
        foreach ($refs as $refId => $stages) {
            foreach ($stages as $stage => $s) {
                if (($s['status'] ?? 'pending') === 'pending') continue;
                $out[] = ['scope' => $scope, 'ref_id' => (int)$refId, 'stage' => $stage,
                    'status' => $s['status'], 'checked_by' => $s['checked_by'], 'checked_at' => $s['checked_at']];
            }
        }
    }
    return $out;
}

/**
 * Sel tanda check untuk satu item: dua tahap -
 *   - "Tanda Check PIC Region" (fase 1, isi oleh PIC Region / orang lapangan)
 *   - "Evaluasi CGS" (fase 2, isi oleh FOC -> NAM -> NOC)
 * Masing-masing punya keterangan sendiri (kontoh: CHECKBOX + KETERANGAN).
 *
 * @param array<int,array<string,array{status:string,note:?string,checked_by:?string,checked_at:?string}>> $all
 */
function signoff_cell(array $all, string $scope, int $refId, string $label, bool $canReview = true): string
{
    $reg = $all[$scope][$refId]['region'] ?? null;
    $cgs = $all[$scope][$refId]['cgs'] ?? null;
    $out = '<div class="d-flex flex-column gap-1 align-items-center">';

    // Fase 1 - PIC Region
    $tip = 'Tanda Check PIC Region';
    if ($reg && $reg['checked_by']) $tip .= ' - dicek oleh ' . $reg['checked_by'];
    $out .= '<div class="d-flex align-items-center gap-1" title="' . e($tip) . '">'
        . '<span class="text-muted" style="font-size:.65rem">PIC Region</span>'
        . signoff_badge($reg['status'] ?? null, $reg['note'] ?? null);
    if ($canReview) {
        $out .= ' <button class="btn btn-sm btn-outline-secondary py-0 px-1" title="Tanda check PIC Region"'
            . ' data-bs-toggle="modal" data-bs-target="#signoffModal" data-stage="region"'
            . ' data-scope="' . e($scope) . '" data-ref="' . $refId . '" data-label="' . e($label) . '"'
            . ' data-status="' . e($reg['status'] ?? 'pending') . '" data-note="' . e($reg['note'] ?? '') . '">'
            . '<i class="bi bi-pencil-square"></i></button>';
    }
    $out .= '</div>';

    // Fase 2 - Evaluasi internal CGS
    $tip2 = 'Evaluasi internal CGS (FOC/NAM/NOC)';
    if ($cgs && $cgs['checked_by']) $tip2 .= ' - dievaluasi oleh ' . $cgs['checked_by'];
    $out .= '<div class="d-flex align-items-center gap-1" title="' . e($tip2) . '">'
        . '<span class="text-muted" style="font-size:.65rem">Eval CGS</span>'
        . signoff_badge($cgs['status'] ?? null, $cgs['note'] ?? null);
    if ($canReview) {
        $out .= ' <button class="btn btn-sm btn-outline-primary py-0 px-1" title="Evaluasi CGS"'
            . ' data-bs-toggle="modal" data-bs-target="#signoffModal" data-stage="cgs"'
            . ' data-scope="' . e($scope) . '" data-ref="' . $refId . '" data-label="' . e($label) . '"'
            . ' data-status="' . e($cgs['status'] ?? 'pending') . '" data-note="' . e($cgs['note'] ?? '') . '">'
            . '<i class="bi bi-pencil-square"></i></button>';
    }
    $out .= '</div>';

    // Nama yang sudah mengecekreview
    foreach ([['PIC Region', $reg], ['Evaluasi CGS', $cgs]] as [$who, $s]) {
        if ($s && ($s['status'] ?? 'pending') !== 'pending' && !empty($s['checked_by'])) {
            $out .= '<div class="text-muted" style="font-size:.62rem">Sudah dicek oleh: ' . e($s['checked_by'])
                . ($s['checked_at'] ? ' (' . e(fmt_dt($s['checked_at'])) . ')' : '') . '</div>';
        }
    }
    $out .= '</div>';
    return $out;
}

/** Badge tanda check. */
function signoff_badge(?string $status, ?string $note = null): string
{
    $status = $status ?? 'pending';
    $map = [
        'ok'     => ['bg-success', 'bi-check2-circle', 'Tanda check OK'],
        'marked' => ['bg-danger', 'bi-flag', 'Tandai perlu perhatian'],
        'pending'=> ['bg-secondary', 'bi-hourglass', 'Belum dicek'],
    ];
    [$cls, $icon, $title] = $map[$status] ?? $map['pending'];
    $tip = $note ? $title . ' - ' . $note : $title;
    return '<span class="badge ' . $cls . '" title="' . e($tip) . '"><i class="bi ' . $icon . '"></i></span>';
}

// ------------------------------------------------------------
// SLA (badge live) - tidak ada lagi penandaan manual RPA/SLA.
// Tiket yang melebihi SLA hanya diberi tanda lewat badge SLA (Lewat SLA / Sesuai SLA).
// ------------------------------------------------------------


// ------------------------------------------------------------
// BASTO - catatan penggunaan material otomatis dari form vendor
// ------------------------------------------------------------
/** Nomor BASTO unik, format BASTO-YYYYMMDD-XXXX */
function generate_basto_no(): string
{
    $prefix = 'BASTO-' . date('Ymd') . '-';
    $last = q('SELECT no_basto FROM basto WHERE no_basto LIKE ? ORDER BY id DESC LIMIT 1', [$prefix . '%'])->fetchColumn();
    $n = $last ? ((int)substr($last, -4)) + 1 : 1;
    return $prefix . str_pad((string)$n, 4, '0', STR_PAD_LEFT);
}

/**
 * Susun payload BASTO dari form vendor yang SUDAH terisi (tidak langsung generate DB).
 * @return array<string,mixed>
 */
function basto_payload(array $t, ?array $report, array $materials, array $photos): array
{
    return [
        'no_basto'     => null, // diisi saat disimpan
        'ticket_no'    => $t['ticket_no'],
        'spk_no'       => $t['spk_no'] ?? null,
        'noc_ref'      => $t['noc_ref'] ?? null,
        'activity_type'=> $t['activity_type'] ?? null,
        'problem_type' => $t['problem_type'] ?? null,
        'city'         => $t['city_name'] ?? null,
        'province'     => $t['province'] ?? null,
        'location'     => $t['location'] ?? null,
        'customer'     => $t['customer_name'] ?? null,
        'vendor'       => $t['vendor_name'] ?? null,
        'vendor_type'  => $t['vendor_type'] ?? 'non_ms',
        'arrived_at'   => $report['arrived_at'] ?? null,
        'finished_at'  => $report['finished_at'] ?? null,
        'damage_type'  => $report['damage_type'] ?? null,
        'repair_action'=> $report['repair_action'] ?? null,
        'root_cause'   => $report['root_cause'] ?? null,
        'action_taken' => $report['action_taken'] ?? null,
        'materials'    => array_map(fn($m) => [
            'code'    => $m['code'] ?? null,
            'name'    => $m['name'] ?? null,
            'unit'    => $m['unit'] ?? null,
            'qty'     => $m['qty_used'] ?? null,
            'note'    => $m['note'] ?? null,
        ], $materials),
        'photos'       => array_map(fn($p) => [
            'category' => $p['category'],
            'path'     => $p['file_path'],
        ], $photos),
        'generated_at' => now(),
    ];
}

/**
 * Generate BASTO untuk sebuah tiket (idempotent - kalau sudah ada, tidak diduplikasi).
 * @return string nomor BASTO
 */
function generate_basto(int $ticketId, ?int $userId = null): ?string
{
    $existing = q('SELECT no_basto FROM basto WHERE ticket_id=?', [$ticketId])->fetch();
    if ($existing) return (string)$existing['no_basto'];

    $t = q('SELECT t.*, c.name city_name, v.name vendor_name, v.vendor_type
            FROM tickets t JOIN cities c ON c.id=t.city_id LEFT JOIN vendors v ON v.id=t.vendor_id
            WHERE t.id=?', [$ticketId])->fetch();
    if (!$t) return null;

    $report = q('SELECT * FROM ticket_reports WHERE ticket_id=?', [$ticketId])->fetch() ?: null;
    $materials = q('SELECT tm.*, m.code, m.name, m.unit FROM ticket_materials tm
                    JOIN materials m ON m.id=tm.material_id
                    WHERE tm.ticket_id=? AND tm.qty_used IS NOT NULL AND tm.qty_used > 0
                    ORDER BY tm.id', [$ticketId])->fetchAll();
    $photos = q('SELECT * FROM ticket_photos WHERE ticket_id=? ORDER BY id', [$ticketId])->fetchAll();

    $payload = basto_payload($t, $report, $materials, $photos);
    $no = generate_basto_no();
    $payload['no_basto'] = $no;
    q('INSERT INTO basto (ticket_id, no_basto, payload, generated_by) VALUES (?,?,?,?)',
        [$ticketId, $no, json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), $userId]);
    log_ticket($ticketId, 'basto_generated', 'BASTO ' . $no . ' otomatis terbentuk dari form vendor yang sudah diisi.');
    return $no;
}

/** Ambil BASTO sebuah tiket (null kalau belum ada) */
function get_basto(int $ticketId): ?array
{
    $row = q('SELECT * FROM basto WHERE ticket_id=?', [$ticketId])->fetch();
    if (!$row) return null;
    $row['data'] = json_decode($row['payload'], true) ?: [];
    return $row;
}
