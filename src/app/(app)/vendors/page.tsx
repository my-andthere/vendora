import { requireRole } from '@/lib/session';

export default async function Page() {
  await requireRole('admin', 'noc', 'procurement');
  return (
    <div className="card">
      <div className="card-header">vendors</div>
      <div className="card-body">Segera tersedia.</div>
    </div>
  );
}
