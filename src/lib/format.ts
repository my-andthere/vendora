/**
 * Formatting helpers — port of bootstrap.php (fmt_dt, fmt_duration, fmt_qty)
 * plus the duration formatter used by SLA / work-time badges.
 */

/** Parse a MySQL DATETIME string (or ISO string) into epoch seconds (local time). */
export function parseDbDate(value: string | null | undefined): number | null {
  if (!value) return null;
  const s = String(value).trim();
  if (!s) return null;
  // "YYYY-MM-DD HH:MM:SS" -> "YYYY-MM-DDTHH:MM:SS" for reliable local parsing
  const iso = s.includes('T') ? s : s.replace(' ', 'T');
  const t = new Date(iso).getTime();
  return Number.isNaN(t) ? null : Math.floor(t / 1000);
}

/** Current local datetime string in MySQL format. */
export function nowSql(d: Date = new Date()): string {
  const p = (n: number) => String(n).padStart(2, '0');
  return (
    `${d.getFullYear()}-${p(d.getMonth() + 1)}-${p(d.getDate())} ` +
    `${p(d.getHours())}:${p(d.getMinutes())}:${p(d.getSeconds())}`
  );
}

/** Format a datetime for display: 12/01/2026 09:30 */
export function fmtDt(dt: string | null | undefined): string {
  const t = parseDbDate(dt);
  if (t === null) return '-';
  const d = new Date(t * 1000);
  const p = (n: number) => String(n).padStart(2, '0');
  return `${p(d.getDate())}/${p(d.getMonth() + 1)}/${d.getFullYear()} ${p(d.getHours())}:${p(d.getMinutes())}`;
}

/** Format minutes as duration: "3 jam 0 menit" */
export function fmtDuration(minutes: number): string {
  const neg = minutes < 0;
  const m = Math.abs(Math.trunc(minutes));
  const s = `${m >= 60 ? `${Math.trunc(m / 60)} jam ` : ''}${m % 60} menit`;
  return neg ? `-${s}` : s;
}

/** Format a quantity: 2 decimal places, trailing zeros trimmed. */
export function fmtQty(n: number | string | null | undefined): string {
  if (n === null || n === undefined || n === '') return '-';
  const num = Number(n);
  if (!Number.isFinite(num)) return '-';
  let s = num.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
  s = s.replace(/\.?0+$/, '');
  return s;
}

/**
 * Format a duration in seconds: 2j 05m / 05m 30d / 30d
 * (minutes shown as "m", remaining seconds shown as "d" — matching legacy UI).
 */
export function fmtDurSeconds(sec: number): string {
  const s = Math.max(0, Math.trunc(sec));
  const h = Math.floor(s / 3600);
  const m = Math.floor((s % 3600) / 60);
  const rem = s % 60;
  if (h > 0) return `${h}j ${String(m).padStart(2, '0')}m`;
  if (m > 0) return `${m}m ${String(rem).padStart(2, '0')}d`;
  return `${rem}d`;
}

/** Escape HTML entities (needed when injecting raw strings into markup). */
export function esc(s: unknown): string {
  return String(s ?? '')
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;')
    .replace(/'/g, '&#039;');
}
