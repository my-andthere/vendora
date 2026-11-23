import { exec, insert, q, q1, tx } from './db';
import { VENDOR_TYPES } from './config';
import { logTicket } from './notify';
import { fmtQty } from './format';
import { materialUsageCheck, materialUsedTotal } from './photo-check';
import { candidateVendors, type CandidateVendor } from './vendors';

/**
 * Vendor MS (Managed Service) flow helpers plus sign-offs and BASTO generation.
 * Port of legacy_php/includes/vendor_ms.php.
 */

// ------------------------------------------------------------
// Vendor type
// ------------------------------------------------------------

/** Vendor type of a vendor id ('ms' | 'non_ms'); defaults to 'non_ms'. */
export async function vendorTypeOf(vendorId: number): Promise<string> {
  const v = await q1<{ vendor_type: string }>('SELECT vendor_type FROM vendors WHERE id=?', [vendorId]);
  return v?.vendor_type ?? 'non_ms';
}

/** Whether the vendor is a Managed Service vendor. */
export async function isMsVendor(vendorId: number | null): Promise<boolean> {
  if (vendorId === null) return false;
  return (await vendorTypeOf(vendorId)) === 'ms';
}

/** Cities that have at least one MS vendor. */
export async function msCities(): Promise<
  { city_id: number; city_name: string; is_primary: number; ms_vendors: number; ms_available: number }[]
> {
  return q(
    `SELECT c.id city_id, c.name city_name, c.is_primary,
            COUNT(v.id) ms_vendors,
            SUM(CASE WHEN v.status='available' THEN 1 ELSE 0 END) ms_available
     FROM cities c JOIN vendors v ON v.city_id=c.id AND v.vendor_type='ms'
     GROUP BY c.id, c.name, c.is_primary
     ORDER BY c.is_primary DESC, c.name`
  );
}

/** Whether a city has an available MS vendor. */
export async function cityHasMsVendor(cityId: number): Promise<boolean> {
  const n = await q1<{ n: number }>(
    "SELECT COUNT(*) AS n FROM vendors WHERE city_id=? AND vendor_type='ms' AND status='available'",
    [cityId]
  );
  return Number(n?.n ?? 0) > 0;
}

/**
 * Candidate vendors for a ticket, MS vendors first, then the lightest active
 * workload.
 */
export async function msFirstCandidates(
  ticketId: number,
  excludeRejected = true
): Promise<CandidateVendor[]> {
  const cands = await candidateVendors(ticketId, excludeRejected);
  return cands.sort((a, b) => {
    const ams = a.vendor_type === 'ms' ? 0 : 1;
    const bms = b.vendor_type === 'ms' ? 0 : 1;
    if (ams !== bms) return ams - bms;
    return Number(a.load_jobs) - Number(b.load_jobs);
  });
}

// ------------------------------------------------------------
// Material used directly by an MS vendor
// ------------------------------------------------------------

export type MsResult = { ok: boolean; error?: string; line_id?: number; saved?: number };

/**
 * Record material used directly by an MS vendor (no inventory request);
 * the stock is decremented immediately.
 */
