import { requireRole } from '@/lib/session';
import PageHeader from '@/components/page-header';
import EmptyState from '@/components/empty-state';

export default async function MaterialsPage() {
  await requireRole('admin', 'inventory', 'procurement');
  return (
    <>
      <PageHeader
        title="Material"
        subtitle="Katalog material pendukung pekerjaan field service."
        icon="bi-box-seam"
        breadcrumb={[{ label: 'Material' }]}
      />

      <div className="card">
        <div className="card-header">
          <span className="flex items-center gap-2">
            <i className="bi bi-box-seam text-slate-400" /> Material
          </span>
          <span className="rounded-full bg-slate-100 px-2.5 py-0.5 text-xs font-medium text-slate-500">
            Segera hadir
          </span>
        </div>
        <div className="card-body">
          <EmptyState icon="bi-box-seam" title="Belum ada material" text="Daftar material beserta stok dan satuan akan tampil di sini." />
        </div>
      </div>
    </>
  );
}
