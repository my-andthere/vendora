import { requireRole } from '@/lib/session';
import PageHeader from '@/components/page-header';
import EmptyState from '@/components/empty-state';

export default async function UsersPage() {
  await requireRole('admin');
  return (
    <>
      <PageHeader
        title="User"
        subtitle="Manajemen akun dan hak akses pengguna."
        icon="bi-people"
        breadcrumb={[{ label: 'User' }]}
      />

      <div className="card">
        <div className="card-header">
          <span className="flex items-center gap-2">
            <i className="bi bi-people text-slate-400" /> User
          </span>
          <span className="rounded-full bg-slate-100 px-2.5 py-0.5 text-xs font-medium text-slate-500">
            Segera hadir
          </span>
        </div>
        <div className="card-body">
          <EmptyState icon="bi-people" title="Belum ada pengguna" text="Akun pengguna beserta peran dan aksesnya dikelola di sini." />
        </div>
      </div>
    </>
  );
}
