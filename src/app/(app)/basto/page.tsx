import { requireRole } from '@/lib/session';
import PageHeader from '@/components/page-header';
import EmptyState from '@/components/empty-state';

export default async function BastoPage() {
  await requireRole('admin', 'noc', 'procurement', 'foc', 'nam');
  return (
    <>
      <PageHeader
        title="BASTO"
        subtitle="Berkas Acceptance Serah Terima Pekerjaan."
        icon="bi-file-earmark-check"
        breadcrumb={[{ label: 'BASTO' }]}
      />

      <div className="card">
        <div className="card-header">
          <span className="flex items-center gap-2">
            <i className="bi bi-file-earmark-check text-slate-400" /> BASTO
          </span>
          <span className="rounded-full bg-slate-100 px-2.5 py-0.5 text-xs font-medium text-slate-500">
            Segera hadir
          </span>
        </div>
        <div className="card-body">
          <EmptyState icon="bi-file-earmark-check" title="Belum ada berkas BASTO" text="Dokumen serah terima pekerjaan akan tampil di sini setelah dibuat." />
        </div>
      </div>
    </>
  );
}
