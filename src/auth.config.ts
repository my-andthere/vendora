import type { NextAuthConfig } from 'next-auth';
import type { DefaultSession } from 'next-auth';

/**
 * Auth.js configuration shared by the Node runtime (login) and the Edge
 * middleware. Kept free of any database access so it can be bundled for the
 * Edge runtime.
 */

export type AppRole =
  | 'admin'
  | 'noc'
  | 'nam'
  | 'procurement'
  | 'vendor'
  | 'inventory'
  | 'foc';

export type AuthUser = {
  id: number;
  role: AppRole;
  vendor_id: number | null;
  vendor_name: string | null;
  full_name: string;
  username: string;
};

declare module 'next-auth' {
  interface Session {
    user: AuthUser & DefaultSession['user'];
  }

}



/** Routes reachable without a session. */
export const PUBLIC_PATHS = ['/login', '/api/auth', '/api/noc'];

/** Role -> allowed page prefixes. Mirrors the role gating of header.php. */
export const ROLE_ROUTES: { prefix: string; roles: AppRole[] }[] = [
  { prefix: '/dashboard', roles: ['admin', 'noc', 'vendor', 'inventory', 'foc'] },
  { prefix: '/procurement-dashboard', roles: ['procurement'] },
  { prefix: '/tickets', roles: ['admin', 'noc', 'vendor', 'inventory', 'foc', 'procurement'] },
  { prefix: '/tickets/new', roles: ['admin', 'noc'] },
  { prefix: '/vendors', roles: ['admin', 'noc', 'procurement'] },
  { prefix: '/materials', roles: ['admin', 'inventory', 'procurement'] },
  { prefix: '/regions', roles: ['admin'] },
  { prefix: '/cities', roles: ['admin'] },
  { prefix: '/users', roles: ['admin'] },
  { prefix: '/basto', roles: ['admin', 'noc', 'procurement', 'foc', 'nam'] },
  { prefix: '/rekap', roles: ['admin', 'noc', 'procurement', 'foc', 'nam'] },
  { prefix: '/master-options', roles: ['admin', 'procurement'] },
  { prefix: '/noc-outbox', roles: ['admin', 'noc'] },
  { prefix: '/profile', roles: ['admin', 'noc', 'nam', 'procurement', 'vendor', 'inventory', 'foc'] },
  { prefix: '/notifications', roles: ['admin', 'noc', 'nam', 'procurement', 'vendor', 'inventory', 'foc'] },
];

export function roleAllowed(role: string | undefined, path: string): boolean {
  if (!role) return false;
  for (const r of ROLE_ROUTES) {
    if (path === r.prefix || path.startsWith(r.prefix + '/')) {
      return r.roles.includes(role as AppRole);
    }
  }
  return true;
}

export const authConfig = {
  trustHost: true,
  providers: [],
  session: { strategy: 'jwt' as const, maxAge: 60 * 60 * 12 },
  pages: { signIn: '/login' },
  callbacks: {
    async jwt({ token, user }: any) {
      if (user) {
        token.id = Number(user.id);
        token.role = user.role;
        token.vendor_id = user.vendor_id === null ? null : Number(user.vendor_id);
        token.vendor_name = user.vendor_name ?? null;
        token.full_name = user.full_name;
        token.username = user.username;
      }
      return token;
    },
    async session({ session, token }: any) {
      session.user.id = token.id;
      session.user.role = token.role;
      session.user.vendor_id = token.vendor_id;
      session.user.vendor_name = token.vendor_name;
      session.user.full_name = token.full_name;
      session.user.username = token.username;
      return session;
    },
  },
} satisfies NextAuthConfig;

/** Landing page per role (login.php redirects procurement to its own dashboard). */
export function homePath(role: string | undefined): string {
  return role === 'procurement' ? '/procurement-dashboard' : '/dashboard';
}