export async function msAddMaterial(
  ticketId: number,
  materialId: number,
  qty: number,
  userId: number | null = null
): Promise<MsResult> {
  if (qty <= 0) return { ok: false, error: 'Jumlah harus lebih dari 0.' };

  const m = await q1<{ id: number; name: string; unit: string; stock: number }>(
    'SELECT id, name, unit, stock FROM materials WHERE id=? AND is_active=1',
    [materialId]
  );
  if (!m) return { ok: false, error: 'Material tidak ditemukan / tidak aktif.' };
  if (Number(m.stock) < qty) {
    return { ok: false, error: `Stok ${m.name} tidak cukup (sisa ${fmtQty(Number(m.stock))}).` };
  }

  const limit = await materialUsageCheck(materialId, qty);
  if (!limit.ok) return { ok: false, error: limit.error };

  if (limit.max > 0) {
    const total = (await materialUsedTotal(ticketId, materialId)) + qty;
    if (total > limit.max) {
      return {
        ok: false,
        error:
          `Total pemakaian ${m.name} pada tiket ini akan menjadi ${fmtQty(total)} ${m.unit}, ` +
          `melebihi batas per tiket (maks ${fmtQty(limit.max)}). ` +
          'Mohon konfirmasi ke CGS sebelum memakai material tersebut.',
      };
    }
  }

  try {
    const lineId = await tx(async () => {
      const id = await insert(
        `INSERT INTO ticket_materials (ticket_id, material_id, qty_requested, qty_issued, qty_used, is_direct, issued_by, issued_at, note)
         VALUES (?,?,?,?,?,1,?,NOW(),?)`,
        [
          ticketId,
          materialId,
          qty,
          qty,
          qty,
          userId,
          'Dipakai langsung oleh Vendor MS (langsung menuju)',
        ]
      );
      await exec('UPDATE materials SET stock = stock - ? WHERE id=?', [qty, materialId]);
      return id;
    });
    await logTicket(
      ticketId,
      'material_used_ms',
      `Material dipakai langsung (Vendor MS): ${m.name} ${fmtQty(qty)} ${m.unit}`
    );
    return { ok: true, line_id: lineId };
  } catch (e) {
    return { ok: false, error: `Gagal menyimpan material: ${(e as Error).message}` };
  }
}

/** Record several MS materials at once (from the checklist form). */
export async function msAddMaterials(
  ticketId: number,
  items: { material_id: number; qty: number; note?: string }[],
  userId: number | null = null
): Promise<MsResult> {
  let saved = 0;
  for (const it of items) {
    const qty = Number(it.qty ?? 0);
    const mid = Number(it.material_id ?? 0);
    if (qty <= 0) continue;
    if (mid <= 0) {
      return { ok: false, error: 'Material belum dipilih padahal jumlah diisi.', saved };
    }
    const res = await msAddMaterial(ticketId, mid, qty, userId);
    if (!res.ok) return { ok: false, error: res.error, saved };
    const note = (it.note ?? '').trim();
    if (note !== '' && res.line_id) {
      await exec('UPDATE ticket_materials SET note=? WHERE id=?', [note, res.line_id]);
    }
    saved++;
  }
  return { ok: true, saved };
}

/** Cancel a direct MS material line; the stock is returned. */
export async function msRemoveMaterial(ticketId: number, lineId: number): Promise<MsResult> {
  const line = await q1<{
    id: number;
    material_id: number;
    qty_issued: number | null;
    qty_returned: number | null;
    name: string;
  }>(
    `SELECT tm.id, tm.material_id, tm.qty_issued, tm.qty_returned, m.name
     FROM ticket_materials tm JOIN materials m ON m.id=tm.material_id
     WHERE tm.id=? AND tm.ticket_id=? AND tm.is_direct=1`,
    [lineId, ticketId]
  );
  if (!line) return { ok: false, error: 'Material tidak ditemukan.' };
  if (line.qty_returned !== null) return { ok: false, error: 'Material sudah dicek inventory.' };

  try {
    await tx(async () => {
      if (line.qty_issued !== null) {
        await exec('UPDATE materials SET stock = stock + ? WHERE id=?', [
          Number(line.qty_issued),
          Number(line.material_id),
        ]);
      }
      await exec('DELETE FROM ticket_materials WHERE id=?', [Number(line.id)]);
    });
  } catch (e) {
    return { ok: false, error: `Gagal membatalkan material: ${(e as Error).message}` };
  }
  await logTicket(ticketId, 'material_removed_ms', `Material dibatalkan oleh vendor: ${line.name}`);
  return { ok: true };
}

// ------------------------------------------------------------
// Master options
// ------------------------------------------------------------

/** Active master options of a category. */
export async function masterOptions(category: string): Promise<any[]> {
  return q('SELECT * FROM master_options WHERE category=? AND is_active=1 ORDER BY name', [category]);
}

