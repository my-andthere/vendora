import { requireRole } from '@/lib/session';
import PageHeader from '@/components/page-header';
import EmptyState from '@/components/empty-state';

export default async function MasterOptionsPage() {
  await requireRole('admin', 'procurement');
  return (
    <>
      <PageHeader
        title="Master Option"
        subtitle="Pilihan konfigurasi yang dipakai pada formulir aplikasi."
        icon="bi-list-check"
        breadcrumb={[{ label: 'Master Option' }]}
      />

      <div className="card">
        <div className="card-header">
          <span className="flex items-center gap-2">
            <i className="bi bi-list-check text-slate-400" /> Master Option
          </span>
          <span className="rounded-full bg-slate-100 px-2.5 py-0.5 text-xs font-medium text-slate-500">
            Segera hadir
          </span>
        </div>
        <div className="card-body">
          <EmptyState icon="bi-list-check" title="Belum ada opsi" text="Konfigurasi pilihan dropdown dan parameter aplikasi diatur di sini." />
        </div>
      </div>
    </>
  );
}
