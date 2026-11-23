import { requireRole } from '@/lib/session';

export default async function ProcurementDashboardPage() {
  await requireRole('procurement');
  return (
    <div className="card">
      <div className="card-header">Dashboard Procurement</div>
      <div className="card-body">Segera tersedia.</div>
    </div>
  );
}
