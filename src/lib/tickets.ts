import { exec, insert, q, q1 } from './db';
import { SLA_MINUTES, SLA_START } from './config';
import { fmtDuration } from './format';
import { logTicket, nocNotify, notifyRole, notifyVendor } from './notify';
import { slaSyncTicket } from './sla';
import { msFirstCandidates, vendorTypeOf } from './ms';
import { cityRegionId, cityRegionId as regionOfCity, refreshVendorStatus, regionName } from './vendors';

/**
 * Ticket lifecycle: numbering, creation, assignment and role-scoped queries.
 * Port of the ticket-related parts of legacy_php/includes/bootstrap.php.
 */

// ------------------------------------------------------------
// Numbering
// ------------------------------------------------------------

function todayCompact(): string {
  const d = new Date();
  const p = (x: number) => String(x).padStart(2, '0');
  return `${d.getFullYear()}${p(d.getMonth() + 1)}${p(d.getDate())}`;
}

/** Ticket number: TKT-YYYYMMDD-XXXX. */
export async function generateTicketNo(): Promise<string> {
  const prefix = `TKT-${todayCompact()}-`;
  const last = await q1<{ ticket_no: string }>(
    'SELECT ticket_no FROM tickets WHERE ticket_no LIKE ? ORDER BY id DESC LIMIT 1',
    [`${prefix}%`]
  );
  const n = last ? Number(String(last.ticket_no).slice(-4)) + 1 : 1;
  return `${prefix}${String(n).padStart(4, '0')}`;
}

/** SPK number (work order): SPK-YYYYMMDD-XXXX. */
export async function generateSpkNo(): Promise<string> {
  const prefix = `SPK-${todayCompact()}-`;
  const last = await q1<{ spk_no: string }>(
    'SELECT spk_no FROM tickets WHERE spk_no LIKE ? ORDER BY id DESC LIMIT 1',
    [`${prefix}%`]
  );
  const n = last ? Number(String(last.spk_no).slice(-4)) + 1 : 1;
  return `${prefix}${String(n).padStart(4, '0')}`;
}

// ------------------------------------------------------------
// Creation
// ------------------------------------------------------------

export type TicketInput = {
  noc_ref?: string | null;
  city_id: number;
  customer_name?: string | null;
  customer_id?: string | null;
  location?: string | null;
  latitude?: string | null;
  longitude?: string | null;
  province?: string | null;
  activity_type?: string | null;
  problem_type?: string | null;
  description?: string | null;
  priority?: string | null;
  reported_at?: string | null;
};

/**
 * Create a ticket (from the NOC form or the API) and auto-assign a vendor.
 */
export async function createTicket(
  d: TicketInput,
  source: 'manual' | 'api',
  userId: number | null
): Promise<{ id: number; ticket_no: string; vendor_id: number | null }> {
  const ticketNo = await generateTicketNo();
  const spkNo = await generateSpkNo();

  const id = await insert(
    `INSERT INTO tickets (ticket_no, spk_no, noc_ref, source, city_id, customer_name, customer_id, location, latitude, longitude,
        province, activity_type, problem_type, description, priority, reported_at, sla_minutes, status, created_by)
     VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)`,
    [
      ticketNo,
      spkNo,
      d.noc_ref || null,
      source,
      d.city_id,
      d.customer_name ?? null,
      d.customer_id ?? null,
      d.location ?? null,
      d.latitude !== undefined && d.latitude !== null && d.latitude !== ('' as any) ? d.latitude : null,
      d.longitude !== undefined && d.longitude !== null && d.longitude !== ('' as any) ? d.longitude : null,
      d.province ?? null,
      d.activity_type ?? null,
      d.problem_type ?? null,
      d.description ?? null,
      d.priority || 'medium',
      d.reported_at || nowSql(),
      SLA_MINUTES,
      'open',
      userId,
    ]
  );

  await logTicket(
    id,
    'created',
    `Tiket dibuat dari ${source === 'api' ? 'sistem NOC (API)' : 'form NOC'}`,
    userId
  );
  await nocNotify(id, 'ticket_received');
  const vendorId = await autoAssign(id, userId);
  return { id, ticket_no: ticketNo, vendor_id: vendorId };
}

export function nowSql(d: Date = new Date()): string {
  const p = (x: number) => String(x).padStart(2, '0');
  return `${d.getFullYear()}-${p(d.getMonth() + 1)}-${p(d.getDate())} ${p(d.getHours())}:${p(
    d.getMinutes()
  )}:${p(d.getSeconds())}`;
}

