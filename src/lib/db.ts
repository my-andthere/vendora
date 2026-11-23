import 'server-only';
import mysql from 'mysql2/promise';
import { AsyncLocalStorage } from 'node:async_hooks';

export type Row = Record<string, any>;
export type Queryable = {
  query(sql: string, params?: any[]): Promise<[any, any]>;
  execute(sql: string, params?: any[]): Promise<[any, any]>;
};

declare global {
  // eslint-disable-next-line no-var
  var __dbPool: mysql.Pool | undefined;
  // eslint-disable-next-line no-var
  var __dbAls: AsyncLocalStorage<mysql.PoolConnection> | undefined;
}

function createPool(): mysql.Pool {
  return mysql.createPool({
    host: process.env.DB_HOST ?? '127.0.0.1',
    port: Number(process.env.DB_PORT ?? 3306),
    user: process.env.DB_USER ?? 'root',
    password: process.env.DB_PASS ?? '',
    database: process.env.DB_NAME ?? 'procurement_db',
    waitForConnections: true,
    connectionLimit: 10,
    maxIdle: 10,
    idleTimeout: 60_000,
    queueLimit: 0,
    charset: 'utf8mb4_unicode_ci',
    dateStrings: true,
    supportBigNumbers: true,
    decimalNumbers: true,
  });
}

export function pool(): mysql.Pool {
  if (!globalThis.__dbPool) globalThis.__dbPool = createPool();
  return globalThis.__dbPool;
}

function als(): AsyncLocalStorage<mysql.PoolConnection> {
  if (!globalThis.__dbAls) globalThis.__dbAls = new AsyncLocalStorage<mysql.PoolConnection>();
  return globalThis.__dbAls;
}

/** The connection to use: the active transaction's connection, or the pool. */
function conn(): Queryable {
  const active = als().getStore();
  return (active ?? pool()) as unknown as Queryable;
}

/** Run a query and return all rows. */
export async function q<T = Row>(sql: string, params: any[] = []): Promise<T[]> {
  const [rows] = await conn().query(sql, params);
  return rows as T[];
}

/** Run a query and return the first row (or null). */
export async function q1<T = Row>(sql: string, params: any[] = []): Promise<T | null> {
  const rows = await q<T>(sql, params);
  return rows.length ? rows[0] : null;
}

/** Run a query and return a single scalar value (first column of first row). */
export async function qv<T = any>(sql: string, params: any[] = []): Promise<T | null> {
  const row = await q1<Row>(sql, params);
  if (!row) return null;
  const keys = Object.keys(row);
  return keys.length ? (row[keys[0]] as T) : null;
}

/** Execute a write statement; returns affected rows. */
export async function exec(sql: string, params: any[] = []): Promise<number> {
  const [result] = await conn().execute(sql, params);
  return (result as any).affectedRows ?? 0;
}

/** Execute an INSERT; returns the inserted id. */
export async function insert(sql: string, params: any[] = []): Promise<number> {
  const [result] = await conn().execute(sql, params);
  return (result as any).insertId as number;
}

/**
 * Run `fn` inside a transaction. All q()/exec()/insert() calls made inside
 * `fn` automatically use the transaction connection.
 */
export async function tx<T>(fn: () => Promise<T>): Promise<T> {
  const connection = await pool().getConnection();
  try {
    await connection.beginTransaction();
    const result = await als().run(connection, fn);
    await connection.commit();
    return result;
  } catch (e) {
    try {
      await connection.rollback();
    } catch {
      /* ignore rollback errors */
    }
    throw e;
  } finally {
    connection.release();
  }
}
