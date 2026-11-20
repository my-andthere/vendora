<?php
/**
 * Semua aksi alur kerja tiket (POST).
 */
require_once __DIR__ . '/includes/bootstrap.php';
$u = require_login();
if ($_SERVER['REQUEST_METHOD'] !== 'POST') redirect('tickets.php');
csrf_check();

$id = (int)($_POST['ticket_id'] ?? 0);
$action = $_POST['action'] ?? '';
$t = get_ticket($id);
if (!$t || !can_view_ticket($u, $t)) {
    http_response_code(404);
    exit('Tiket tidak ditemukan.');
}
$back = 'ticket_view.php?id=' . $id;
$isMine = $u['role'] === 'vendor' && (int)$t['vendor_id'] === (int)$u['vendor_id'];

function fail(string $msg, string $back): void
{
    if (db()->inTransaction()) db()->rollBack();
    flash('danger', $msg);
    redirect($back);
}

function need(bool $cond, string $back, string $msg = 'Aksi tidak diizinkan pada status tiket saat ini.'): void
{
    if (!$cond) fail($msg, $back);
}

db()->beginTransaction();
// kunci baris tiket agar aksi bersamaan tidak bentrok
$t = array_merge($t, q('SELECT status, vendor_id FROM tickets WHERE id=? FOR UPDATE', [$id])->fetch());
$st = $t['status'];
$no = $t['ticket_no'];