// ------------------------------------------------------------
// Assignment
// ------------------------------------------------------------

/**
 * Auto-assign with the Vendor MS priority (flow box 3 of the diagram): when the
 * ticket city has an available MS vendor, that vendor is picked first.
 */
export async function autoAssign(ticketId: number, userId: number | null = null): Promise<number | null> {
  const cands = await msFirstCandidates(ticketId);
  if (cands.length === 0) {
    await exec(
      "UPDATE tickets SET vendor_id=NULL, assigned_at=NULL, sla_deadline=NULL, status='open' WHERE id=?",
      [ticketId]
    );
    const t = await q1<{ ticket_no: string; city: string; city_id: number }>(
      'SELECT t.ticket_no, c.name city, t.city_id FROM tickets t JOIN cities c ON c.id=t.city_id WHERE t.id=?',
      [ticketId]
    );
    const regionId = await cityRegionId(Number(t?.city_id ?? 0));
    const rName = await regionName(regionId);

    let avail = 0;
    if (regionId !== null) {
      const row = await q1<{ n: number }>(
        `SELECT COUNT(*) AS n FROM vendors v JOIN vendor_regions vr ON vr.vendor_id=v.id
         WHERE vr.region_id=? AND v.status='available'
         AND v.id NOT IN (SELECT vendor_id FROM ticket_rejections WHERE ticket_id=?)`,
        [regionId, ticketId]
      );
      avail = Number(row?.n ?? 0);
    } else {
      const row = await q1<{ n: number }>(
        `SELECT COUNT(*) AS n FROM vendors WHERE city_id=? AND status='available'
         AND id NOT IN (SELECT vendor_id FROM ticket_rejections WHERE ticket_id=?)`,
        [Number(t?.city_id ?? 0), ticketId]
      );
      avail = Number(row?.n ?? 0);
    }

    const where = regionId !== null ? `di region ${rName}` : `di kota ${t?.city ?? '-'}`;
    const reason =
      avail > 0
        ? `semua vendor available ${where} sudah mencapai limit workload`
        : `tidak ada vendor available ${where}`;

    await logTicket(
      ticketId,
      'no_vendor',
      `Tidak ada vendor yang bisa ditugaskan: ${reason}`,
      userId
    );
    const msg = `Tiket ${t?.ticket_no ?? ''}: ${reason}. Perlu penunjukan manual.`;
    await notifyRole('noc', msg, ticketId);
    await notifyRole('admin', msg, ticketId);
    await nocNotify(ticketId, 'no_vendor_available');
    return null;
  }

  await assignVendor(ticketId, Number(cands[0].id), userId, true);
  return Number(cands[0].id);
}

/**
 * Assign a vendor to a ticket. Fails when the vendor already reached its
 * workload limit in the ticket's region or does not serve that region.
 *
 * Vendor MS goes "langsung terus menuju": the ticket immediately becomes
 * 'on_progress' without waiting for an acceptance.
 */
export async function assignVendor(
  ticketId: number,
  vendorId: number,
  userId: number | null,
  auto = false
): Promise<boolean> {
  await refreshVendorStatus();

  const t = await q1<{ city_id: number }>('SELECT city_id FROM tickets WHERE id=?', [ticketId]);
  const regionId = t ? await regionOfCity(Number(t.city_id)) : null;

  if (regionId !== null) {
    const serves = await q1<{ x: number }>(
      'SELECT 1 AS x FROM vendor_regions WHERE vendor_id=? AND region_id=?',
      [vendorId, regionId]
    );
    if (!serves) return false;
  }

  const w = await vendorWorkloadSafe(vendorId, regionId, ticketId);
  if (w.full) return false;

  const deadline =
    SLA_START === 'assigned'
      ? new Date(Date.now() + SLA_MINUTES * 60 * 1000)
      : null;
  const isMs = (await vendorTypeOf(vendorId)) === 'ms';

  if (isMs) {
    await exec(
      `UPDATE tickets SET vendor_id=?, assigned_at=NOW(), accepted_at=NOW(), sla_deadline=?, status='on_progress',
        work_started_at=COALESCE(work_started_at, NOW()), sla_status=NULL WHERE id=?`,
      [vendorId, deadline ? nowSql(deadline) : null, ticketId]
    );
  } else {
    await exec(
      `UPDATE tickets SET vendor_id=?, assigned_at=NOW(), accepted_at=NULL, sla_deadline=?, status='assigned',
        work_started_at=COALESCE(work_started_at, NOW()), sla_status=NULL WHERE id=?`,
      [vendorId, deadline ? nowSql(deadline) : null, ticketId]
    );
  }

  const v = await q1<{ name: string }>('SELECT name FROM vendors WHERE id=?', [vendorId]);
  const no = await q1<{ ticket_no: string }>('SELECT ticket_no FROM tickets WHERE id=?', [ticketId]);

  await logTicket(
    ticketId,
    'assigned',
    `${auto ? 'Otomatis ditugaskan ke ' : 'Ditugaskan manual ke '}${v?.name ?? '-'}` +
      (isMs ? ' (Vendor MS - langsung on_progress, tanpa request material)' : ''),
    userId
  );
  await notifyVendor(
    vendorId,
    `Tiket baru ${no?.ticket_no ?? ''} ditugaskan kepada Anda. Segera terima & kerjakan (SLA ${fmtDuration(
      SLA_MINUTES
    )}).`,
    ticketId
  );
  await nocNotify(ticketId, 'vendor_assigned');
  return true;
}

