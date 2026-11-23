import { requireRole } from '@/lib/session';
import PageHeader from '@/components/page-header';
import EmptyState from '@/components/empty-state';

export default async function ProcurementDashboardPage() {
  await requireRole('procurement');
  return (
    <>
      <PageHeader
        title="Dashboard Procurement"
        subtitle="Monitoring pengadaan, progress vendor, dan tenggat SLA."
        icon="bi-clipboard-data"
        breadcrumb={[{ label: 'Dashboard Procurement' }]}
      />

      <div className="card">
        <div className="card-header">
          <span className="flex items-center gap-2">
            <i className="bi bi-clipboard-data text-slate-400" /> Dashboard Procurement
          </span>
          <span className="rounded-full bg-slate-100 px-2.5 py-0.5 text-xs font-medium text-slate-500">
            Segera hadir
          </span>
        </div>
        <div className="card-body">
          <EmptyState icon="bi-clipboard-data" title="Dashboard sedang disiapkan" text="Ringkasan spend, performance vendor, dan status SLA akan segera tersedia." />
        </div>
      </div>
    </>
  );
}
