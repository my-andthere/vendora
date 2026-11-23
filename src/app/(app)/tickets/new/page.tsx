import { requireRole } from '@/lib/session';
import PageHeader from '@/components/page-header';
import EmptyState from '@/components/empty-state';

export default async function TicketCreatePage() {
  await requireRole('admin', 'noc');
  return (
    <>
      <PageHeader
        title="Buat Tiket"
        subtitle="Formulir permintaan layanan field service baru."
        icon="bi-plus-circle"
        breadcrumb={[{ label: 'Tiket' }]}
      />

      <div className="card">
        <div className="card-header">
          <span className="flex items-center gap-2">
            <i className="bi bi-plus-circle text-slate-400" /> Buat Tiket
          </span>
          <span className="rounded-full bg-slate-100 px-2.5 py-0.5 text-xs font-medium text-slate-500">
            Segera hadir
          </span>
        </div>
        <div className="card-body">
          <EmptyState icon="bi-ui-checks-grid" title="Formulir sedang disiapkan" text="Formulir lengkap untuk membuat tiket baru akan segera tersedia." />
        </div>
      </div>
    </>
  );
}
