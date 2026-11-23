import { exec, insert, q, q1 } from './db';
import { nowSql } from './format';
import { slaState } from './sla';

/**
 * Logging, internal notifications and NOC outbox — port of bootstrap.php
 * (log_ticket / notify_users / notify_role / notify_vendor / noc_notify / noc_send).
 */

const NOC_API_KEY = process.env.NOC_API_KEY ?? '';
const NOC_WEBHOOK_URL = process.env.NOC_WEBHOOK_URL ?? '';

/** Append an entry to the ticket activity log. */
export async function logTicket(
  ticketId: number,
  action: string,
  note = '',
  userId: number | null = null
): Promise<void> {
  await exec('INSERT INTO ticket_logs (ticket_id, user_id, action, note) VALUES (?,?,?,?)', [
    ticketId,
    userId,
    action,
    note,
  ]);
}

/** Send an in-app notification to a set of users. */
export async function notifyUsers(
  userIds: (number | null | undefined)[],
  msg: string,
  ticketId: number | null = null
): Promise<void> {
  const unique = Array.from(new Set(userIds.filter((v): v is number => v != null)));
  for (const uid of unique) {
    await exec('INSERT INTO notifications (user_id, ticket_id, message) VALUES (?,?,?)', [
      uid,
      ticketId,
      msg,
    ]);
  }
}

/** Notify every active user holding a given role. */
export async function notifyRole(
  role: string,
  msg: string,
  ticketId: number | null = null
): Promise<void> {
  const users = await q<{ id: number }>('SELECT id FROM users WHERE role=? AND is_active=1', [role]);
  await notifyUsers(users.map((u) => u.id), msg, ticketId);
}

/** Notify the active users belonging to a vendor. */
export async function notifyVendor(
  vendorId: number,
  msg: string,
  ticketId: number | null = null
): Promise<void> {
  const users = await q<{ id: number }>(
    'SELECT id FROM users WHERE vendor_id=? AND is_active=1',
    [vendorId]
  );
  await notifyUsers(users.map((u) => u.id), msg, ticketId);
}

export type NocEvent =
  | 'ticket_received'
  | 'vendor_assigned'
  | 'no_vendor_available'
  | 'vendor_accepted'
  | 'work_submitted'
  | 'temporary_fix'
  | 'permanent_closed'
  | 'cancelled';

/**
 * Record an event for the NOC system. Always written to noc_outbox; when
 * NOC_WEBHOOK_URL is configured it is POSTed immediately as JSON.
 */
export async function nocNotify(
  ticketId: number,
  event: NocEvent | string,
  extra: Record<string, unknown> = {}
): Promise<void> {
  const t = await q1<any>(
    `SELECT t.*, c.name AS city, v.name AS vendor
     FROM tickets t
     JOIN cities c ON c.id = t.city_id
     LEFT JOIN vendors v ON v.id = t.vendor_id
     WHERE t.id = ?`,
    [ticketId]
  );
  if (!t) return;

  const payload: Record<string, unknown> = {
    event,
    ticket_no: t.ticket_no,
    spk_no: t.spk_no ?? null,
    noc_ref: t.noc_ref,
    status: t.status,
    city: t.city,
    vendor: t.vendor,
    sla_deadline: t.sla_deadline,
    sla_status: slaState(t).verdict,
    repair_result: t.repair_result,
    timestamp: nowSql(),
    ...extra,
  };

  const json = JSON.stringify(payload);
  const outboxId = await insert('INSERT INTO noc_outbox (ticket_id, event, payload) VALUES (?,?,?)', [
    ticketId,
    event,
    json,
  ]);
  if (NOC_WEBHOOK_URL !== '') {
    await nocSend(outboxId);
  }
}

/** Attempt to deliver a queued outbox row to the NOC webhook. */
export async function nocSend(outboxId: number): Promise<boolean> {
  const row = await q1<any>('SELECT * FROM noc_outbox WHERE id=?', [outboxId]);
  if (!row || NOC_WEBHOOK_URL === '') return false;

  let ok = false;
  let detail = '';
  try {
    const controller = new AbortController();
    const timer = setTimeout(() => controller.abort(), 5000);
    const res = await fetch(NOC_WEBHOOK_URL, {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        'X-API-KEY': NOC_API_KEY,
      },
      body: row.payload,
      signal: controller.signal,
    });
    clearTimeout(timer);
    const text = await res.text();
    ok = res.status >= 200 && res.status < 300;
    detail = ok ? text.slice(0, 2000) : `HTTP ${res.status} ${text.slice(0, 1000)}`;
  } catch (e: any) {
    ok = false;
    detail = `ERROR ${e?.message ?? e}`;
  }

  await exec(
    'UPDATE noc_outbox SET send_status=?, response=?, attempts=attempts+1, sent_at=? WHERE id=?',
    [ok ? 'sent' : 'failed', detail, ok ? nowSql() : null, outboxId]
  );
  return ok;
}
