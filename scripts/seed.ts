/**
 * Seed the database: applies schema.sql then inserts the demo dataset
 * (ported from legacy_php/install.php).
 *
 * Usage: npm run seed  [-- --force]
 */
import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';
import mysql from 'mysql2/promise';
import bcrypt from 'bcryptjs';

const force = process.argv.includes('--force');

async function main() {
  const pool = mysql.createPool({
    host: process.env.DB_HOST ?? '127.0.0.1',
    port: Number(process.env.DB_PORT ?? 3306),
    user: process.env.DB_USER ?? 'root',
    password: process.env.DB_PASS ?? '',
    database: process.env.DB_NAME ?? 'procurement_db',
    multipleStatements: true,
    charset: 'utf8mb4_unicode_ci',
  });

  const conn = await pool.getConnection();
  try {
    // Ensure the database exists (connect without a database first).
    const admin = await mysql.createConnection({
      host: process.env.DB_HOST ?? '127.0.0.1',
      port: Number(process.env.DB_PORT ?? 3306),
      user: process.env.DB_USER ?? 'root',
      password: process.env.DB_PASS ?? '',
    });
    await admin.query(
      `CREATE DATABASE IF NOT EXISTS \`${process.env.DB_NAME ?? 'procurement_db'}\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci`,
    );
    await admin.end();

    const [existing] = await conn.query('SHOW TABLES');
    const tableCount = (existing as any[]).length;
    if (tableCount > 0 && !force) {
      console.log(`Database already has ${tableCount} tables. Re-run with --force to reset.`);
      return;
    }

    const sql = readFileSync(resolve(process.cwd(), 'schema.sql'), 'utf8');
    await conn.query(sql);
    console.log('Schema applied.');

    // ---------- Regions ----------
    const regions: [string, string, string][] = [
      ['Central', 'CENTRAL', 'Jawa Barat, DKI Jakarta dan sekitarnya'],
      ['East', 'EAST', 'Jawa Tengah dan Jawa Timur'],
      ['West', 'WEST', 'Sumatera (Medan dan surroundings)'],
    ];
    const regionId: Record<string, number> = {};
    for (const r of regions) {
      const [res] = await conn.query('INSERT INTO regions (name, code, description) VALUES (?,?,?)', r);
      regionId[r[0]] = (res as any).insertId;
    }

    // ---------- Cities ----------
    const cities: [string, string][] = [
      ['Jakarta', 'Central'],
      ['Bandung', 'Central'],
      ['Surabaya', 'East'],
      ['Medan', 'West'],
      ['Semarang', 'East'],
    ];
    for (const c of cities) {
      await conn.query('INSERT INTO cities (name, region_id) VALUES (?,?)', [c[0], regionId[c[1]]]);
    }

    // ---------- Vendors ----------
    const vendors: [string, number, string, string, string, number][] = [
      ['PT Fiber Nusantara', 1, 'Budi', '081200000001', 'available', 5],
      ['CV Jaringan Prima', 1, 'Andi', '081200000002', 'available', 3],
      ['PT Sinar Optik', 1, 'Rudi', '081200000003', 'blacklist', 5],
      ['CV Parahyangan Teknik', 2, 'Asep', '081200000004', 'available', 0],
      ['PT Bandung Link', 2, 'Dedi', '081200000005', 'freeze', 5],
      ['PT Surya Fiber', 3, 'Joko', '081200000006', 'available', 2],
      ['CV Deli Network', 4, 'Rizal', '081200000007', 'available', 5],
    ];
    const vendorId: number[] = [];
    for (const v of vendors) {
      const until = v[4] === 'freeze' ? new Date(Date.now() + 7 * 86400_000) : null;
      const note =
        v[4] === 'available' ? null : v[4] === 'freeze' ? 'Contoh: freeze 7 hari karena sering lewat SLA' : 'Contoh: pelanggaran berat';
      const [res] = await conn.query(
        'INSERT INTO vendors (name, city_id, pic_name, phone, status, workload_limit, freeze_until, status_note, status_changed_at) VALUES (?,?,?,?,?,?,?,?,NOW())',
        [v[0], v[1], v[2], v[3], v[4], v[5], until, note],
      );
      vendorId.push((res as any).insertId);
    }

    // ---------- Vendor regions ----------
    const vendorRegions: [number, string, number][] = [
      [1, 'Central', 5],
      [2, 'Central', 3],
      [3, 'Central', 5],
      [4, 'Central', 5],
      [4, 'East', 0],
      [5, 'Central', 5],
      [6, 'East', 5],
      [7, 'West', 5],
    ];
    for (const vr of vendorRegions) {
      await conn.query('INSERT INTO vendor_regions (vendor_id, region_id, workload_limit) VALUES (?,?,?)', [
        vr[0],
        regionId[vr[1]],
        vr[2],
      ]);
    }

    // ---------- Skills ----------
    const skills: [string, string][] = [
      ['Aktivasi', 'Aktivasi layanan / skill ODP pelanggan'],
      ['Troubleshooting', 'Pencarian & perbaikan gangguan'],
      ['Maintenance', 'Perawatan berkala'],
      ['Project', 'Pekerjaan proyek / penambahan jaringan'],
      ['Instalasi', 'Instalasi jaringan baru'],
      ['Jaringan', 'Pekerjaan jaringan (pulling, splicing)'],
      ['Survey Lokasi', 'Survei & pengukuran lokasi'],
      ['Handling Keluhan', 'Penanganan keluhan pelanggan'],
    ];
    const skillId: Record<string, number> = {};
    for (const s of skills) {
      const [res] = await conn.query('INSERT INTO skills (name, description) VALUES (?,?)', s);
      skillId[s[0]] = (res as any).insertId;
    }

    const vendorSkills: Record<number, string[]> = {
      1: ['Aktivasi', 'Troubleshooting', 'Instalasi'],
      2: ['Jaringan', 'Maintenance'],
      3: ['Project', 'Instalasi'],
      4: ['Troubleshooting', 'Maintenance', 'Survey Lokasi', 'Instalasi', 'Jaringan'],
      5: ['Maintenance', 'Handling Keluhan'],
      6: ['Instalasi', 'Aktivasi'],
      7: ['Troubleshooting', 'Survey Lokasi', 'Handling Keluhan'],
    };
    for (const [vid, list] of Object.entries(vendorSkills)) {
      for (const sn of list) {
        await conn.query('INSERT INTO vendor_skills (vendor_id, skill_id) VALUES (?,?)', [vid, skillId[sn]]);
      }
    }

    // ---------- Users ----------
    const users: [string, string, string, string, number | null][] = [
      ['admin', 'admin123', 'Administrator', 'admin', null],
      ['noc', 'noc123', 'Petugas NOC', 'noc', null],
      ['procurement', 'procurement123', 'Petugas Procurement', 'procurement', null],
      ['inventory', 'inventory123', 'Petugas Inventory', 'inventory', null],
      ['foc', 'foc123', 'Petugas FOC', 'foc', null],
      ['nam', 'nam123', 'NAM (Network Area Manager)', 'nam', null],
      ['vendor1', 'vendor123', 'Teknisi Fiber Nusantara', 'vendor', 1],
      ['vendor2', 'vendor123', 'Teknisi Jaringan Prima', 'vendor', 2],
      ['vendor3', 'vendor123', 'Teknisi Parahyangan', 'vendor', 4],
      ['vendor4', 'vendor123', 'Teknisi Surya Fiber', 'vendor', 6],
    ];
    for (const u of users) {
      const hash = bcrypt.hashSync(u[1], 10);
      await conn.query('INSERT INTO users (username, password_hash, full_name, role, vendor_id) VALUES (?,?,?,?,?)', [
        u[0],
        hash,
        u[2],
        u[3],
        u[4],
      ]);
    }

    // ---------- Materials ----------
    const materials: [string, string, string, number][] = [
      ['MTR-001', 'Kabel FO Drop Core 1 Core', 'meter', 5000],
      ['MTR-002', 'Kabel FO 12 Core', 'meter', 2000],
      ['MTR-003', 'Patch Cord SC-UPC 3m', 'pcs', 200],
      ['MTR-004', 'Fast Connector SC', 'pcs', 500],
      ['MTR-005', 'Splitter 1:8', 'pcs', 100],
      ['MTR-006', 'Closure Joint 24 Core', 'unit', 40],
      ['MTR-007', 'Sleeve Protector', 'pcs', 1000],
      ['MTR-008', 'ODP Pole 16 Port', 'unit', 30],
      ['MTR-009', 'Clamp / S-Clamp', 'pcs', 800],
      ['MTR-010', 'Pipa Conduit 20mm', 'batang', 150],
    ];
    for (const m of materials) {
      await conn.query('INSERT INTO materials (code, name, unit, stock) VALUES (?,?,?,?)', m);
    }

    // ---------- Master options ----------
    const defaults: Record<string, string[]> = {
      damage_type: [
        'Kabel FO putus',
        'Redaman tinggi',
        'ODP rusak',
        'Closure rusak',
        'Tiang roboh',
        'LOS / No signal',
        'Konektor kotor',
      ],
      repair_action: [
        'Ganti splices',
        'Ganti closure',
        'Ganti ODP',
        'Patch ulang FO',
        'Perbaikan kabel',
        'Bersihkan konektor',
        'Ganti tiang',
      ],
    };
    for (const [cat, list] of Object.entries(defaults)) {
      for (const name of list) {
        await conn.query('INSERT IGNORE INTO master_options (category, name) VALUES (?,?)', [cat, name]);
      }
    }

    console.log('Demo data seeded.');
    console.log(
      'Login: admin/admin123, noc/noc123, procurement/procurement123, inventory/inventory123, foc/foc123, nam/nam123, vendor1..vendor4/vendor123',
    );
  } finally {
    conn.release();
    await pool.end();
  }
}

main().catch((e) => {
  console.error(e);
  process.exit(1);
});
