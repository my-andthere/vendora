import { requireRole } from '@/lib/session';

export default async function Page() {
  await requireRole('admin');
  return (
    <div className="card">
      <div className="card-header">regions</div>
      <div className="card-body">Segera tersedia.</div>
    </div>
  );
}
