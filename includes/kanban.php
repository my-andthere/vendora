<?php
/**
 * Kanban progres tiket - READ ONLY.
 * Board ini TIDAK bisa diedit / di-drag; seluruh isi diambil langsung dari
 * tabel `tickets` sehingga selalu menunjukkan progress yang sebenarnya.
 */

/**
 * Definisi lane board (status pengerjaan).
 * @return array<int,array{key:string,label:string,icon:string,cls:string,statuses:array<int,string>}>
 */
function kanban_lanes(): array
{
    return [
        [
            'key'      => 'progress',
            'label'    => 'On Progress',
            'icon'     => 'hourglass-split',
            'cls'      => 'primary',
            'statuses' => ['open', 'assigned', 'on_progress', 'revision'],
        ],
        [
            'key'      => 'linkup',
            'label'    => 'Link Up; On Progress',
            'icon'     => 'link-45deg',
            'cls'      => 'warning',
            'statuses' => ['inventory_check', 'foc_review'],
        ],
        [
            'key'      => 'done',
            'label'    => 'Done',
            'icon'     => 'check2-circle',
            'cls'      => 'success',
            'statuses' => ['closed', 'cancelled'],
        ],
    ];
}

/**
 * Tahap detail per status tiket (ditampilkan DI DALAM kartu kanban).
 * @return array{0:string,1:string} [label tahap, kelas bootstrap]
 */
function kanban_stage(array $t): array
{
    switch ($t['status'] ?? '') {
        case 'open':
            return ['Menunggu Vendor', 'secondary'];
        case 'assigned':
            return [!empty($t['accepted_at']) ? 'Diterima Vendor' : 'Menunggu Vendor Menerima', 'info'];
        case 'on_progress':
            return ['Dikerjakan', 'primary'];
        case 'revision':
            return ['Revisi ke-' . max(1, (int)($t['revision_count'] ?? 1)), 'danger'];
        case 'inventory_check':
            return ['Cek Inventory', 'warning'];
        case 'foc_review':
            return ['Review FOC', 'warning'];
        case 'closed':
            // Tiket closed bisa PERMANENT atau TEMPORER -> badge dibedakan.
            $rr = $t['repair_result'] ?? '';
            if ($rr === 'temporary') return ['Selesai Temporary', 'warning'];
            if ($rr === 'permanent') return ['Selesai Permanent', 'success'];
            return ['Selesai', 'secondary'];
        case 'cancelled':
            return ['Dibatalkan', 'dark'];
    }
    return [ucfirst((string)($t['status'] ?? '-')), 'secondary'];
}

/** Tanggal panjang Indonesia: 18 November 2026 */
function kanban_tanggal(?string $dt): string
{
    if (!$dt) return '-';
    $bulan = [1 => 'Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni',
              'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember'];
    $ts = strtotime($dt);
    if (!$ts) return '-';
    return date('d', $ts) . ' ' . $bulan[(int)date('n', $ts)] . ' ' . date('Y', $ts);
}

/** Jam: 14:34 */
function kanban_jam(?string $dt): string
{
    return $dt ? date('H:i', strtotime($dt)) : '-';
}

/**
 * Ambil data kanban per lane.
 * @return array<string,array{total:int,byStatus:array<string,int>,byResult:array<string,array<string,int>>,cards:array}>
 */
function kanban_data(string $where, array $params, int $perCol = 12): array
{
    $join = ' FROM tickets t
              JOIN cities c ON c.id=t.city_id
              LEFT JOIN regions r ON r.id=c.region_id
              LEFT JOIN vendors v ON v.id=t.vendor_id ';

    $data = [];
    foreach (kanban_lanes() as $lane) {
        $data[$lane['key']] = ['total' => 0, 'byStatus' => [], 'byResult' => [], 'cards' => []];
    }

    // total per status + per hasil perbaikan (dipakai untuk angka di header lane)
    foreach (q("SELECT t.status, t.repair_result, COUNT(*) n $join WHERE $where GROUP BY t.status, t.repair_result", $params)->fetchAll() as $row) {
        foreach (kanban_lanes() as $lane) {
            if (in_array($row['status'], $lane['statuses'], true)) {
                $data[$lane['key']]['total'] += (int)$row['n'];
                $data[$lane['key']]['byStatus'][$row['status']] = ($data[$lane['key']]['byStatus'][$row['status']] ?? 0) + (int)$row['n'];
                $rr = $row['repair_result'] ?: 'null';
                $data[$lane['key']]['byResult'][$row['status']][$rr] = ($data[$lane['key']]['byResult'][$row['status']][$rr] ?? 0) + (int)$row['n'];
            }
        }
    }

    // kartu per lane
    foreach (kanban_lanes() as $lane) {
        $ph = implode(',', array_fill(0, count($lane['statuses']), '?'));
        $order = ($lane['key'] === 'done')
            ? 't.id DESC'
            : "FIELD(t.priority,'critical','high','medium','low'), t.sla_deadline IS NULL, t.sla_deadline ASC, t.id DESC";
        $rows = q("SELECT t.*, c.name city_name, r.name region_name, v.name vendor_name $join
                   WHERE $where AND t.status IN ($ph) ORDER BY $order LIMIT " . (int)$perCol,
                   [...$params, ...$lane['statuses']])->fetchAll();
        $data[$lane['key']]['cards'] = $rows;
    }
    return $data;
}