/** Names of the active master options of a category. */
export async function masterOptionNames(category: string): Promise<string[]> {
  const rows = await masterOptions(category);
  return rows.map((o) => String(o.name));
}

// ------------------------------------------------------------
// Sign-offs (region stage + CGS evaluation stage)
// ------------------------------------------------------------

export type SignoffStatus = 'pending' | 'ok' | 'marked';

export type Signoff = {
  status: SignoffStatus;
  note: string | null;
  checked_by: string | null;
  checked_at: string | null;
};

/** All sign-offs of a ticket: [scope][ref_id][stage]. */
export async function ticketSignoffs(
  ticketId: number
): Promise<Record<string, Record<number, Record<string, Signoff>>>> {
  const rows = await q<any>(
    `SELECT s.*, u.full_name AS checked_by_name FROM ticket_signoffs s
     LEFT JOIN users u ON u.id = s.checked_by WHERE s.ticket_id=? ORDER BY s.id`,
    [ticketId]
  );
  const out: Record<string, Record<number, Record<string, Signoff>>> = {};
  for (const r of rows) {
    const scope = String(r.scope);
    const refId = Number(r.ref_id);
    const stage = String(r.stage);
    (out[scope] ??= {})[refId] ??= {};
    out[scope][refId][stage] = {
      status: r.status,
      note: r.note,
      checked_by: r.checked_by_name ?? null,
      checked_at: r.checked_at,
    };
  }
  return out;
}

/** Save a sign-off. Stage 'region' (PIC Region) or 'cgs' (internal evaluation). */
export async function saveSignoff(
  ticketId: number,
  scope: string,
  refId: number,
  status: SignoffStatus,
  note: string | null,
  userId: number | null,
  stage = 'region'
): Promise<boolean> {
  if (!['material', 'dokumentasi', 'penyelesaian', 'sla'].includes(scope)) return false;
  const st = stage === 'cgs' ? 'cgs' : 'region';
  await exec(
    `INSERT INTO ticket_signoffs (ticket_id, scope, ref_id, stage, status, note, checked_by, checked_at)
     VALUES (?,?,?,?,?,?,?,?)
     ON DUPLICATE KEY UPDATE status=VALUES(status), note=VALUES(note),
       checked_by=VALUES(checked_by), checked_at=VALUES(checked_at)`,
    [ticketId, scope, Math.max(0, refId), st, status, note || null, userId, status === 'pending' ? null : nowSql()]
  );
  return true;
}

/** A single sign-off. */
export async function getSignoff(
  ticketId: number,
  scope: string,
  refId: number,
  stage = 'region'
): Promise<Signoff | null> {
  const all = await ticketSignoffs(ticketId);
  return all[scope]?.[refId]?.[stage] ?? null;
}

/** Flat list of sign-offs that are not pending. */
export async function signoffSummary(
  ticketId: number
): Promise<
  { scope: string; ref_id: number; stage: string; status: string; checked_by: string | null; checked_at: string | null }[]
> {
  const all = await ticketSignoffs(ticketId);
  const out: any[] = [];
  for (const [scope, refs] of Object.entries(all)) {
    for (const [refId, stages] of Object.entries(refs)) {
      for (const [stage, s] of Object.entries(stages)) {
        if ((s.status ?? 'pending') === 'pending') continue;
        out.push({
          scope,
          ref_id: Number(refId),
          stage,
          status: s.status,
          checked_by: s.checked_by,
          checked_at: s.checked_at,
        });
      }
    }
  }
  return out;
}

// ------------------------------------------------------------
// BASTO (material usage report, generated from the vendor form)
// ------------------------------------------------------------

/** Unique BASTO number: BASTO-YYYYMMDD-XXXX. */
export async function generateBastoNo(): Promise<string> {
  const prefix = `BASTO-${todayCompact()}-`;
  const last = await q1<{ no_basto: string }>(
    'SELECT no_basto FROM basto WHERE no_basto LIKE ? ORDER BY id DESC LIMIT 1',
    [`${prefix}%`]
  );
  const n = last ? Number(String(last.no_basto).slice(-4)) + 1 : 1;
  return `${prefix}${String(n).padStart(4, '0')}`;
}

