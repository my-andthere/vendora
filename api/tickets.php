<?php
/**
 * API untuk sistem NOC.
 *
 * Autentikasi: header  X-API-KEY: <NOC_API_KEY di config.php>
 *
 * 1) Buat tiket baru (otomatis menunjuk vendor di kota yang sama)
 *    POST /procurement/api/tickets.php
 *    Content-Type: application/json
 *    {
 *      "noc_ref": "NOC-12345",          (wajib, unik - ID tiket di sistem NOC)
 *      "city": "Jakarta",               (wajib, harus terdaftar di menu Kota)
 *      "problem_type": "Kabel FO putus",(wajib)
 *      "location": "Jl. Sudirman No.1", (wajib)
 *      "description": "...", "customer_name": "...", "customer_id": "...",
 *      "priority": "low|medium|high|critical", "reported_at": "2026-11-18 10:00:00",
 *      "latitude": -6.2, "longitude": 106.8
 *    }
 *
 * 2) Cek status tiket
 *    GET /procurement/api/tickets.php?noc_ref=NOC-12345   atau   ?ticket_no=TKT-20261118-0001
 */
require_once __DIR__ . '/../includes/bootstrap.php';
header('Content-Type: application/json; charset=utf-8');

function respond(int $code, array $data): void
{
    http_response_code($code);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

$key = $_SERVER['HTTP_X_API_KEY'] ?? '';
if (!hash_equals(NOC_API_KEY, $key)) respond(401, ['success' => false, 'error' => 'API key tidak valid']);

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    if (!empty($_GET['noc_ref'])) $t = q('SELECT id FROM tickets WHERE noc_ref=? ORDER BY id DESC LIMIT 1', [$_GET['noc_ref']])->fetch();
    elseif (!empty($_GET['ticket_no'])) $t = q('SELECT id FROM tickets WHERE ticket_no=?', [$_GET['ticket_no']])->fetch();
    else respond(400, ['success' => false, 'error' => 'Parameter noc_ref atau ticket_no wajib']);
    if (!$t) respond(404, ['success' => false, 'error' => 'Tiket tidak ditemukan']);
    $t = get_ticket((int)$t['id']);
    respond(200, ['success' => true, 'data' => [
        'ticket_no' => $t['ticket_no'], 'spk_no' => $t['spk_no'], 'noc_ref' => $t['noc_ref'], 'status' => $t['status'],
        'status_label' => TICKET_STATUS[$t['status']][0] ?? $t['status'], 'city' => $t['city_name'], 'vendor' => $t['vendor_name'],
        'vendor_type' => $t['vendor_type'], 'assigned_at' => $t['assigned_at'], 'accepted_at' => $t['accepted_at'],
        'sla_deadline' => $t['sla_deadline'], 'submitted_at' => $t['submitted_at'],
        'sla_status' => sla_state($t)['verdict'], 'repair_result' => $t['repair_result'], 'completed_at' => $t['completed_at'],
    ]]);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') respond(405, ['success' => false, 'error' => 'Method tidak didukung']);

$in = json_decode(file_get_contents('php://input'), true);
if (!is_array($in)) $in = $_POST;

$errors = [];
foreach (['noc_ref', 'city', 'problem_type', 'location'] as $f) {
    if (trim((string)($in[$f] ?? '')) === '') $errors[] = "$f wajib diisi";
}
$city = q('SELECT id FROM cities WHERE LOWER(name)=LOWER(?)', [trim((string)($in['city'] ?? ''))])->fetch();
if (!empty($in['city']) && !$city) $errors[] = 'Kota "' . $in['city'] . '" belum terdaftar di sistem Procurement';
$priority = $in['priority'] ?? 'medium';
if (!isset(PRIORITIES[$priority])) $errors[] = 'priority harus low|medium|high|critical';
$reportedAt = !empty($in['reported_at']) ? parse_dt($in['reported_at']) : null;
if (!empty($in['reported_at']) && $reportedAt === null) $errors[] = 'reported_at tidak valid (format: YYYY-MM-DD HH:MM:SS)';
if ($errors) respond(422, ['success' => false, 'errors' => $errors]);

$dup = q('SELECT ticket_no FROM tickets WHERE noc_ref=?', [$in['noc_ref']])->fetchColumn();
if ($dup) respond(409, ['success' => false, 'error' => 'noc_ref sudah pernah dibuat', 'ticket_no' => $dup]);

db()->beginTransaction();
$res = create_ticket([
    'noc_ref' => trim($in['noc_ref']),
    'city_id' => (int)$city['id'],
    'customer_name' => $in['customer_name'] ?? null,
    'customer_id' => $in['customer_id'] ?? null,
    'location' => $in['location'],
    'latitude' => isset($in['latitude']) ? (string)$in['latitude'] : '',
    'longitude' => isset($in['longitude']) ? (string)$in['longitude'] : '',
    'problem_type' => $in['problem_type'],
    'description' => $in['description'] ?? null,
    'priority' => $priority,
    'reported_at' => $reportedAt,
], 'api', null);
db()->commit();

$t = get_ticket($res['id']);
respond(201, ['success' => true, 'data' => [
    'ticket_no' => $t['ticket_no'], 'spk_no' => $t['spk_no'], 'noc_ref' => $t['noc_ref'], 'status' => $t['status'],
    'vendor' => $t['vendor_name'], 'vendor_type' => $t['vendor_type'], 'sla_deadline' => $t['sla_deadline'],
    'message' => $t['vendor_id'] ? 'Vendor otomatis ditugaskan' : 'Tidak ada vendor available di kota ini',
]]);
