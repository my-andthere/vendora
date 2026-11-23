import { requireRole } from '@/lib/session';

export default async function Page() {
  await requireRole('admin', 'noc', 'procurement', 'foc', 'nam');
  return (
    <div className="card">
      <div className="card-header">rekap</div>
      <div className="card-body">Segera tersedia.</div>
    </div>
  );
}
