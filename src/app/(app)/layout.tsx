import Shell from '@/components/shell';
import { requireUser } from '@/lib/session';
import { q1 } from '@/lib/db';

/**
 * Authenticated application shell (sidebar + top bar).
 */
export default async function AppLayout({ children }: { children: React.ReactNode }) {
  const user = await requireUser();
  const unread = await q1<{ n: number }>(
    'SELECT COUNT(*) AS n FROM notifications WHERE user_id=? AND is_read=0',
    [user.id]
  );

  return (
    <Shell user={user} unread={Number(unread?.n ?? 0)}>{children}</Shell>
  );
}
