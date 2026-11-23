import { requireRole } from '@/lib/session';
import PageHeader from '@/components/page-header';
import EmptyState from '@/components/empty-state';

export default async function VendorsPage() {
  await requireRole('admin', 'noc', 'procurement');
  return (
    <>
      <PageHeader
        title="Vendor"
        subtitle="Data mitra kerja, cakupan area, dan status ketersediaan."
        icon="bi-truck"
        breadcrumb={[{ label: 'Vendor' }]}
      />

      <div className="card">
        <div className="card-header">
          <span className="flex items-center gap-2">
            <i className="bi bi-truck text-slate-400" /> Vendor
          </span>
          <span className="rounded-full bg-slate-100 px-2.5 py-0.5 text-xs font-medium text-slate-500">
            Segera hadir
          </span>
        </div>
        <div className="card-body">
          <EmptyState icon="bi-truck" title="Belum ada vendor" text="Daftar mitra kerja beserta informasi kontak akan tampil di sini." />
        </div>
      </div>
    </>
  );
}