// Local helper to avoid a circular import at module scope.
async function vendorWorkloadSafe(
  vendorId: number,
  regionId: number | null,
  excludeTicketId: number
): Promise<{ full: boolean }> {
  const { vendorWorkload } = await import('./vendors');
  return vendorWorkload(vendorId, regionId, excludeTicketId);
}

// ------------------------------------------------------------
// Queries
// ------------------------------------------------------------

/** A ticket with its city and vendor joined in. */
export async function getTicket(id: number): Promise<any | null> {
  return q1<any>(
    `SELECT t.*, c.name AS city_name, t.province AS city_province, c.is_primary AS city_is_primary,
            v.name AS vendor_name, v.phone AS vendor_phone, v.pic_name AS vendor_pic, v.vendor_type AS vendor_type
     FROM tickets t JOIN cities c ON c.id=t.city_id LEFT JOIN vendors v ON v.id=t.vendor_id
     WHERE t.id=?`,
    [id]
  );
}

export type SessionUser = {
  id: number;
  role: string;
  vendor_id?: number | null;
  full_name?: string | null;
};

/** Whether a user may see this ticket. */
export function canViewTicket(u: SessionUser, t: any): boolean {
  if (u.role === 'vendor') {
    return Number(t.vendor_id) === Number(u.vendor_id);
  }
  return true;
}

/** SQL scope + params for a user's ticket list. */
export function ticketScope(u: SessionUser): { where: string; params: any[] } {
  if (u.role === 'vendor') return { where: 't.vendor_id = ?', params: [Number(u.vendor_id)] };
  return { where: '1=1', params: [] };
}

/** Tickets awaiting action from this user. */
export async function actionTickets(u: SessionUser, limit = 20): Promise<any[]> {
  const base = `SELECT t.*, c.name AS city_name, v.name AS vendor_name FROM tickets t
               JOIN cities c ON c.id=t.city_id LEFT JOIN vendors v ON v.id=t.vendor_id WHERE `;
  switch (u.role) {
    case 'vendor':
      return q(
        base +
          "t.vendor_id=? AND t.status IN ('assigned','on_progress','revision') ORDER BY t.sla_deadline IS NULL, t.sla_deadline LIMIT ?",
        [Number(u.vendor_id), limit]
      );
    case 'inventory':
      return q(
        base +
          `(t.status='inventory_check' OR EXISTS (SELECT 1 FROM ticket_materials m WHERE m.ticket_id=t.id AND m.qty_issued IS NULL))
           AND t.status NOT IN ('closed','cancelled') ORDER BY t.updated_at LIMIT ?`,
        [limit]
      );
    case 'foc':
    case 'nam':
      return q(base + "t.status='foc_review' ORDER BY t.updated_at LIMIT ?", [limit]);
    case 'procurement':
      return q(
        base +
          `t.status NOT IN ('closed','cancelled')
           ORDER BY t.sla_deadline IS NULL, t.sla_deadline ASC, t.id DESC LIMIT ?`,
        [limit]
      );
    default: // admin, noc
      return q(base + "t.status='open' ORDER BY t.created_at LIMIT ?", [limit]);
  }
}

/** Re-sync the SLA verdict of a single ticket. */
export async function syncTicketSla(ticketId: number): Promise<void> {
  await slaSyncTicket(ticketId);
}
