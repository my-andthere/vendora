<?php
/**
 * Helper alur kerja Vendor -> Inventory -> FOC (per 2026-11-20).
 *
 * ALUR BARU:
 *   1. Vendor mengisi form laporan (dokumentasi, penyebab, tindakan, material).
 *   2. Tiket masuk status 'inventory_check' - Inventory reviewing form vendor
 *      (read-only, isi vendor tidak boleh diubah) lalu memberi CHECKLIST + KETERANGAN
 *      di samping tiap material. SEMUA material wajib dicentang, kalau tidak tiket
 *      tidak bisa diteruskan ke FOC.
 *   3. Tiket masuk status 'foc_review' - FOC menerima form yang sudah "done" dari
 *      Inventory. Isi form vendor tidak bisa diedit FOC. FOC memberi CHECKLIST +
 *      KETERANGAN di samping tiap kolom dokumentasi, kolom tindakan yang dilakukan,
 *      dan kolom detail penyebab gangguan. SEMUA item wajib dicentang, kalau tidak
 *      link up (tahap selanjutnya) tidak bisa dilakukan.
 *
 * Penyimpanan memakai tabel `ticket_signoffs` dengan stage baru:
 *   - stage 'inventory' : ceklis + keterangan per material   (diisi Inventory)
 *   - stage 'foc'       : ceklis + keterangan per item FOC   (diisi FOC)
 */

require_once __DIR__ . '/bootstrap.php';

// ------------------------------------------------------------
// Konstanta alur baru
// ------------------------------------------------------------

/** Stage tanda check: ceklis material oleh Inventory. */
const WF_STAGE_INVENTORY = 'inventory';

/** Stage tanda check: ceklis evaluation oleh FOC. */
const WF_STAGE_FOC = 'foc';

/** ref_id scope 'penyelesaian' untuk "Detail Penyebab Gangguan" (stage FOC). */
const WF_REF_ROOT_CAUSE = 1;

/** ref_id scope 'penyelesaian' untuk "Detail Tindakan yang Dilakukan" (stage FOC). */
const WF_REF_ACTION_TAKEN = 2;

/** Role yang boleh melakukan evaluation / link up FOC. */
const WF_FOC_ROLES = ['foc', 'nam', 'noc', 'admin'];

/** Role yang boleh melakukan cek material (Inventory). */
const WF_INVENTORY_ROLES = ['inventory', 'admin'];

/**
 * Status tiket setelah vendor mengirim laporan.
 * Alur baru: Vendor -> Inventory -> FOC, jadi form vendor SELALU masuk ke Inventory dulu.
 */
const WF_STATUS_AFTER_VENDOR = 'inventory_check';

// ------------------------------------------------------------
// Status alur
// ------------------------------------------------------------

/**
 * Apakah form sudah selesai dicek Inventory?
 * Tiket baru dianggap "done" dari Inventory setelah inv_result terisi.
 */
function inventory_done(array $t): bool
{
    return !empty($t['inv_result']);
}

/**
 * Apakah user boleh melakukan evaluation / link up FOC pada tiket ini?
 * Syarat: tiket di tahap review FOC DAN form sudah done dari Inventory.
 */
function can_foc_review(array $u, array $t): bool
{
    return in_array($u['role'] ?? '', WF_FOC_ROLES, true)
        && ($t['status'] ?? '') === 'foc_review'
        && inventory_done($t);
}

/**
 * Apakah user boleh mengisi checklist material (Inventory) pada tiket ini?
 */
function can_inventory_check(array $u, array $t): bool
{
    return in_array($u['role'] ?? '', WF_INVENTORY_ROLES, true)
        && ($t['status'] ?? '') === 'inventory_check';
}

// ------------------------------------------------------------
// Checklist material (Inventory)
// ------------------------------------------------------------

/**
 * Checklist material untuk satu tiket (1 baris = 1 material).
 * @return array<int,array{label:string,checked:bool,note:?string,by:?string,at:?string}>
 *         indeks = id baris ticket_materials
 */
