import { auth } from '@/auth';
import { redirect } from 'next/navigation';
import type { AppRole } from '@/auth.config';

export type CurrentUser = {
  id: number;
  role: AppRole;
  vendor_id: number | null;
  vendor_name: string | null;
  full_name: string;
  username: string;
};

/** The signed-in user, or null. */
export async function currentUser(): Promise<CurrentUser | null> {
  const session = await auth();
  return (session?.user as CurrentUser | undefined) ?? null;
}

/** The signed-in user; redirects to /login when there is no session. */
export async function requireUser(): Promise<CurrentUser> {
  const u = await currentUser();
  if (!u) redirect('/login');
  return u;
}

/** The signed-in user; 403 page when the role is not allowed. */
export async function requireRole(...roles: AppRole[]): Promise<CurrentUser> {
  const u = await requireUser();
  if (!roles.includes(u.role)) redirect('/forbidden');
  return u;
}

/** Whether the signed-in user has one of the given roles. */
export function hasRole(u: CurrentUser | null, ...roles: AppRole[]): boolean {
  return !!u && roles.includes(u.role);
}
