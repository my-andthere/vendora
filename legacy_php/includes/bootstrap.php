<?php
require_once __DIR__ . '/../config.php';

if (session_status() === PHP_SESSION_NONE && PHP_SAPI !== 'cli') {
    session_set_cookie_params(['httponly' => true, 'samesite' => 'Lax']);
    session_start();
}

// ------------------------------------------------------------
// Database
// ------------------------------------------------------------
function db(): PDO
{
    static $pdo = null;
    if ($pdo === null) {
        $pdo = new PDO(
            'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4',
            DB_USER,
            DB_PASS,
            [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ]
        );
        $pdo->exec("SET time_zone = '" . date('P') . "'");
    }
    return $pdo;
}

function q(string $sql, array $params = []): PDOStatement
{
    $st = db()->prepare($sql);
    $st->execute($params);
    return $st;
}

function now(): string
{
    return date('Y-m-d H:i:s');
}

// ------------------------------------------------------------
// Output & navigasi
// ------------------------------------------------------------
function e($s): string
{
    return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
}

function url(string $path = ''): string
{
    return BASE_URL . '/' . ltrim($path, '/');
}

function redirect(string $path): void
{
    header('Location: ' . url($path));
    exit;
}

function flash(string $type, string $msg): void
{
    $_SESSION['flash'][] = [$type, $msg];
}

function take_flashes(): array
{
    $f = $_SESSION['flash'] ?? [];
    unset($_SESSION['flash']);
    return $f;
}

function fmt_dt(?string $dt): string
{
    return $dt ? date('d/m/Y H:i', strtotime($dt)) : '-';
}

function fmt_duration(int $minutes): string
{
    $neg = $minutes < 0;
    $m = abs($minutes);
    $s = ($m >= 60 ? intdiv($m, 60) . ' jam ' : '') . ($m % 60) . ' menit';
    return $neg ? '-' . $s : $s;
}

function fmt_qty($n): string
{
    if ($n === null) return '-';
    return rtrim(rtrim(number_format((float)$n, 2, ',', '.'), '0'), ',');
}

// ------------------------------------------------------------
// CSRF
// ------------------------------------------------------------
function csrf_token(): string
{
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf'];
}

function csrf_field(): string
{
    return '<input type="hidden" name="csrf" value="' . csrf_token() . '">';
}

function csrf_check(): void
{
    if (!hash_equals($_SESSION['csrf'] ?? '', $_POST['csrf'] ?? '')) {
        http_response_code(403);
        exit('Sesi kedaluwarsa / token tidak valid. Silakan muat ulang halaman.');
    }
}

// ------------------------------------------------------------
// Auth
// ------------------------------------------------------------
const ROLES = [
    'admin' => 'Administrator',
    'noc' => 'NOC',
    'nam' => 'NAM',
    'procurement' => 'Procurement',
    'vendor' => 'Vendor',
    'inventory' => 'Inventory',
    'foc' => 'FOC',
];

/** Role yang boleh melakukan review internal CGS (FOC -> NAM -> NOC). */
const CGS_REVIEW_ROLES = ['foc', 'nam', 'noc', 'admin', 'procurement'];

function current_user(): ?array
{
    static $user = false;
    if ($user === false) {
        $user = null;
        if (!empty($_SESSION['uid'])) {
            $user = q(
                'SELECT u.*, v.name AS vendor_name, v.city_id AS vendor_city_id, v.status AS vendor_status
                 FROM users u LEFT JOIN vendors v ON v.id = u.vendor_id
                 WHERE u.id = ? AND u.is_active = 1',
                [$_SESSION['uid']]
            )->fetch() ?: null;
        }
    }
    return $user;
}

function require_login(): array
{
    $u = current_user();
    if (!$u) {
        redirect('login.php');
    }
    return $u;
}

function require_role(string ...$roles): array
{
    $u = require_login();
    if (!in_array($u['role'], $roles, true)) {
        http_response_code(403);
        exit('Akses ditolak untuk role Anda.');
    }
    return $u;
}

function has_role(string ...$roles): bool
{
    $u = current_user();
    return $u && in_array($u['role'], $roles, true);
}

// ------------------------------------------------------------
// Label status
// ------------------------------------------------------------
const TICKET_STATUS = [
    'open'            => ['Menunggu Vendor', 'secondary'],
    'assigned'        => ['Ditugaskan', 'info'],
    'on_progress'     => ['Dikerjakan', 'primary'],
    'inventory_check' => ['Cek Inventory', 'warning'],
    'foc_review'      => ['Review FOC', 'warning'],
    'revision'        => ['Revisi (Temporary)', 'danger'],
    'closed'          => ['Selesai Permanent', 'success'],
    'cancelled'       => ['Dibatalkan', 'dark'],
];

const VENDOR_STATUS = [
    'available' => ['Available', 'success', 'Siap menerima pekerjaan'],
    'freeze'    => ['Freeze', 'warning', 'Tidak bisa menerima pekerjaan dalam jangka waktu tertentu'],
    'blacklist' => ['Blacklist', 'danger', 'Tidak bisa menerima pekerjaan selamanya'],
];

const PRIORITIES = ['low' => 'Low', 'medium' => 'Medium', 'high' => 'High', 'critical' => 'Critical'];

// Status tiket yang masih dihitung sebagai beban kerja (pekerjaan) vendor.
//_pts: pekerjaan yang MASIH DI TANGAN vendor (belum dikirim / perlu dikerjakan / perlu diperbaiki).
// Slot limit workload baru bebas ketika pekerjaan ini selesai (tiket closed/cancelled).
const WORKLOAD_STATUSES = ['assigned', 'on_progress', 'revision'];

// Semua tiket yang masih berjalan di sistem (dipakai untuk kolom "Aktif" &_choice auto-assign).
const ACTIVE_TICKET_STATUSES = ['assigned', 'on_progress', 'inventory_check', 'foc_review', 'revision'];
// Kategori foto pekerjaan pada laporan vendor.
// Key tetap (before/progress/after/material) supaya data foto yang sudah ada tetap valid;
// label yang dipakai di tampilan.
const PHOTO_CATEGORIES = [
    'before'   => 'Foto Tiang',
    'progress' => 'Foto Sinyal',
    'after'    => 'Foto OTDR',
    'material' => 'Foto Data Core',
];
// Foto yang WAJIB diunggah minimal 1 (key PHOTO_CATEGORIES).
const PHOTO_REQUIRED = ['before', 'after'];

function status_badge(string $status): string
{
    [$label, $cls] = TICKET_STATUS[$status] ?? [$status, 'secondary'];
    $text = in_array($cls, ['warning', 'info'], true) ? ' text-dark' : '';
    return '<span class="badge bg-' . $cls . $text . '">' . e($label) . '</span>';
}

function vendor_badge(string $status): string
{
    [$label, $cls] = VENDOR_STATUS[$status] ?? [$status, 'secondary'];
    $text = $cls === 'warning' ? ' text-dark' : '';
    return '<span class="badge bg-' . $cls . $text . '">' . e($label) . '</span>';
}

function priority_badge(string $p): string
{
    $map = ['low' => 'secondary', 'medium' => 'info text-dark', 'high' => 'warning text-dark', 'critical' => 'danger'];
    return '<span class="badge bg-' . ($map[$p] ?? 'secondary') . '">' . e(PRIORITIES[$p] ?? $p) . '</span>';
}

// ------------------------------------------------------------
// SLA - diukur sampai pekerjaan dinyatakan PERMANENT
// ------------------------------------------------------------
// Definisi (per 2026-11-19): SLA tidak lagi berhenti saat laporan pertama.
// Batas SLA (sla_deadline) tetap dihitung dari penugasan/acceptance,
// tetapi VERDICT hanya sah setelah pekerjaan PERMANENT:
//   - tiket closed permanent : work_finished_at <= sla_deadline -> ontime, else late
//   - tiket masih berjalan & lewat deadline : late (pasti late, belum ada verdict final)
//   - tiket masih berjalan & masih dalam deadline : belum ada verdict (NULL)
//   - tiket dibatalkan / tanpa deadline : tidak dinilai (NULL)
// Badge menampilkan hitungan LIVE (sisa waktu / keterlambatan) untuk tiket berjalan.

