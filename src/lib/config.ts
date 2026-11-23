export const APP_NAME = process.env.APP_NAME || 'Procurement Field Service';

export const SLA_MINUTES = Number(process.env.SLA_MINUTES || 180);
export const SLA_START = (process.env.SLA_START || 'assigned') as 'assigned' | 'accepted';
export const DEFAULT_WORKLOAD_LIMIT = Number(process.env.DEFAULT_WORKLOAD_LIMIT || 5);
export const MAX_UPLOAD_MB = Number(process.env.MAX_UPLOAD_MB || 5);
export const UPLOAD_DIR = process.env.UPLOAD_DIR || 'public/uploads';

export const ROLES: Record<string, string> = {
  admin: 'Administrator',
  noc: 'NOC',
  nam: 'NAM (Network Area Manager)',
  procurement: 'Procurement',
  vendor: 'Vendor',
  inventory: 'Inventory',
  foc: 'FOC',
};

export const CGS_REVIEW_ROLES = ['foc', 'nam', 'noc', 'admin', 'procurement'];

export type TicketStatus =
  | 'open'
  | 'assigned'
  | 'on_progress'
  | 'inventory_check'
  | 'foc_review'
  | 'revision'
  | 'closed'
  | 'cancelled';

export const TICKET_STATUS: Record<string, { label: string; badge: string; variant: string }> = {
  open: { label: 'Menunggu Vendor', badge: 'Menunggu Vendor', variant: 'secondary' },
  assigned: { label: 'Ditugaskan', badge: 'Ditugaskan', variant: 'info' },
  on_progress: { label: 'Dikerjakan', badge: 'Dikerjakan', variant: 'primary' },
  inventory_check: { label: 'Cek Inventory', badge: 'Cek Inventory', variant: 'warning' },
  foc_review: { label: 'Review FOC', badge: 'Review FOC', variant: 'warning' },
  revision: { label: 'Revisi (Temporary)', badge: 'Revisi (Temporary)', variant: 'danger' },
  closed: { label: 'Selesai Permanent', badge: 'Selesai Permanent', variant: 'success' },
  cancelled: { label: 'Dibatalkan', badge: 'Dibatalkan', variant: 'dark' },
};

export const VENDOR_STATUS: Record<string, { label: string; variant: string }> = {
  available: { label: 'Available', variant: 'success' },
  freeze: { label: 'Freeze', variant: 'warning' },
  blacklist: { label: 'Blacklist', variant: 'danger' },
};

export const PRIORITIES: Record<string, { label: string; variant: string }> = {
  low: { label: 'Low', variant: 'secondary' },
  medium: { label: 'Medium', variant: 'info' },
  high: { label: 'High', variant: 'warning' },
  critical: { label: 'Critical', variant: 'danger' },
};

export const WORKLOAD_STATUSES = ['assigned', 'on_progress', 'revision'];
export const ACTIVE_TICKET_STATUSES = ['assigned', 'on_progress', 'inventory_check', 'foc_review', 'revision'];

export const PHOTO_CATEGORIES: Record<string, string> = {
  before: 'Foto Tiang',
  progress: 'Foto Sinyal',
  after: 'Foto OTDR',
  material: 'Foto Data Core',
};

export const PHOTO_REQUIRED = ['before', 'after'];

export const SIGNOFF_SCOPES: Record<string, string> = {
  material: 'Material',
  dokumentasi: 'Dokumentasi',
  penyelesaian: 'Penyelesaian',
  sla: 'SLA',
};

export const SIGNOFF_STAGES: Record<string, string> = {
  region: 'Tanda Check PIC Region',
  cgs: 'Evaluasi CGS',
};

export const VENDOR_TYPES: Record<string, { label: string; variant: string }> = {
  ms: { label: 'Vendor MS', variant: 'primary' },
  non_ms: { label: 'Vendor Non-MS', variant: 'secondary' },
};

export function hasRole(user: { role?: string } | null | undefined, ...roles: string[]): boolean {
  if (!user?.role) return false;
  return roles.includes(user.role);
}
