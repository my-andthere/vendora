import { requireRole } from '@/lib/session';
import PageHeader from '@/components/page-header';
import EmptyState from '@/components/empty-state';

export default async function RekapPage() {
  await requireRole('admin', 'noc', 'procurement', 'foc', 'nam');
  return (
    <>
      <PageHeader
        title="Rekap Bulanan"
        subtitle="Rekapitulasi pekerjaan dan SLA per periode."
        icon="bi-graph-up-arrow"
        breadcrumb={[{ label: 'Rekap Bulanan' }]}
      />

      <div className="card">
        <div className="card-header">
          <span className="flex items-center gap-2">
            <i className="bi bi-graph-up-arrow text-slate-400" /> Rekap Bulanan
          </span>
          <span className="rounded-full bg-slate-100 px-2.5 py-0.5 text-xs font-medium text-slate-500">
            Segera hadir
          </span>
        </div>
        <div className="card-body">
          <EmptyState icon="bi-graph-up-arrow" title="Belum ada rekapitulasi" text="Pilih periode untuk menampilkan rekapitulasi pekerjaan." />
        </div>
      </div>
    </>
  );
}