/**
 * Status SLA real-time untuk satu tiket.
 * @return array{verdict:?string,final:bool,overdue:bool,remaining:?int,deadline:?string}
 *   verdict   : 'ontime' | 'late' | null (null = belum ada verdict / tidak dinilai)
 *   final     : true bila verdict sudah final (tiket PERMANENT)
 *   overdue   : true bila sudah lewat batas SLA
 *   remaining : detik tersisa (negatif = keterlambatan), null bila tidak ada deadline
 */
function sla_state(array $t): array
{
    $deadline = $t['sla_deadline'] ?? null;
    $status = $t['status'] ?? '';
    $finished = $t['work_finished_at'] ?? null;

    $out = ['verdict' => null, 'final' => false, 'overdue' => false, 'remaining' => null, 'deadline' => $deadline];
    if (!$deadline || $status === 'cancelled') return $out;

    $dl = strtotime($deadline);
    if ($status === 'closed') {
        $fin = $finished ?: ($t['completed_at'] ?: ($t['updated_at'] ?: null));
        if ($fin) {
            $out['final'] = true;
            $out['remaining'] = $dl - strtotime($fin);
            $out['overdue'] = $out['remaining'] < 0;
            $out['verdict'] = $out['overdue'] ? 'late' : 'ontime';
            return $out;
        }
    }

    // Masih berjalan: live.
    $out['remaining'] = $dl - time();
    if ($out['remaining'] < 0) {
        $out['overdue'] = true;
        $out['verdict'] = 'late'; // pasti late, verdict final menunggu PERMANENT
    }
    return $out;
}

/**
 * Ekspresi SQL CASE untuk status SLA (harus identik dengan sla_state()).
 * Dipakai agar query agregat/filter selalu sees the live value tanpa
 * bergantung pada kolom sla_status yang di-cache.
 */
function sla_case_sql(string $alias = ''): string
{
    $p = $alias === '' ? '' : $alias . '.';
    return "CASE"
        . " WHEN {$p}sla_deadline IS NULL THEN NULL"
        . " WHEN {$p}status = 'cancelled' THEN NULL"
        . " WHEN {$p}status = 'closed' THEN"
        . "   IF(COALESCE({$p}work_finished_at, {$p}completed_at, {$p}updated_at) <= {$p}sla_deadline, 'ontime', 'late')"
        . " WHEN NOW() > {$p}sla_deadline THEN 'late'"
        . " ELSE NULL END";
}

/**
 * Sinkronkan kolom tickets.sla_status dengan definisi SLA baru.
 * Dipanggil periodik (throttle 60 detik) - kolom ini hanya cache; tampilan &
 * agregat memakai sla_case_sql()/sla_state() yang selalu live.
 */
