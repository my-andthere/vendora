<?php
function renderTicketTable(array $rows, string $empty = 'Tidak ada data.'): void
{
    if (!$rows) {
        echo '<div class="p-4 text-center text-muted">' . e($empty) . '</div>';
        return;
    }
    ?>
    <table class="table table-hover mb-0 small">
        <thead class="table-light">
        <tr>
            <th>No Tiket</th><th>Kota</th><th>Masalah</th><th>Prioritas</th><th>Vendor</th>
            <th>Status</th><th>SLA</th><th>Dibuat</th>
        </tr>
        </thead>
        <tbody>
        <?php foreach ($rows as $t): ?>
            <tr style="cursor:pointer" onclick="location.href='<?= url('ticket_view.php?id=' . $t['id']) ?>'">
                <td class="fw-semibold"><a href="<?= url('ticket_view.php?id=' . $t['id']) ?>"><?= e($t['ticket_no']) ?></a>
                    <?php if ($t['noc_ref']): ?><div class="text-muted">NOC: <?= e($t['noc_ref']) ?></div><?php endif; ?></td>
                <td><?= e($t['city_name']) ?></td>
                <td><?= e($t['problem_type']) ?><div class="text-muted text-truncate" style="max-width:220px"><?= e($t['location']) ?></div></td>
                <td><?= priority_badge($t['priority']) ?></td>
                <td><?= $t['vendor_name'] ? e($t['vendor_name']) : '<span class="text-muted">-</span>' ?>
                    <?php if ($t['accepted_at']): ?><div class="text-muted" style="font-size:.7rem"><i class="bi bi-check2-circle text-success"></i> Diterima <?= fmt_dt($t['accepted_at']) ?></div>
                    <?php elseif ($t['assigned_at']): ?><div class="text-muted" style="font-size:.7rem">Belum diterima</div><?php endif; ?></td>
                <td><?= status_badge($t['status']) ?>
                    <?php if ($t['inv_result'] === 'tidak_sesuai'): ?><span class="badge bg-danger-subtle text-danger" title="Material tidak sesuai">Material ≠</span><?php endif; ?></td>
                <td><?= in_array($t['status'], ['cancelled'], true) ? '-' : sla_badge($t) ?>
                    <div class="mt-1"><?= work_time_badge($t) ?></div></td>
                <td><?= fmt_dt($t['created_at']) ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <?php
}
