import { requireUser } from '@/lib/session';
import PageHeader from '@/components/page-header';
import EmptyState from '@/components/empty-state';

export default async function NotificationsPage() {
  await requireUser();
  return (
    <>
      <PageHeader
        title="Notifikasi"
        subtitle="Pemberitahuan masuk untuk akun Anda."
        icon="bi-bell"
        breadcrumb={[{ label: 'Notifikasi' }]}
      />

      <div className="card">
        <div className="card-header">
          <span className="flex items-center gap-2">
            <i className="bi bi-bell text-slate-400" /> Notifikasi
          </span>
          <span className="rounded-full bg-slate-100 px-2.5 py-0.5 text-xs font-medium text-slate-500">
            Segera hadir
          </span>
        </div>
        <div className="card-body">
          <EmptyState icon="bi-bell-slash" title="Tidak ada notifikasi" text="Anda sudah dipahami. Notifikasi baru akan muncul di sini." />
        </div>
      </div>
    </>
  );
}
