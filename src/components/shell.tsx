'use client';

import { useEffect, useRef, useState } from 'react';
import Link from 'next/link';
import { usePathname } from 'next/navigation';
import { signOut } from 'next-auth/react';
import { MENU, NAV_GROUPS, pageMeta } from '@/lib/nav';
import type { CurrentUser } from '@/lib/session';
import { ROLES } from '@/lib/config';

function initials(name: string) {
  return (
    name
      .split(' ')
      .filter(Boolean)
      .slice(0, 2)
      .map((w) => w[0]?.toUpperCase() ?? '')
      .join('') || '?'
  );
}

export default function Shell({
  user,
  unread,
  children,
}: {
  user: CurrentUser;
  unread: number;
  children: React.ReactNode;
}) {
  const [open, setOpen] = useState(false);
  const [menuOpen, setMenuOpen] = useState(false);
  const [collapsed, setCollapsed] = useState(false);
  const [clock, setClock] = useState('');
  const menuRef = useRef<HTMLDivElement>(null);
  const pathname = usePathname();

  const items = MENU.filter((m) => m.roles.includes(user.role));
  const meta = pageMeta(pathname);

  const isActive = (href: string) =>
    href === '/tickets' ? pathname === '/tickets' || pathname.startsWith('/tickets/') : pathname === href;

  // Close the dropdown on outside click / Escape.
  useEffect(() => {
    if (!menuOpen) return;
    const onClick = (e: MouseEvent) => {
      if (menuRef.current && !menuRef.current.contains(e.target as Node)) setMenuOpen(false);
    };
    const onKey = (e: KeyboardEvent) => {
      if (e.key === 'Escape') setMenuOpen(false);
    };
    document.addEventListener('mousedown', onClick);
    document.addEventListener('keydown', onKey);
    return () => {
      document.removeEventListener('mousedown', onClick);
      document.removeEventListener('keydown', onKey);
    };
  }, [menuOpen]);

  // Live clock in the topbar.
  useEffect(() => {
    const tick = () =>
      setClock(
        new Date().toLocaleString('id-ID', {
          weekday: 'short',
          day: '2-digit',
          month: 'short',
          year: 'numeric',
          hour: '2-digit',
          minute: '2-digit',
        })
      );
    tick();
    const id = setInterval(tick, 30_000);
    return () => clearInterval(id);
  }, []);

  // Close the mobile drawer whenever the route changes.
  useEffect(() => {
    setOpen(false);
  }, [pathname]);

  const showLabels = !collapsed;

  const sidebar = (
    <div className="flex h-full flex-col">
      {/* Brand */}
      <div
        className={`flex h-16 shrink-0 items-center gap-3 border-b border-white/10 px-4 ${
          collapsed ? 'lg:justify-center lg:px-0' : ''
        }`}
      >
        <Link
          href={user.role === 'procurement' ? '/procurement-dashboard' : '/dashboard'}
          className="flex min-w-0 items-center gap-3 no-underline"
        >
          <span className="flex h-9 w-9 shrink-0 items-center justify-center rounded-xl bg-blue-600 text-base text-white shadow-lg shadow-blue-600/30">
            <i className="bi bi-tools" />
          </span>
          {showLabels ? (
            <span className="min-w-0 leading-tight">
              <span className="block truncate text-sm font-semibold text-white">Procurement</span>
              <span className="block truncate text-[11px] text-slate-400">Field Service</span>
            </span>
          ) : null}
        </Link>
      </div>

      {/* Navigation */}
      <nav className="flex-1 overflow-y-auto px-3 py-4">
        {NAV_GROUPS.map((group) => {
          const groupItems = items.filter((m) => m.group === group.key);
          if (!groupItems.length) return null;
          return (
            <div key={group.key} className="mb-1">
              {showLabels ? <p className="sidebar-section">{group.label}</p> : null}
              <div className="space-y-0.5">
                {groupItems.map((m) => {
                  const active = isActive(m.href);
                  return (
                    <Link
                      key={m.href}
                      href={m.href}
                      onClick={() => setOpen(false)}
                      title={collapsed ? m.label : undefined}
                      className={`sidebar-link ${active ? 'sidebar-link-active' : ''} ${
                        collapsed ? 'lg:justify-center lg:px-0' : ''
                      }`}
                    >
                      {active ? (
                        <span className="absolute top-1/2 -left-3 h-6 w-1 -translate-y-1/2 rounded-r-full bg-blue-500" />
                      ) : null}
                      <i className={`bi ${m.icon} w-4 shrink-0 text-center text-base`} />
                      {showLabels ? <span className="truncate">{m.label}</span> : null}
                    </Link>
                  );
                })}
              </div>
            </div>
          );
        })}
      </nav>

      {/* Sidebar footer */}
      <div className="shrink-0 border-t border-white/10 px-3 py-3">
        <div className={`rounded-xl bg-white/5 p-3 ${collapsed ? 'lg:hidden' : ''}`}>
          <div className="flex items-center gap-2">
            <i className="bi bi-person-circle text-lg text-blue-300" />
            <div className="min-w-0 leading-tight">
              <p className="truncate text-xs font-semibold text-white">{user.full_name}</p>
              <p className="truncate text-[11px] text-slate-400">{ROLES[user.role] ?? user.role}</p>
            </div>
          </div>
          {user.vendor_name ? (
            <p className="mt-2 truncate text-[11px] text-slate-500">
              <i className="bi bi-truck me-1" />
              {user.vendor_name}
            </p>
          ) : null}
        </div>
      </div>
    </div>
  );

  return (
    <div className="min-h-screen bg-slate-50">
      {/* ================= Top bar ================= */}
      <header className="sticky top-0 z-40 h-16 border-b border-slate-200 bg-white/90 backdrop-blur-md">
        <div className="flex h-full items-center gap-3 px-3 sm:px-5">
          <button
            type="button"
            className="btn btn-icon btn-ghost lg:hidden"
            onClick={() => setOpen((v) => !v)}
            aria-label="Buka menu navigasi"
            aria-expanded={open}
          >
            <i className="bi bi-list text-lg" />
          </button>

          {/* Page context */}
          <div className="flex min-w-0 items-center gap-3">
            <span className="hidden h-9 w-9 shrink-0 items-center justify-center rounded-lg bg-slate-100 text-base text-slate-600 sm:flex">
              <i className={`bi ${meta.icon}`} />
            </span>
            <div className="min-w-0 leading-tight">
              <p className="truncate text-sm font-semibold text-slate-800">{meta.title}</p>
              <p className="hidden truncate text-xs text-slate-500 lg:block">{meta.subtitle}</p>
            </div>
          </div>

          <div className="ml-auto flex items-center gap-2 sm:gap-3">
            {/* Clock (desktop only) */}
            <span className="hidden items-center gap-2 rounded-lg bg-slate-100 px-3 py-1.5 text-xs text-slate-600 xl:flex">
              <i className="bi bi-clock text-slate-400" />
              {clock}
            </span>

            {/* Notifications */}
            <Link
              href="/notifications"
              className="btn btn-icon btn-ghost relative"
              title={`Notifikasi${unread > 0 ? ` (${unread} belum dibaca)` : ''}`}
            >
              <i className="bi bi-bell text-lg" />
              {unread > 0 ? (
                <span className="absolute top-1 right-1 flex h-4 min-w-4 items-center justify-center rounded-full bg-red-500 px-1 text-[10px] leading-none font-semibold text-white ring-2 ring-white">
                  {unread > 99 ? '99+' : unread}
                </span>
              ) : null}
            </Link>

            {/* User menu */}
            <div className="relative" ref={menuRef}>
              <button
                type="button"
                onClick={() => setMenuOpen((v) => !v)}
                className="flex items-center gap-2 rounded-xl border border-slate-200 py-1.5 pr-2 pl-1.5 transition hover:border-slate-300 hover:bg-slate-50"
                aria-haspopup="menu"
                aria-expanded={menuOpen}
              >
                <span className="flex h-7 w-7 items-center justify-center rounded-lg bg-gradient-to-br from-blue-500 to-blue-700 text-[11px] font-semibold text-white">
                  {initials(user.full_name)}
                </span>
                <span className="hidden leading-tight sm:block">
                  <span className="block max-w-[9rem] truncate text-xs font-semibold text-slate-800">
                    {user.full_name}
                  </span>
                  <span className="block max-w-[9rem] truncate text-[10px] text-slate-500">
                    {ROLES[user.role] ?? user.role}
                  </span>
                </span>
                <i className="bi bi-chevron-down text-[10px] text-slate-400" />
              </button>

              {menuOpen ? (
                <div
                  role="menu"
                  className="absolute right-0 z-50 mt-2 w-60 overflow-hidden rounded-xl border border-slate-200 bg-white shadow-xl shadow-slate-900/10"
                >
                  <div className="border-b border-slate-100 bg-slate-50/70 px-4 py-3">
                    <p className="truncate text-sm font-semibold text-slate-800">{user.full_name}</p>
                    <p className="truncate text-xs text-slate-500">@{user.username}</p>
                    <span className="mt-2 inline-flex items-center gap-1 rounded-full bg-blue-50 px-2 py-0.5 text-[11px] font-medium text-blue-700">
                      <i className="bi bi-shield-check" />
                      {ROLES[user.role] ?? user.role}
                    </span>
                  </div>
                  <div className="p-1.5">
                    <Link
                      href="/profile"
                      onClick={() => setMenuOpen(false)}
                      role="menuitem"
                      className="flex items-center gap-2.5 rounded-lg px-3 py-2 text-sm text-slate-700 no-underline transition hover:bg-slate-100"
                    >
                      <i className="bi bi-key text-slate-400" /> Ganti Password
                    </Link>
                    <Link
                      href="/notifications"
                      onClick={() => setMenuOpen(false)}
                      role="menuitem"
                      className="flex items-center gap-2.5 rounded-lg px-3 py-2 text-sm text-slate-700 no-underline transition hover:bg-slate-100"
                    >
                      <i className="bi bi-bell text-slate-400" /> Notifikasi
                    </Link>
                    <button
                      type="button"
                      onClick={() => signOut({ redirectTo: '/login' })}
                      role="menuitem"
                      className="flex w-full items-center gap-2.5 rounded-lg px-3 py-2 text-left text-sm text-red-600 transition hover:bg-red-50"
                    >
                      <i className="bi bi-box-arrow-right" /> Logout
                    </button>
                  </div>
                </div>
              ) : null}
            </div>
          </div>
        </div>
      </header>

      <div className="flex">
        {/* ================= Side bar ================= */}
        <aside
          className={`${open ? 'block' : 'hidden'} fixed inset-y-16 left-0 z-40 w-64 overflow-hidden bg-slate-900 lg:sticky lg:top-16 lg:block lg:h-[calc(100vh-4rem)] lg:shrink-0 ${
            collapsed ? 'lg:w-[76px]' : 'lg:w-64'
          }`}
        >
          <div
            aria-hidden
            className="pointer-events-none absolute inset-0"
            style={{
              background:
                'radial-gradient(30rem 20rem at 0% 0%, rgba(37,99,235,0.20), transparent 65%), radial-gradient(25rem 18rem at 100% 100%, rgba(14,165,233,0.12), transparent 65%)',
            }}
          />
          <div className="relative h-full">{sidebar}</div>
        </aside>

        {/* Collapse toggle (desktop) */}
        <button
          type="button"
          onClick={() => setCollapsed((v) => !v)}
          className="btn btn-icon btn-ghost fixed bottom-5 z-40 hidden border border-slate-200 bg-white shadow-lg transition-all lg:flex"
          style={collapsed ? { left: '5.25rem' } : { left: '17rem' }}
          title={collapsed ? 'Perluas menu' : 'Ciutkan menu'}
          aria-label={collapsed ? 'Perluas menu' : 'Ciutkan menu'}
        >
          <i className={`bi ${collapsed ? 'bi-chevron-double-right' : 'bi-chevron-double-left'}`} />
        </button>

        {/* Mobile overlay */}
        {open ? (
          <div
            className="fixed inset-0 top-16 z-30 bg-slate-900/50 backdrop-blur-sm lg:hidden"
            onClick={() => setOpen(false)}
            aria-hidden
          />
        ) : null}

        {/* ================= Content ================= */}
        <div className="flex min-w-0 flex-1 flex-col">
          <main className="flex-1 px-4 py-5 sm:px-6 lg:px-8 lg:py-6">{children}</main>

          {/* ================= Footer ================= */}
          <footer className="border-t border-slate-200 bg-white px-4 py-4 sm:px-6 lg:px-8">
            <div className="flex flex-col items-center justify-between gap-2 text-xs text-slate-500 sm:flex-row">
              <p className="flex items-center gap-1.5">
                <i className="bi bi-copyright" />
                {new Date().getFullYear()} Procurement Field Service. Seluruh hak cipta dilindungi.
              </p>
              <p className="flex items-center gap-3">
                <span className="flex items-center gap-1.5">
                  <span className="h-1.5 w-1.5 rounded-full bg-emerald-500" />
                  Server aktif
                </span>
                <span className="hidden sm:inline">v1.0.0</span>
              </p>
            </div>
          </footer>
        </div>
      </div>
    </div>
  );
}