function inventory_checklist(int $ticketId): array
{
    $rows = q('SELECT tm.id, m.code, m.name FROM ticket_materials tm JOIN materials m ON m.id=tm.material_id
               WHERE tm.ticket_id=? ORDER BY tm.id', [$ticketId])->fetchAll();
    $all = ticket_signoffs($ticketId);
    $out = [];
    foreach ($rows as $r) {
        $so = $all['material'][(int)$r['id']][WF_STAGE_INVENTORY] ?? null;
        $out[(int)$r['id']] = [
            'label'   => $r['code'] . ' - ' . $r['name'],
            'checked' => (bool)$so && ($so['status'] ?? 'pending') !== 'pending',
            'note'    => $so['note'] ?? null,
            'by'      => $so['checked_by'] ?? null,
            'at'      => $so['checked_at'] ?? null,
        ];
    }
    return $out;
}

/**
 * Material yang BELUM dicentang Inventory.
 * @return array<int,string> label material
 */
function inventory_pending_labels(int $ticketId): array
{
    $out = [];
    foreach (inventory_checklist($ticketId) as $item) {
        if (!$item['checked']) $out[] = $item['label'];
    }
    return $out;
}

/** Simpan ceklis + keterangan material oleh Inventory. */
function save_inventory_check(int $ticketId, int $lineId, bool $checked, ?string $note, ?int $userId = null): void
{
    save_signoff($ticketId, 'material', $lineId, $checked ? 'ok' : 'pending', $note, $userId, WF_STAGE_INVENTORY);
}

// ------------------------------------------------------------
// Gate (validasi) - dipakai ticket_action.php & test
// ------------------------------------------------------------

/**
 * Validasi checklist material Inventory dari data POST.
 * Kalau ada material yang belum dicentang, form tidak boleh diteruskan ke FOC.
 *
 * @return array{ok:bool,missing:array<int,string>,items:array,checked:array,notes:array}
 */
function wf_inventory_check_gate(int $ticketId, array $post): array
{
    $items = inventory_checklist($ticketId);
    $checked = (array)($post['inv_check'] ?? []);
    $notes = (array)($post['inv_note'] ?? []);
    $missing = [];
    foreach ($items as $lineId => $item) {
        if (empty($checked[$lineId])) $missing[] = $item['label'];
    }
    return [
        'ok'      => !$missing,
        'missing' => $missing,
        'items'   => $items,
        'checked' => $checked,
        'notes'   => $notes,
    ];
}

/**
 * Simpan checklist material (checkbox + keterangan) dari hasil gate.
 * @return int jumlah item yang disimpan
 */
function wf_save_inventory_checks(int $ticketId, array $gate, ?int $userId = null): int
{
    $n = 0;
    foreach ($gate['items'] as $lineId => $item) {
        $note = trim((string)($gate['notes'][$lineId] ?? ''));
        save_inventory_check($ticketId, (int)$lineId, !empty($gate['checked'][$lineId]), $note ?: null, $userId);
        $n++;
    }
    return $n;
}

// ------------------------------------------------------------
// Checklist evaluation FOC
// ------------------------------------------------------------

/**
 * Daftar item yang wajib dicentang FOC:
 *  - satu item per kategori dokumentasi foto yang punya foto
 *  - Detail Penyebab Gangguan
 *  - Detail Tindakan yang Dilakukan
 *
 * @return array<int,array{key:string,scope:string,ref_id:int,label:string,group:string,
 *                         checked:bool,note:?string,by:?string,at:?string}>
 */
function foc_checklist(int $ticketId): array
{
    $photos = q('SELECT id, category FROM ticket_photos WHERE ticket_id=? ORDER BY id', [$ticketId])->fetchAll();
    $all = ticket_signoffs($ticketId);
    $items = [];

    foreach (PHOTO_CATEGORIES as $cat => $label) {
        $firstId = null;
        foreach ($photos as $p) {
            if ($p['category'] === $cat) { $firstId = (int)$p['id']; break; }
        }
        if ($firstId === null) continue; // kategori tanpa foto -> tidak perlu dicek
        $so = $all['dokumentasi'][$firstId][WF_STAGE_FOC] ?? null;
        $items[] = [
            'key'     => 'doc_' . $cat,
            'scope'   => 'dokumentasi',
            'ref_id'  => $firstId,
            'label'   => $label,
            'group'   => 'dokumentasi',
            'checked' => (bool)$so && ($so['status'] ?? 'pending') !== 'pending',
            'note'    => $so['note'] ?? null,
            'by'      => $so['checked_by'] ?? null,
            'at'      => $so['checked_at'] ?? null,
        ];
    }

    foreach ([
        [WF_REF_ROOT_CAUSE, 'root_cause', 'Detail Penyebab Gangguan'],
        [WF_REF_ACTION_TAKEN, 'action_taken', 'Detail Tindakan yang Dilakukan'],
    ] as [$refId, $key, $label]) {
        $so = $all['penyelesaian'][$refId][WF_STAGE_FOC] ?? null;
        $items[] = [
            'key'     => $key,
            'scope'   => 'penyelesaian',
            'ref_id'  => $refId,
            'label'   => $label,
            'group'   => 'penyelesaian',
            'checked' => (bool)$so && ($so['status'] ?? 'pending') !== 'pending',
            'note'    => $so['note'] ?? null,
            'by'      => $so['checked_by'] ?? null,
            'at'      => $so['checked_at'] ?? null,
        ];
    }

    return $items;
}

/**
 * Item FOC yang belum dicentang.
 * @return array<int,string> label item
 */
function foc_pending_labels(int $ticketId): array
{
    $out = [];
    foreach (foc_checklist($ticketId) as $it) {
        if (!$it['checked']) $out[] = $it['label'];
    }
    return $out;
}

/** Simpan ceklis + keterangan satu item FOC. */
function save_foc_check(int $ticketId, string $scope, int $refId, bool $checked, ?string $note, ?int $userId = null): void
{
    save_signoff($ticketId, $scope, $refId, $checked ? 'ok' : 'pending', $note, $userId, WF_STAGE_FOC);
}

/**
 * Validasi checklist FOC dari data POST.
 * Semua item (dokumentasi + penyebab + tindakan) wajib dicentang sebelum lanjut tahap.
 *
 * @return array{ok:bool,missing:array<int,string>,items:array,checked:array,notes:array}
 */
function wf_foc_check_gate(int $ticketId, array $post): array
{
    $items = foc_checklist($ticketId);
    $checked = (array)($post['foc_check'] ?? []);
    $notes = (array)($post['foc_note'] ?? []);
    $missing = [];
    foreach ($items as $item) {
        if (empty($checked[$item['key']])) $missing[] = $item['label'];
    }
    return [
        'ok'      => !$missing,
        'missing' => $missing,
        'items'   => $items,
        'checked' => $checked,
        'notes'   => $notes,
    ];
}

/**
 * Simpan checklist FOC (checkbox + keterangan) dari hasil gate.
 * @return int jumlah item yang disimpan
 */
function wf_save_foc_checks(int $ticketId, array $gate, ?int $userId = null): int
{
    $n = 0;
    foreach ($gate['items'] as $item) {
        $note = trim((string)($gate['notes'][$item['key']] ?? ''));
        save_foc_check($ticketId, $item['scope'], $item['ref_id'],
            !empty($gate['checked'][$item['key']]), $note ?: null, $userId);
        $n++;
    }
    return $n;
}

// ------------------------------------------------------------
// Aksi alur (dipakai ticket_action.php & test regresi)
// ------------------------------------------------------------

/**
 * Cek material oleh Inventory.
 *
 * Aturan:
 *  - SEMUA material wajib dicentang (kolom "Cek Inventory") + boleh ada keterangan.
 *  - Ada material yang belum dicentang -> form tidak bisa diteruskan ke FOC.
 *  - Form vendor (isi laporan) TIDAK diubah oleh Inventory.
 *
 * @return array{ok:bool,error?:string,result?:string,items?:int}
 */
function wf_run_inventory_check(int $ticketId, array $post, int $userId): array
{
    $gate = wf_inventory_check_gate($ticketId, $post);
    if (!$gate['ok']) {
        return ['ok' => false, 'error' => 'Material belum dicentang: ' . implode(', ', $gate['missing'])
            . '. Centang semua material (kolom Cek Inventory) sebelum diteruskan ke FOC.'];
    }

    $result = (string)($post['result'] ?? '');
    $note = trim((string)($post['note'] ?? ''));
    if (!in_array($result, ['sesuai', 'tidak_sesuai'], true)) {
        return ['ok' => false, 'error' => 'Hasil pengecekan tidak valid.'];
    }
    if ($result !== 'sesuai' && $note === '') {
        return ['ok' => false, 'error' => 'Catatan wajib diisi jika material tidak sesuai.'];
    }

    // Simpan checklist + keterangan per material.
    wf_save_inventory_checks($ticketId, $gate, $userId);

    // Terima kembalian material (jika ada).
    $lines = q('SELECT tm.*, m.name FROM ticket_materials tm JOIN materials m ON m.id=tm.material_id
                WHERE tm.ticket_id=? AND tm.qty_issued > 0 AND tm.qty_returned IS NULL AND tm.is_direct=0 FOR UPDATE',
        [$ticketId])->fetchAll();
    $returnedInput = (array)($post['returned'] ?? []);
    foreach ($lines as $l) {
        if (!isset($returnedInput[$l['id']]) || $returnedInput[$l['id']] === '') {
            return ['ok' => false, 'error' => 'Jumlah kembali untuk ' . $l['name'] . ' wajib diisi.'];
        }
        $ret = (float)$returnedInput[$l['id']];
        if ($ret < 0 || $ret > (float)$l['qty_issued']) {
            return ['ok' => false, 'error' => 'Jumlah kembali ' . $l['name'] . ' tidak boleh melebihi jumlah keluar.'];
        }
        q('UPDATE ticket_materials SET qty_returned=?, returned_by=?, returned_at=NOW() WHERE id=?', [$ret, $userId, $l['id']]);
        q('UPDATE materials SET stock = stock + ? WHERE id=?', [$ret, $l['material_id']]);
    }

    $t = get_ticket($ticketId);
    q('INSERT INTO inventory_checks (ticket_id, checked_by, result, note) VALUES (?,?,?,?)', [$ticketId, $userId, $result, $note]);
    q("UPDATE tickets SET inv_result=?, status='foc_review' WHERE id=?", [$result, $ticketId]);
    $label = $result === 'sesuai' ? 'SESUAI' : 'TIDAK SESUAI';
    log_ticket($ticketId, 'inventory_checked', "Cek material oleh inventory: {$label}" . ($note ? " - {$note}" : '')
        . ' - checklist ' . count($gate['items']) . ' material sudah dicentang.', $userId);
    notify_role('foc', "Tiket " . ($t['ticket_no'] ?? '') . " siap direview FOC (cek material: {$label}).", $ticketId);
    if ($result === 'tidak_sesuai' && !empty($t['vendor_id'])) {
        notify_vendor((int)$t['vendor_id'], "Tiket " . ($t['ticket_no'] ?? '') . ": pemakaian material dinyatakan TIDAK SESUAI oleh inventory.", $ticketId);
        notify_role('admin', "Tiket " . ($t['ticket_no'] ?? '') . ": material vendor " . ($t['vendor_name'] ?? '') . " TIDAK SESUAI.", $ticketId);
    }

    return ['ok' => true, 'result' => $result, 'note' => $note, 'items' => count($gate['items'])];
}

/**
 * Simpan ceklist FOC saja (tanpa link up) - boleh belum lengkap.
 * @return array{ok:bool,items?:int,missing?:int}
 */
function wf_run_foc_eval(int $ticketId, array $post, int $userId): array
{
    $gate = wf_foc_check_gate($ticketId, $post);
    wf_save_foc_checks($ticketId, $gate, $userId);
    log_ticket($ticketId, 'foc_eval', 'Ceklist FOC disimpan (' . count($gate['items']) . ' item, '
        . count($gate['missing']) . ' belum dicentang).', $userId);
    return ['ok' => true, 'items' => count($gate['items']), 'missing' => count($gate['missing'])];
}

/**
 * Link up FOC: PERMANEN (tiket ditutup + BASTO) atau TEMPORER (kembali ke vendor).
 *
 * Aturan:
 *  - Form harus sudah DONE dari Inventory (inv_result terisi).
 *  - SEMUA item ceklist FOC wajib dicentang, kalau tidak tidak bisa lanjut tahap.
 *
 * @return array{ok:bool,error?:string}
 */
function wf_run_foc_linkup(int $ticketId, array $post, int $userId, string $result): array
{
    if (!in_array($result, ['permanent', 'temporary'], true)) {
        return ['ok' => false, 'error' => 'Hasil link up tidak valid.'];
    }
    $t = get_ticket($ticketId);
    if (!inventory_done($t)) {
        return ['ok' => false, 'error' => 'Form belum selesai dicek Inventory. FOC hanya menerima form yang sudah DONE dari Inventory.'];
    }
    $gate = wf_foc_check_gate($ticketId, $post);
    if (!$gate['ok']) {
        return ['ok' => false, 'error' => 'Ceklist FOC belum lengkap: ' . implode(', ', $gate['missing'])
            . '. Centang semua item ceklist FOC sebelum melanjutkan ke tahap selanjutnya.'];
    }
    $note = trim((string)($post['note'] ?? ''));
    if ($result !== 'permanent' && $note === '') {
        return ['ok' => false, 'error' => 'Catatan wajib diisi jika perbaikan temporary (agar vendor tahu apa yang harus diperbaiki).'];
    }

    // Simpan ceklist FOC (checkbox + keterangan) sebelum menyatakan link up.
    wf_save_foc_checks($ticketId, $gate, $userId);

    q('INSERT INTO foc_reviews (ticket_id, reviewer_id, result, note) VALUES (?,?,?,?)', [$ticketId, $userId, $result, $note]);
    $no = $t['ticket_no'] ?? '';
    if ($result === 'permanent') {
        // Waktu pengerjaan BERHENTI di sini (tidak berhenti saat cek inventory / review FOC / revisi).
        // Tiket LANGSUNG ditutup + BASTO terbentuk otomatis dari form vendor.
        q("UPDATE tickets SET status='closed', repair_result='permanent',
            completed_at=COALESCE(completed_at, NOW()),
            work_finished_at=COALESCE(work_finished_at, NOW()) WHERE id=?", [$ticketId]);
        sla_sync_ticket($ticketId);
        $bastoNo = generate_basto($ticketId, $userId);
        $sla = sla_state(get_ticket($ticketId));
        $slaLabel = $sla['overdue'] ? 'LEWAT SLA' : 'SESUAI SLA';
        log_ticket($ticketId, 'foc_permanent', 'FOC/NOC: LINK UP PERMANEN. Tiket selesai - ' . $slaLabel . '.'
            . ($note ? " {$note}" : '') . ($bastoNo ? ' | BASTO ' . $bastoNo . ' terbentuk otomatis' : ''), $userId);
        notify_role('noc', "Tiket {$no} SELESAI - {$slaLabel}.", $ticketId);
        notify_role('procurement', "Tiket {$no} SELESAI - {$slaLabel}.", $ticketId);
        notify_role('foc', "Tiket {$no} SELESAI - {$slaLabel}.", $ticketId);
        noc_notify($ticketId, 'permanent_closed', ['sla' => $sla['verdict'], 'basto_no' => $bastoNo]);
        return ['ok' => true, 'sla' => $slaLabel, 'basto_no' => $bastoNo];
    }

    q("UPDATE tickets SET status='revision', repair_result='temporary', revision_count=revision_count+1 WHERE id=?", [$ticketId]);
    log_ticket($ticketId, 'foc_temporary', 'FOC/NOC: LINK UP TEMPORER, form dikembalikan ke vendor - ' . $note, $userId);
    if (!empty($t['vendor_id'])) {
        notify_vendor((int)$t['vendor_id'], "Tiket {$no} dikembalikan (TEMPORER): {$note}", $ticketId);
    }
    notify_role('noc', "Tiket {$no}: layanan pulih sementara (temporary), menunggu perbaikan permanent.", $ticketId);
    noc_notify($ticketId, 'temporary_fix', ['note' => $note]);
    return ['ok' => true, 'sla' => 'TEMPORER'];
}

// ------------------------------------------------------------
// Render helper (dipakai di ticket_view.php)
// ------------------------------------------------------------

/**
 * Sel ceklis + keterangan untuk satu item checklist.
 *
 * Saat $editable = true, input diikat ke form lain memakai atribut `form=`,
 * sehingga kotak ceklis bisa diletakkan DISAMPING kolomnya (tabel material /
 * kolom dokumentasi) sementara formsubmit-nya berada di panel aksi.
 *
 * @param string $formId  id form tujuan (atribut form="")
 * @param string $prefix  prefix nama field (inv_ / foc_)
 * @param string $key     kunci item (id baris material / doc_before / root_cause ...)
 */
function wf_check_cell(string $formId, string $prefix, string $key, bool $checked, ?string $note, bool $editable): string
{
    $id = 'wf_' . $prefix . '_' . preg_replace('/[^a-z0-9]+/i', '_', (string)$key);
    $cbName = $prefix . '_check[' . $key . ']';
    $ntName = $prefix . '_note[' . $key . ']';

    if ($editable) {
        $out  = '<div class="form-check mb-1">'
            . '<input class="form-check-input mt-0" type="checkbox" form="' . e($formId) . '" name="' . e($cbName) . '"'
            . ' id="' . e($id) . '" value="1"' . ($checked ? ' checked' : '') . '>'
            . '<label class="form-check-label small" for="' . e($id) . '">Sudah dicek</label></div>';
        $out .= '<input type="text" class="form-control form-control-sm" form="' . e($formId) . '"'
            . ' name="' . e($ntName) . '" value="' . e($note ?? '') . '" maxlength="255" placeholder="Keterangan">';
        return $out;
    }

    $out = $checked
        ? '<span class="badge bg-success"><i class="bi bi-check2-circle"></i> Sudah dicek</span>'
        : '<span class="badge bg-secondary"><i class="bi bi-hourglass"></i> Belum dicek</span>';
    if (!empty($note)) {
        $out .= '<div class="text-muted" style="font-size:.68rem">' . e($note) . '</div>';
    }
    return $out;
}