function sla_resync(): void
{
    // Throttle:simpan penanda waktu di tabel app_meta (aman multi-server).
    $last = 0;
    try {
        $last = (int)q("SELECT v FROM app_meta WHERE k='sla_last_sync'")->fetchColumn();
    } catch (PDOException $e) {
        return; // tabel belum ada (mis. saat instalasi)
    }
    if (time() - $last < 60) return;

    $case = sla_case_sql();
    // updated_at = updated_at => kolom tidak berubah (ON UPDATE CURRENT_TIMESTAMP diabaikan).
    q("UPDATE tickets SET sla_status = ($case), updated_at = updated_at
        WHERE sla_deadline IS NOT NULL AND NOT (sla_status <=> ($case))");
    q("INSERT INTO app_meta (k, v) VALUES ('sla_last_sync', ?)
       ON DUPLICATE KEY UPDATE v = VALUES(v)", [(string)time()]);
}

/** Hitung ulang sla_status untuk satu tiket (dipakai saat status tiket berubah). */
function sla_sync_ticket(int $ticketId): void
{
    q("UPDATE tickets SET sla_status = (" . sla_case_sql() . ") WHERE id=?", [$ticketId]);
}

/**
 * Badge SLA live.
 * - tiket PERMANENT : hijau "Sesuai SLA" / merah "Lewat SLA" (verdict final)
 * - tiket berjalan  : hitung mundur sisa waktu, otomatis merah + "Lewat SLA" bila terlambat
 */
function sla_badge(array $t): string
{
    $s = sla_state($t);
    if (!$s['deadline'] || ($t['status'] ?? '') === 'cancelled') {
        return '<span class="text-muted small">-</span>';
    }
    $iso = e(date('c', strtotime($s['deadline'])));

    if ($s['final']) {
        $selisih = fmt_dur_seconds(abs($s['remaining']));
        $tip = 'Selesai (PERMANENT) ' . fmt_dt($t['work_finished_at']) . ' - batas SLA ' . fmt_dt($s['deadline'])
            . ' (' . ($s['overdue'] ? 'terlambat ' : 'selisih ') . $selisih . ')';
        return $s['overdue']
            ? '<span class="badge bg-danger" title="' . e($tip) . '">Lewat SLA</span>'
            : '<span class="badge bg-success" title="' . e($tip) . '">Sesuai SLA</span>';
    }

    // Masih berjalan: badge live (JS updating), merah otomatis saat lewat deadline.
    if ($s['overdue']) {
        return '<span class="badge bg-danger sla-countdown" data-deadline="' . $iso . '"'
            . ' title="Batas SLA ' . e(fmt_dt($s['deadline'])) . ' - sudah lewat, pekerjaan belum PERMANENT">'
            . 'Lewat SLA &middot; ' . e(fmt_dur_seconds(-$s['remaining'])) . '</span>';
    }
    return '<span class="badge bg-light text-dark border sla-countdown" data-deadline="' . $iso . '"'
        . ' title="Batas SLA ' . e(fmt_dt($s['deadline'])) . ' - masih berjalan, belum PERMANENT">'
        . 'Sisa ' . e(fmt_dur_seconds($s['remaining'])) . '</span>';
}

/** Penjelasan singkat status SLA untuk teks (mis. di halaman detail tiket). */
function sla_note(array $t): string
{
    $s = sla_state($t);
    if (!$s['deadline']) return 'Belum ada batas SLA (tiket belum ditugaskan).';
    if (($t['status'] ?? '') === 'cancelled') return 'Tiket dibatalkan - SLA tidak dinilai.';
    if ($s['final']) {
        return $s['overdue']
            ? 'Selesai PERMANENT ' . fmt_dt($t['work_finished_at']) . ', terlambat ' . fmt_dur_seconds(-$s['remaining']) . ' dari batas SLA.'
            : 'Selesai PERMANENT ' . fmt_dt($t['work_finished_at']) . ', sesuai batas SLA.';
    }
    return $s['overdue']
        ? 'Masih berjalan - sudah lewat batas SLA ' . fmt_dt($s['deadline']) . ' (' . fmt_dur_seconds(-$s['remaining']) . ' keterlambatan).'
        : 'Masih berjalan - sisa ' . fmt_dur_seconds($s['remaining']) . ' sampai batas SLA ' . fmt_dt($s['deadline']) . '.';
}

// ------------------------------------------------------------
// Waktu pengerjaan tiket
// Sama dengan SLA (keduanya diukur sampai PERMANENT), waktu pengerjaan
// BERJALAN terus sampai tiket dinyatakan PERMANENT (tidak berhenti saat
// cek inventory, review FOC, atau revisi/temporary).
// ------------------------------------------------------------

/** Format durasi dari detik: 2j 05m / 05m 30d / 30d */
function fmt_dur_seconds(int $sec): string
{
    $sec = max(0, $sec);
    $h = intdiv($sec, 3600);
    $m = intdiv($sec % 3600, 60);
    $s = $sec % 60;
    if ($h > 0) return $h . 'j ' . str_pad((string)$m, 2, '0', STR_PAD_LEFT) . 'm';
    if ($m > 0) return $m . 'm ' . str_pad((string)$s, 2, '0', STR_PAD_LEFT) . 'd';
    return $s . 'd';
}

/**
 * Hitung waktu pengerjaan sebuah tiket.
 * @return array{start:?string,end:?string,seconds:int,running:bool,started:bool,permanent:bool}
 */
function work_time(array $t): array
{
    $start = $t['work_started_at'] ?? null;
    if (!$start) $start = $t['assigned_at'] ?? null; // fallback untuk data lama
    $end = $t['work_finished_at'] ?? null;
    $status = $t['status'] ?? '';

    if (!$start) {
        return ['start' => null, 'end' => null, 'seconds' => 0, 'running' => false, 'started' => false, 'permanent' => false];
    }
    $running = false;
    if (!$end) {
        if (in_array($status, ['closed', 'cancelled'], true)) {
            // tiket berhenti tanpa cap waktu pengerjaan (data lama) -> pakai waktu selesai
            $end = $t['completed_at'] ?: ($t['updated_at'] ?: null);
        } else {
            $running = true;
            $end = date('Y-m-d H:i:s');
        }
    }
    $seconds = 0;
    if ($end) $seconds = max(0, strtotime($end) - strtotime($start));

    return [
        'start'     => $start,
        'end'       => $t['work_finished_at'] ?? null,
        'seconds'   => $seconds,
        'running'   => $running,
        'started'   => true,
        'permanent' => ($status === 'closed' && ($t['repair_result'] ?? '') === 'permanent'),
    ];
}

/** Badge waktu pengerjaan (live counter bila masih berjalan) */
function work_time_badge(array $t): string
{
    $w = work_time($t);
    if (!$w['started']) return '<span class="text-muted small">-</span>';
    $txt = fmt_dur_seconds($w['seconds']);
    if ($w['permanent']) {
        return '<span class="badge bg-success" title="Waktu pengerjaan (berhenti saat PERMANENT)">&#9202; ' . e($txt) . '</span>';
    }
    if (!$w['running']) {
        $cls = ($t['status'] ?? '') === 'cancelled' ? 'bg-secondary' : 'bg-light text-dark border';
        return '<span class="badge ' . $cls . '" title="Waktu pengerjaan (berhenti)">&#9202; ' . e($txt) . '</span>';
    }
    $dataStart = e(date('c', strtotime($w['start'])));
    $dataEnd = $w['end'] ? ' data-end="' . e(date('c', strtotime($w['end']))) . '"' : '';
    return '<span class="badge bg-primary work-timer" data-start="' . $dataStart . '"' . $dataEnd
        . ' title="Waktu pengerjaan berjalan, berhenti saat PERMANENT">&#9202; ' . e($txt) . '</span>';
}

// ------------------------------------------------------------
// Waktu vendor menekan tombol "Terima & Mulai Kerjakan"
// ------------------------------------------------------------

/**
 * Informasi kapan vendor menerima (menekan tombol "Terima & Mulai Kerjakan").
 * @return array{accepted:?string,assigned:?string,delay:?int,accepted_label:string,delay_label:string}
 */
function accept_info(array $t): array
{
    $assigned = $t['assigned_at'] ?? null;
    $accepted = $t['accepted_at'] ?? null;
    $delay = ($assigned && $accepted) ? max(0, strtotime($accepted) - strtotime($assigned)) : null;

    if ($accepted) {
        $acceptedLabel = fmt_dt($accepted);
        $delayLabel = $delay !== null ? fmt_dur_seconds($delay) : '-';
    } else {
        $acceptedLabel = 'Belum diterima';
        $delayLabel = $assigned ? 'Menunggu vendor menerima' : '-';
    }
    return [
        'accepted'      => $accepted,
        'assigned'      => $assigned,
        'delay'         => $delay,
        'accepted_label' => $acceptedLabel,
        'delay_label'   => $delayLabel,
    ];
}

/** Badge waktu vendor menerima tiket */
function accept_badge(array $t): string
{
    $a = accept_info($t);
    if (!$a['accepted']) {
        return '<span class="badge bg-secondary-subtle text-secondary border" title="Vendor belum menekan tombol Terima &amp; Mulai Kerjakan">Belum diterima</span>';
    }
    $cls = 'bg-success';
    $icon = 'bi-check2-circle';
    return '<span class="badge ' . $cls . '" title="Vendor menekan tombol Terima &amp; Mulai Kerjakan pada ' . e($a['accepted_label'])
        . '"><i class="bi ' . $icon . '"></i> Diterima ' . e($a['accepted_label']) . '</span>';
}

// ------------------------------------------------------------
// Vendor status: freeze yang sudah lewat masa berlakunya otomatis kembali available
// ------------------------------------------------------------
function refresh_vendor_status(): void
{
    $rows = q("SELECT id FROM vendors WHERE status='freeze' AND freeze_until IS NOT NULL AND freeze_until <= NOW()")->fetchAll();
    foreach ($rows as $r) {
        q("UPDATE vendors SET status='available', freeze_until=NULL, status_note='Masa freeze berakhir otomatis', status_changed_at=NOW() WHERE id=?", [$r['id']]);
    }
}

// ------------------------------------------------------------
// Log, notifikasi internal, notifikasi ke NOC
// ------------------------------------------------------------
function log_ticket(int $ticketId, string $action, string $note = '', ?int $userId = null): void
{
    $userId = $userId ?? (current_user()['id'] ?? null);
    q('INSERT INTO ticket_logs (ticket_id, user_id, action, note) VALUES (?,?,?,?)', [$ticketId, $userId, $action, $note]);
}

function notify_users(array $userIds, string $msg, ?int $ticketId = null): void
{
    foreach (array_unique($userIds) as $uid) {
        q('INSERT INTO notifications (user_id, ticket_id, message) VALUES (?,?,?)', [$uid, $ticketId, $msg]);
    }
}

function notify_role(string $role, string $msg, ?int $ticketId = null): void
{
    $ids = q('SELECT id FROM users WHERE role=? AND is_active=1', [$role])->fetchAll(PDO::FETCH_COLUMN);
    notify_users($ids, $msg, $ticketId);
}

function notify_vendor(int $vendorId, string $msg, ?int $ticketId = null): void
{
    $ids = q('SELECT id FROM users WHERE vendor_id=? AND is_active=1', [$vendorId])->fetchAll(PDO::FETCH_COLUMN);
    notify_users($ids, $msg, $ticketId);
}

/**
 * Kirim event ke sistem NOC. Selalu dicatat di noc_outbox; jika NOC_WEBHOOK_URL terisi, langsung di-POST (JSON).
 * Event: ticket_received, vendor_assigned, no_vendor_available, vendor_accepted, work_submitted,
 *        temporary_fix, permanent_closed, cancelled
 */
function noc_notify(int $ticketId, string $event, array $extra = []): void
{
    $t = q('SELECT t.*, c.name AS city, v.name AS vendor FROM tickets t JOIN cities c ON c.id=t.city_id
            LEFT JOIN vendors v ON v.id=t.vendor_id WHERE t.id=?', [$ticketId])->fetch();
    if (!$t) return;
    $payload = array_merge([
        'event' => $event,
        'ticket_no' => $t['ticket_no'],
        'spk_no' => $t['spk_no'] ?? null,
        'noc_ref' => $t['noc_ref'],
        'status' => $t['status'],
        'city' => $t['city'],
        'vendor' => $t['vendor'],
        'sla_deadline' => $t['sla_deadline'],
        'sla_status' => sla_state($t)['verdict'],
        'repair_result' => $t['repair_result'],
        'timestamp' => date('c'),
    ], $extra);
    $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    q('INSERT INTO noc_outbox (ticket_id, event, payload) VALUES (?,?,?)', [$ticketId, $event, $json]);
    $id = (int)db()->lastInsertId();
    if (NOC_WEBHOOK_URL !== '') {
        noc_send($id);
    }
}

function noc_send(int $outboxId): bool
{
    $row = q('SELECT * FROM noc_outbox WHERE id=?', [$outboxId])->fetch();
    if (!$row || NOC_WEBHOOK_URL === '' || !function_exists('curl_init')) return false;
    $ch = curl_init(NOC_WEBHOOK_URL);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $row['payload'],
        CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'X-API-KEY: ' . NOC_API_KEY],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 5,
    ]);
    $resp = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);
    $ok = $resp !== false && $code >= 200 && $code < 300;
    q('UPDATE noc_outbox SET send_status=?, response=?, attempts=attempts+1, sent_at=? WHERE id=?', [
        $ok ? 'sent' : 'failed',
        $ok ? substr((string)$resp, 0, 2000) : ('HTTP ' . $code . ' ' . $err . ' ' . substr((string)$resp, 0, 1000)),
        $ok ? now() : null,
        $outboxId,
    ]);
    return $ok;
}

