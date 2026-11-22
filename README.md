# Procurement Field Service

PHP native + MySQL (XAMPP). Buka: http://localhost/procurement

## Jalankan Online (Docker + Cloudflare Tunnel)

Stack produksi sudah disiapkan di folder [`deploy/`](deploy/) — MariaDB 11 + Apache/PHP 8.3
(ekstensi `pdo_mysql`, `mysqli`, `gd`) + Cloudflare Tunnel untuk URL publik HTTPS.

```bash
cd deploy
./start.sh          # build container, jalankan DB+web, buat tunnel, cetak URL publik
./start.sh stop     # hentikan tunnel & container
./start.sh logs     # log container web
```

- **URL lokal**: http://localhost:8080
- **URL publik**: `https://<random>.trycloudflare.com` (dicetak oleh `start.sh`)
  - Quick tunnel memakai domain acak — berubah tiap container tunnel dibuat ulang.
  - Untuk URL tetap, pakai Cloudflare Named Tunnel (`cloudflared tunnel login` + `config.yml`).
- **Kredensial & API key** diatur di [`deploy/.env`](deploy/.env) (jangan di-commit).
- **Image Docker**: base image di-pin ke digest + versi paket `apt` di-pin
  ([`deploy/Dockerfile`](deploy/Dockerfile)) agar build reproducible dan bebas
  warning hadolint (`DL3008`).

File [.env](deploy/.env) yang tersedia:

| Variabel | Fungsi |
|----------|--------|
| `WEB_PORT` | Port host untuk Apache (default `8080`) |
| `DB_PASSWORD` / `DB_ROOT_PASSWORD` | Password MariaDB |
| `BASE_URL` | Path aplikasi: `''` (root) atau `/procurement` (subfolder) |
| `NOC_API_KEY` | Key header `X-API-KEY` untuk `/api/tickets.php` |

Instalasi database (destructive, `?force=1`/`--force` menghapus semua data):

```bash
cd deploy
docker compose exec -e DB_USER=root -e DB_PASS=root web php install.php --force
```

> `install.php` lewat browser hanya boleh dari `localhost`; dari container selalu jalankan via CLI.

### Perbedaan vs XAMPP

- `config.php` kini membaca nilai dari environment variable (`DB_HOST`, `DB_USER`, `DB_PASS`,
  `PROC_DB_NAME`, `BASE_URL`, `NOC_API_KEY`, `NOC_WEBHOOK_URL`) dengan fallback ke nilai lama —
  jadi instalasi XAMPP yang lama tetap jalan tanpa perubahan.
- Folder `uploads/` harus writable oleh user web server (`chmod 777 uploads`).

## Instalasi
1. Jalankan Apache & MySQL di XAMPP.
2. Buka http://localhost/procurement/install.php (sekali saja). Untuk reset total: `install.php?force=1`.
3. Login:

| Role        | Username   | Password         |
|-------------|------------|------------------|
| Admin       | admin      | admin123         |
| NOC         | noc        | noc123           |
| Procurement | procurement| procurement123   |
| Inventory   | inventory  | inventory123     |
| FOC         | foc        | foc123           |
| Vendor      | vendor1–4  | vendor123        |

Ganti semua password setelah dipakai sungguhan. Ganti juga `NOC_API_KEY` di `config.php`.

## Alur kerja
1. **NOC** membuat tiket (form atau API) → sistem otomatis menunjuk vendor **Available** yang melayani **region kota tiket**
   (yang pekerjaan aktifnya paling sedikit **dan belum mencapai limit workload di region tersebut**). Jika tidak ada, tiket berstatus *Menunggu Vendor* dan NOC/Admin menunjuk manual.
