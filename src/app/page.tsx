import { redirect } from 'next/navigation';
import { auth } from '@/auth';
import { homePath } from '@/auth.config';

export default async function Home() {
  const session = await auth();
  if (!session?.user) redirect('/login');
  redirect(homePath(session.user.role));
}
