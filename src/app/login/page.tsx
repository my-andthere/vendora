import QuickLogin from './quick-login';
import { loginAction } from './actions';

export default async function LoginPage({
  searchParams,
}: {
  searchParams: Promise<{ callbackUrl?: string; error?: string }>;
}) {
  const params = await searchParams;

  return (
    <div className="grid min-h-screen lg:grid-cols-2">
      {/* Brand panel */}
      <div className="relative hidden overflow-hidden bg-slate-900 lg:flex lg:flex-col lg:justify-between lg:p-12">
        <div
          aria-hidden
          className="pointer-events-none absolute inset-0 opacity-90"
          style={{
            background:
              'radial-gradient(60rem 40rem at 15% 10%, rgba(37,99,235,0.35), transparent 60%), radial-gradient(50rem 35rem at 90% 90%, rgba(14,165,233,0.25), transparent 60%)',
          }}
        />
        <div
          aria-hidden
          className="pointer-events-none absolute inset-0 opacity-[0.07]"
          style={{
            backgroundImage:
              'linear-gradient(to right, white 1px, transparent 1px), linear-gradient(to bottom, white 1px, transparent 1px)',
            backgroundSize: '56px 56px',
          }}
        />

        <div className="relative flex items-center gap-3">
          <span className="flex h-11 w-11 items-center justify-center rounded-xl bg-blue-600 text-lg text-white shadow-lg shadow-blue-600/30">
            <i className="bi bi-tools" />
          </span>
          <div className="leading-tight">
            <p className="text-base font-semibold text-white">Procurement Field Service</p>
            <p className="text-xs text-slate-400">Vendor &amp; Ticket Management</p>
          </div>
        </div>

        <div className="relative max-w-md">
          <h2 className="text-3xl leading-snug font-semibold text-white">
            Kelola tiket, vendor, dan material dalam satu platform.
          </h2>
          <p className="mt-3 text-sm leading-relaxed text-slate-400">
            Pantau permintaan layanan, proses persetujuan, dan riwayat kerja lapangan secara
            real-time untuk seluruh unit operasional.
          </p>

          <ul className="mt-8 space-y-3 text-sm text-slate-300">
            {[
              { icon: 'bi-clipboard-check', text: 'Manajemen tiket berbasis SLA & region' },
              { icon: 'bi-people', text: 'Portal vendor dan laporan kinerja' },
              { icon: 'bi-graph-up-arrow', text: 'Rekapitulasi & dashboard procurement' },
            ].map((f) => (
              <li key={f.text} className="flex items-center gap-3">
                <span className="flex h-8 w-8 shrink-0 items-center justify-center rounded-lg bg-white/10 text-blue-300">
                  <i className={`bi ${f.icon}`} />
                </span>
                {f.text}
              </li>
            ))}
          </ul>
        </div>

        <p className="relative text-xs text-slate-500">
          &copy; {new Date().getFullYear()} Procurement Field Service. All rights reserved.
        </p>
      </div>

      {/* Form panel */}
      <div className="flex items-center justify-center bg-slate-100 px-4 py-10 sm:px-8">
        <div className="w-full max-w-md">
          <div className="mb-6 lg:hidden">
            <span className="flex h-11 w-11 items-center justify-center rounded-xl bg-blue-600 text-lg text-white shadow-lg shadow-blue-600/30">
              <i className="bi bi-tools" />
            </span>
          </div>

          <div className="mb-8">
            <h1 className="text-2xl font-semibold tracking-tight text-slate-900">Masuk ke akun Anda</h1>
            <p className="mt-1.5 text-sm text-slate-500">
              Gunakan username dan password yang diberikan oleh administrator.
            </p>
          </div>

          <div className="card overflow-hidden">
            <div className="card-body p-6">
              {params.error && (
                <div className="alert alert-danger flex items-start gap-2" role="alert">
                  <i className="bi bi-exclamation-circle-fill mt-0.5" />
                  <span>Username atau password salah.</span>
                </div>
              )}

              <form action={loginAction} className="space-y-4">
                <div>
                  <label className="form-label" htmlFor="username">
                    Username
                  </label>
                  <div className="relative">
                    <i className="bi bi-person pointer-events-none absolute top-1/2 left-3 -translate-y-1/2 text-sm text-slate-400" />
                    <input
                      id="username"
                      name="username"
                      className="form-control pl-9"
                      placeholder="Masukkan username"
                      required
                      autoFocus
                      autoComplete="username"
                    />
                  </div>
                </div>

                <div>
                  <label className="form-label" htmlFor="password">
                    Password
                  </label>
                  <div className="relative">
                    <i className="bi bi-lock pointer-events-none absolute top-1/2 left-3 -translate-y-1/2 text-sm text-slate-400" />
                    <input
                      id="password"
                      name="password"
                      type="password"
                      className="form-control pl-9"
                      placeholder="Masukkan password"
                      required
                      autoComplete="current-password"
                    />
                  </div>
                </div>

                <input type="hidden" name="callbackUrl" value={params.callbackUrl ?? ''} />

                <button className="btn btn-primary w-full py-2.5 text-sm font-semibold">
                  <i className="bi bi-box-arrow-in-right" />
                  Masuk
                </button>
              </form>
            </div>
          </div>

          <QuickLogin callbackUrl={params.callbackUrl} />

          <p className="mt-6 text-center text-xs text-slate-400">
            Butuh bantuan? Hubungi administrator sistem.
          </p>
        </div>
      </div>
    </div>
  );
}
