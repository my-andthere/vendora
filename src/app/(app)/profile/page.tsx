import { requireUser } from '@/lib/session';

export default async function ProfilePage() {
  await requireUser();
  return (
    <div className="card">
      <div className="card-header">Ganti Password</div>
      <div className="card-body">Segera tersedia.</div>
    </div>
  );
}
