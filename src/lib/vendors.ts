import { exec, q, q1 } from './db';
import { WORKLOAD_STATUSES } from './config';

/**
 * Vendor region coverage, workload limits and candidate selection —
 * port of bootstrap.php: city_region_id, region_name, vendor_region_limits,
 * vendor_workload, vendor_can_take_job, vendor_serves_region, candidate_vendors,
 * refresh_vendor_status.
 */

/** Region id of a city (null when the city is not mapped to any region). */
export async function cityRegionId(cityId: number): Promise<number | null> {
  const row = await q1<{ region_id: number | null }>('SELECT region_id FROM cities WHERE id=?', [
    cityId,
  ]);
  return row?.region_id ?? null;
}

/** Region name (safe to call with a null id). */
export async function regionName(regionId: number | null): Promise<string> {
  if (!regionId) return '-';
  const row = await q1<{ name: string }>('SELECT name FROM regions WHERE id=?', [regionId]);
  return row?.name ?? '-';
}

/** All regions (id => name) — useful for dropdowns. */
export async function allRegions(): Promise<{ id: number; name: string; code: string | null }[]> {
  return q('SELECT id, name, code FROM regions ORDER BY name');
}

/**
 * Per-region workload limits of a vendor: [region_id => limit].
 * A limit of 0 means "unlimited".
 */
export async function vendorRegionLimits(vendorId: number): Promise<Record<number, number>> {
  const rows = await q<{ region_id: number; workload_limit: number }>(
    'SELECT region_id, workload_limit FROM vendor_regions WHERE vendor_id=?',
    [vendorId]
  );
  const out: Record<number, number> = {};
  for (const r of rows) out[Number(r.region_id)] = Number(r.workload_limit);
  return out;
}

export type VendorWorkload = {
  active: number;
  limit: number;
  unlimited: boolean;
  free: number;
  full: boolean;
  region: number | null;
};

/**
 * Active workload of a vendor vs its limit. The limit comes from the
 * vendor-region pair when the region is known, otherwise from the vendor's
 * global limit.
 *
 * `excludeTicketId` is used when re-assigning a ticket so the ticket itself is
 * not counted (a vendor already holding it may take a re-assignment).
 */
export async function vendorWorkload(
  vendorId: number,
  regionId: number | null = null,
  excludeTicketId: number | null = null
): Promise<VendorWorkload> {
  const globalLimit = Number(
    (await q1<{ workload_limit: number }>('SELECT workload_limit FROM vendors WHERE id=?', [vendorId]))
      ?.workload_limit ?? 0
  );

  const ph = WORKLOAD_STATUSES.map(() => '?').join(',');
  let sql: string;
  const params: any[] = [];
  let limit = globalLimit;

  if (regionId !== null) {
    const limits = await vendorRegionLimits(vendorId);
    if (regionId in limits) limit = limits[regionId];
    sql = `SELECT COUNT(*) AS n FROM tickets t JOIN cities c ON c.id=t.city_id
           WHERE t.vendor_id=? AND c.region_id=? AND t.status IN (${ph})`;
    params.push(vendorId, regionId);
  } else {
    sql = `SELECT COUNT(*) AS n FROM tickets t WHERE t.vendor_id=? AND t.status IN (${ph})`;
    params.push(vendorId);
  }
  params.push(...WORKLOAD_STATUSES);

  if (excludeTicketId) {
    sql += ' AND t.id<>?';
    params.push(excludeTicketId);
  }

  const row = await q1<{ n: number }>(sql, params);
  const active = Number(row?.n ?? 0);
  const unlimited = limit <= 0;
  const free = unlimited ? -1 : Math.max(0, limit - active);

  return {
    active,
    limit,
    unlimited,
    free,
    full: !unlimited && active >= limit,
    region: regionId,
  };
}

/** Whether a vendor may still take new work in a region. */
export async function vendorCanTakeJob(
  vendorId: number,
  regionId: number | null = null,
  excludeTicketId: number | null = null
): Promise<boolean> {
  const w = await vendorWorkload(vendorId, regionId, excludeTicketId);
  return !w.full;
}

/** Whether a vendor is registered to serve a region. */
export async function vendorServesRegion(vendorId: number, regionId: number | null): Promise<boolean> {
  if (regionId === null) return false;
  const limits = await vendorRegionLimits(vendorId);
  return regionId in limits;
}

export type CandidateVendor = {
  id: number;
  name: string;
  vendor_type: string;
  city_id: number;
  workload_limit: number;
  region_limit: number;
  region_id: number | null;
  active_jobs: number;
  load_jobs: number;
  free_slots: number;
  limit: number;
};

/**
 * Available vendors serving the ticket's region, ordered by the lightest
 * active workload. Vendors whose workload limit is already full are excluded.
 * When the ticket's city is not mapped to a region, the legacy rule applies:
 * vendors located in the same city.
 */
