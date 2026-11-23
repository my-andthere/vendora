import type { AppRole } from '@/auth.config';

/** Sidebar menu — port of the `$menu` array in legacy_php/includes/header.php. */
export type MenuItem = {
  href: string;
  icon: string;
  label: string;
  roles: AppRole[];
};

export const MENU: MenuItem[] = [
  { href: '/dashboard', icon: 'bi-speedometer2', label: 'Dashboard', roles: ['admin', 'noc', 'vendor', 'inventory', 'foc'] },
  { href: '/procurement-dashboard', icon: 'bi-clipboard-data', label: 'Dashboard Procurement', roles: ['procurement'] },
  { href: '/tickets', icon: 'bi-ticket-detailed', label: 'Tiket', roles: ['admin', 'noc', 'vendor', 'inventory', 'foc', 'procurement'] },
  { href: '/tickets/new', icon: 'bi-plus-circle', label: 'Buat Tiket (NOC)', roles: ['admin', 'noc'] },
  { href: '/vendors', icon: 'bi-truck', label: 'Vendor', roles: ['admin', 'noc', 'procurement'] },
  { href: '/materials', icon: 'bi-box-seam', label: 'Material', roles: ['admin', 'inventory', 'procurement'] },
  { href: '/regions', icon: 'bi-map', label: 'Region', roles: ['admin'] },
  { href: '/cities', icon: 'bi-geo-alt', label: 'Kota', roles: ['admin'] },
  { href: '/users', icon: 'bi-people', label: 'User', roles: ['admin'] },
  { href: '/basto', icon: 'bi-file-earmark-check', label: 'BASTO', roles: ['admin', 'noc', 'procurement', 'foc', 'nam'] },
  { href: '/rekap', icon: 'bi-bar-chart-line', label: 'Rekap Bulanan', roles: ['admin', 'noc', 'procurement', 'foc', 'nam'] },
  { href: '/master-options', icon: 'bi-list-check', label: 'Master Option', roles: ['admin', 'procurement'] },
  { href: '/noc-outbox', icon: 'bi-broadcast', label: 'Notifikasi ke NOC', roles: ['admin', 'noc'] },
];
