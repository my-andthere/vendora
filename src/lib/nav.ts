import type { AppRole } from '@/auth.config';

/** Sidebar menu — port of the `$menu` array in legacy_php/includes/header.php. */
export type MenuItem = {
  href: string;
  icon: string;
  label: string;
  roles: AppRole[];
  /** Sidebar group the item belongs to. */
  group: NavGroup;
};

export type NavGroup = 'utama' | 'tiket' | 'master' | 'laporan';

/** Ordered sidebar groups with their display labels. */
export const NAV_GROUPS: { key: NavGroup; label: string; icon: string }[] = [
  { key: 'utama', label: 'Utama', icon: 'bi-speedometer2' },
  { key: 'tiket', label: 'Tiket & Field', icon: 'bi-ticket-detailed' },
  { key: 'master', label: 'Data Master', icon: 'bi-database' },
  { key: 'laporan', label: 'Laporan', icon: 'bi-bar-chart-line' },
];

export const MENU: MenuItem[] = [
  { href: '/dashboard', icon: 'bi-speedometer2', label: 'Dashboard', roles: ['admin', 'noc', 'vendor', 'inventory', 'foc'], group: 'utama' },
  { href: '/procurement-dashboard', icon: 'bi-clipboard-data', label: 'Dashboard Procurement', roles: ['procurement'], group: 'utama' },

  { href: '/tickets', icon: 'bi-ticket-detailed', label: 'Tiket', roles: ['admin', 'noc', 'vendor', 'inventory', 'foc', 'procurement'], group: 'tiket' },
  { href: '/tickets/new', icon: 'bi-plus-circle', label: 'Buat Tiket (NOC)', roles: ['admin', 'noc'], group: 'tiket' },
  { href: '/basto', icon: 'bi-file-earmark-check', label: 'BASTO', roles: ['admin', 'noc', 'procurement', 'foc', 'nam'], group: 'tiket' },
  { href: '/noc-outbox', icon: 'bi-broadcast', label: 'Notifikasi ke NOC', roles: ['admin', 'noc'], group: 'tiket' },
  { href: '/notifications', icon: 'bi-bell', label: 'Notifikasi', roles: ['admin', 'noc', 'nam', 'procurement', 'vendor', 'inventory', 'foc'], group: 'tiket' },

  { href: '/vendors', icon: 'bi-truck', label: 'Vendor', roles: ['admin', 'noc', 'procurement'], group: 'master' },
  { href: '/materials', icon: 'bi-box-seam', label: 'Material', roles: ['admin', 'inventory', 'procurement'], group: 'master' },
  { href: '/regions', icon: 'bi-map', label: 'Region', roles: ['admin'], group: 'master' },
  { href: '/cities', icon: 'bi-geo-alt', label: 'Kota', roles: ['admin'], group: 'master' },
  { href: '/users', icon: 'bi-people', label: 'User', roles: ['admin'], group: 'master' },
  { href: '/master-options', icon: 'bi-list-check', label: 'Master Option', roles: ['admin', 'procurement'], group: 'master' },

  { href: '/rekap', icon: 'bi-graph-up-arrow', label: 'Rekap Bulanan', roles: ['admin', 'noc', 'procurement', 'foc', 'nam'], group: 'laporan' },
];

/** Page title map used for the topbar / page header. */
export const PAGE_TITLES: Record<string, { title: string; subtitle: string; icon: string }> = {
  '/dashboard': { title: 'Dashboard', subtitle: 'Ringkasan aktivitas operasional hari ini.', icon: 'bi-speedometer2' },
  '/procurement-dashboard': { title: 'Dashboard Procurement', subtitle: 'Monitoring pengadaan dan performance vendor.', icon: 'bi-clipboard-data' },
  '/tickets': { title: 'Daftar Tiket', subtitle: 'Kelola seluruh tiket field beserta SLA-nya.', icon: 'bi-ticket-detailed' },
  '/tickets/new': { title: 'Buat Tiket', subtitle: 'Formulir permintaan layanan baru.', icon: 'bi-plus-circle' },
  '/basto': { title: 'BASTO', subtitle: 'Berkas Acceptance Serah Terima Pekerjaan.', icon: 'bi-file-earmark-check' },
  '/noc-outbox': { title: 'Notifikasi ke NOC', subtitle: 'Kirim pemberitahuan ke pusat NOC.', icon: 'bi-broadcast' },
  '/notifications': { title: 'Notifikasi', subtitle: 'Pemberitahuan masuk untuk akun Anda.', icon: 'bi-bell' },
  '/vendors': { title: 'Vendor', subtitle: 'Data mitra kerja dan status ketersediaan.', icon: 'bi-truck' },
  '/materials': { title: 'Material', subtitle: 'Katalog material pendukung pekerjaan.', icon: 'bi-box-seam' },
  '/regions': { title: 'Region', subtitle: 'Pembagian wilayah operasional.', icon: 'bi-map' },
  '/cities': { title: 'Kota', subtitle: 'Daftar kota yang dilayani.', icon: 'bi-geo-alt' },
  '/users': { title: 'User', subtitle: 'Manajemen akun dan hak akses pengguna.', icon: 'bi-people' },
  '/master-options': { title: 'Master Option', subtitle: 'Pilihan konfigurasi aplikasi.', icon: 'bi-list-check' },
  '/rekap': { title: 'Rekap Bulanan', subtitle: 'Rekapitulasi pekerjaan per periode.', icon: 'bi-graph-up-arrow' },
  '/profile': { title: 'Profil Saya', subtitle: 'Kelola kata sandi akun Anda.', icon: 'bi-person-gear' },
};

/** Title + subtitle for a given pathname. */
export function pageMeta(pathname: string) {
  if (PAGE_TITLES[pathname]) return PAGE_TITLES[pathname];
  if (pathname.startsWith('/tickets')) {
    return {
      title: 'Detail Tiket',
      subtitle: 'Riwayat dan progres penanganan tiket.',
      icon: 'bi-ticket-detailed',
    };
  }
  return { title: 'Portal Field Service', subtitle: 'Kelola tiket, vendor, dan material.', icon: 'bi-tools' };
}
