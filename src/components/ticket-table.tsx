import Link from 'next/link';
import { fmtDt } from '@/lib/format';
import type { TicketLike } from '@/lib/sla';
import { AcceptLine, PriorityBadge, SlaBadge, StatusBadge, WorkTimeBadge } from './badges';

/**
 * Ticket table — port of includes/ticket_table.php (renderTicketTable).
 */

export type TicketRow = TicketLike & {
  id: number;
  ticket_no: string;
  noc_ref?: string | null;
  city_name?: string | null;
  problem_type?: string | null;
  location?: string | null;
  priority?: string | null;
  vendor_name?: string | null;
  inv_result?: string | null;
  created_at?: string | null;
};

export default function TicketTable({
  rows,
  empty = 'Tidak ada data.',
}: {
  rows: TicketRow[];
  empty?: string;
}) {
  if (!rows.length) {
    return <div className="p-4 text-center text-slate-500">{empty}</div>;
  }

  return (
    <div className="overflow-x-auto">
      <table className="table">
        <thead>
          <tr>
            <th>No Tiket</th>
            <th>Kota</th>
            <th>Masalah</th>
            <th>Prioritas</th>
            <th>Vendor</th>
            <th>Status</th>
            <th>SLA</th>
            <th>Dibuat</th>
          </tr>
        </thead>
        <tbody>
          {rows.map((t) => (
            <tr key={t.id} className="hover:bg-slate-50">
              <td className="font-semibold">
                <Link href={`/tickets/${t.id}`} className="text-blue-700 hover:underline">
                  {t.ticket_no}
                </Link>
                {t.noc_ref ? <div className="text-slate-500">NOC: {t.noc_ref}</div> : null}
              </td>
              <td>{t.city_name ?? '-'}</td>
              <td>
                {t.problem_type ?? '-'}
                <div className="max-w-[220px] truncate text-slate-500">{t.location}</div>
              </td>
              <td>
                <PriorityBadge priority={t.priority} />
              </td>
              <td>
                {t.vendor_name ? (
                  t.vendor_name
                ) : (
                  <span className="text-slate-500">-</span>
                )}
                <AcceptLine ticket={t} />
              </td>
              <td>
                <StatusBadge status={t.status} />
                {t.inv_result === 'tidak_sesuai' ? (
                  <span className="badge bg-red-100 text-red-700" title="Material tidak sesuai">
                    Material ≠
                  </span>
                ) : null}
              </td>
              <td>
                {(t.status ?? '') === 'cancelled' ? (
                  <span className="text-xs text-slate-400">-</span>
                ) : (
                  <SlaBadge ticket={t} />
                )}
                <div className="mt-1">
                  <WorkTimeBadge ticket={t} />
                </div>
              </td>
              <td>{fmtDt(t.created_at)}</td>
            </tr>
          ))}
        </tbody>
      </table>
    </div>
  );
}