/** Sisa waktu SLA (menit) - negatif berarti sudah lewat */
function kanban_sla_sisa(array $t): ?int
{
    if (empty($t['sla_deadline'])) return null;
    if (in_array($t['status'] ?? '', ['closed', 'cancelled'], true)) return null;
    return (int)floor((strtotime($t['sla_deadline']) - time()) / 60);
}

/** Render satu kartu kanban */
function render_kanban_card(array $t): void
{
    [$stage, $cls] = kanban_stage($t);
    $sisa = kanban_sla_sisa($t);
    $sla = sla_state($t);
    $late = $sla['verdict'] === 'late';
    $prio = PRIORITIES[$t['priority'] ?? ''] ?? '-';
    $prioCls = ['critical' => 'danger', 'high' => 'warning', 'medium' => 'info', 'low' => 'secondary'][$t['priority'] ?? ''] ?? 'secondary';
    $mulai = $t['assigned_at'] ?: $t['reported_at'] ?: $t['created_at'];
    ?>
    <a class="kcard kcard-<?= $cls ?>" href="<?= url('ticket_view.php?id=' . (int)$t['id']) ?>">
        <div class="d-flex justify-content-between align-items-start gap-1">
            <span class="kcard-code"><?= e($t['ticket_no']) ?></span>
            <span class="badge bg-<?= $prioCls ?>"><?= e($prio) ?></span>
        </div>
        <div class="kcard-date"><?= e(kanban_tanggal($t['reported_at'] ?: $t['created_at'])) ?></div>
        <div class="kcard-time">
            Mulai = <?= e(kanban_jam($mulai)) ?>, Batas SLA = <?= e(kanban_jam($t['sla_deadline'])) ?>
        </div>
        <div class="kcard-stage kcard-stage-<?= $cls ?>"><?= e($stage) ?></div>
        <div class="kcard-vendor"><?= e($t['vendor_name'] ?: 'Belum ada vendor') ?>
            <?php if (($t['vendor_type'] ?? '') === 'ms'): ?><span class="badge bg-primary text-dark" style="font-size:.58rem">MS</span><?php endif; ?>
        </div>
        <div class="kcard-meta">
            <?= e($t['city_name']) ?><?= $t['region_name'] ? ' · ' . e($t['region_name']) : '' ?>
        </div>
        <div class="kcard-workrow"><span class="kcard-meta">Waktu pengerjaan</span> <?= work_time_badge($t) ?></div>
        <?php if ($t['accepted_at']): ?>
            <div class="kcard-meta"><i class="bi bi-check2-circle text-success"></i> Diterima <?= e(kanban_tanggal($t['accepted_at'])) ?> <?= e(kanban_jam($t['accepted_at'])) ?></div>
        <?php elseif ($t['assigned_at']): ?>
            <div class="kcard-meta fst-italic">Belum diterima vendor</div>
        <?php endif; ?>
        <?php if ($late): ?>
            <span class="badge bg-danger mt-1">LEWAT SLA</span>
        <?php elseif ($sisa !== null): ?>
            <span class="badge bg-<?= $sisa <= 30 ? 'warning' : 'light' ?> text-<?= $sisa <= 30 ? 'dark' : 'muted' ?> border mt-1">
                sisa <?= e(fmt_duration($sisa)) ?>
            </span>
        <?php elseif ($sla['final']): ?>
            <span class="badge bg-success mt-1">SESUAI SLA</span>
        <?php endif; ?>
    </a>
    <?php
}

/**
 * Render blok kanban lengkap (board + filter region + toggle "tampilkan yang selesai").
 * Dipakai bersama oleh dashboard Admin dan dashboard Procurement.
 * @param string $where  WHERE untuk tabel tickets (alias t & c)
 * @param array  $params parameter untuk $where
 * @param array  $opts   ['per_col'=>int,'regions'=>array]
 */
function render_kanban_section(string $where, array $params, array $opts = []): void
{
    $regionId = (int)($_GET['kregion'] ?? 0);
    $showClosed = !isset($_GET['kselesai']) || $_GET['kselesai'] === '1';
    $regions = $opts['regions'] ?? q('SELECT * FROM regions ORDER BY name')->fetchAll();
    $perCol = (int)($opts['per_col'] ?? 12);

    $w = $where;
    $p = $params;
    if ($regionId) {
        $w .= ' AND c.region_id=?';
        $p[] = $regionId;
    }
    $data = kanban_data($w, $p, $perCol);
    render_kanban($data, [
        'per_col'     => $perCol,
        'regions'     => $regions,
        'region_id'   => $regionId,
        'show_closed' => $showClosed,
    ]);
}

