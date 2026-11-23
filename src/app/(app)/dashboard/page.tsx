import { requireRole } from '@/lib/session';
import PageHeader from '@/components/page-header';
import EmptyState from '@/components/empty-state';

export default async function DashboardPage() {
  await requireRole('admin', 'noc', 'vendor', 'inventory', 'foc');
  return (
    <>
      <PageHeader
        title="Dashboard"
        subtitle="Ringkasan aktivitas operasional dan performa tiket hari ini."
        icon="bi-speedometer2"
        breadcrumb={[{ label: 'Dashboard' }]}
      />

      <div className="card">
        <div className="card-header">
          <span className="flex items-center gap-2">
            <i className="bi bi-speedometer2 text-slate-400" /> Dashboard
          </span>
          <span className="rounded-full bg-slate-100 px-2.5 py-0.5 text-xs font-medium text-slate-500">
            Segera hadir
          </span>
        </div>
        <div className="card-body">
          <EmptyState icon="bi-speedometer2" title="Dashboard sedang disiapkan" text="Widget ringkasan SLA, tiket aktif, dan performa vendor akan segera tersedia." />
        </div>
      </div>
    </>
  );
}