export async function candidateVendors(
  ticketId: number,
  excludeRejected = true
): Promise<CandidateVendor[]> {
  await refreshVendorStatus();

  const t = await q1<{ city_id: number }>('SELECT city_id FROM tickets WHERE id=?', [ticketId]);
  const cityId = Number(t?.city_id ?? 0);
  const regionId = await cityRegionId(cityId);
  const statusList = WORKLOAD_STATUSES.map(() => '?').join(',');

  let sql: string;
  let params: any[];

  if (regionId !== null) {
    sql = `SELECT v.*, vr.workload_limit AS region_limit, vr.region_id,
                  (SELECT COUNT(*) FROM tickets x JOIN cities c ON c.id=x.city_id
                     WHERE x.vendor_id=v.id AND c.region_id=vr.region_id AND x.status IN (${statusList})) AS active_jobs,
                  (SELECT COUNT(*) FROM tickets x JOIN cities c ON c.id=x.city_id
                     WHERE x.vendor_id=v.id AND c.region_id=vr.region_id AND x.id<>? AND x.status IN (${statusList})) AS load_jobs
           FROM vendors v JOIN vendor_regions vr ON vr.vendor_id=v.id AND vr.region_id=?
           WHERE v.status='available'`;
    params = [...WORKLOAD_STATUSES, ticketId, ...WORKLOAD_STATUSES, regionId];
  } else {
    sql = `SELECT v.*, v.workload_limit AS region_limit, NULL AS region_id,
                  (SELECT COUNT(*) FROM tickets x WHERE x.vendor_id=v.id AND x.status IN (${statusList})) AS active_jobs,
                  (SELECT COUNT(*) FROM tickets x WHERE x.vendor_id=v.id AND x.id<>? AND x.status IN (${statusList})) AS load_jobs
           FROM vendors v WHERE v.city_id=? AND v.status='available'`;
    params = [...WORKLOAD_STATUSES, ticketId, ...WORKLOAD_STATUSES, cityId];
  }

  if (excludeRejected) {
    sql += ` AND v.id NOT IN (SELECT vendor_id FROM ticket_rejections WHERE ticket_id=${Number(ticketId)})`;
  }
  sql += ' ORDER BY load_jobs ASC, RAND()';

  const rows = await q<any>(sql, params);
  const out: CandidateVendor[] = [];
  for (const r of rows) {
    const limit = Number(r.region_limit ?? 0);
    if (limit > 0 && Number(r.load_jobs) >= limit) continue; // limit reached
    out.push({
      ...r,
      region_limit: limit,
      region_id: r.region_id ?? null,
      active_jobs: Number(r.active_jobs ?? 0),
      load_jobs: Number(r.load_jobs ?? 0),
      free_slots: limit > 0 ? limit - Number(r.load_jobs) : -1,
      limit,
    });
  }
  return out;
}

/**
 * Freeze status of a vendor whose freeze period has expired is reverted to
 * available automatically.
 */
export async function refreshVendorStatus(): Promise<void> {
  const rows = await q<{ id: number }>(
    "SELECT id FROM vendors WHERE status='freeze' AND freeze_until IS NOT NULL AND freeze_until <= NOW()"
  );
  for (const r of rows) {
    await exec(
      `UPDATE vendors SET status='available', freeze_until=NULL,
       status_note='Masa freeze berakhir otomatis', status_changed_at=NOW() WHERE id=?`,
      [r.id]
    );
  }
}

/** Replace a vendor's skill list wholesale. */
export async function syncVendorSkills(vendorId: number, skillIds: number[]): Promise<void> {
  if (vendorId <= 0) return;
  await exec('DELETE FROM vendor_skills WHERE vendor_id=?', [vendorId]);
  for (const raw of skillIds) {
    const sid = Number(raw);
    if (sid <= 0) continue;
    const ok = await q1('SELECT 1 AS x FROM skills WHERE id=?', [sid]);
    if (ok) await exec('INSERT IGNORE INTO vendor_skills (vendor_id, skill_id) VALUES (?,?)', [vendorId, sid]);
  }
}

/** Replace a vendor's region registrations (and their workload limits). */
export async function syncVendorRegions(
  vendorId: number,
  limits: Record<number, number>
): Promise<void> {
  if (vendorId <= 0) return;
  await exec('DELETE FROM vendor_regions WHERE vendor_id=?', [vendorId]);
  for (const [ridRaw, limitRaw] of Object.entries(limits)) {
    const rid = Number(ridRaw);
    if (rid <= 0) continue;
    const ok = await q1('SELECT 1 AS x FROM regions WHERE id=?', [rid]);
    if (!ok) continue;
    await exec('INSERT INTO vendor_regions (vendor_id, region_id, workload_limit) VALUES (?,?,?)', [
      vendorId,
      rid,
      Math.max(0, Number(limitRaw)),
    ]);
  }
}

/** Skills of one vendor. */
export async function vendorSkills(vendorId: number): Promise<{ id: number; name: string }[]> {
  return q(
    `SELECT s.id, s.name FROM vendor_skills vs JOIN skills s ON s.id=vs.skill_id
     WHERE vs.vendor_id=? ORDER BY s.name`,
    [vendorId]
  );
}

/** Skills of all vendors at once: [vendor_id => [{id, name}]]. */
export async function allVendorSkills(): Promise<Record<number, { id: number; name: string }[]>> {
  const rows = await q<{ vendor_id: number; id: number; name: string }>(
    `SELECT vs.vendor_id, s.id, s.name FROM vendor_skills vs JOIN skills s ON s.id=vs.skill_id
     ORDER BY s.name`
  );
  const out: Record<number, { id: number; name: string }[]> = {};
  for (const r of rows) {
    (out[Number(r.vendor_id)] ??= []).push({ id: Number(r.id), name: r.name });
  }
  return out;
}