switch ($action) {

    // ================= VENDOR =================
    case 'accept':
        need($isMine && $st === 'assigned', $back);
        $deadline = SLA_START === 'accepted' ? date('Y-m-d H:i:s', time() + (int)$t['sla_minutes'] * 60) : $t['sla_deadline'];
        $acceptedAt = date('Y-m-d H:i:s');
        q("UPDATE tickets SET status='on_progress', accepted_at=?, sla_deadline=? WHERE id=?", [$acceptedAt, $deadline, $id]);
        $delay = $t['assigned_at'] ? max(0, strtotime($acceptedAt) - strtotime($t['assigned_at'])) : 0;
        log_ticket($id, 'accepted', 'Vendor menekan tombol "Terima & Mulai Kerjakan" pada ' . fmt_dt($acceptedAt)
            . ' (' . fmt_dur_seconds($delay) . ' setelah ditugaskan)');
        noc_notify($id, 'vendor_accepted');
        flash('success', 'Tiket diterima. Selamat bekerja!');
        break;

    case 'reject':
        need($isMine && $st === 'assigned', $back);
        $reason = trim($_POST['reason'] ?? '');
        need($reason !== '', $back, 'Alasan penolakan wajib diisi.');
        q('INSERT INTO ticket_rejections (ticket_id, vendor_id, reason) VALUES (?,?,?)', [$id, $t['vendor_id'], $reason]);
        log_ticket($id, 'rejected', 'Vendor ' . $t['vendor_name'] . ' menolak: ' . $reason);
        auto_assign($id, $u['id']);
        db()->commit();
        flash('info', 'Tiket ditolak dan dialihkan.');
        redirect('dashboard.php');

    case 'request_material':
        need($isMine && in_array($st, ['on_progress', 'revision'], true), $back);
        $mid = (int)($_POST['material_id'] ?? 0);
        $qty = (float)($_POST['qty'] ?? 0);
        $mat = q('SELECT * FROM materials WHERE id=? AND is_active=1', [$mid])->fetch();
        need($mat && $qty > 0, $back, 'Material / jumlah tidak valid.');
        q('INSERT INTO ticket_materials (ticket_id, material_id, qty_requested, requested_by) VALUES (?,?,?,?)', [$id, $mid, $qty, $u['id']]);
        log_ticket($id, 'material_request', 'Minta material ' . $mat['name'] . ' ' . fmt_qty($qty) . ' ' . $mat['unit']);
        notify_role('inventory', "Tiket {$no}: vendor {$t['vendor_name']} meminta material {$mat['name']} " . fmt_qty($qty) . " {$mat['unit']}.", $id);
        flash('success', 'Permintaan material dikirim ke inventory.');
        break;

    case 'cancel_request':
        need($isMine, $back);
        $line = q('SELECT tm.*, m.name FROM ticket_materials tm JOIN materials m ON m.id=tm.material_id WHERE tm.id=? AND tm.ticket_id=? AND tm.qty_issued IS NULL',
            [(int)$_POST['line_id'], $id])->fetch();
        need((bool)$line, $back, 'Permintaan tidak ditemukan atau sudah diproses.');
        q('DELETE FROM ticket_materials WHERE id=?', [$line['id']]);
        log_ticket($id, 'material_request_cancel', 'Batal minta material ' . $line['name']);
        flash('success', 'Permintaan material dibatalkan.');
        break;

    // ================= INVENTORY =================
    case 'issue_material':
        need(has_role('inventory', 'admin') && !in_array($st, ['closed', 'cancelled'], true), $back);
        $issued = [];
        foreach ((array)($_POST['issue'] ?? []) as $lineId => $qty) {
            $qty = (float)$qty;
            $line = q('SELECT tm.*, m.name, m.unit, m.stock FROM ticket_materials tm JOIN materials m ON m.id=tm.material_id
                       WHERE tm.id=? AND tm.ticket_id=? AND tm.qty_issued IS NULL FOR UPDATE', [(int)$lineId, $id])->fetch();
            if (!$line) continue;
            need($qty >= 0, $back, 'Jumlah tidak valid.');
            need($qty <= (float)$line['stock'], $back, "Stok {$line['name']} tidak cukup (sisa " . fmt_qty($line['stock']) . ').');
            q('UPDATE ticket_materials SET qty_issued=?, issued_by=?, issued_at=NOW(), qty_used=IF(?=0,0,NULL), qty_returned=IF(?=0,0,NULL) WHERE id=?',
                [$qty, $u['id'], $qty, $qty, $line['id']]);
            q('UPDATE materials SET stock = stock - ? WHERE id=?', [$qty, $line['material_id']]);
            $issued[] = $line['name'] . ' ' . fmt_qty($qty) . ' ' . $line['unit'];
        }
        need((bool)$issued, $back, 'Tidak ada material yang diproses.');
        log_ticket($id, 'material_issued', 'Material diserahkan: ' . implode(', ', $issued));
        if ($t['vendor_id']) notify_vendor((int)$t['vendor_id'], "Tiket {$no}: material siap diambil - " . implode(', ', $issued), $id);
        flash('success', 'Material diserahkan ke vendor.');
        break;

    case 'issue_direct':
        need(has_role('inventory', 'admin') && in_array($st, ['assigned', 'on_progress', 'revision'], true), $back);
        $mid = (int)($_POST['material_id'] ?? 0);
        $qty = (float)($_POST['qty'] ?? 0);
        $mat = q('SELECT * FROM materials WHERE id=? AND is_active=1 FOR UPDATE', [$mid])->fetch();
        need($mat && $qty > 0, $back, 'Material / jumlah tidak valid.');
        need($qty <= (float)$mat['stock'], $back, "Stok {$mat['name']} tidak cukup (sisa " . fmt_qty($mat['stock']) . ').');
        q('INSERT INTO ticket_materials (ticket_id, material_id, qty_requested, qty_issued, issued_by, issued_at, note) VALUES (?,?,?,?,?,NOW(),?)',
            [$id, $mid, $qty, $qty, $u['id'], 'Diserahkan langsung oleh inventory']);
        q('UPDATE materials SET stock = stock - ? WHERE id=?', [$qty, $mid]);
        log_ticket($id, 'material_issued', 'Material diserahkan langsung: ' . $mat['name'] . ' ' . fmt_qty($qty) . ' ' . $mat['unit']);
        if ($t['vendor_id']) notify_vendor((int)$t['vendor_id'], "Tiket {$no}: inventory menyerahkan {$mat['name']} " . fmt_qty($qty) . " {$mat['unit']}.", $id);
        flash('success', 'Material diserahkan.');
        break;

    case 'inventory_check':
        need(has_role('inventory', 'admin') && $st === 'inventory_check', $back);
        // Cek material oleh Inventory: checklist + keterangan per material WAJIB lengkap.
        // Isi form vendor tidak diubah; Inventory hanya menambahkan checklist.
        $invRes = wf_run_inventory_check($id, $_POST, (int)$u['id']);
        need($invRes['ok'], $back, $invRes['error'] ?? 'Pengecekan material gagal.');
        flash('success', 'Pengecekan material disimpan, tiket diteruskan ke FOC.');
        break;

    // ================= FOC =================
    // Link Up: PERMANEN / TEMPORER (diagram kotak 6b - "LINK UP | PERMANEN" / "LINK UP | TEMPORER")
    // + Simpan ceklist FOC (tanpa link up).
    case 'foc_permanent':
    case 'foc_temporary':
    case 'save_foc_eval':
        need(has_role('foc', 'admin', 'noc', 'nam') && $st === 'foc_review', $back);

        if ($action === 'save_foc_eval') {
            // Simpan ceklist FOC saja (boleh belum lengkap) - tanpa link up.
            $focRes = wf_run_foc_eval($id, $_POST, (int)$u['id']);
            need($focRes['ok'], $back, $focRes['error'] ?? 'Gagal menyimpan ceklist FOC.');
            flash('success', 'Ceklist FOC disimpan.');
            break;
        }

        // Link up: FOC hanya menerima form yang sudah DONE dari Inventory dan
        // semua item ceklist FOC (dokumentasi + penyebab + tindakan) wajib dicentang.
        $result = $action === 'foc_permanent' ? 'permanent' : 'temporary';
        $focRes = wf_run_foc_linkup($id, $_POST, (int)$u['id'], $result);
        need($focRes['ok'], $back, $focRes['error'] ?? 'Link up gagal.');
        if ($result === 'permanent') {
            flash('success', 'LINK UP PERMANEN. Tiket ditutup - ' . ($focRes['sla'] ?? 'SESUAI SLA') . '.'
                . (!empty($focRes['basto_no']) ? ' BASTO ' . $focRes['basto_no'] . ' terbentuk otomatis.' : ''));
        } else {
            flash('warning', 'LINK UP TEMPORER. Form dikembalikan ke vendor.');
        }
        break;

    // ================= TANDA CHECK (PIC Region / Evaluasi CGS) =================
    case 'signoff':
        need(has_role('noc', 'admin', 'procurement', 'foc', 'nam')
            && in_array($st, ['inventory_check', 'foc_review'], true), $back);
        $scope = $_POST['scope'] ?? '';
        need(isset(SIGNOFF_SCOPES[$scope]), $back, 'Scope tanda check tidak valid.');
        $stage = $_POST['stage'] ?? 'region';
        need(isset(SIGNOFF_STAGES[$stage]), $back, 'Tahap tanda check tidak valid.');
        // Ceklis Inventory hanya boleh diisi pihak Inventory, ceklis FOC hanya oleh FOC/NAM/NOC.
        if ($stage === WF_STAGE_INVENTORY) {
            need(has_role('inventory', 'admin'), $back, 'Ceklis material hanya boleh diisi oleh Inventory.');
        } elseif ($stage === WF_STAGE_FOC) {
            need(has_role('foc', 'nam', 'noc', 'admin'), $back, 'Ceklist FOC hanya boleh diisi oleh FOC.');
        }
        $refId = (int)($_POST['ref_id'] ?? 0);
        $mark = $_POST['mark'] ?? 'ok';
        need(in_array($mark, ['ok', 'marked', 'pending'], true), $back, 'Tanda check tidak valid.');
        $signNote = trim($_POST['note'] ?? '');
        save_signoff($id, $scope, $refId, $mark, $signNote ?: null, (int)$u['id'], $stage);
        log_ticket($id, 'signoff', SIGNOFF_STAGES[$stage] . ' - ' . SIGNOFF_SCOPES[$scope] . ': '
            . ($mark === 'ok' ? 'OK' : ($mark === 'marked' ? 'TANDAIN' : 'reset')) . ($signNote ? ' - ' . $signNote : ''));
        flash('success', SIGNOFF_STAGES[$stage] . ' (' . SIGNOFF_SCOPES[$scope] . ') disimpan.');
        break;

    // ================= NOC / ADMIN =================
    case 'assign':
        need(has_role('admin', 'noc', 'procurement') && in_array($st, ['open', 'assigned', 'on_progress', 'revision'], true), $back);
        $vid = (int)($_POST['vendor_id'] ?? 0);
        $tRegion = region_name(city_region_id((int)($t['city_id'] ?? 0)));
        $where = $tRegion !== '-' ? 'region ' . $tRegion : 'kota ' . ($t['city_name'] ?? '-');
        $ok = array_filter(ms_first_candidates($id, false), fn($v) => (int)$v['id'] === $vid);
        need((bool)$ok, $back, 'Vendor tidak valid / tidak available / tidak terdaftar di ' . $where . ' / sudah mencapai limit workload.');
        if ($t['vendor_id'] && (int)$t['vendor_id'] !== $vid) {
            notify_vendor((int)$t['vendor_id'], "Tiket {$no} dialihkan ke vendor lain.", $id);
        }
        need(assign_vendor($id, $vid, $u['id']), $back, 'Vendor sudah mencapai limit workload di ' . $where . ' dan tidak bisa menerima pekerjaan baru.');
        flash('success', 'Vendor ditugaskan.');
        break;

    case 'auto_assign':
        need(has_role('admin', 'noc', 'procurement') && $st === 'open', $back);
        $vid = auto_assign($id, $u['id']);
        $tRegion2 = region_name(city_region_id((int)($t['city_id'] ?? 0)));
        flash($vid ? 'success' : 'warning', $vid ? 'Vendor berhasil ditugaskan otomatis.'
            : 'Masih belum ada vendor available di ' . ($tRegion2 !== '-' ? 'region ' . $tRegion2 : 'kota ' . ($t['city_name'] ?? '-')) . '.');
        break;

    case 'cancel':
        need(has_role('admin', 'noc') && !in_array($st, ['closed', 'cancelled'], true), $back);
        $reason = trim($_POST['reason'] ?? '');
        need($reason !== '', $back, 'Alasan wajib diisi.');
        q("UPDATE tickets SET status='cancelled', work_finished_at=COALESCE(work_finished_at, NOW()), sla_status=NULL WHERE id=?", [$id]);
        log_ticket($id, 'cancelled', 'Tiket dibatalkan: ' . $reason);
        if ($t['vendor_id']) notify_vendor((int)$t['vendor_id'], "Tiket {$no} dibatalkan: {$reason}", $id);
        noc_notify($id, 'cancelled', ['reason' => $reason]);
        flash('info', 'Tiket dibatalkan. Material yang sudah keluar tetap tercatat; lakukan penyesuaian stok jika perlu.');
        break;

    default:
        fail('Aksi tidak dikenal.', $back);
}

db()->commit();
redirect($back);
