<?php
/**
 * Form pembuatan tiket oleh NOC (manual).
 * Nantinya tiket juga bisa masuk otomatis dari sistem NOC via api/tickets.php.
 */
require_once __DIR__ . '/includes/bootstrap.php';
$u = require_role('admin', 'noc', 'procurement');
$cities = q("SELECT c.*, (SELECT COUNT(*) FROM vendors v WHERE v.city_id=c.id AND v.status='available') avail FROM cities c ORDER BY name")->fetchAll();
$errors = [];
$d = $_POST + ['noc_ref' => '', 'city_id' => '', 'customer_name' => '', 'customer_id' => '', 'location' => '', 'latitude' => '',
        'longitude' => '', 'province' => '', 'activity_type' => '', 'problem_type' => '', 'description' => '',
        'priority' => 'medium', 'reported_at' => date('Y-m-d\TH:i')];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    if (!q('SELECT 1 FROM cities WHERE id=?', [(int)$d['city_id']])->fetch()) $errors[] = 'Kota wajib dipilih.';
    if (trim($d['problem_type']) === '') $errors[] = 'Jenis masalah wajib diisi.';
    if (trim($d['location']) === '') $errors[] = 'Lokasi wajib diisi.';
    if (!isset(PRIORITIES[$d['priority']])) $errors[] = 'Prioritas tidak valid.';
    if ($d['noc_ref'] !== '' && q('SELECT 1 FROM tickets WHERE noc_ref=?', [$d['noc_ref']])->fetch()) $errors[] = 'Nomor referensi NOC sudah pernah dibuat.';
    // Tanggal wajib format valid; input sampah tidak boleh sampai ke database.
    $rawReported = trim((string)$d['reported_at']);
    if ($rawReported !== '' && parse_dt($rawReported) === null) $errors[] = 'Waktu Gangguan Dilaporkan tidak valid (contoh: 2026-11-22 10:30).';
    if (!$errors) {
        $d['reported_at'] = parse_dt($rawReported);
        db()->beginTransaction();
        $res = create_ticket($d, 'manual', $u['id']);
        db()->commit();
        flash($res['vendor_id'] ? 'success' : 'warning', $res['vendor_id']
            ? "Tiket {$res['ticket_no']} dibuat dan otomatis ditugaskan ke vendor di kota yang sama."
            : "Tiket {$res['ticket_no']} dibuat, tetapi TIDAK ADA vendor available di kota tersebut. Silakan tunjuk manual.");
        redirect('ticket_view.php?id=' . $res['id']);
    }
}
$pageTitle = 'Buat Tiket';
require __DIR__ . '/includes/header.php';
?>
<?= page_head('Buat Tiket Gangguan (NOC)', 'plus-circle', 'Pencatatan gangguan baru — vendor dan nomor SPK ditentukan otomatis') ?>
<?php if ($errors): ?><div class="alert alert-danger"><ul class="mb-0"><?php foreach ($errors as $er): ?><li><?= e($er) ?></li><?php endforeach; ?></ul></div><?php endif; ?>
<div class="alert alert-info small"><i class="bi bi-info-circle"></i> Setelah disimpan, sistem otomatis menunjuk vendor berstatus <b>Available</b> di kota yang sama
    (prioritas: <b>Vendor MS</b> dulu, lalu vendor dengan pekerjaan aktif paling sedikit).
    Nomor SPK dibuat otomatis. SLA pengerjaan: <b><?= fmt_duration(SLA_MINUTES) ?></b>.</div>
<form method="post" class="card"><div class="card-body">
    <?= csrf_field() ?>
    <div class="row g-3">
        <div class="col-md-4"><label class="form-label">No. Referensi NOC</label>
            <input name="noc_ref" class="form-control" value="<?= e($d['noc_ref']) ?>" placeholder="ID tiket dari sistem NOC (opsional)"></div>
        <div class="col-md-4"><label class="form-label">Kota <span class="text-danger">*</span></label>
            <select name="city_id" class="form-select" required>
                <option value="">- pilih kota -</option>
                <?php foreach ($cities as $c): ?>
                    <option value="<?= $c['id'] ?>" <?= $d['city_id'] == $c['id'] ? 'selected' : '' ?>><?= e($c['name']) ?> (<?= $c['avail'] ?> vendor available)</option>
                <?php endforeach; ?>
            </select></div>
        <div class="col-md-4"><label class="form-label">Prioritas</label>
            <select name="priority" class="form-select">
                <?php foreach (PRIORITIES as $k => $l): ?><option value="<?= $k ?>" <?= $d['priority'] === $k ? 'selected' : '' ?>><?= $l ?></option><?php endforeach; ?>
            </select></div>
        <div class="col-md-4"><label class="form-label">Nama Pelanggan / Site</label><input name="customer_name" class="form-control" value="<?= e($d['customer_name']) ?>"></div>
        <div class="col-md-4"><label class="form-label">ID Pelanggan / Site</label><input name="customer_id" class="form-control" value="<?= e($d['customer_id']) ?>"></div>
        <div class="col-md-4"><label class="form-label">Waktu Gangguan Dilaporkan</label><input type="datetime-local" name="reported_at" class="form-control" value="<?= e($d['reported_at']) ?>"></div>
        <div class="col-md-6"><label class="form-label">Jenis Masalah <span class="text-danger">*</span></label>
            <input name="problem_type" class="form-control" list="problemTypes" required value="<?= e($d['problem_type']) ?>">
            <datalist id="problemTypes"><option>Kabel FO putus</option><option>Redaman tinggi</option><option>ODP rusak</option><option>Closure rusak</option><option>Tiang roboh</option><option>LOS / No signal</option></datalist></div>
        <div class="col-md-4"><label class="form-label">Jenis Kegiatan</label>
            <input name="activity_type" class="form-control" list="activityTypes" value="<?= e($d['activity_type']) ?>" placeholder="mis. Troubleshooting">
            <datalist id="activityTypes"><option>Troubleshooting</option><option>Maintenance</option><option>Project</option><option>Instalasi</option><option>Aktivasi</option></datalist></div>
        <div class="col-md-4"><label class="form-label">Provinsi</label>
            <input name="province" class="form-control" value="<?= e($d['province']) ?>" placeholder="mis. DKI Jakarta"></div>
        <div class="col-md-3"><label class="form-label">Latitude</label><input name="latitude" class="form-control" value="<?= e($d['latitude']) ?>"></div>
        <div class="col-md-3"><label class="form-label">Longitude</label><input name="longitude" class="form-control" value="<?= e($d['longitude']) ?>"></div>
        <div class="col-12"><label class="form-label">Lokasi / Alamat <span class="text-danger">*</span></label><textarea name="location" class="form-control" rows="2" required><?= e($d['location']) ?></textarea></div>
        <div class="col-12"><label class="form-label">Deskripsi Masalah</label><textarea name="description" class="form-control" rows="3"><?= e($d['description']) ?></textarea></div>
    </div>
</div>
<div class="card-footer bg-white text-end">
    <a href="<?= url('tickets.php') ?>" class="btn btn-outline-secondary">Batal</a>
    <button class="btn btn-primary"><i class="bi bi-send"></i> Simpan & Tugaskan Vendor</button>
</div></form>
<?php require __DIR__ . '/includes/footer.php'; ?>
