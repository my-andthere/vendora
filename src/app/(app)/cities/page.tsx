import { requireRole } from '@/lib/session';
import PageHeader from '@/components/page-header';
import EmptyState from '@/components/empty-state';

export default async function CitiesPage() {
  await requireRole('admin');
  return (
    <>
      <PageHeader
        title="Kota"
        subtitle="Daftar kota yang dilayani oleh tim field service."
        icon="bi-geo-alt"
        breadcrumb={[{ label: 'Kota' }]}
      />

      <div className="card">
        <div className="card-header">
          <span className="flex items-center gap-2">
            <i className="bi bi-geo-alt text-slate-400" /> Kota
          </span>
          <span className="rounded-full bg-slate-100 px-2.5 py-0.5 text-xs font-medium text-slate-500">
            Segera hadir
          </span>
        </div>
        <div className="card-body">
          <EmptyState icon="bi-geo-alt" title="Belum ada kota" text="Tambahkan kota untuk memetakan cakupan area layanan." />
        </div>
      </div>
    </>
  );
}
