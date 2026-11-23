import { requireRole } from '@/lib/session';

export default async function Page() {
  await requireRole('admin', 'inventory', 'procurement');
  return (
    <div className="card">
      <div className="card-header">materials</div>
      <div className="card-body">Segera tersedia.</div>
    </div>
  );
}
