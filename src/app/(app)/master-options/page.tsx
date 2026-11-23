import { requireRole } from '@/lib/session';

export default async function Page() {
  await requireRole('admin', 'procurement');
  return (
    <div className="card">
      <div className="card-header">master-options</div>
      <div className="card-body">Segera tersedia.</div>
    </div>
  );
}