// ------------------------------------------------------------
// Tiket: pembuatan & penugasan vendor
// ------------------------------------------------------------
function generate_ticket_no(): string
{
    $prefix = 'TKT-' . date('Ymd') . '-';
    $last = q('SELECT ticket_no FROM tickets WHERE ticket_no LIKE ? ORDER BY id DESC LIMIT 1', [$prefix . '%'])->fetchColumn();
    $n = $last ? ((int)substr($last, -4)) + 1 : 1;
    return $prefix . str_pad((string)$n, 4, '0', STR_PAD_LEFT);
}

/** Nomor SPK (Surat Perintah Kerja) - unik, format SPK-YYYYMMDD-XXXX */
function generate_spk_no(): string
{
    $prefix = 'SPK-' . date('Ymd') . '-';
    $last = q('SELECT spk_no FROM tickets WHERE spk_no LIKE ? ORDER BY id DESC LIMIT 1', [$prefix . '%'])->fetchColumn();
    $n = $last ? ((int)substr($last, -4)) + 1 : 1;
    return $prefix . str_pad((string)$n, 4, '0', STR_PAD_LEFT);
}

/**
 * Membuat tiket baru (dari form NOC atau API) lalu otomatis menunjuk vendor di kota yang sama.
 * @return array ['id'=>int, 'ticket_no'=>string, 'vendor_id'=>?int]
 */
function create_ticket(array $d, string $source, ?int $userId): array
{
    $ticketNo = generate_ticket_no();
    $spkNo = generate_spk_no();
    q(
        'INSERT INTO tickets (ticket_no, spk_no, noc_ref, source, city_id, customer_name, customer_id, location, latitude, longitude,
            province, activity_type, problem_type, description, priority, reported_at, sla_minutes, status, created_by)
         VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)',
        [
            $ticketNo, $spkNo, $d['noc_ref'] ?: null, $source, $d['city_id'], $d['customer_name'] ?? null, $d['customer_id'] ?? null,
            $d['location'] ?? null, ($d['latitude'] ?? '') !== '' ? $d['latitude'] : null, ($d['longitude'] ?? '') !== '' ? $d['longitude'] : null,
            $d['province'] ?? null, $d['activity_type'] ?? null,
            $d['problem_type'] ?? null, $d['description'] ?? null, $d['priority'] ?? 'medium',
            $d['reported_at'] ?: now(), SLA_MINUTES, 'open', $userId,
        ]
    );
    $id = (int)db()->lastInsertId();
    log_ticket($id, 'created', 'Tiket dibuat dari ' . ($source === 'api' ? 'sistem NOC (API)' : 'form NOC'), $userId);
    noc_notify($id, 'ticket_received');
    $vendorId = auto_assign($id, $userId);
    return ['id' => $id, 'ticket_no' => $ticketNo, 'vendor_id' => $vendorId];
}

// ------------------------------------------------------------
// Region (wilayah)
// ------------------------------------------------------------

/** Region id dari sebuah kota (null jika kota belum masuk region mana pun). */
function city_region_id(int $cityId): ?int
{
    static $cache = null;
    if ($cache === null) {
        $cache = [];
        foreach (q('SELECT id, region_id FROM cities')->fetchAll() as $r) {
            $cache[(int)$r['id']] = $r['region_id'] === null ? null : (int)$r['region_id'];
        }
    }
    return $cache[$cityId] ?? null;
}

/** Nama region (aman dipanggil dengan id null). */
function region_name(?int $regionId): string
{
    if (!$regionId) return '-';
    static $names = null;
    if ($names === null) {
        $names = [];
        foreach (q('SELECT id, name FROM regions')->fetchAll() as $r) $names[(int)$r['id']] = $r['name'];
    }
    return $names[$regionId] ?? '-';
}

/** Batas workload vendor per region: [region_id => limit]. 0 = tanpa batas. */
function vendor_region_limits(int $vendorId): array
{
    $out = [];
    foreach (q('SELECT region_id, workload_limit FROM vendor_regions WHERE vendor_id=?', [$vendorId])->fetchAll() as $r) {
        $out[(int)$r['region_id']] = (int)$r['workload_limit'];
    }
    return $out;
}

/**
 * Beban kerja vendor: jumlah pekerjaan aktif vs batas limit workload.
 * Batas diambil dari pasangan vendor-region bila region diketahui (tiap region punya batas sendiri),
 * jika tidak ada pasangan region, dipakai batas global vendor sebagai cadangan.
 *
 * $excludeTicketId dipakai saat menghitung untuk penugasan ulang, agar tiket itu sendiri
 * tidak ikut terhitung (vendor yang sudah memegang tiket boleh menerima penugasan ulang).
 *
 * @return array{active:int,limit:int,unlimited:bool,free:int,full:bool,region:?int}
 */
function vendor_workload(int $vendorId, ?int $regionId = null, ?int $excludeTicketId = null): array
{
    $globalLimit = (int)q('SELECT workload_limit FROM vendors WHERE id=?', [$vendorId])->fetchColumn();
    $ph = implode(',', array_fill(0, count(WORKLOAD_STATUSES), '?'));
    if ($regionId !== null) {
        $limits = vendor_region_limits($vendorId);
        $limit = array_key_exists($regionId, $limits) ? $limits[$regionId] : $globalLimit;
        $sql = "SELECT COUNT(*) FROM tickets t JOIN cities c ON c.id=t.city_id
                WHERE t.vendor_id=? AND c.region_id=? AND t.status IN ($ph)";
        $params = [$vendorId, $regionId];
    } else {
        $limit = $globalLimit;
        $sql = "SELECT COUNT(*) FROM tickets t WHERE t.vendor_id=? AND t.status IN ($ph)";
        $params = [$vendorId];
    }
    foreach (WORKLOAD_STATUSES as $st) $params[] = $st;
    if ($excludeTicketId) {
        $sql .= ' AND t.id<>?';
        $params[] = $excludeTicketId;
    }
    $active = (int)q($sql, $params)->fetchColumn();
    $unlimited = $limit <= 0;
    $free = $unlimited ? PHP_INT_MAX : max(0, $limit - $active);
    return [
        'active'    => $active,
        'limit'     => $limit,
        'unlimited' => $unlimited,
        'free'      => $unlimited ? -1 : $free,
        'full'      => !$unlimited && $active >= $limit,
        'region'    => $regionId,
    ];
}

/** Apakah vendor masih boleh menerima pekerjaan baru di region tertentu? */
function vendor_can_take_job(int $vendorId, ?int $regionId = null, ?int $excludeTicketId = null): bool
{
    return !vendor_workload($vendorId, $regionId, $excludeTicketId)['full'];
}

/** Apakah vendor terdaftar melayani region tersebut? */
function vendor_serves_region(int $vendorId, ?int $regionId): bool
{
    if ($regionId === null) return false;
    $limits = vendor_region_limits($vendorId);
    return array_key_exists($regionId, $limits);
}

/** Daftar vendor available yang melayani region tiket, urut dari yang paling sedikit pekerjaan aktif.
 *  Vendor yang limit workload-nya sudah penuh TIDAK ikut sebagai kandidat.
 *  Bila kota tiket belum masuk region mana pun, dipakai aturan lama: vendor dengan kota sama. */
