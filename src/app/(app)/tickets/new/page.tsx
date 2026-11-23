import { requireRole } from '@/lib/session';

export default async function TicketCreatePage() {
  await requireRole('admin', 'noc');
  return (
    <div className="card">
      <div className="card-header">Buat Tiket (NOC)</div>
      <div className="card-body">Segera tersedia.</div>
    </div>
  );
}
