<?php
// ============================================================
// Konfigurasi Aplikasi
// ============================================================
date_default_timezone_set('Asia/Jakarta');

define('DB_HOST', 'localhost');
// Bisa dioverride lewat env var PROC_DB_NAME (berguna untuk tes installer di DB terpisah)
define('DB_NAME', getenv('PROC_DB_NAME') ?: 'procurement_db');
define('DB_USER', 'root');
define('DB_PASS', '');

define('APP_NAME', 'Procurement Field Service');
define('BASE_URL', '/procurement');           // sesuaikan jika nama folder berubah

// SLA pengerjaan vendor (menit). 180 = 3 jam
define('SLA_MINUTES', 180);
// Kapan SLA mulai dihitung: 'assigned' (saat vendor ditunjuk) atau 'accepted' (saat vendor menerima tiket)
define('SLA_START', 'assigned');

// Batas default jumlah pekerjaan aktif yang boleh dipegang satu vendor (0 = tanpa batas)
define('DEFAULT_WORKLOAD_LIMIT', 5);

// Integrasi NOC
define('NOC_API_KEY', 'GANTI-API-KEY-NOC-INI');   // dipakai sistem NOC saat memanggil api/tickets.php
define('NOC_WEBHOOK_URL', '');                    // URL milik sistem NOC untuk menerima notifikasi (kosong = hanya disimpan di outbox)

// Upload foto
define('UPLOAD_DIR', __DIR__ . '/uploads');
define('MAX_UPLOAD_MB', 5);
