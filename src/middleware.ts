import { NextResponse } from 'next/server';
import NextAuth from 'next-auth';
import { authConfig, homePath, PUBLIC_PATHS, roleAllowed } from './auth.config';

const { auth } = NextAuth(authConfig);

/**
 * Route protection.
 * - Unauthenticated -> redirect to /login
 * - Wrong role -> 403 (Akses ditolak untuk role Anda)
 */
export default auth((req) => {
  const { pathname } = req.nextUrl;
  const isPublic = PUBLIC_PATHS.some((p) => pathname === p || pathname.startsWith(p + '/'));

  if (isPublic) {
    if (pathname === '/login' && req.auth?.user) {
      const url = req.nextUrl.clone();
      url.pathname = homePath(req.auth.user.role);
      url.search = '';
      return NextResponse.redirect(url);
    }
    return NextResponse.next();
  }

  if (!req.auth?.user) {
    const url = req.nextUrl.clone();
    url.pathname = '/login';
    url.search = '';
    url.searchParams.set('callbackUrl', pathname);
    return NextResponse.redirect(url);
  }

  if (!roleAllowed(req.auth.user.role, pathname)) {
    const url = req.nextUrl.clone();
    url.pathname = '/forbidden';
    url.search = '';
    return NextResponse.rewrite(url);
  }

  return NextResponse.next();
});

export const config = {
  matcher: ['/((?!_next/static|_next/image|favicon.ico|uploads/|.*\\.(?:svg|png|jpg|jpeg|gif|webp|ico)$).*)'],
};