function candidate_vendors(int $ticketId, bool $excludeRejected = true): array
{
    refresh_vendor_status();
    $t = q('SELECT city_id FROM tickets WHERE id=?', [$ticketId])->fetch();
    $cityId = (int)$t['city_id'];
    $regionId = city_region_id($cityId);
    $statusList = "'" . implode("','", WORKLOAD_STATUSES) . "'";
    if ($regionId !== null) {
        $sql = "SELECT v.*, vr.workload_limit AS region_limit, vr.region_id,
                       (SELECT COUNT(*) FROM tickets x JOIN cities c ON c.id=x.city_id
                          WHERE x.vendor_id=v.id AND c.region_id=vr.region_id AND x.status IN ($statusList)) AS active_jobs,
                       (SELECT COUNT(*) FROM tickets x JOIN cities c ON c.id=x.city_id
                          WHERE x.vendor_id=v.id AND c.region_id=vr.region_id AND x.id<>? AND x.status IN ($statusList)) AS load_jobs
                FROM vendors v JOIN vendor_regions vr ON vr.vendor_id=v.id AND vr.region_id=?
                WHERE v.status='available'";
        $params = [$ticketId, $regionId];
    } else {
        $sql = "SELECT v.*, v.workload_limit AS region_limit, NULL AS region_id,
                       (SELECT COUNT(*) FROM tickets x WHERE x.vendor_id=v.id AND x.status IN ($statusList)) AS active_jobs,
                       (SELECT COUNT(*) FROM tickets x WHERE x.vendor_id=v.id AND x.id<>? AND x.status IN ($statusList)) AS load_jobs
                FROM vendors v WHERE v.city_id=? AND v.status='available'";
        $params = [$ticketId, $cityId];
    }
    if ($excludeRejected) {
        $sql .= ' AND v.id NOT IN (SELECT vendor_id FROM ticket_rejections WHERE ticket_id=' . (int)$ticketId . ')';
    }
    $sql .= ' ORDER BY load_jobs ASC, RAND()';
    $out = [];
    foreach (q($sql, $params)->fetchAll() as $r) {
        $limit = (int)$r['region_limit'];
        if ($limit > 0 && (int)$r['load_jobs'] >= $limit) continue; // limit penuh
        $r['free_slots'] = $limit > 0 ? $limit - (int)$r['load_jobs'] : -1;
        $r['limit'] = $limit;
        $out[] = $r;
    }
    return $out;
}

/**
 * Auto-assign dengan prioritas Vendor MS (flow kotak 3 diagram):
 * kalau kota tiket punya vendor MS available, vendor MS yang dipilih lebih dulu.
 */
