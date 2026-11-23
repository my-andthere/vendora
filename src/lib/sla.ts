import { exec, q, q1 } from './db';
import { fmtDurSeconds, parseDbDate } from './format';

/**
 * SLA & work-time domain logic — port of bootstrap.php:
 * sla_state / sla_case_sql / sla_resync / sla_sync_ticket / work_time / accept_info.
 *
 * SLA is measured until the job is declared PERMANENT.
 */

export type SlaVerdict = 'ontime' | 'late' | null;

export type SlaState = {
  verdict: SlaVerdict;
  final: boolean;
  overdue: boolean;
  remaining: number | null; // seconds; negative = overdue
  deadline: string | null;
};

export type TicketLike = {
  id?: number;
  status?: string;
  sla_deadline?: string | null;
  work_finished_at?: string | null;
  completed_at?: string | null;
  updated_at?: string | null;
  assigned_at?: string | null;
  accepted_at?: string | null;
  work_started_at?: string | null;
  repair_result?: string | null;
};

/** Live SLA state for a single ticket. */
export function slaState(t: TicketLike): SlaState {
  const deadline = t.sla_deadline ?? null;
  const status = t.status ?? '';
  const out: SlaState = { verdict: null, final: false, overdue: false, remaining: null, deadline };
  if (!deadline || status === 'cancelled') return out;

  const dl = parseDbDate(deadline);
  if (dl === null) return out;

  if (status === 'closed') {
    const fin =
      parseDbDate(t.work_finished_at) ?? parseDbDate(t.completed_at) ?? parseDbDate(t.updated_at);
    if (fin !== null) {
      out.final = true;
      out.remaining = dl - fin;
      out.overdue = out.remaining < 0;
      out.verdict = out.overdue ? 'late' : 'ontime';
      return out;
    }
  }

  // Still running: live value.
  out.remaining = dl - Math.floor(Date.now() / 1000);
  if (out.remaining < 0) {
    out.overdue = true;
    out.verdict = 'late'; // definitely late, final verdict waits for PERMANENT
  }
  return out;
}

/**
 * SQL CASE expression for SLA status. Must stay identical to slaState().
 * The `sla_status` column is only a cache; queries use this expression so
 * aggregates always see the live value.
 */
export function slaCaseSql(alias = ''): string {
  const p = alias === '' ? '' : `${alias}.`;
  return (
    'CASE' +
    ` WHEN ${p}sla_deadline IS NULL THEN NULL` +
    ` WHEN ${p}status = 'cancelled' THEN NULL` +
    ` WHEN ${p}status = 'closed' THEN` +
    `   IF(COALESCE(${p}work_finished_at, ${p}completed_at, ${p}updated_at) <= ${p}sla_deadline, 'ontime', 'late')` +
    ` WHEN NOW() > ${p}sla_deadline THEN 'late'` +
    ' ELSE NULL END'
  );
}

/**
 * Resync the cached tickets.sla_status column (throttled to once per minute
 * using the app_meta table so it is safe across servers).
 */
export async function slaResync(): Promise<void> {
  const row = await q1<{ v: string }>("SELECT v FROM app_meta WHERE k='sla_last_sync'");
  const last = Number(row?.v ?? 0);
  const now = Math.floor(Date.now() / 1000);
  if (now - last < 60) return;

  const c = slaCaseSql();
  await exec(
    `UPDATE tickets SET sla_status = (${c}), updated_at = updated_at
     WHERE sla_deadline IS NOT NULL AND NOT (sla_status <=> (${c}))`
  );
  await exec(
    "INSERT INTO app_meta (k, v) VALUES ('sla_last_sync', ?) ON DUPLICATE KEY UPDATE v = VALUES(v)",
    [String(now)]
  );
}

/** Recompute sla_status for a single ticket (called when a ticket changes state). */
export async function slaSyncTicket(ticketId: number): Promise<void> {
  await exec(`UPDATE tickets SET sla_status = (${slaCaseSql()}) WHERE id=?`, [ticketId]);
}

export type WorkTime = {
  start: string | null;
  end: string | null;
  seconds: number;
  running: boolean;
  started: boolean;
  permanent: boolean;
};

/**
 * Elapsed work time of a ticket. Keeps running until the ticket is declared
 * PERMANENT (it does not stop at inventory check / FOC review / revision).
 */
export function workTime(t: TicketLike): WorkTime {
  let start = t.work_started_at ?? null;
  if (!start) start = t.assigned_at ?? null; // fallback for legacy rows
  const endRaw = t.work_finished_at ?? null;
  const status = t.status ?? '';

  if (!start) {
    return { start: null, end: null, seconds: 0, running: false, started: false, permanent: false };
  }

  let running = false;
  let end = endRaw;
  if (!end) {
    if (status === 'closed' || status === 'cancelled') {
      // Legacy rows without a work timestamp: fall back to completion time.
      end = t.completed_at ?? t.updated_at ?? null;
    } else {
      running = true;
    }
  }

  let seconds = 0;
  const s = parseDbDate(start);
  const e = end ? parseDbDate(end) : running ? Math.floor(Date.now() / 1000) : null;
  if (s !== null && e !== null) seconds = Math.max(0, e - s);

  return {
    start,
    end: endRaw,
    seconds,
    running,
    started: true,
    permanent: status === 'closed' && (t.repair_result ?? '') === 'permanent',
  };
}

export type AcceptInfo = {
  accepted: string | null;
  assigned: string | null;
  delay: number | null;
  acceptedLabel: string;
  delayLabel: string;
};

/** When the vendor pressed "Terima & Mulai Kerjakan" and how long that took. */
export function acceptInfo(t: TicketLike): AcceptInfo {
  const assigned = t.assigned_at ?? null;
  const accepted = t.accepted_at ?? null;
  const a = parseDbDate(assigned);
  const b = parseDbDate(accepted);
  const delay = a !== null && b !== null ? Math.max(0, b - a) : null;

  return {
    accepted,
    assigned,
    delay,
    acceptedLabel: accepted ? fmtDtSafe(accepted) : 'Belum diterima',
    delayLabel:
      accepted !== null
        ? delay !== null
          ? fmtDurSeconds(delay)
          : '-'
        : assigned
          ? 'Menunggu vendor menerima'
          : '-',
  };
}

function fmtDtSafe(v: string): string {
  // Local import avoided to prevent a circular import at module-init time.
  const t = parseDbDate(v);
  if (t === null) return '-';
  const d = new Date(t * 1000);
  const p = (n: number) => String(n).padStart(2, '0');
  return `${p(d.getDate())}/${p(d.getMonth() + 1)}/${d.getFullYear()} ${p(d.getHours())}:${p(d.getMinutes())}`;
}

/** Tickets with a live SLA verdict (helper for dashboards). */
export async function ticketsSlaSummary(ids: number[]): Promise<Map<number, SlaState>> {
  const map = new Map<number, SlaState>();
  if (!ids.length) return map;
  const rows = await q<any>(
    `SELECT id, status, sla_deadline, work_finished_at, completed_at, updated_at
     FROM tickets WHERE id IN (${ids.map(() => '?').join(',')})`,
    ids
  );
  for (const r of rows) map.set(Number(r.id), slaState(r));
  return map;
}
