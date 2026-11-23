import { requireRole } from '@/lib/session';
import PageHeader from '@/components/page-header';
import EmptyState from '@/components/empty-state';

export default async function NocOutboxPage() {
  await requireRole('admin', 'noc');
  return (
    <>
      <PageHeader
        title="Notifikasi ke NOC"
        subtitle="Kirim pemberitahuan operasional ke pusat NOC."
        icon="bi-broadcast"
        breadcrumb={[{ label: 'Notifikasi ke NOC' }]}
      />

      <div className="card">
        <div className="card-header">
          <span className="flex items-center gap-2">
            <i className="bi bi-broadcast text-slate-400" /> Notifikasi ke NOC
          </span>
          <span className="rounded-full bg-slate-100 px-2.5 py-0.5 text-xs font-medium text-slate-500">
            Segera hadir
          </span>
        </div>
        <div className="card-body">
          <EmptyState icon="bi-broadcast" title="Belum ada notifikasi terkirim" text="Riwayat pemberitahuan yang dikirim ke NOC akan tampil di sini." />
        </div>
      </div>
    </>
  );
}