function auto_assign(int $ticketId, ?int $userId = null): ?int
{
    // Kandidat urut: vendor MS dulu, lalu beban terkecil.
    $cands = ms_first_candidates($ticketId);
    if (!$cands) {
        q("UPDATE tickets SET vendor_id=NULL, assigned_at=NULL, sla_deadline=NULL, status='open' WHERE id=?", [$ticketId]);
        $t = q('SELECT t.ticket_no, c.name city, t.city_id FROM tickets t JOIN cities c ON c.id=t.city_id WHERE t.id=?', [$ticketId])->fetch();
        $regionId = city_region_id((int)$t['city_id']);
        $regionName = region_name($regionId);
        if ($regionId !== null) {
            $avail = (int)q("SELECT COUNT(*) FROM vendors v JOIN vendor_regions vr ON vr.vendor_id=v.id
                WHERE vr.region_id=? AND v.status='available'
                AND v.id NOT IN (SELECT vendor_id FROM ticket_rejections WHERE ticket_id=?)", [$regionId, $ticketId])->fetchColumn();
        } else {
            $avail = (int)q("SELECT COUNT(*) FROM vendors v WHERE v.city_id=? AND v.status='available'
                AND v.id NOT IN (SELECT vendor_id FROM ticket_rejections WHERE ticket_id=?)", [$t['city_id'], $ticketId])->fetchColumn();
        }
        $where = $regionId !== null ? 'di region ' . $regionName : 'di kota ' . $t['city'];
        $reason = $avail > 0
            ? 'semua vendor available ' . $where . ' sudah mencapai limit workload'
            : 'tidak ada vendor available ' . $where;
        log_ticket($ticketId, 'no_vendor', 'Tidak ada vendor yang bisa ditugaskan: ' . $reason, $userId);
        $msg = "Tiket {$t['ticket_no']}: {$reason}. Perlu penunjukan manual.";
        notify_role('noc', $msg, $ticketId);
        notify_role('admin', $msg, $ticketId);
        noc_notify($ticketId, 'no_vendor_available');
        return null;
    }
    assign_vendor($ticketId, (int)$cands[0]['id'], $userId, true);
    return (int)$cands[0]['id'];
}

/**
 * Menugaskan vendor ke tiket. Mengembalikan false bila vendor sudah mencapai limit workload
 * pada region tiket, atau vendor tidak terdaftar melayani region tersebut.
 *
 * Flow Vendor MS (kotak 5 diagram): vendor MS "LANGSUNG TERUS MENUJU" - begitu ditugaskan
 * tiket langsung on_progress (tidak perlu menunggu vendor menekan tombol Terima),
 * dan tidak perlu request material ke inventory lebih dulu.
 */
function assign_vendor(int $ticketId, int $vendorId, ?int $userId, bool $auto = false): bool
{
    $t = q('SELECT city_id FROM tickets WHERE id=?', [$ticketId])->fetch();
    $regionId = $t ? city_region_id((int)$t['city_id']) : null;
    if ($regionId !== null && !vendor_serves_region($vendorId, $regionId)) return false;
    if (!vendor_can_take_job($vendorId, $regionId, $ticketId)) return false;
    $deadline = SLA_START === 'assigned' ? date('Y-m-d H:i:s', time() + SLA_MINUTES * 60) : null;
    $isMs = vendor_type_of($vendorId) === 'ms';

    // Vendor MS: langsung on_progress (langsung terus menuju, ada 1 request langsung menuju).
    if ($isMs) {
        q("UPDATE tickets SET vendor_id=?, assigned_at=NOW(), accepted_at=NOW(), sla_deadline=?, status='on_progress',
            work_started_at=COALESCE(work_started_at, NOW()), sla_status=NULL WHERE id=?",
            [$vendorId, $deadline, $ticketId]);
    } else {
        // work_started_at hanya diisi sekali (tidak direset saat tiket dialihkan ke vendor lain)
        // sla_status di-reset: verdict SLA baru sah setelah PERMANENT.
        q("UPDATE tickets SET vendor_id=?, assigned_at=NOW(), accepted_at=NULL, sla_deadline=?, status='assigned',
            work_started_at=COALESCE(work_started_at, NOW()), sla_status=NULL WHERE id=?",
            [$vendorId, $deadline, $ticketId]);
    }
    $v = q('SELECT name FROM vendors WHERE id=?', [$vendorId])->fetchColumn();
    $no = q('SELECT ticket_no FROM tickets WHERE id=?', [$ticketId])->fetchColumn();
    log_ticket($ticketId, 'assigned', ($auto ? 'Otomatis ditugaskan ke ' : 'Ditugaskan manual ke ') . $v
        . ($isMs ? ' (Vendor MS - langsung on_progress, tanpa request material)' : ''), $userId);
    notify_vendor($vendorId, "Tiket baru {$no} ditugaskan kepada Anda. Segera terima & kerjakan (SLA " . fmt_duration(SLA_MINUTES) . ').', $ticketId);
    noc_notify($ticketId, 'vendor_assigned');
    return true;
}

function get_ticket(int $id): ?array
{
    return q('SELECT t.*, c.name AS city_name, t.province AS city_province, c.is_primary AS city_is_primary,
                     v.name AS vendor_name, v.phone AS vendor_phone, v.pic_name AS vendor_pic, v.vendor_type AS vendor_type
              FROM tickets t JOIN cities c ON c.id=t.city_id LEFT JOIN vendors v ON v.id=t.vendor_id
              WHERE t.id=?', [$id])->fetch() ?: null;
}

/** Apakah user boleh melihat tiket ini */
function can_view_ticket(array $u, array $t): bool
{
    if ($u['role'] === 'vendor') {
        if ((int)$t['vendor_id'] === (int)$u['vendor_id']) return true;
        // vendor yang pernah menolak tidak bisa melihat lagi
        return false;
    }
    return true;
}

/** Filter SQL tiket sesuai role (vendor hanya melihat tiket miliknya) */
function ticket_scope(array $u): array
{
    if ($u['role'] === 'vendor') return ['t.vendor_id = ?', [(int)$u['vendor_id']]];
    return ['1=1', []];
}

/** Daftar tiket yang menunggu tindakan dari user ini */
function action_tickets(array $u, int $limit = 20): array
{
    $base = 'SELECT t.*, c.name AS city_name, v.name AS vendor_name FROM tickets t
             JOIN cities c ON c.id=t.city_id LEFT JOIN vendors v ON v.id=t.vendor_id WHERE ';
    switch ($u['role']) {
        case 'vendor':
            return q($base . "t.vendor_id=? AND t.status IN ('assigned','on_progress','revision') ORDER BY t.sla_deadline IS NULL, t.sla_deadline LIMIT $limit",
                [(int)$u['vendor_id']])->fetchAll();
        case 'inventory':
            return q($base . "(t.status='inventory_check' OR EXISTS (SELECT 1 FROM ticket_materials m WHERE m.ticket_id=t.id AND m.qty_issued IS NULL)
                      ) AND t.status NOT IN ('closed','cancelled') ORDER BY t.updated_at LIMIT $limit")->fetchAll();
        case 'foc':
            return q($base . "t.status='foc_review' ORDER BY t.updated_at LIMIT $limit")->fetchAll();
        case 'nam':
            // NAM (Network Area Manager) overseeing alur FOC -> NAM -> NOC: fokus review pekerjaan vendor.
            return q($base . "t.status='foc_review' ORDER BY t.updated_at LIMIT $limit")->fetchAll();
        case 'procurement':
            // Procurement memantau semua tiket yang belum selesai, diurutkan deadline terdekat.
            return q($base . "t.status NOT IN ('closed','cancelled')
                      ORDER BY t.sla_deadline IS NULL, t.sla_deadline ASC, t.id DESC LIMIT $limit")->fetchAll();
        default: // admin, noc
            return q($base . "t.status='open' ORDER BY t.created_at LIMIT $limit")->fetchAll();
    }
}

// ------------------------------------------------------------
// Upload foto
// ------------------------------------------------------------
/** Simpan satu file upload gambar, kembalikan path relatif atau lempar Exception */
function save_upload(array $file, int $ticketId): string
{
    if ($file['error'] !== UPLOAD_ERR_OK) throw new RuntimeException('Upload gagal (kode ' . $file['error'] . ').');
    if ($file['size'] > MAX_UPLOAD_MB * 1024 * 1024) throw new RuntimeException('Ukuran foto maksimal ' . MAX_UPLOAD_MB . ' MB.');
    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($file['tmp_name']);
    $ext = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'][$mime] ?? null;
    if (!$ext) throw new RuntimeException('Format foto harus JPG, PNG, atau WEBP.');
    $dir = UPLOAD_DIR . '/' . $ticketId;
    if (!is_dir($dir)) mkdir($dir, 0775, true);
    $name = date('YmdHis') . '_' . bin2hex(random_bytes(6)) . '.' . $ext;
    if (!move_uploaded_file($file['tmp_name'], $dir . '/' . $name)) throw new RuntimeException('Gagal menyimpan foto.');
    return 'uploads/' . $ticketId . '/' . $name;
}

/** Normalisasi $_FILES['x'] multiple menjadi list file */
function files_list(?array $f): array
{
    if (!$f || !isset($f['name'])) return [];
    if (!is_array($f['name'])) return $f['error'] === UPLOAD_ERR_NO_FILE ? [] : [$f];
    $out = [];
    foreach ($f['name'] as $i => $n) {
        if ($f['error'][$i] === UPLOAD_ERR_NO_FILE) continue;
        $out[] = ['name' => $n, 'type' => $f['type'][$i], 'tmp_name' => $f['tmp_name'][$i], 'error' => $f['error'][$i], 'size' => $f['size'][$i]];
    }
    return $out;
}

// ------------------------------------------------------------
// Technical Skill vendor
// ------------------------------------------------------------

/**
 * Sinkronkan daftar skill milik sebuah vendor (ganti total).
 * @param int $vendorId
 * @param int[] $skillIds
 */
function sync_vendor_skills(int $vendorId, array $skillIds): void
{
    if ($vendorId <= 0) return;
    q('DELETE FROM vendor_skills WHERE vendor_id=?', [$vendorId]);
    foreach ($skillIds as $sid) {
        $sid = (int)$sid;
        if ($sid <= 0) continue;
        $ok = q('SELECT 1 FROM skills WHERE id=?', [$sid])->fetch();
        if ($ok) q('INSERT IGNORE INTO vendor_skills (vendor_id, skill_id) VALUES (?,?)', [$vendorId, $sid]);
    }
}

/**
 * Sinkronkan pendaftaran vendor di region beserta limit workload-nya.
 * Dipakai dari form vendor (Admin & Procurement) supaya vendor baru
 * langsung punya region + limit tanpa harus buka halaman Region.
 * @param array<int,int> $limits  [region_id => workload_limit]
 */
function sync_vendor_regions(int $vendorId, array $limits): void
{
    if ($vendorId <= 0) return;
    q('DELETE FROM vendor_regions WHERE vendor_id=?', [$vendorId]);
    foreach ($limits as $rid => $limit) {
        $rid = (int)$rid;
        if ($rid <= 0) continue;
        if (!q('SELECT 1 FROM regions WHERE id=?', [$rid])->fetch()) continue;
        $limit = max(0, (int)$limit);
        q('INSERT INTO vendor_regions (vendor_id, region_id, workload_limit) VALUES (?,?,?)', [$vendorId, $rid, $limit]);
    }
}

/**
 * Ambil skill milik satu vendor: array of ['id','name'].
 * @return array<int,array{id:int,name:string}>
 */
function vendor_skills(int $vendorId): array
{
    static $cache = [];
    if (isset($cache[$vendorId])) return $cache[$vendorId];
    $rows = q('SELECT s.id, s.name FROM vendor_skills vs JOIN skills s ON s.id=vs.skill_id
               WHERE vs.vendor_id=? ORDER BY s.name', [$vendorId])->fetchAll();
    return $cache[$vendorId] = $rows;
}

/**
 * Ambil skill semua vendor sekaligus: [vendor_id => [['id','name'], ...]].
 * @return array<int,array<int,array{id:int,name:string}>>
 */
function all_vendor_skills(): array
{
    static $cache = null;
    if ($cache !== null) return $cache;
    $cache = [];
    foreach (q('SELECT vs.vendor_id, s.id, s.name FROM vendor_skills vs JOIN skills s ON s.id=vs.skill_id
                ORDER BY s.name')->fetchAll() as $r) {
        $cache[(int)$r['vendor_id']][] = ['id' => (int)$r['id'], 'name' => $r['name']];
    }
    return $cache;
}

// ------------------------------------------------------------
// Migrasi ringan: pastikan kolom limit workload & tabel region ada
// ------------------------------------------------------------
function migrate_schema(): void
{
    $cols = q('SHOW COLUMNS FROM vendors')->fetchAll(PDO::FETCH_COLUMN);
    if (!in_array('workload_limit', $cols, true)) {
        q('ALTER TABLE vendors ADD COLUMN workload_limit INT NOT NULL DEFAULT 5 AFTER status');
    }

    // Waktu pengerjaan: mulai saat ditugaskan, berhenti saat PERMANENT
    $tcols = q('SHOW COLUMNS FROM tickets')->fetchAll(PDO::FETCH_COLUMN);
    if (!in_array('work_started_at', $tcols, true)) {
        q('ALTER TABLE tickets ADD COLUMN work_started_at DATETIME NULL AFTER completed_at');
    }
    if (!in_array('work_finished_at', $tcols, true)) {
        q('ALTER TABLE tickets ADD COLUMN work_finished_at DATETIME NULL AFTER work_started_at');
    }

    // Backfill data lama: mulai = saat ditugaskan, berhenti = saat tiket ditutup/dibatalkan
    q("UPDATE tickets SET work_started_at=assigned_at
        WHERE work_started_at IS NULL AND assigned_at IS NOT NULL AND status<>'open'");
    q("UPDATE tickets SET work_finished_at=COALESCE(completed_at, updated_at)
        WHERE work_finished_at IS NULL AND status IN ('closed','cancelled')");

    // Penyimpanan key/value internal (penanda throttle sinkronisasi SLA, dll).
    q('CREATE TABLE IF NOT EXISTS app_meta (
        k VARCHAR(50) NOT NULL PRIMARY KEY,
        v TEXT NULL
    ) ENGINE=InnoDB');

    // ===== Flow Vendor MS (2026-11-19) =====
    // Tipe vendor: MS (Managed Service) vs non_ms.
    $vcols = q('SHOW COLUMNS FROM vendors')->fetchAll(PDO::FETCH_COLUMN);
    if (!in_array('vendor_type', $vcols, true)) {
        q("ALTER TABLE vendors ADD COLUMN vendor_type ENUM('ms','non_ms') NOT NULL DEFAULT 'non_ms' AFTER address");
    }
    // Kota utama (ibu kota wilayah).
    $ccols = q('SHOW COLUMNS FROM cities')->fetchAll(PDO::FETCH_COLUMN);
    if (!in_array('is_primary', $ccols, true)) {
        q('ALTER TABLE cities ADD COLUMN is_primary TINYINT(1) NOT NULL DEFAULT 0 AFTER region_id');
    }

    // Tiket: nomor SPK, provinsi, jenis kegiatan, dan cek ready SLA.
    $tcols2 = q('SHOW COLUMNS FROM tickets')->fetchAll(PDO::FETCH_COLUMN);
    if (!in_array('spk_no', $tcols2, true)) {
        q('ALTER TABLE tickets ADD COLUMN spk_no VARCHAR(30) NULL UNIQUE AFTER ticket_no');
    }
    if (!in_array('province', $tcols2, true)) {
        q('ALTER TABLE tickets ADD COLUMN province VARCHAR(60) NULL AFTER location');
    }
    if (!in_array('activity_type', $tcols2, true)) {
        q('ALTER TABLE tickets ADD COLUMN activity_type VARCHAR(100) NULL AFTER province');
    }
    // Catatan: kolom sla_ready / sla_ready_at / sla_ready_by / sla_ready_note DIHAPUS
    // (19 Nov 2026) - tahap "Cek Ready SLA" + tombol RPA/SLA OK/TANDAIN dihapus.
    // Tiket yang lewat SLA hanya ditandai lewat badge SLA (Lewat SLA / Sesuai SLA).
    foreach (['sla_ready_note', 'sla_ready_by', 'sla_ready_at', 'sla_ready'] as $dropCol) {
        if (in_array($dropCol, $tcols2, true)) {
            try { q("ALTER TABLE tickets DROP COLUMN `$dropCol`"); } catch (PDOException $ex) { /* diabaikan */ }
        }
    }

    // Baris material: keterangan.
    // Catatan: kolom mat_status (fixad/fixat) DIHAPUS (19 Nov 2026) - status material
    // tidak lagi diisi vendor, cukup kolom "Keterangan" per baris.
    $mcols = q('SHOW COLUMNS FROM ticket_materials')->fetchAll(PDO::FETCH_COLUMN);
    if (in_array('mat_status', $mcols, true)) {
        try { q('ALTER TABLE ticket_materials DROP COLUMN mat_status'); } catch (PDOException $ex) { /* diabaikan */ }
    }
    // Material dipakai langsung oleh Vendor MS (tidak melalui request/return inventory).
    if (!in_array('is_direct', $mcols, true)) {
        q('ALTER TABLE ticket_materials ADD COLUMN is_direct TINYINT(1) NOT NULL DEFAULT 0 AFTER qty_returned');
    }

    // Laporan: jenis kerusakan (pilih + upload), tindakan perbaikan (pilih).
    $rcols = q('SHOW COLUMNS FROM ticket_reports')->fetchAll(PDO::FETCH_COLUMN);
    if (!in_array('damage_type', $rcols, true)) {
        q('ALTER TABLE ticket_reports ADD COLUMN damage_type VARCHAR(100) NULL AFTER root_cause');
    }
    if (!in_array('damage_photo', $rcols, true)) {
        q('ALTER TABLE ticket_reports ADD COLUMN damage_photo VARCHAR(255) NULL AFTER damage_type');
    }
    if (!in_array('repair_action', $rcols, true)) {
        q('ALTER TABLE ticket_reports ADD COLUMN repair_action VARCHAR(100) NULL AFTER damage_photo');
    }

    // Master pilihan (jenis kerusakan / tindakan perbaikan).
    q('CREATE TABLE IF NOT EXISTS master_options (
        id INT AUTO_INCREMENT PRIMARY KEY,
        category ENUM(\'damage_type\',\'repair_action\') NOT NULL,
        name VARCHAR(100) NOT NULL,
        description VARCHAR(255) NULL,
        is_active TINYINT(1) NOT NULL DEFAULT 1,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        UNIQUE (category, name)
    ) ENGINE=InnoDB');
    // Buang kategori material_status (fixad/fixat) - sudah dihapus dari form vendor.
    try {
        $moCat = q("SHOW COLUMNS FROM master_options LIKE 'category'")->fetch();
        if ($moCat && stripos((string)$moCat['Type'], 'material_status') !== false) {
            q("DELETE FROM master_options WHERE category='material_status'");
            q("ALTER TABLE master_options MODIFY category ENUM('damage_type','repair_action') NOT NULL");
        }
    } catch (PDOException $ex) { /* diabaikan */ }

    // Batas maksimal pemakaian material per tiket (konsep: "kabel jenis tertentu + 500m => panjang max 3").
    $matCols = q('SHOW COLUMNS FROM materials')->fetchAll(PDO::FETCH_COLUMN);
    if (!in_array('max_use_per_ticket', $matCols, true)) {
        q('ALTER TABLE materials ADD COLUMN max_use_per_ticket DECIMAL(12,2) NOT NULL DEFAULT 0 AFTER stock');
    }

    // Stage tanda check: region (PIC Region) & cgs (evaluasi internal CGS).
    $soCols = q('SHOW COLUMNS FROM ticket_signoffs')->fetchAll(PDO::FETCH_COLUMN);
    if (!in_array('stage', $soCols, true)) {
        q("ALTER TABLE ticket_signoffs ADD COLUMN stage ENUM('region','cgs') NOT NULL DEFAULT 'region' AFTER ref_id");
    }
    // Ganti unique key lama (ticket_id, scope, ref_id) -> ditambah stage.
    // Urutan penting: tambah key baru DULU (FK butuh index dengan kolom pertama ticket_id),
    // baru drop key lama - kalau dibalik, MySQL menolak karena index dipakai foreign key.
    $soNewIdx = q("SHOW INDEX FROM ticket_signoffs WHERE Key_name='uniq_signoff'")->fetch();
    if (!$soNewIdx) {
        q('ALTER TABLE ticket_signoffs ADD UNIQUE KEY uniq_signoff (ticket_id, scope, ref_id, stage)');
    }
    $soIdx = q("SHOW INDEX FROM ticket_signoffs WHERE Key_name='ticket_id'")->fetch();
    if ($soIdx) {
        try { q('ALTER TABLE ticket_signoffs DROP INDEX `ticket_id`'); } catch (PDOException $ex) { /* diabaikan */ }
    }

    // Flag foto blur (hasil pemeriksaan ketajaman).
    $phCols = q('SHOW COLUMNS FROM ticket_photos')->fetchAll(PDO::FETCH_COLUMN);
    if (!in_array('is_blurry', $phCols, true)) {
        q('ALTER TABLE ticket_photos ADD COLUMN is_blurry TINYINT(1) NOT NULL DEFAULT 0 AFTER caption');
    }
    if (!in_array('blur_score', $phCols, true)) {
        q('ALTER TABLE ticket_photos ADD COLUMN blur_score DECIMAL(10,4) NULL AFTER is_blurry');
    }

    // Tanda check / keterangan: stage region (PIC Region) & cgs (evaluasi internal CGS).
    q('CREATE TABLE IF NOT EXISTS ticket_signoffs (
        id INT AUTO_INCREMENT PRIMARY KEY,
        ticket_id INT NOT NULL,
        scope ENUM(\'material\',\'dokumentasi\',\'penyelesaian\',\'sla\') NOT NULL,
        ref_id INT NOT NULL DEFAULT 0,
        stage ENUM(\'region\',\'cgs\') NOT NULL DEFAULT \'region\',
        status ENUM(\'pending\',\'ok\',\'marked\') NOT NULL DEFAULT \'pending\',
        note VARCHAR(255) NULL,
        checked_by INT NULL,
        checked_at DATETIME NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        UNIQUE (ticket_id, scope, ref_id, stage),
        FOREIGN KEY (ticket_id) REFERENCES tickets(id) ON DELETE CASCADE,
        FOREIGN KEY (checked_by) REFERENCES users(id) ON DELETE SET NULL
    ) ENGINE=InnoDB');

    // BASTO (catatan penggunaan material) - otomatis dari form vendor.
    q('CREATE TABLE IF NOT EXISTS basto (
        id INT AUTO_INCREMENT PRIMARY KEY,
        ticket_id INT NOT NULL UNIQUE,
        no_basto VARCHAR(40) NOT NULL UNIQUE,
        payload TEXT NOT NULL,
        generated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        generated_by INT NULL,
        FOREIGN KEY (ticket_id) REFERENCES tickets(id) ON DELETE CASCADE,
        FOREIGN KEY (generated_by) REFERENCES users(id) ON DELETE SET NULL
    ) ENGINE=InnoDB');

    // Seed master pilihan bawaan.
    $defaults = [
        'damage_type' => ['Kabel FO putus', 'Redaman tinggi', 'ODP rusak', 'Closure rusak', 'Tiang roboh', 'LOS / No signal', 'Konektor kotor'],
        'repair_action' => ['Ganti splices', 'Ganti closure', 'Ganti ODP', 'Patch ulang FO', 'Perbaikan kabel', 'Bersihkan konektor', 'Ganti tiang'],
    ];
    foreach ($defaults as $cat => $list) {
        foreach ($list as $name) {
            q('INSERT IGNORE INTO master_options (category, name) VALUES (?,?)', [$cat, $name]);
        }
    }

    // Backfill nomor SPK untuk tiket lama.
    foreach (q('SELECT id FROM tickets WHERE spk_no IS NULL ORDER BY id') as $r) {
        q('UPDATE tickets SET spk_no=? WHERE id=?', [generate_spk_no(), (int)$r['id']]);
    }

    // Role 'procurement' & 'nam' (2026-11-19): pastikan enum users.role memuat role baru.
    try {
        $roleCol = q("SHOW COLUMNS FROM users LIKE 'role'")->fetch();
        $roleType = (string)($roleCol['Type'] ?? '');
        if ($roleType !== '' && stripos($roleType, "'nam'") === false) {
            q("ALTER TABLE users MODIFY role ENUM('admin','noc','nam','procurement','vendor','inventory','foc') NOT NULL");
        } elseif ($roleType !== '' && stripos($roleType, "'procurement'") === false) {
            q("ALTER TABLE users MODIFY role ENUM('admin','noc','procurement','vendor','inventory','foc') NOT NULL");
        }
    } catch (PDOException $ex) {
        // tabel users belum ada (mis. saat instalasi) - diabaikan
    }

    // SLA baru: verdict dihitung sampai PERMANENT (bukan berhenti di laporan pertama).
    sla_resync();

    q('CREATE TABLE IF NOT EXISTS regions (
        id INT AUTO_INCREMENT PRIMARY KEY,
        name VARCHAR(100) NOT NULL UNIQUE,
        code VARCHAR(20) NULL,
        description VARCHAR(255) NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB');

    $ccols = q('SHOW COLUMNS FROM cities')->fetchAll(PDO::FETCH_COLUMN);
    if (!in_array('region_id', $ccols, true)) {
        q('ALTER TABLE cities ADD COLUMN region_id INT NULL AFTER name');
    }

    q('CREATE TABLE IF NOT EXISTS vendor_regions (
        id INT AUTO_INCREMENT PRIMARY KEY,
        vendor_id INT NOT NULL,
        region_id INT NOT NULL,
        workload_limit INT NOT NULL DEFAULT 5,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        UNIQUE (vendor_id, region_id),
        FOREIGN KEY (vendor_id) REFERENCES vendors(id) ON DELETE CASCADE,
        FOREIGN KEY (region_id) REFERENCES regions(id) ON DELETE CASCADE
    ) ENGINE=InnoDB');

    // Master Technical Skill + relasi vendor <-> skill (banyak-ke-banyak)
    q('CREATE TABLE IF NOT EXISTS skills (
        id INT AUTO_INCREMENT PRIMARY KEY,
        name VARCHAR(80) NOT NULL UNIQUE,
        description VARCHAR(255) NULL,
        is_active TINYINT(1) NOT NULL DEFAULT 1,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB');

    q('CREATE TABLE IF NOT EXISTS vendor_skills (
        id INT AUTO_INCREMENT PRIMARY KEY,
        vendor_id INT NOT NULL,
        skill_id INT NOT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        UNIQUE (vendor_id, skill_id),
        FOREIGN KEY (vendor_id) REFERENCES vendors(id) ON DELETE CASCADE,
        FOREIGN KEY (skill_id) REFERENCES skills(id) ON DELETE CASCADE
    ) ENGINE=InnoDB');

    // Skill default (bisa ditambah/diubah dari halaman Vendor)
    $defaultSkills = [
        'Aktivasi' => 'Aktivasi layanan / skill ODP pelanggan',
        'Troubleshooting' => 'Pencarian & perbaikan gangguan',
        'Maintenance' => 'Perawatan berkala',
        'Project' => 'Pekerjaan proyek / penambahan jaringan',
        'Instalasi' => 'Instalasi jaringan baru',
        'Jaringan' => 'Pekerjaan jaringan (pulling, splicing)',
        'Survey Lokasi' => 'Survei & pengukuran lokasi',
        'Handling Keluhan' => 'Penanganan keluhan pelanggan',
    ];
    foreach ($defaultSkills as $sName => $sDesc) {
        q('INSERT IGNORE INTO skills (name, description) VALUES (?,?)', [$sName, $sDesc]);
    }

    // Migrasi data lama: setiap kota dijadikan satu region, vendor terdaftar di region kotanya
    // dengan batas workload yang sama seperti sebelumnya (tidak ada data yang hilang).
    $regionCount = (int)q('SELECT COUNT(*) FROM regions')->fetchColumn();
    if ($regionCount === 0) {
        foreach (q('SELECT id, name FROM cities ORDER BY id')->fetchAll() as $c) {
            q('INSERT INTO regions (name, description) VALUES (?, ?)', [$c['name'], 'Wilayah default hasil migrasi (kota ' . $c['name'] . ')']);
            $rid = (int)db()->lastInsertId();
            q('UPDATE cities SET region_id=? WHERE id=?', [$rid, $c['id']]);
            foreach (q('SELECT id, workload_limit FROM vendors WHERE city_id=?', [$c['id']])->fetchAll() as $v) {
                q('INSERT INTO vendor_regions (vendor_id, region_id, workload_limit) VALUES (?,?,?)',
                    [$v['id'], $rid, (int)$v['workload_limit']]);
            }
        }
    }
}

if (PHP_SAPI !== 'cli') {
    try {
        migrate_schema();
        refresh_vendor_status();
    } catch (PDOException $ex) {
        if (basename($_SERVER['SCRIPT_NAME']) !== 'install.php') {
            exit('Database belum siap. Jalankan <a href="' . url('install.php') . '">install.php</a> terlebih dahulu.<br><small>' . e($ex->getMessage()) . '</small>');
        }
    }
}

// Helper flow Vendor MS (Managed Service) - dimuat setelah semua fungsi di atas siap.
require_once __DIR__ . '/vendor_ms.php';

// Helper pemeriksaan dokumentasi foto (blur) & validasi batas material.
require_once __DIR__ . '/photo_check.php';
