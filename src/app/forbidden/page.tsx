import Link from 'next/link';

export default function ForbiddenPage() {
  return (
    <div className="flex min-h-screen items-center justify-center bg-slate-100 p-4">
      <div className="relative w-full max-w-md overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-xl shadow-slate-900/5">
        <div className="absolute inset-x-0 top-0 h-1 bg-gradient-to-r from-red-500 via-orange-500 to-amber-400" />
        <div className="p-8 text-center">
          <div className="mx-auto flex h-16 w-16 items-center justify-center rounded-2xl bg-red-50 text-red-600 ring-1 ring-red-100">
            <i className="bi bi-shield-lock text-3xl" />
          </div>
          <p className="mt-5 text-xs font-semibold tracking-[0.2em] text-slate-400 uppercase">Error 403</p>
          <h1 className="mt-1 text-xl font-semibold tracking-tight text-slate-900">Akses Ditolak</h1>
          <p className="mt-2 text-sm text-slate-500">
            Role Anda tidak memiliki izin untuk membuka halaman ini. Hubungi administrator bila Anda
            merasa ini adalah kesalahan.
          </p>
          <div className="mt-6 flex flex-wrap items-center justify-center gap-2">
            <Link href="/dashboard" className="btn btn-primary">
              <i className="bi bi-house" /> Kembali ke Dashboard
            </Link>
            <Link href="/login" className="btn btn-outline-secondary">
              <i className="bi bi-box-arrow-right" /> Ganti Akun
            </Link>
          </div>
        </div>
      </div>
    </div>
  );
}
