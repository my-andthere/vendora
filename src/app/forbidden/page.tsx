import Link from 'next/link';

export default function ForbiddenPage() {
  return (
    <div className="flex min-h-screen items-center justify-center bg-slate-100 p-4">
      <div className="card w-full max-w-md">
        <div className="card-body text-center">
          <i className="bi bi-shield-x text-4xl text-red-600" />
          <h1 className="mt-3 text-lg font-semibold">403 &mdash; Akses Ditolak</h1>
          <p className="mt-2 text-sm text-slate-600">Akses ditolak untuk role Anda.</p>
          <Link href="/dashboard" className="btn btn-primary mt-4">
            <i className="bi bi-house" /> Kembali
          </Link>
        </div>
      </div>
    </div>
  );
}
