import { requireUser } from '@/lib/session';

export default async function NotificationsPage() {
  await requireUser();
  return (
    <div className="card">
      <div className="card-header">Notifikasi</div>
      <div className="card-body">Segera tersedia.</div>
    </div>
  );
}
