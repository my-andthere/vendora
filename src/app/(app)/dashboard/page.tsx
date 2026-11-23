import { requireRole } from '@/lib/session';

export default async function DashboardPage() {
  await requireRole('admin', 'noc', 'vendor', 'inventory', 'foc');
  return (
    <div className="card">
      <div className="card-header">Dashboard</div>
      <div className="card-body">Segera tersedia.</div>
    </div>
  );
}
