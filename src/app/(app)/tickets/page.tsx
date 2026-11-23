import Link from 'next/link';
import { requireRole } from '@/lib/session';
import { q, q1 } from '@/lib/db';
import { TICKET_STATUS } from '@/lib/config';
import { slaCaseSql } from '@/lib/sla';
import { ticketScope } from '@/lib/tickets';
import TicketTable, { type TicketRow } from '@/components/ticket-table';

export const dynamic = 'force-dynamic';

type Search = {
  q?: string;
  status?: string;
  city?: string;
  sla?: string;
  from?: string;
  to?: string;
  page?: string;
};

const PER = 25;

export default async function TicketsPage({ searchParams }: { searchParams: Promise<Search> }) {
  const user = await requireRole('admin', 'noc', 'vendor', 'inventory', 'foc', 'procurement');
  const sp = await searchParams;

  const f = {
    q: (sp.q ?? '').trim(),
    status: sp.status ?? '',
    city: Number(sp.city ?? 0),
    sla: sp.sla ?? '',
    from: sp.from ?? '',
    to: sp.to ?? '',
  };

  const scope = ticketScope(user);
  let where = scope.where;
  const params = scope.params;

  if (f.q !== '') {
    where +=
      ' AND (t.ticket_no LIKE ? OR t.noc_ref LIKE ? OR t.customer_name LIKE ? OR t.location LIKE ? OR t.problem_type LIKE ?)';
    params.push(...Array(5).fill(`%${f.q}%`));
  }
  if (TICKET_STATUS[f.status]) {
    where += ' AND t.status=?';
    params.push(f.status);
  }
  if (f.city) {
    where += ' AND t.city_id=?';
    params.push(f.city);
  }
  if (f.sla === 'ontime' || f.sla === 'late') {
    // SLA is measured until PERMANENT -> use the live expression, not the cached column.
    where += ` AND ${slaCaseSql('t')}=?`;
    params.push(f.sla);
  }
  if (f.from) {
    where += ' AND DATE(t.created_at) >= ?';
    params.push(f.from);
  }
  if (f.to) {
    where += ' AND DATE(t.created_at) <= ?';
    params.push(f.to);
  }

  const page = Math.max(1, Number(sp.page ?? 1) || 1);
  const totalRow = await q1<{ c: number }>(`SELECT COUNT(*) AS c FROM tickets t WHERE ${where}`, params);
  const total = Number(totalRow?.c ?? 0);

  const rows = (await q<TicketRow>(
    `SELECT t.*, c.name AS city_name, v.name AS vendor_name FROM tickets t
     JOIN cities c ON c.id=t.city_id LEFT JOIN vendors v ON v.id=t.vendor_id
     WHERE ${where} ORDER BY t.id DESC LIMIT ? OFFSET ?`,
    [...params, PER, (page - 1) * PER]
  )) as TicketRow[];

  const cities = await q<{ id: number; name: string }>('SELECT * FROM cities ORDER BY name');

  const pages = Math.ceil(total / PER);
  const pageLink = (n: number) => {
    const qs = new URLSearchParams();
    if (f.q) qs.set('q', f.q);
    if (f.status) qs.set('status', f.status);
    if (f.city) qs.set('city', String(f.city));
    if (f.sla) qs.set('sla', f.sla);
    if (f.from) qs.set('from', f.from);
    if (f.to) qs.set('to', f.to);
    if (n > 1) qs.set('page', String(n));
    const s = qs.toString();
    return s ? `/tickets?${s}` : '/tickets';
  };

  const canCreate = user.role === 'admin' || user.role === 'noc';

  return (
    <>
      <div className="mb-3 flex items-center justify-between">
        <h4 className="mb-0 text-lg font-semibold">Daftar Tiket</h4>
        {canCreate ? (
          <Link href="/tickets/new" className="btn btn-primary">
            <i className="bi bi-plus-circle" /> Buat Tiket
          </Link>
        ) : null}
      </div>

      <form className="card card-body mb-3" method="get">
        <div className="flex flex-wrap gap-2">
          <input
            name="q"
            className="form-control form-control-sm w-full sm:w-64"
            placeholder="Cari no tiket / ref NOC / pelanggan / lokasi"
            defaultValue={f.q}
          />
          <select name="status" className="form-select form-select-sm w-auto" defaultValue={f.status}>
            <option value="">Semua status</option>
            {Object.entries(TICKET_STATUS).map(([k, v]) => (
              <option key={k} value={k}>
                {v.label}
              </option>
            ))}
          </select>
          <select
            name="city"
            className="form-select form-select-sm w-auto"
            defaultValue={String(f.city || '')}
          >
            <option value="">Semua kota</option>
            {cities.map((c) => (
              <option key={c.id} value={c.id}>
                {c.name}
              </option>
            ))}
          </select>
          <select name="sla" className="form-select form-select-sm w-auto" defaultValue={f.sla}>
            <option value="">SLA</option>
            <option value="ontime">Sesuai</option>
            <option value="late">Lewat</option>
          </select>
          <input
            type="date"
            name="from"
            className="form-control form-control-sm w-auto"
            defaultValue={f.from}
            title="Dari tanggal"
          />
          <input
            type="date"
            name="to"
            className="form-control form-control-sm w-auto"
            defaultValue={f.to}
            title="Sampai tanggal"
          />
          <div className="flex gap-1">
            <button className="btn btn-sm btn-primary flex-1">
              <i className="bi bi-search" /> Filter
            </button>
            <Link href="/tickets" className="btn btn-sm btn-outline-secondary">
              Reset
            </Link>
          </div>
        </div>
      </form>

      <div className="card">
        <TicketTable rows={rows} empty="Tidak ada tiket yang cocok." />
        {total > PER ? (
          <div className="flex items-center justify-between border-t border-slate-200 bg-white px-4 py-3 text-sm">
            <span>{total} tiket</span>
            <ul className="mb-0 flex gap-1">
              {Array.from({ length: pages }, (_, i) => i + 1).map((n) => (
                <li key={n}>
                  <Link
                    href={pageLink(n)}
                    aria-current={n === page ? 'page' : undefined}
                    className={
                      n === page
                        ? 'rounded bg-blue-600 px-2.5 py-1 text-xs font-medium text-white'
                        : 'rounded border border-slate-300 px-2.5 py-1 text-xs text-slate-600 hover:bg-slate-100'
                    }
                  >
                    {n}
                  </Link>
                </li>
              ))}
            </ul>
          </div>
        ) : null}
      </div>
    </>
  );
}
