'use client';

import { useState } from 'react';
import Link from 'next/link';
import { usePathname } from 'next/navigation';
import { signOut } from 'next-auth/react';
import { MENU } from '@/lib/nav';
import type { CurrentUser } from '@/lib/session';
import { ROLES } from '@/lib/config';

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
  const pathname = usePathname();

  const items = MENU.filter((m) => m.roles.includes(user.role));

  const isActive = (href: string) =>
    href === '/tickets' ? pathname === '/tickets' || pathname.startsWith('/tickets/') : pathname === href;

  return (
    <div className="min-h-screen bg-slate-100">
      {/* Top bar */}
      <header className="sticky top-0 z-40 flex h-14 items-center gap-3 bg-slate-900 px-3 text-slate-100 shadow">
        <button
          type="button"
          className="rounded border border-slate-600 px-2 py-1 text-sm lg:hidden"
          onClick={() => setOpen((v) => !v)}
          aria-label="Menu"
        >
          <i className="bi bi-list" />
        </button>
        <Link
          href={user.role === 'procurement' ? '/procurement-dashboard' : '/dashboard'}
          className="flex items-center gap-2 font-semibold text-white no-underline"
        >
          <i className="bi bi-tools" />
          <span className="hidden sm:inline">Procurement Field Service</span>
        </Link>

        <div className="ml-auto flex items-center gap-3">
          <Link href="/notifications" className="relative text-slate-200" title="Notifikasi">
            <i className="bi bi-bell text-xl" />
            {unread > 0 && (
              <span className="absolute -top-1 -right-2 rounded-full bg-red-600 px-1.5 text-[10px] leading-4 font-semibold text-white">
                {unread}
              </span>
            )}
          </Link>

          <div className="relative">
            <button
              type="button"
              onClick={() => setMenuOpen((v) => !v)}
              className="flex items-center gap-2 text-sm text-slate-200"
            >
              <i className="bi bi-person-circle text-lg" />
              <span className="hidden sm:inline">{user.full_name}</span>
              <i className="bi bi-chevron-down text-xs" />
            </button>
            {menuOpen && (
              <>
                <div className="fixed inset-0 z-10" onClick={() => setMenuOpen(false)} />
                <div className="absolute right-0 z-20 mt-1 w-56 rounded border border-slate-200 bg-white py-1 text-slate-700 shadow-lg">
                  <div className="border-b border-slate-100 px-4 py-2 text-xs text-slate-500">
                    {ROLES[user.role] ?? user.role}
                    {user.vendor_name ? ' · ' + user.vendor_name : ''}
                  </div>
                  <Link
                    href="/profile"
                    onClick={() => setMenuOpen(false)}
                    className="flex items-center gap-2 px-4 py-2 text-sm hover:bg-slate-100"
                  >
                    <i className="bi bi-key" /> Ganti Password
                  </Link>
                  <button
                    type="button"
                    onClick={() => signOut({ redirectTo: '/login' })}
                    className="flex w-full items-center gap-2 px-4 py-2 text-left text-sm text-red-600 hover:bg-slate-100"
                  >
                    <i className="bi bi-box-arrow-right" /> Logout
                  </button>
                </div>
              </>
            )}
          </div>
        </div>
      </header>

      <div className="flex">
        {/* Sidebar */}
        <aside
          className={`${open ? 'block' : 'hidden'} fixed inset-y-14 left-0 z-30 w-60 overflow-y-auto border-r border-slate-200 bg-white lg:sticky lg:top-14 lg:block lg:h-[calc(100vh-3.5rem)]`}
        >
          <nav className="py-2">
            {items.map((m) => (
              <Link
                key={m.href}
                href={m.href}
                onClick={() => setOpen(false)}
                className={`flex items-center gap-2 border-l-3 px-4 py-2 text-sm no-underline transition ${
                  isActive(m.href)
                    ? 'border-blue-600 bg-blue-50 font-medium text-blue-700'
                    : 'border-transparent text-slate-700 hover:bg-slate-100'
                }`}
              >
                <i className={`bi ${m.icon}`} />
                {m.label}
              </Link>
            ))}
          </nav>
        </aside>

        {open && (
          <div className="fixed inset-0 top-14 z-20 bg-black/30 lg:hidden" onClick={() => setOpen(false)} />
        )}

        {/* Content */}
        <main className="min-w-0 flex-1 p-3 md:p-4">{children}</main>
      </div>
    </div>
  );
}