/**
 * Render board kanban.
 * @param array $data  hasil kanban_data()
 * @param array $opts  ['per_col'=>int,'query'=>string,'region_id'=>int,'regions'=>array,'show_closed'=>bool]
 */
function render_kanban(array $data, array $opts = []): void
{
    $perCol = (int)($opts['per_col'] ?? 12);
    $query = (string)($opts['query'] ?? '');
    $regions = $opts['regions'] ?? [];
    $regionId = (int)($opts['region_id'] ?? 0);
    $showClosed = (bool)($opts['show_closed'] ?? true);
    ?>
    <div class="card mb-4">
        <div class="card-header bg-white d-flex justify-content-between align-items-center flex-wrap gap-2">
            <div class="fw-semibold">
                <i class="bi bi-kanban text-primary"></i> Kanban Progres Tiket
                <span class="badge bg-light text-muted border fw-normal ms-1"><i class="bi bi-lock-fill"></i> Read-only &middot; progress real-time</span>
            </div>
            <form method="get" class="d-flex gap-2 align-items-center flex-wrap">
                <select name="kregion" class="form-select form-select-sm" style="width:auto" onchange="this.form.submit()">
                    <option value="">Semua region</option>
                    <?php foreach ($regions as $r): ?>
                        <option value="<?= (int)$r['id'] ?>" <?= $regionId === (int)$r['id'] ? 'selected' : '' ?>><?= e($r['name']) ?></option>
                    <?php endforeach; ?>
                </select>
                <div class="form-check form-switch m-0">
                    <input class="form-check-input" type="checkbox" name="kselesai" value="1" id="kselesai" <?= $showClosed ? 'checked' : '' ?> onchange="this.form.submit()">
                    <label class="form-check-label small" for="kselesai">Tampilkan yang selesai</label>
                </div>
                <noscript><button class="btn btn-sm btn-outline-secondary">Filter</button></noscript>
            </form>
        </div>
        <div class="card-body p-0">
            <div class="kboard">
                <?php foreach (kanban_lanes() as $lane):
                    $key = $lane['key'];
                    $col = $data[$key] ?? ['total' => 0, 'byStatus' => [], 'cards' => []];
                    if (!$showClosed && $key === 'done') {
                        echo '<div class="klane klane-hidden"><div class="klane-head"><i class="bi bi-'
                            . e($lane['icon']) . '"></i> ' . e($lane['label'])
                            . ' <span class="badge rounded-pill bg-secondary ms-auto">' . (int)$col['total'] . '</span></div>'
                            . '<div class="klane-body"><div class="kempty">Disembunyikan</div></div></div>';
                        continue;
                    }
                    $stageChips = '';
                    foreach ($lane['statuses'] as $st) {
                        if (empty($col['byStatus'][$st])) continue;
                        $byRes = $col['byResult'][$st] ?? [];
                        if ($st === 'closed') {
                            // Tiket selesai dibedakan: PERMANENT vs TEMPORER.
                            foreach (['permanent' => 'Selesai Permanent', 'temporary' => 'Selesai Temporary', 'null' => 'Selesai'] as $rr => $rLabel) {
                                if (empty($byRes[$rr])) continue;
                                $href = $rr === 'null'
                                    ? url('tickets.php?status=closed')
                                    : url('tickets.php?status=closed&repair=' . $rr);
                                $stageChips .= '<a class="klane-chip" href="' . $href . '">'
                                    . e($rLabel) . ' <b>' . (int)$byRes[$rr] . '</b></a>';
                            }
                            continue;
                        }
                        [$sLabel] = kanban_stage(['status' => $st]);
                        $stageChips .= '<a class="klane-chip" href="' . url('tickets.php?status=' . urlencode($st)) . '">'
                            . e($sLabel) . ' <b>' . (int)$col['byStatus'][$st] . '</b></a>';
                    }
                    ?>
                    <div class="klane klane-<?= e($lane['cls']) ?>">
                        <div class="klane-head">
                            <i class="bi bi-<?= e($lane['icon']) ?>"></i> <?= e($lane['label']) ?>
                            <span class="badge rounded-pill bg-secondary ms-auto"><?= (int)$col['total'] ?></span>
                        </div>
                        <?php if ($stageChips): ?>
                            <div class="klane-stages"><?= $stageChips ?></div>
                        <?php endif; ?>
                        <div class="klane-body">
                            <?php foreach ($col['cards'] as $t) render_kanban_card($t); ?>
                            <?php if (!$col['cards']): ?>
                                <div class="kempty"><i class="bi bi-inbox"></i><br>Tidak ada tiket</div>
                            <?php endif; ?>
                            <?php if ((int)$col['total'] > count($col['cards'])): ?>
                                <a class="kmore" href="<?= url('tickets.php') ?>">
                                    +<?= (int)$col['total'] - count($col['cards']) ?> tiket lainnya
                                </a>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
    <?php
}