2. **Vendor** menerima (atau menolak → otomatis dialihkan ke vendor lain di region yang sama).
3. Vendor minta material → **Inventory** menyerahkan material (stok berkurang).
4. Vendor mengisi **form laporan** (bagian atas otomatis dari tiket NOC), dokumentasi foto (**Foto Tiang**, **Foto Sinyal**, **Foto OTDR**, **Foto Data Core** — Foto Tiang & Foto OTDR wajib), dan jumlah material terpakai.
   **Waktu Selesai Dikerjakan tidak diisi manual** — dicatat otomatis oleh sistem saat vendor menekan tombol **Kirim Laporan** (`ticket_reports.finished_at`).
   SLA (default 3 jam) dihitung sampai pekerjaan **PERMANENT** → *Sesuai SLA* / *Lewat SLA*. Lihat bagian [SLA](#sla-dihitung-sampai-permanent).
5. **Inventory** mengecek form vendor (lihat [Alur Vendor → Inventory → FOC](#alur-vendor--inventory--foc)):
   isi form vendor **read-only**, Inventory hanya memberi **checklist + keterangan di samping tiap material**.
   Semua material wajib dicentang, kalau tidak form **tidak bisa diteruskan ke FOC**.
6. **FOC** menerima form yang sudah **done** dari Inventory, lalu memberi **checklist + keterangan** di samping tiap kolom
   **dokumentasi**, kolom **tindakan yang dilakukan**, dan kolom **detail penyebab gangguan**.
   Semua item wajib dicentang, kalau tidak **link up tidak bisa dilakukan**.
   **Permanent** → tiket **langsung ditutup** & **BASTO otomatis terbentuk** (lihat [Alur Vendor MS](#alur-vendor-ms-managed-service)). **Temporary** → form dikembalikan ke vendor (revisi), NOC diberi notifikasi layanan pulih sementara.
7. Tiket yang **melewati SLA** hanya diberi **tanda** lewat badge `Lewat SLA` (merah) — tidak ada penandaan manual.

## Alur Vendor → Inventory → FOC
Perubahan 20 Nov 2026. Alur penilaian berlapis: **Vendor → Inventory → FOC**.

```
Vendor kirim laporan ──> inventory_check ──(semua material dicentang)──> foc_review ──(semua item FOC dicentang)──> closed + BASTO
                            │  (Inventory)                                  │  (FOC)
                            └─ form vendor READ-ONLY                       └─ form vendor READ-ONLY
```

### Aturan utama
| Aturan | Detail |
|---|---|
| Form vendor **selalu** masuk Inventory | `WF_STATUS_AFTER_VENDOR = 'inventory_check'` — berlaku untuk Vendor MS maupun non-MS. Tidak ada lagi lompat langsung ke FOC. |
| Inventory **tidak boleh mengubah** isi form vendor | Form hanya bisa dibuka & disimpan oleh role `vendor` (`vendor_report.php` → `require_role('vendor')`). Inventory/FOC hanya menambah checklist + keterangan. |
| Inventory wajib **centang semua material** | Kalau ada material yang belum dicentang → pesan: *"Material belum dicentang: … Centang semua material (kolom Cek Inventory) sebelum diteruskan ke FOC."* Tiket **tetap** di `inventory_check`. |
| FOC hanya menerima form **done** dari Inventory | Syarat: `tickets.inv_result` terisi. Kalau belum → *"Form belum selesai dicek Inventory. FOC hanya menerima form yang sudah DONE dari Inventory."* |
| FOC wajib **centang semua item** | Item = tiap kategori dokumentasi yang punya foto + *Detail Penyebab Gangguan* + *Detail Tindakan yang Dilakukan*. Kalau belum semua → *"Ceklist FOC belum lengkap: …"* Tiket **tetap** di `foc_review`. |

### Checklist & keterangan

| Tahap | Stage | Scope | Isi per item | Wajib dicentang |
|---|---|---|---|---|
| **Inventory** | `inventory` | `material` | 1 item per baris `ticket_materials` — checkbox + keterangan | ✅ semua material |
| **FOC** | `foc` | `dokumentasi` | 1 item per kategori foto (Foto Tiang / Sinyal / OTDR / Data Core) — checkbox + keterangan | ✅ semua kategori |
| **FOC** | `foc` | `penyelesaian` | `ref_id=1` → Detail Penyebab Gangguan · `ref_id=2` → Detail Tindakan yang Dilakukan | ✅ keduanya |

Penyimpanan memakai tabel `ticket_signoffs` dengan **stage** baru (`ENUM('region','inventory','cgs','foc')`).
Tanda check lama (`region` = PIC Region, `cgs` = Evaluasi CGS) tetap ada dan tidak berubah.

### Tampilan
- **Kolom `Cek Inventory`** di tabel *Material* (`ticket_view.php`) — checkbox + keterangan per material,
  editable hanya saat tiket berstatus `inventory_check` dan user punya role `inventory`/`admin`.
- **Kolom `Ceklist FOC`** di samping tiap kolom dokumentasi, *Detail Penyebab Gangguan*, dan *Detail Tindakan yang Dilakukan*.
- Tombol **`Simpan Ceklist FOC`** menyimpan checklist tanpa melakukan link up (boleh belum lengkap).
- Badge **`Read-only`** di header *Form Laporan Vendor* menandakan isi form tidak bisa diubah Inventory/FOC.
- Panel FOC menampilkan daftar item yang **belum dicentang** sebagai pengingat.

### Helper (di [includes/workflow.php](includes/workflow.php))
| Fungsi | Guna |
|---|---|
| `inventory_checklist($ticketId)` | Checklist material + status centang + keterangan. |
| `inventory_pending_labels($ticketId)` | Label material yang belum dicentang. |
| `foc_checklist($ticketId)` | Item checklist FOC (dokumentasi + penyebab + tindakan). |
| `foc_pending_labels($ticketId)` | Label item FOC yang belum dicentang. |
| `wf_inventory_check_gate($ticketId, $post)` | Validasi POST checklist material → `['ok','missing',...]`. |
| `wf_foc_check_gate($ticketId, $post)` | Validasi POST checklist FOC → `['ok','missing',...]`. |
| `wf_run_inventory_check($ticketId, $post, $userId)` | Aksi Inventory: simpan checklist, terima pengembalian, tulis `inventory_checks`, teruskan ke FOC. |
| `wf_run_foc_eval($ticketId, $post, $userId)` | Simpan checklist FOC saja (tanpa link up). |
| `wf_run_foc_linkup($ticketId, $post, $userId, $result)` | Aksi link up PERMANEN/TEMPORER — menolak bila form belum done atau checklist belum lengkap. |
| `inventory_done($t)` / `can_foc_review($u,$t)` / `can_inventory_check($u,$t)` | Syarat akses per tahap. |

## Status vendor (diatur manual oleh Admin di menu Vendor)
- **Available** – siap menerima kerja
- **Freeze** – tidak menerima kerja sampai tanggal tertentu (otomatis kembali Available setelah lewat)
- **Blacklist** – tidak menerima kerja selamanya

## Region (wilayah) & Limit Workload per region
Region mengelompokkan beberapa kota: **Central** = Jawa Barat + DKI Jakarta, **East** = Jawa Tengah + Jawa Timur, **West** = Sumatera.
Tabel terkait:
- `regions` — daftar wilayah (nama unik, kode, deskripsi).
- `cities.region_id` — kota masuk ke sebuah region (boleh NULL → kota tanpa region).
- `vendor_regions` — pasangan **vendor × region** beserta `workload_limit` (batas kerja aktif di region itu, `0` = tanpa batas).

Aturan:
- Satu vendor boleh terdaftar di **lebih dari satu region**, dan tiap pasangan punya **limit sendiri** (mis. CV Parahyangan Teknik: Central `5`, East `0`/tak terbatas) — karena tim yang mengerjakan tiap wilayah memang berbeda orang.
- Batas kerja dihitung dari tiket **kota → region**, jadi satu vendor dengan 2 region punya 2 kuota terpisah.
- Vendor **tidak bisa** ditugaskan tiket dari region yang tidak dilayaninya (penugasan manual ditolak, tidak muncul di daftar kandidat).
- Slot bebas kembali ketika pekerjaannya selesai (tiket `closed`/`cancelled`).

Pengelolaan (menu **Region**, khusus Admin):
- Tambah/hapus region, masuk-kelolakan kota ke region, dan daftar vendor per region beserta limit-nya.
- Kolom **Limit Workload** di menu Vendor menampilkan kapasitas **per region** (mis. `Central: 1/5 · sisa 4`, `East: 0/∞`); merah + badge *Penuh* saat kuota region habis.
- Tabel Vendor dikelompokkan **Region → Kota → Vendor** (sel merged di kolom No/Region/Kota). Vendor terdaftar di 2 region akan muncul di blok masing-masing, karena limit-nya berbeda per region.
- Filter tambahan di halaman Vendor: **Region**, **Kota**, **Status**.
- Kolom `vendors.workload_limit` kini hanya **cadangan** untuk kota yang belum punya region.
- Dashboard vendor menampilkan beban kerja per region masing-masing.

## Zona waktu region & kota
Setiap region dan kota punya **zona waktu operasional** agar jam kerja/timestamp ditampilkan sesuai wilayahnya.

| Label | Offset | Region |
|---|---|---|
| **WIB** | UTC+7 | **West** (Sumatera) |
| **WITA** | UTC+8 | **Central** |
| **WIT** | UTC+9 | **East** |

Tabel terkait:
- `regions.timezone` — zona waktu region (`WIB` / `WITA` / `WIT`), boleh NULL.
- `cities.timezone` — zona waktu kota, boleh NULL → otomatis mengikuti zona waktu region.

Aturan resolusi kota (berurutan): **kolom kota** → **lokasi geografis kota** → **zona waktu region** → fallback `WIB`.
Contoh pemetaan geografis: Jakarta/Bandung/Medan/Semarang/Pontianak → **WIB**; Surabaya/Makassar/Manado/Denpasar/Balikpapan → **WITA**; Ambon/Ternate/Jayapura → **WIT**.

Penyimpanan & tampilan:
- Semua waktu disimpan dalam waktu aplikasi (`Asia/Jakarta`); tampilan digeser sesuai offset zona tujuan lewat `fmt_dt_tz($dt, $tz)`.
- Detail tiket menampilkan baris **Zona Waktu** (waktu lokal kota) + badge zona waktu region.
- Halaman **Region** & **Kota** punya selector zona waktu (mode **Otomatis** memakai aturan di atas).
- Migrasi `migrate_schema()` di `includes/bootstrap.php` menambah kolom baru (bila belum ada) lalu mengisi zona waktu kosong secara idempoten — data lama ikut terisi tanpa menimpa pilihan manual admin.

## Integrasi NOC
**Masuk (NOC → Procurement)** — `api/tickets.php`, header `X-API-KEY`:
```
POST /procurement/api/tickets.php
{"noc_ref":"NOC-123","city":"Jakarta","problem_type":"Kabel FO putus","location":"Jl. ...",
 "description":"...","customer_name":"...","customer_id":"...","priority":"high",
 "reported_at":"2026-11-18 10:00:00","latitude":-6.2,"longitude":106.8}

GET /procurement/api/tickets.php?noc_ref=NOC-123
```
**Keluar (Procurement → NOC)** — setiap event dicatat di tabel `noc_outbox` (menu *Notifikasi ke NOC*).
Isi `NOC_WEBHOOK_URL` di `config.php` agar dikirim otomatis (POST JSON). Event:
`ticket_received, vendor_assigned, no_vendor_available, vendor_accepted, work_submitted, temporary_fix, permanent_closed, cancelled`.

## Pengaturan (`config.php`)
- `SLA_MINUTES` – batas SLA (menit), default 180
- `SLA_START` – `assigned` (SLA mulai saat vendor ditunjuk) atau `accepted` (saat vendor menerima)
- `DEFAULT_WORKLOAD_LIMIT` – limit workload default untuk vendor baru, default 5
- `NOC_API_KEY`, `NOC_WEBHOOK_URL`, `MAX_UPLOAD_MB`
- `PROC_DB_NAME` (env var, opsional) – nama database lain, berguna untuk tes installer di DB terpisah

## Technical Skill vendor
Setiap vendor punya daftar **Technical Skill** (kebpeteerian) yang dikuasai, dipilih saat input/edit vendor.
Tabel terkait:
- `skills` — master skill (nama unik + keterangan). Contoh bawaan: Aktivasi, Troubleshooting, Maintenance, Project, Instalasi, Jaringan, Survey Lokasi, Handling Keluhan.
- `vendor_skills` — relasi **vendor ↔ skill** (banyak-ke-banyak), 1 vendor bisa punya banyak skill.

Penggunaan:
- Kolom **Technical Skill** di menu Vendor menampilkan badge skill tiap vendor.
- Saat menambah/mengedit vendor, pilih satu atau lebih skill lewat checklist (semua skill yang sudah tercentang otomatis tersimpan ulang).
- Filter **Skill** di halaman Vendor untuk mencari vendor yang punya skill tertentu.
- Dropdown penugasan vendor di halaman Tiket menampilkan skill kandidat, memudahkan memilih vendor yang tepat.
- Skill yang sudah dipakai vendor tidak bisa dihapus dari Master Skill (hapus dari vendor dulu).

## Role Procurement
Role `procurement` (added 2026-11-19) untuk petugas procurement yang fokus pada **performa vendor & kepatuhan SLA**.

Hak akses:
- **Dashboard Procurement** (`procurement_dashboard.php`) — halaman khusus, contain **kanban progres tiket** + KPI SLA.
- **Tiket** — melihat semua tiket, buat tiket baru, menugaskan / auto-assign vendor.
- **Vendor** — **tambah & edit vendor** (nama, kota, PIC, kontak, alamat, limit cadangan, **region yang dilayani + limit workload per region**, technical skill).
- **Material** — read-only (lihat stok material).

TIDAK punya akses: manage User, manage Region/Kota, **ubah status vendor** (freeze/blacklist), tambah/hapus master skill, aksi Inventory & FOC.

> Form tambah/edit vendor punya section **Region Dilayani & Limit Workload** — checkbox per region + input limit. Jadi petugas procurement bisa mendaftarkan vendor ke region beserta kuotanya langsung dari form vendor, tanpa harus ke halaman Region.

Isi dashboard (`procurement_dashboard.php`):
- 4 KPI card — Tiket Aktif, Kepatuhan SLA (%), Tiket Lewat SLA (Berjalan), Material Tidak Sesuai.
- **Kanban Progres Tiket** — sama persis dengan dashboard Admin (read-only, filter region, toggle "tampilkan yang selesai").
- Tabel **Tiket yang perlu perhatian** — belum ada vendor atau sudah lewat SLA.
- Tabel **Beban Kerja & Performa SLA Vendor** — beban aktif, selesai, jumlah lewat SLA per vendor.
- Tabel **Rekap Tiket per Region** + **Material Stok Menipis** (stok ≤ 10).

Login setelah buat role ini: `procurement / procurement123`. Role ini juga otomatis masuk ke enum `users.role` lewat migrasi ringan (`migrate_schema()`), jadi tidak perlu ALTER manual.

Kode: [procurement_dashboard.php](procurement_dashboard.php), helper kanban reusable `render_kanban_section()` di [includes/kanban.php](includes/kanban.php).

## Kanban Progres Tiket (dashboard Admin)
Board kanban **read-only** di dashboard Admin yang menampilkan progress tiket yang sebenarnya (langsung dari tabel `tickets`).
Tidak bisa diedit, tidak bisa di-drag, tidak ada tombol ubah status — kartu hanya bisa diklik untuk membuka detail tiket.

### Status pengerjaan (3 lane)
Board memakai 3 status pengerjaan sebagai kolom utama:

| Lane (status pengerjaan) | Warna | Tiket yang masuk |
|---|---|---|
| **On Progress** | biru | `open`, `assigned`, `on_progress`, `revision` |
| **Link Up; On Progress** | oranye | `inventory_check`, `foc_review` |
| **Done** | hijau | `closed`, `cancelled` |

### Tahap detail (di dalam kanban)
Tahap yang lebih rinci **tidak lagi jadi kolom terpisah** dan tidak muncul di luar board — semuanya ditampilkan **di dalam kartu** dan di header lane:
- Badge tahap pada kartu: `Menunggu Vendor`, `Diterima Vendor` / `Menunggu Vendor Menerima`, `Dikerjakan`, `Revisi ke-N`, `Cek Inventory`, `Review FOC`, `Selesai Permanent`, `Dibatalkan`.
- Chip tahap pada header lane (dengan jumlah, bisa diklik ke Tiket terfilter status), contoh: `Menunggu Vendor 1 · Dikerjakan 1 · Revisi ke-1 1`.

Isi kartu (mengikuti status riil tiket):
- No tiket + badge prioritas
- Tanggal laporan (contoh: `18 November 2026`)
- `Mulai = 14:34, Batas SLA = 17:34`
- Badge tahap (di dalam kartu)
- Nama vendor + kota/region
- Badge sisa SLA, `LEWAT SLA` (merah) atau `SESUAI SLA` (hijau)

Kendali:
- Filter **Region** (`?kregion=<id>`).
- Toggle **Tampilkan yang selesai** (`?kselesai=0` menyembunyikan lane `Done`).
- Maksimal 12 kartu per lane; sisanya lewat link `+N tiket lainnya` ke menu Tiket.
- Hanya untuk role **admin** (role lain tidak menampilkan board).

Kode: [includes/kanban.php](includes/kanban.php) (`kanban_lanes()`, `kanban_stage()`, `kanban_data()`, `render_kanban()`), CSS di `assets/style.css`.

## SLA (dihitung sampai PERMANENT)
Mulai 19 Nov 2026 definisi SLA berubah: SLA **tidak lagi berhenti di laporan pertama**, tapi diukur sampai pekerjaan dinyatakan **PERMANENT** — sama seperti waktu pengerjaan.

- **Batas SLA** (`sla_deadline`) tetap dihitung dari penugasan vendor (`SLA_START='assigned'`, default `assigned_at` + `SLA_MINUTES`).
- **Verdict final** — sah setelah FOC menyatakan **PERMANENT**:
  - `work_finished_at <= sla_deadline` → **Sesuai SLA** (hijau)
  - `work_finished_at >  sla_deadline` → **Lewat SLA** (merah)
- **Tiket masih berjalan** — belum ada verdict final, tapi badge tetap **live**:
  - masih dalam deadline → `Sisa 1j 12m` (abu-abu, counter per detik)
  - sudah lewat deadline → `Lewat SLA · 16j 02m` (merah, counter per detik)
- **Tiket dibatalkan / tanpa deadline** → SLA tidak dinilai (`-`).

Helper di [includes/bootstrap.php](includes/bootstrap.php):
- `sla_state(array $t): array` — verdict (`ontime`/`late`/null), `final`, `overdue`, `remaining` (detik), `deadline`.
- `sla_case_sql(string $alias)` — ekspresi SQL `CASE` yang **identik** dengan `sla_state()`; dipakai filter/agregat (halaman Tiket, Dashboard, Vendor) supaya selalu nilai live.
- `sla_badge(array $t): string` — badge live; `sla_note(array $t): string` — penjelasan singkat.
- `sla_sync_ticket(int $id)` / `sla_resync()` — menyinkronkan kolom cache `tickets.sla_status` (dipakai untuk payload NOC/API).

Tampil di:
- **Header detail tiket** — badge SLA live.
- **Baris "Status SLA"** di detail tiket — badge + penjelasan ("Selesai PERMANENT 18/11/2026 17:07, sesuai batas SLA." / "Masih berjalan - sudah lewat batas SLA ...").
- **Kartu kanban** — `LEWAT SLA` (merah), `sisa 2j 30m`, atau `SESUAI SLA` (hijau).
- **Tabel Tiket** — kolom SLA + badge waktu pengerjaan.
- **Dashboard** — kartu *Kepatuhan SLA* menghitung **hanya tiket PERMANENT**, plus catatan jumlah tiket yang masih berjalan & sudah lewat SLA.
- **Filter SLA** di halaman Tiket — `Sesuai` / `Lewat` memakai ekspresi live.

> ⚠️ Perubahan definisi ini mengubah makna angka historis: `ontime`/`late` lama (yang berhenti di laporan pertama) dihitung ulang ke definisi PERMANENT. Kolom `tickets.sla_status` di-backfill otomatis oleh migrasi ringan.

## Waktu Pengerjaan (berhenti saat PERMANENT)
Sama seperti SLA (keduanya diukur sampai PERMANENT), **waktu pengerjaan** terus berjalan sampai tiket dinyatakan **PERMANENT**. Cara kerja:
- **Mulai** — saat tiket ditugaskan ke vendor (`assigned_at` → `tickets.work_started_at`). Kalau tiket dialihkan ke vendor lain, timer **tidak direset** (tetap total waktu dari penugasan pertama).
- **Berhenti** — hanya saat FOC menyatakan **PERMANENT** (`tickets.work_finished_at`). Tidak berhenti saat cek inventory, review FOC, maupun revisi/temporary — semuanya masih terhitung.
- **Pengecualian** — tiket yang **dibatalkan** juga membekukan timer (badge abu-abu), supaya tidak berjalan terus selamanya.

Kolom baru di tabel `tickets`: `work_started_at`, `work_finished_at` (dibuat otomatis oleh migrasi ringan + backfill data lama).

Tampil di:
- **Kartu kanban** — baris `Waktu pengerjaan ⏱ 1j 35m` dengan counter live per detik (biru = masih berjalan, hijau = permanent, abu-abu = dibatalkan).
- **Detail tiket** — baris "Waktu Pengerjaan" dengan penjelasan "berjalan terus sejak …, berhenti hanya saat PERMANENT".
- **Tabel Tiket** — badge di bawah SLA.

Format durasi: `2j 03m` / `57m 07d` / `45d` (jam / menit / detik).

## Waktu Vendor Menekan "Terima & Mulai Kerjakan"
Setiap kali vendor menekan tombol **Terima & Mulai Kerjakan**, waktu pressesnya dicatat di `tickets.accepted_at` dan ditampilkan di beberapa tempat:

- **Detail tiket** — baris `Diterima Vendor`: `✓ 18/11/2026 16:36 — vendor menekan tombol Terima & Mulai Kerjakan`. Kalau belum, tertulis *"Belum ada vendor yang menekan tombol Terima & Mulai Kerjakan"*.
- **Baris `Respons Vendor`** — berapa lama vendor merespons sejak tiket ditugaskan (mis. `5 menit setelah tiket ditugaskan`).
- **Header halaman tiket** — badge hijau `✓ Diterima 18/11/2026 16:36` (abu-abu `Belum diterima` kalau belum).
- **Tabel Tiket** — di bawah nama vendor: `✓ Diterima 18/11/2026 16:36`.
- **Kartu Kanban** — `✓ Diterima 18 November 2026 16:36`, atau *Belum diterima vendor* kalau masih ditugaskan.
- **Riwayat tiket** — log: *"Vendor menekan tombol "Terima & Mulai Kerjakan" pada 18/11/2026 16:36 (4m 56d setelah ditugaskan)"*.
- **API NOC** — field `accepted_at` dikembalikan di response `GET /api/tickets.php`.

Helper di [includes/bootstrap.php](includes/bootstrap.php): `accept_info()` (waktu + delay respons) dan `accept_badge()`.

## Konsep Form & Siapa yang Mengisi (alur Vendor MS)

### Peta layar

| Layar | Judul | Diisi oleh | Isi |
|---|---|---|---|
| **Screen 1** | Pengisian Ticketing oleh Vendor MS (disesuaikan untuk HP, unik per tiket) | **Vendor MS** | Header tiket, checklist material, dokumentasi foto, penyelesaian kerusakan |
| **Screen 2** | Evaluasi Pekerjaan Vendor oleh Internal CGS | **FOC → NAM → NOC** | Review per item (checkbox + keterangan), Link Up PERMANEN / TEMPORER |
| **Screen 3** | Evaluasi Pekerjaan (versi lengkap) | **FOC → NAM → NOC** (NOC yang menyimpulkan) | Sama dengan Screen 2 + kolom **TANDA CHECK** per tahap & *sudah dicek oleh* |

Halamannya: Screen 1 → [vendor_report.php](vendor_report.php), Screen 2 & 3 → [ticket_view.php](ticket_view.php).

### Screen 1 — Vendor MS

**Header (otomatis dari NOC, read-only):** No. Tiket, Jenis Kegiatan, Daerah (Jalan / Kota / Provinsi), Latitude, Longitude.

**Material — bentuk checklist (`MATERIAL A/B/C`):**

| Elemen | Sumber | Aturan |
|---|---|---|
| Checkbox | Vendor MS | Material yang dipakai |
| Jumlah | Vendor MS | Kalau jumlah diisi, material **wajib** tercentang (divalidasi server juga) |
| Satuan | Master material (fixed) | Tidak bisa diubah vendor |
| Keterangan | Vendor MS | Catatan bebas per baris material (mis. *"kabel disambung ulang 3 titik"*) |
| Batas / Stok | Master material | `materials.max_use_per_ticket` — `0` = tanpa batas |

> **Batas per tiket** (konsep: *"jumlah harus dicek, kalau lewat threshold tertentu tidak bisa dibuat — mis. kabel jenis tertentu + 500 meter ⇒ panjang max 3"*).
> Kalau total pemakaian pada tiket melebihi batas, sistem **menolak** dengan pesan: *"…melebihi batas per tiket (maks 3 meter). Mohon konfirmasi ke CGS."*
> Diisi di menu **Material** → kolom *Batas Pemakaian / Tiket*.

**Dokumentasi foto — 4 kategori wajib:** Foto Tiang, Foto Sinyal, Foto OTDR, Foto Data Core.
- **Kompres ukuran gambar** otomatis di sisi klien (canvas, maks 1600px, JPEG q0.82) — supaya upload dari HP cepat.
- **Cek blur** di sisi server (variance of Laplacian, ambang `BLUR_THRESHOLD` = 90): foto terblur **ditolak**, vendor diminta upload ulang. Skor blur tersimpan di `ticket_photos.blur_score` + badge **Blur** di daftar foto.
- Keterangan per kategori foto (mis. *"data signal user, signal input/output splitter & main splitter (jika ada) hasil GTDR"*).

**Penyelesaian Kerusakan:** dropdown **Jenis Kerusakan** (master dari `master_options` kategori `damage_type`) + dropdown **Tindakan Penanganan** (`repair_action`) + upload bukti jenis kerusakan.

**Waktu pengerjaan di form vendor:**

| Kolom | Diisi oleh | Cara kerja |
|---|---|---|
| **Waktu Tiba di Lokasi** | Vendor | Diisi manual (wajib). Jadi batas atas validasi: waktu tiba tidak boleh melewati waktu selesai. |
| **Waktu Selesai Dikerjakan** | **Sistem (otomatis)** | Tidak ada input anymore — field readonly berisi *"Otomatis dicatat saat Anda menekan 'Kirim Laporan'"*. Nilai `ticket_reports.finished_at` = **waktu klik tombol Kirim Laporan**. |

> Kalau vendor menyimpan **Draft** dulu, `finished_at` yang sudah tercatat tetap dipertahankan (tidak di-reset), sehingga revisi tidak mengubah waktu selesai asli.
> Validasi: *"Waktu tiba tidak boleh setelah waktu selesai dikerjakan."*

### Screen 2 — Evaluasi internal CGS (FOC → NAM → NOC)

Setiap item (material, foto, jenis kerusakan, tindakan penanganan) punya **dua tahap tanda check** dengan keterangan masing-masing:

| Tahap | Diisi oleh | Isi |
|---|---|---|
| **Tanda Check PIC Region** (fase 1) | PIC Region / orang lapangan | Status (OK / TANDAIN / reset) + keterangan |
| **Evaluasi CGS** (fase 2) | FOC → NAM → NOC | Status (OK / TANDAIN / reset) + keterangan |

Tombolnya: **LINK UP \| PERMANEN** (tiket langsung ditutup + BASTO terbentuk) atau **LINK UP \| TEMPORER** (form kembali ke vendor).

### Screen 3 — Tanda check dua tahap

Tiap item punya **dua tahap tanda check**, masing-masing dengan keterangan sendiri:

| Tahap | Diisi oleh | Keterangan |
|---|---|---|
| **Tanda Check PIC Region** (fase 1) | PIC Region / orang lapangan | Status (OK / TANDAIN / reset) + keterangan |
| **Evaluasi CGS** (fase 2) | FOC → NAM → NOC | Status (OK / TANDAIN / reset) + keterangan |

Di bawah badge ditampilkan **“Sudah dicek oleh: &lt;nama&gt;”** beserta waktunya.

### Tanda check dua tahap (tabel `ticket_signoffs`)

Kolom baru `stage` `enum('region','cgs')` + unique key `(ticket_id, scope, ref_id, stage)` — jadi satu item bisa punya tanda check PIC Region **dan** Evaluasi CGS sekaligus, masing-masing dengan keterangannya sendiri.

Helper: `ticket_signoffs()`, `get_signoff($ticketId,$scope,$refId,$stage)`, `save_signoff(..., $stage)`, `signoff_summary()`, `signoff_cell()` (merender badge + tombol edit + *sudah dicek oleh*).

### Role `nam` (Network Area Manager)

Ditambahkan 2026-11-19 sesuai hierarki **FOC → NAM → NOC** pada konsep. Masuk enum `users.role` lewat migrasi ringan.

| Hak akses | Role `nam` |
|---|---|
| Review pekerjaan vendor (Evaluasi CGS, tanda check, keterangan) | ✅ |
| Link Up PERMANEN / TEMPORER | ✅ |
| Cek / tutup tiket (Link Up PERMANEN) | ✅ |
| Menu Tiket, Dashboard, BASTO, Rekap Bulanan | ✅ |
| Cek / terbitkan material ke inventory | ❌ |
| Manage User, Region/Kota, master option | ❌ |

Akun demo: `nam / nam123`.

## Testing
- `php tools/test_region.php` – regression suite region + limit workload per region (31 pemeriksaan, data uji dibersihkan otomatis).
- `php tools/test_worktime.php` – regression suite waktu pengerjaan (25 pemeriksaan: mulai saat assignment, tidak berhenti di tahap antara, berhenti saat permanent, dibekukan saat cancel).
- `php tools/test_accept_time.php` – regression suite waktu vendor menekan tombol Terima (21 pemeriksaan).
- `php tools/test_sla.php` – regression suite SLA sampai PERMANENT (42 pemeriksaan: verdict live vs final, badge, sinkronisasi PHP↔SQL, resync kolom, reset saat penugasan).
- `php tools/test_vendor_ms.php` – regression suite alur Vendor MS (67 pemeriksaan: auto-assign MS, SPK, material langsung, Link Up PERMANEN → tutup + BASTO, tanda check, rekap).
- `php tools/test_form_concept.php` – regression suite konsept form (48 pemeriksaan: checklist material, batas per tiket, deteksi blur, dua tahap tanda check, role NAM).
- `php tools/test_inv_foc_flow.php` – regression suite alur Vendor → Inventory → FOC (85 pemeriksaan: form vendor selalu masuk Inventory, gate checklist material, checklist + keterangan per tahap, FOC hanya menerima form done dari Inventory, gate checklist FOC, link up permanen/temporer, isi form vendor read-only).
- `php tools/test_timezone.php` – regression suite zona waktu region & kota (35 pemeriksaan: mapping East=WIT/West=WIB/Central=WITA, resolusi kota kolom > geografi > region, badge, pergeseran waktu).
- `php tools/test_datetime.php` – regression suite proteksi input tanggal (34 pemeriksaan: parser tanggal, sanitasi kolom datetime, pengaman lapis query, insert dengan tanggal sampah tidak fatal).
- `php tools/check_nested_form.php` – memindai seluruh halaman untuk `<form>` di dalam `<form>` (HTML tidak mengizinkan nested form; browser mengabaikannya sehingga field terkirim ke form yang salah).

Total: **385 pemeriksaan, 0 gagal**.

## Proteksi input tanggal/waktu
Input tanggal dari form/API selalu divalidasi sebelum masuk database, supaya tidak memicu error MySQL `1366 Incorrect datetime value`.

- `parse_dt($v)` – ubah input mentah jadi `Y-m-d H:i:s`; Mendukung format DB, `datetime-local` browser, dan Indonesia `dd/mm/yyyy`; nilai tidak valid (mis. `gkggf`) → `null`.
- `sanitize_dt_fields($d)` – bersihkan field yang namanya berakhiran `_at`.
- `sanitize_row($table, $d)` – bersihkan berdasarkan **tipe kolom di database** (`information_schema`), sehingga kolom tanggal baru pun otomatis aman.
- `sanitize_params($sql, $params)` – pengaman lapis query: pada `INSERT` dengan daftar kolom eksplisit, parameter untuk kolom `DATE`/`DATETIME`/`TIMESTAMP` otomatis dinormalisasi (aman untuk kolom tambahan seperti `imported_at`).
- `insert_row($table, $data)` – insert dengan nama kolom dari array data (tidak ada lagi risiko salah jumlah placeholder).
- Exception handler global: error `SQLSTATE 22007` ditampilkan sebagai halaman ramah **"Format tanggal/waktu tidak valid"** (HTTP 422) atau JSON untuk API — bukan stack trace.

## Alur Vendor MS (Managed Service)
Ada dua tipe vendor:

| Tipe | Badge | Perilaku |
|---|---|---|
| **Vendor MS** | `Vendor MS` (hijau) | Langsung dikerjakan — material dipakai langsung dari form laporan (stok otomatis berkurang), **tidak perlu request ke inventory**, **BASTO otomatis terbentuk** setelah pekerjaan selesai. |
| **Vendor Non-MS** | `Vendor Non-MS` (abu-abu) | Alur biasa — vendor request material ke Inventory, inventory menyerahkan & mengecek pengembalian. |

Semua Vendor MS **diutamakan** otomatis saat penugasan: `ms_first_candidates()` mengurutkan kandidat **Vendor MS di depan** vendor lain, baru diurutkan dari beban kerja terkecil.

### Perbedaan alur pada detail
- **Auto-assign** — tiket di kota yang punya Vendor MS langsung ditugaskan ke Vendor MS; status langsung `on_progress` + `accepted_at` terisi (**langsung terus menuju**, tanpa perlu vendor menekan tombol terima).
- **Material** — kolom `ticket_materials.is_direct` menandai material yang dipakai langsung. Baris `is_direct=1` **tidak pernah** masuk daftar yang perlu dicek/diterima inventory (`materials.php` kolom *At Vendor*, `ticket_action.php` cek pengembalian, `vendor_report.php` alur `inventory_check`).
- **Panel Minta Material ke Inventory** disembunyikan untuk Vendor MS; digantikan info “material dipakai langsung dari form laporan”.
- **Nomor SPK** — otomatis dibuat saat tiket dibuat: `SPK-YYYYMMDD-XXXX` (unik, tampil di detail tiket, form vendor, dan BASTO).

### Penyelesaian tiket: LINK UP PERMANEN langsung menutup
Tidak ada lagi tahap **Cek Ready for SLA** (status `sla_check`) maupun tombol **RPA/SLA OK** / **RPA/SLA TANDAIN** — sudah dihapus (19 Nov 2026).

Alur sekarang:

```
Vendor kirim laporan  ->  Review FOC
   LINK UP | PERMANEN  ->  Tiket LANGSUNG ditutup + BASTO otomatis terbentuk
   LINK UP | TEMPORER  ->  Form kembali ke vendor (revisi)
```

- Tiket yang **melewati SLA** hanya diberi **tanda** lewat badge SLA yang sudah ada: `Lewat SLA` (merah) vs `Sesuai SLA` (hijau) — di header detail, tabel Tiket, kartu kanban, dan rekap bulanan.
- Verdict SLA menjadi final saat tiket ditutup (`work_finished_at` vs `sla_deadline`).
- Notifikasi NOC / procurement / FOC menyebut hasilnya: *"Tiket selesai — LEWAT SLA"* atau *"SESUAI SLA"*.
- Scope tanda check (`ticket_signoffs`) sekarang: `material`, `dokumentasi`, `penyelesaian`.

### BASTO
**BASTO** = catatan penggunaan material, **otomatis terbentuk** dari form vendor yang sudah diisi ketika tiket dinyatakan selesai (PERMANENT).

- Nomor: `BASTO-YYYYMMDD-XXXX` (idempotent — satu tiket satu BASTO).
- Isi: Identitas (No. BASTO, No. Tiket, No. SPK, Ref NOC, Jenis Kegiatan, Jenis Masalah, Kota, Provinsi, Pelanggan, Vendor, Lokasi, waktu tiba/selesai) · Penyelesaian Kerusakan (jenis kerusakan, tindakan perbaikan, detail penyebab & tindakan) · Penggunaan Material (kode, nama, jumlah, status, keterangan) · Dokumentasi (foto per kategori).
- Halaman **BASTO** (`basto.php`) — daftar semua BASTO, dan detail + tombol **Cetak** per tiket (`basto.php?ticket_id=<id>`).
- Link BASTO juga tampil di baris **BASTO** pada detail tiket.

### Rekap Bulanan
Halaman **Rekap Bulanan** (`rekap.php`, role admin/noc/procurement/foc) — rekap pekerjaan **selesai (PERMANENT)** per bulan (`?month=YYYY-MM`, 12 bulan terakhir).

- 4 KPI: Tiket Selesai, Kepatuhan SLA (%), Lewat SLA, BASTO Terbentuk.
- Tabel: rekap **per Vendor** (dengan tipe vendor, sesuai/lewat), **per Region**, **per Jenis Kegiatan**, dan daftar tiket selesai (dengan link BASTO).
- Ekspor **CSV** (`?export=csv`) dengan BOM UTF-8 agar rapi di Excel.

### Helper & tabel
Kode: [includes/vendor_ms.php](includes/vendor_ms.php) — `vendor_type_badge()`, `vendor_type_of()`, `is_ms_vendor()`, `ms_cities()`, `city_has_ms_vendor()`, `ms_first_candidates()`, `ms_add_material()`, `ms_add_materials()`, `ms_remove_material()`, `master_options()`, `ticket_signoffs()`, `get_signoff()`, `save_signoff()`, `signoff_badge()`, `signoff_cell()`, `generate_basto()`, `basto_payload()`.

Tabel/ kolom baru:
- `vendors.vendor_type` — `enum('ms','non_ms')`, default `non_ms`.
- `cities.is_primary` — kota utama (tampil dengan badge *Kota Utama* di detail tiket).
- `tickets.spk_no`, `tickets.province`, `tickets.activity_type`.
- `ticket_materials.is_direct` — menandai material yang dipakai langsung Vendor MS.
- `ticket_reports.damage_type`, `damage_photo`, `repair_action`.
- `master_options` — master jenis kerusakan / tindakan perbaikan.
- `ticket_signoffs` — tanda check per scope.
- `basto` — BASTO yang terbentuk otomatis.
