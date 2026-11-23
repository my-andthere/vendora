import NextAuth from 'next-auth';
import Credentials from 'next-auth/providers/credentials';
import bcrypt from 'bcryptjs';
import { q1 } from '@/lib/db';
import { authConfig, type AppRole } from './auth.config';

/**
 * Auth.js (NextAuth v5) configuration for the Node runtime.
 * Credentials are validated against the existing `users` table.
 */
export const { handlers, auth, signIn, signOut } = NextAuth({
  ...authConfig,
  providers: [
    Credentials({
      name: 'Credentials',
      credentials: {
        username: { label: 'Username', type: 'text' },
        password: { label: 'Password', type: 'password' },
      },
      async authorize(credentials) {
        const username = String(credentials?.username ?? '').trim();
        const password = String(credentials?.password ?? '');
        if (!username || !password) return null;

        const user = await q1<{
          id: number;
          username: string;
          password_hash: string;
          full_name: string;
          role: AppRole;
          vendor_id: number | null;
          is_active: number;
          vendor_name: string | null;
        }>(
          `SELECT u.id, u.username, u.password_hash, u.full_name, u.role, u.vendor_id, u.is_active,
                  v.name AS vendor_name
           FROM users u LEFT JOIN vendors v ON v.id = u.vendor_id
           WHERE u.username=? AND u.is_active=1`,
          [username]
        );
        if (!user) return null;

        const ok = await bcrypt.compare(password, user.password_hash);
        if (!ok) return null;

        return {
          id: String(user.id),
          username: user.username,
          full_name: user.full_name,
          role: user.role,
          vendor_id: user.vendor_id === null ? null : Number(user.vendor_id),
          vendor_name: user.vendor_name ?? null,
        } as unknown as import('next-auth').User;
      },
    }),
  ],
});
