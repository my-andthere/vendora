'use server';

import { redirect } from 'next/navigation';
import { AuthError } from 'next-auth';
import { signIn, auth } from '@/auth';
import { homePath } from '@/auth.config';

/**
 * Credentials sign-in. On failure it redirects back to /login with an error flag.
 */
export async function loginAction(formData: FormData) {
  const username = String(formData.get('username') ?? '').trim();
  const password = String(formData.get('password') ?? '');
  const callbackUrl = String(formData.get('callbackUrl') ?? '');

  try {
    await signIn('credentials', {
      username,
      password,
      redirect: false,
    });
  } catch (err) {
    if (err instanceof AuthError) {
      redirect(`/login?error=1${callbackUrl ? `&callbackUrl=${encodeURIComponent(callbackUrl)}` : ''}`);
    }
    throw err;
  }

  const session = await auth();
  if (!session?.user) {
    redirect(`/login?error=1${callbackUrl ? `&callbackUrl=${encodeURIComponent(callbackUrl)}` : ''}`);
  }

  redirect(callbackUrl && callbackUrl.startsWith('/') ? callbackUrl : homePath(session.user.role));
}
