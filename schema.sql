-- ============================================================
-- Procurement Field Service - Database Schema (MySQL/MariaDB)
-- ============================================================
SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

DROP TABLE IF EXISTS noc_outbox, notifications, ticket_logs, foc_reviews, inventory_checks,
  ticket_photos, ticket_reports, ticket_materials, ticket_rejections, tickets,
  vendor_skills, skills, vendor_regions, materials, users, vendors, cities, regions, app_meta,
  basto, ticket_signoffs, master_options;

SET FOREIGN_KEY_CHECKS = 1;

-- Penyimpanan key/value internal aplikasi (penanda throttle sinkronisasi SLA, dll).
CREATE TABLE app_meta (
  k VARCHAR(50) NOT NULL PRIMARY KEY,
  v TEXT NULL
) ENGINE=InnoDB;

-- Region (wilayah): satu region berisi beberapa kota.
CREATE TABLE regions (
  id INT AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(100) NOT NULL UNIQUE,
  code VARCHAR(20) NULL,
  description VARCHAR(255) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE cities (
  id INT AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(100) NOT NULL UNIQUE,
  -- kota berada pada satu region (region berisi banyak kota)
  region_id INT NULL,
  -- kota utama (ibu kota wilayah): pusat layanan vendor MS
  is_primary TINYINT(1) NOT NULL DEFAULT 0,
  FOREIGN KEY (region_id) REFERENCES regions(id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE vendors (
  id INT AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(150) NOT NULL,
  city_id INT NOT NULL,
  pic_name VARCHAR(100),
  phone VARCHAR(30),
  email VARCHAR(100),
  address TEXT,
  -- Tipe vendor: MS = Managed Service (kota dengan vendor MS), non_ms = vendor biasa.
  -- Vendor MS punya alur sendiri: langsung kerjakan tanpa request material, BASTO otomatis.
  vendor_type ENUM('ms','non_ms') NOT NULL DEFAULT 'non_ms',
  status ENUM('available','freeze','blacklist') NOT NULL DEFAULT 'available',
  -- batas jumlah pekerjaan aktif yang boleh dipegang vendor (0 = tanpa batas)
  workload_limit INT NOT NULL DEFAULT 5,
  freeze_until DATETIME NULL,
  status_note VARCHAR(255),
  status_changed_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (city_id) REFERENCES cities(id)
) ENGINE=InnoDB;

-- Relasi vendor <-> region (banyak-ke-banyak). 1 vendor bisa ada di beberapa region,
-- dan tiap pasangan vendor-region punya batas workload sendiri (0 = tanpa batas).
CREATE TABLE vendor_regions (
  id INT AUTO_INCREMENT PRIMARY KEY,
  vendor_id INT NOT NULL,
  region_id INT NOT NULL,
  workload_limit INT NOT NULL DEFAULT 5,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE (vendor_id, region_id),
  FOREIGN KEY (vendor_id) REFERENCES vendors(id) ON DELETE CASCADE,
  FOREIGN KEY (region_id) REFERENCES regions(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- Master Technical Skill (kebpeteerian yang dikuasai vendor)
CREATE TABLE skills (
  id INT AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(80) NOT NULL UNIQUE,
  description VARCHAR(255) NULL,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- Relasi vendor <-> skill (banyak-ke-banyak): 1 vendor bisa punya banyak skill
CREATE TABLE vendor_skills (
  id INT AUTO_INCREMENT PRIMARY KEY,
  vendor_id INT NOT NULL,
  skill_id INT NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE (vendor_id, skill_id),
  FOREIGN KEY (vendor_id) REFERENCES vendors(id) ON DELETE CASCADE,
  FOREIGN KEY (skill_id) REFERENCES skills(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE users (
  id INT AUTO_INCREMENT PRIMARY KEY,
  username VARCHAR(50) NOT NULL UNIQUE,
  password_hash VARCHAR(255) NOT NULL,
  full_name VARCHAR(100) NOT NULL,
  role ENUM('admin','noc','nam','procurement','vendor','inventory','foc') NOT NULL,
  vendor_id INT NULL,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (vendor_id) REFERENCES vendors(id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE materials (
  id INT AUTO_INCREMENT PRIMARY KEY,
  code VARCHAR(30) NOT NULL UNIQUE,
  name VARCHAR(150) NOT NULL,
  unit VARCHAR(20) NOT NULL DEFAULT 'pcs',
  stock DECIMAL(12,2) NOT NULL DEFAULT 0,
  -- Batas maksimal pemakaian per tiket (0 = tanpa batas).
  -- Contoh: "kabel jenis tertentu + 500 meter => panjang max 3".
  max_use_per_ticket DECIMAL(12,2) NOT NULL DEFAULT 0,
  is_active TINYINT(1) NOT NULL DEFAULT 1
) ENGINE=InnoDB;

-- status: open | assigned | on_progress | inventory_check | foc_review | revision | closed | cancelled
CREATE TABLE tickets (
  id INT AUTO_INCREMENT PRIMARY KEY,
  ticket_no VARCHAR(30) NOT NULL UNIQUE,
  -- Nomor SPK (Surat Perintah Kerja) - dibuat otomatis, unik
  spk_no VARCHAR(30) NULL UNIQUE,
  noc_ref VARCHAR(60) NULL,
  source ENUM('manual','api') NOT NULL DEFAULT 'manual',
  city_id INT NOT NULL,
  customer_name VARCHAR(150),
  customer_id VARCHAR(60),
  location TEXT,
  province VARCHAR(60) NULL,
  -- Jenis kegiatan (mis. troubleshooting, maintenance, project)
  activity_type VARCHAR(100) NULL,
  latitude DECIMAL(10,7) NULL,
  longitude DECIMAL(10,7) NULL,
  problem_type VARCHAR(100),
  description TEXT,
  priority ENUM('low','medium','high','critical') NOT NULL DEFAULT 'medium',
  reported_at DATETIME NULL,
  vendor_id INT NULL,
  assigned_at DATETIME NULL,
  accepted_at DATETIME NULL,
  sla_minutes INT NOT NULL DEFAULT 180,
  sla_deadline DATETIME NULL,
  submitted_at DATETIME NULL,
  -- Cache verdict SLA (diukur sampai PERMANENT). Sumber kebenaran: sla_case_sql()/sla_state().
  sla_status ENUM('ontime','late') NULL,
  completed_at DATETIME NULL,
  -- Waktu pengerjaan: mulai saat tiket ditugaskan ke vendor, BERHENTI hanya saat PERMANENT.
  work_started_at DATETIME NULL,
  work_finished_at DATETIME NULL,
  inv_result ENUM('sesuai','tidak_sesuai') NULL,
  repair_result ENUM('permanent','temporary') NULL,
  revision_count INT NOT NULL DEFAULT 0,
  status VARCHAR(20) NOT NULL DEFAULT 'open',
  created_by INT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX (status), INDEX (vendor_id), INDEX (city_id), INDEX (noc_ref),
  FOREIGN KEY (city_id) REFERENCES cities(id),
  FOREIGN KEY (vendor_id) REFERENCES vendors(id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE ticket_rejections (
  id INT AUTO_INCREMENT PRIMARY KEY,
  ticket_id INT NOT NULL,
  vendor_id INT NOT NULL,
  reason VARCHAR(255),
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (ticket_id) REFERENCES tickets(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- Material flow per ticket: request (vendor) -> issued (inventory) -> used (vendor report) -> returned (inventory)
CREATE TABLE ticket_materials (
  id INT AUTO_INCREMENT PRIMARY KEY,
  ticket_id INT NOT NULL,
  material_id INT NOT NULL,
  qty_requested DECIMAL(12,2) NOT NULL DEFAULT 0,
  qty_issued DECIMAL(12,2) NULL,
  qty_used DECIMAL(12,2) NULL,
  qty_returned DECIMAL(12,2) NULL,
  -- 1 = dipakai langsung oleh Vendor MS (tidak ada alur request/return ke inventory)
  is_direct TINYINT(1) NOT NULL DEFAULT 0,
  requested_by INT NULL,
  issued_by INT NULL,
  issued_at DATETIME NULL,
  returned_by INT NULL,
  returned_at DATETIME NULL,
  note VARCHAR(255),
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (ticket_id) REFERENCES tickets(id) ON DELETE CASCADE,
  FOREIGN KEY (material_id) REFERENCES materials(id)
) ENGINE=InnoDB;

CREATE TABLE ticket_reports (
  id INT AUTO_INCREMENT PRIMARY KEY,
  ticket_id INT NOT NULL UNIQUE,
  arrived_at DATETIME NULL,
  finished_at DATETIME NULL,
  root_cause TEXT,
  action_taken TEXT,
  -- Jenis kerusakan (dipilih dari master) + upload bukti jenis kerusakan
  damage_type VARCHAR(100) NULL,
  damage_photo VARCHAR(255) NULL,
  -- Tindakan perbaikan (dipilih dari master)
  repair_action VARCHAR(100) NULL,
  notes TEXT,
  submitted_by INT NULL,
  submitted_at DATETIME NULL,
  FOREIGN KEY (ticket_id) REFERENCES tickets(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE ticket_photos (
  id INT AUTO_INCREMENT PRIMARY KEY,
  ticket_id INT NOT NULL,
  category ENUM('before','progress','after','material') NOT NULL,
  file_path VARCHAR(255) NOT NULL,
  caption VARCHAR(255),
  -- Hasil pemeriksaan ketajaman foto (blur detection, lihat image_blur_score()).
  is_blurry TINYINT(1) NOT NULL DEFAULT 0,
  blur_score DECIMAL(10,4) NULL,
  uploaded_by INT NULL,
  uploaded_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (ticket_id) REFERENCES tickets(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE inventory_checks (
  id INT AUTO_INCREMENT PRIMARY KEY,
  ticket_id INT NOT NULL,
  checked_by INT NOT NULL,
  result ENUM('sesuai','tidak_sesuai') NOT NULL,
  note TEXT,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (ticket_id) REFERENCES tickets(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE foc_reviews (
  id INT AUTO_INCREMENT PRIMARY KEY,
  ticket_id INT NOT NULL,
  reviewer_id INT NOT NULL,
  result ENUM('permanent','temporary') NOT NULL,
  note TEXT,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (ticket_id) REFERENCES tickets(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE ticket_logs (
  id INT AUTO_INCREMENT PRIMARY KEY,
  ticket_id INT NOT NULL,
  user_id INT NULL,
  action VARCHAR(50) NOT NULL,
  note TEXT,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX (ticket_id),
  FOREIGN KEY (ticket_id) REFERENCES tickets(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE notifications (
  id INT AUTO_INCREMENT PRIMARY KEY,
  user_id INT NOT NULL,
  ticket_id INT NULL,
  message VARCHAR(255) NOT NULL,
  is_read TINYINT(1) NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX (user_id, is_read),
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- Master pilihan dinamis (jenis kerusakan, tindakan perbaikan).
CREATE TABLE master_options (
  id INT AUTO_INCREMENT PRIMARY KEY,
  category ENUM('damage_type','repair_action') NOT NULL,
  name VARCHAR(100) NOT NULL,
  description VARCHAR(255) NULL,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE (category, name)
) ENGINE=InnoDB;

-- Tanda check / keterangan atas hasil pekerjaan vendor.
-- scope: material | dokumentasi | penyelesaian | sla
-- ref_id: id baris ticket_materials / ticket_photos (0 = berlaku umum)
-- stage: region = tanda check PIC Region (fase 1)
--        inventory = ceklis + keterangan tiap material (Inventory, wajib sebelum ke FOC)
--        cgs = evaluasi internal CGS (FOC/NAM/NOC, fase 2)
--        foc = ceklis + keterangan tiap dokumentasi / penyebab / tindakan (FOC)
CREATE TABLE ticket_signoffs (
  id INT AUTO_INCREMENT PRIMARY KEY,
  ticket_id INT NOT NULL,
  scope ENUM('material','dokumentasi','penyelesaian','sla') NOT NULL,
  ref_id INT NOT NULL DEFAULT 0,
  stage ENUM('region','inventory','cgs','foc') NOT NULL DEFAULT 'region',
  status ENUM('pending','ok','marked') NOT NULL DEFAULT 'pending',
  note VARCHAR(255) NULL,
  checked_by INT NULL,
  checked_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE (ticket_id, scope, ref_id, stage),
  FOREIGN KEY (ticket_id) REFERENCES tickets(id) ON DELETE CASCADE,
  FOREIGN KEY (checked_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- BASTO: catatan penggunaan material yang OTOMATIS terbentuk dari form vendor yang sudah diisi.
CREATE TABLE basto (
  id INT AUTO_INCREMENT PRIMARY KEY,
  ticket_id INT NOT NULL UNIQUE,
  no_basto VARCHAR(40) NOT NULL UNIQUE,
  payload TEXT NOT NULL,
  generated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  generated_by INT NULL,
  FOREIGN KEY (ticket_id) REFERENCES tickets(id) ON DELETE CASCADE,
  FOREIGN KEY (generated_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- Pesan keluar ke sistem NOC (webhook). Dikirim jika NOC_WEBHOOK_URL diisi, selain itu tersimpan sebagai 'pending'.
CREATE TABLE noc_outbox (
  id INT AUTO_INCREMENT PRIMARY KEY,
  ticket_id INT NULL,
  event VARCHAR(50) NOT NULL,
  payload TEXT NOT NULL,
  send_status ENUM('pending','sent','failed') NOT NULL DEFAULT 'pending',
  response TEXT,
  attempts INT NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  sent_at DATETIME NULL
) ENGINE=InnoDB;
