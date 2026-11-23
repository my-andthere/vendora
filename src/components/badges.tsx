import {
  PRIORITIES,
  TICKET_STATUS,
  VENDOR_STATUS,
} from '@/lib/config';
import { fmtDurSeconds, fmtDt } from '@/lib/format';
import { acceptInfo, slaState, workTime, type TicketLike } from '@/lib/sla';

/**
 * Badge helpers — port of bootstrap.php
 * (status_badge / vendor_badge / priority_badge / sla_badge / work_time_badge).
 */

const VARIANT: Record<string, string> = {
  secondary: 'bg-slate-100 text-slate-600 ring-1 ring-slate-200/80 ring-inset',
  info: 'bg-sky-50 text-sky-700 ring-1 ring-sky-200/80 ring-inset',
  primary: 'bg-blue-50 text-blue-700 ring-1 ring-blue-200/80 ring-inset',
  warning: 'bg-amber-50 text-amber-700 ring-1 ring-amber-200/80 ring-inset',
  danger: 'bg-red-50 text-red-700 ring-1 ring-red-200/80 ring-inset',
  success: 'bg-emerald-50 text-emerald-700 ring-1 ring-emerald-200/80 ring-inset',
  dark: 'bg-slate-800 text-white',
  light: 'bg-white text-slate-600 ring-1 ring-slate-200 ring-inset',
};

function cls(variant: string): string {
  return VARIANT[variant] ?? VARIANT.secondary;
}

export function StatusBadge({ status }: { status?: string | null }) {
  const s = TICKET_STATUS[status ?? ''];
  if (!s) return <span className={`badge ${cls('secondary')}`}>{status ?? '-'}</span>;
  return <span className={`badge ${cls(s.variant)}`}>{s.label}</span>;
}

export function PriorityBadge({ priority }: { priority?: string | null }) {
  const p = PRIORITIES[priority ?? ''];
  if (!p) return <span className={`badge ${cls('secondary')}`}>{priority ?? '-'}</span>;
  return <span className={`badge ${cls(p.variant)}`}>{p.label}</span>;
}

export function VendorBadge({ status }: { status?: string | null }) {
  const v = VENDOR_STATUS[status ?? ''];
  if (!v) return <span className={`badge ${cls('secondary')}`}>{status ?? '-'}</span>;
  return <span className={`badge ${cls(v.variant)}`}>{v.label}</span>;
}

/**
 * Live SLA badge.
 * - PERMANENT ticket: green "Sesuai SLA" / red "Lewat SLA" (final verdict)
 * - running ticket: countdown of remaining time, turns red once overdue
 */
export function SlaBadge({ ticket }: { ticket: TicketLike }) {
  const s = slaState(ticket);
  if (!s.deadline || (ticket.status ?? '') === 'cancelled') {
    return <span className="text-xs text-slate-400">-</span>;
  }

  if (s.final) {
    const diff = fmtDurSeconds(Math.abs(s.remaining ?? 0));
    const tip =
      `Selesai (PERMANENT) ${fmtDt(ticket.work_finished_at)} - batas SLA ${fmtDt(s.deadline)}` +
      ` (${s.overdue ? 'terlambat ' : 'selisih '}${diff})`;
    return (
      <span className={`badge ${s.overdue ? cls('danger') : cls('success')}`} title={tip}>
        {s.overdue ? 'Lewat SLA' : 'Sesuai SLA'}
      </span>
    );
  }

  if (s.overdue) {
    return (
      <span
        className={`badge ${cls('danger')}`}
        title={`Batas SLA ${fmtDt(s.deadline)} - sudah lewat, pekerjaan belum PERMANENT`}
      >
        Lewat SLA · {fmtDurSeconds(-(s.remaining ?? 0))}
      </span>
    );
  }
  return (
    <span
      className={`badge ${cls('light')} border border-slate-300`}
      title={`Batas SLA ${fmtDt(s.deadline)} - masih berjalan, belum PERMANENT`}
    >
      Sisa {fmtDurSeconds(s.remaining ?? 0)}
    </span>
  );
}

/** Short explanation of the SLA state for text (e.g. on the ticket detail page). */
export function slaNote(ticket: TicketLike): string {
  const s = slaState(ticket);
  if (!s.deadline) return 'Belum ada batas SLA (tiket belum ditugaskan).';
  if ((ticket.status ?? '') === 'cancelled') return 'Tiket dibatalkan - SLA tidak dinilai.';
  if (s.final) {
    return s.overdue
      ? `Selesai PERMANENT ${fmtDt(ticket.work_finished_at)}, terlambat ${fmtDurSeconds(-(s.remaining ?? 0))} dari batas SLA.`
      : `Selesai PERMANENT ${fmtDt(ticket.work_finished_at)}, sesuai batas SLA.`;
  }
  return s.overdue
    ? `Masih berjalan - sudah lewat batas SLA ${fmtDt(s.deadline)} (${fmtDurSeconds(-(s.remaining ?? 0))} keterlambatan).`
    : `Masih berjalan - sisa ${fmtDurSeconds(s.remaining ?? 0)} sampai batas SLA ${fmtDt(s.deadline)}.`;
}

/** Work-time badge (live counter while running). */
export function WorkTimeBadge({ ticket }: { ticket: TicketLike }) {
  const w = workTime(ticket);
  if (!w.started) return <span className="text-xs text-slate-400">-</span>;
  const txt = fmtDurSeconds(w.seconds);
  if (w.permanent) {
    return (
      <span className={`badge ${cls('success')}`} title="Waktu pengerjaan (berhenti saat PERMANENT)">
        ⏲ {txt}
      </span>
    );
  }
  if (!w.running) {
    const c = (ticket.status ?? '') === 'cancelled' ? cls('secondary') : `${cls('light')} border border-slate-300`;
    return (
      <span className={`badge ${c}`} title="Waktu pengerjaan (berhenti)">
        ⏲ {txt}
      </span>
    );
  }
  return (
    <span className={`badge ${cls('primary')}`} title="Waktu pengerjaan berjalan, berhenti saat PERMANENT">
      ⏲ {txt}
    </span>
  );
}

/** "Diterima <tanggal>" / "Belum diterima" sub-line for the vendor column. */
export function AcceptLine({ ticket }: { ticket: TicketLike }) {
  const info = acceptInfo(ticket);
  if (info.accepted) {
    return (
      <div className="mt-0.5 text-[0.7rem] text-slate-500">
        <i className="bi bi-check2-circle text-emerald-600" /> Diterima {fmtDt(info.accepted)}
      </div>
    );
  }
  if (info.assigned) {
    return <div className="mt-0.5 text-[0.7rem] text-slate-500">Belum diterima</div>;
  }
  return null;
}
