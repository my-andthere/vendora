import { requireUser } from '@/lib/session';
import PageHeader from '@/components/page-header';
import EmptyState from '@/components/empty-state';

export default async function ProfilePage() {
  await requireUser();
  return (
    <>
      <PageHeader
        title="Profil Saya"
        subtitle="Kelola kata sandi dan keamanan akun Anda."
        icon="bi-person-gear"
        breadcrumb={[{ label: 'Profil' }]}
      />

      <div className="card">
        <div className="card-header">
          <span className="flex items-center gap-2">
            <i className="bi bi-person-gear text-slate-400" /> Profil Saya
          </span>
          <span className="rounded-full bg-slate-100 px-2.5 py-0.5 text-xs font-medium text-slate-500">
            Segera hadir
          </span>
        </div>
        <div className="card-body">
          <EmptyState icon="bi-person-lock" title="Pengaturan profil" text="Formulir ganti kata sandi akan tersedia di sini." />
        </div>
      </div>
    </>
  );
}
