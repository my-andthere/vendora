import { requireRole } from '@/lib/session';
import PageHeader from '@/components/page-header';
import EmptyState from '@/components/empty-state';

export default async function RegionsPage() {
  await requireRole('admin');
  return (
    <>
      <PageHeader
        title="Region"
        subtitle="Pembagian wilayah operasional vendor."
        icon="bi-map"
        breadcrumb={[{ label: 'Region' }]}
      />

      <div className="card">
        <div className="card-header">
          <span className="flex items-center gap-2">
            <i className="bi bi-map text-slate-400" /> Region
          </span>
          <span className="rounded-full bg-slate-100 px-2.5 py-0.5 text-xs font-medium text-slate-500">
            Segera hadir
          </span>
        </div>
        <div className="card-body">
          <EmptyState icon="bi-map" title="Belum ada region" text="Tambahkan region untuk mengelompokkan cakupan wilayah layanan." />
        </div>
      </div>
    </>
  );
}