function todayCompact(): string {
  const d = new Date();
  const p = (x: number) => String(x).padStart(2, '0');
  return `${d.getFullYear()}${p(d.getMonth() + 1)}${p(d.getDate())}`;
}

function nowSql(): string {
  const d = new Date();
  const p = (x: number) => String(x).padStart(2, '0');
  return `${d.getFullYear()}-${p(d.getMonth() + 1)}-${p(d.getDate())} ${p(d.getHours())}:${p(
    d.getMinutes()
  )}:${p(d.getSeconds())}`;
}

/** Build the BASTO payload from an already-filled vendor form. */
export function bastoPayload(
  t: any,
  report: any | null,
  materials: any[],
  photos: any[]
): Record<string, unknown> {
  return {
    no_basto: null,
    ticket_no: t.ticket_no,
    spk_no: t.spk_no ?? null,
    noc_ref: t.noc_ref ?? null,
    activity_type: t.activity_type ?? null,
    problem_type: t.problem_type ?? null,
    city: t.city_name ?? null,
    province: t.province ?? null,
    location: t.location ?? null,
    customer: t.customer_name ?? null,
    vendor: t.vendor_name ?? null,
    vendor_type: t.vendor_type ?? 'non_ms',
    arrived_at: report?.arrived_at ?? null,
    finished_at: report?.finished_at ?? null,
    damage_type: report?.damage_type ?? null,
    repair_action: report?.repair_action ?? null,
    root_cause: report?.root_cause ?? null,
    action_taken: report?.action_taken ?? null,
    materials: materials.map((m) => ({
      code: m.code ?? null,
      name: m.name ?? null,
      unit: m.unit ?? null,
      qty: m.qty_used ?? null,
      note: m.note ?? null,
    })),
    photos: photos.map((p) => ({ category: p.category, path: p.file_path })),
    generated_at: nowSql(),
  };
}

/**
 * Generate the BASTO of a ticket (idempotent — existing entries are kept).
 */
export async function generateBasto(ticketId: number, userId: number | null = null): Promise<string | null> {
  const existing = await q1<{ no_basto: string }>('SELECT no_basto FROM basto WHERE ticket_id=?', [ticketId]);
  if (existing) return String(existing.no_basto);

  const t = await q1<any>(
    `SELECT t.*, c.name city_name, v.name vendor_name, v.vendor_type
     FROM tickets t JOIN cities c ON c.id=t.city_id LEFT JOIN vendors v ON v.id=t.vendor_id
     WHERE t.id=?`,
    [ticketId]
  );
  if (!t) return null;

  const report = await q1<any>('SELECT * FROM ticket_reports WHERE ticket_id=?', [ticketId]);
  const materials = await q<any>(
    `SELECT tm.*, m.code, m.name, m.unit FROM ticket_materials tm
     JOIN materials m ON m.id=tm.material_id
     WHERE tm.ticket_id=? AND tm.qty_used IS NOT NULL AND tm.qty_used > 0
     ORDER BY tm.id`,
    [ticketId]
  );
  const photos = await q<any>('SELECT * FROM ticket_photos WHERE ticket_id=? ORDER BY id', [ticketId]);

  const payload = bastoPayload(t, report, materials, photos);
  const no = await generateBastoNo();
  payload.no_basto = no;

  await exec('INSERT INTO basto (ticket_id, no_basto, payload, generated_by) VALUES (?,?,?,?)', [
    ticketId,
    no,
    JSON.stringify(payload),
    userId,
  ]);
  await logTicket(
    ticketId,
    'basto_generated',
    `BASTO ${no} otomatis terbentuk dari form vendor yang sudah diisi.`
  );
  return no;
}

/** The BASTO of a ticket, if any. */
export async function getBasto(ticketId: number): Promise<any | null> {
  return q1<any>('SELECT * FROM basto WHERE ticket_id=?', [ticketId]);
}

export { VENDOR_TYPES };
