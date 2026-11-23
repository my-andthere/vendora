import QuickLogin from './quick-login';
import { loginAction } from './actions';

export default async function LoginPage({
  searchParams,
}: {
  searchParams: Promise<{ callbackUrl?: string; error?: string }>;
}) {
  const params = await searchParams;

  return (
    <div className="flex min-h-screen items-center justify-center bg-slate-100 p-4">
      <div className="w-full max-w-sm">
        <div className="mb-4 text-center">
          <i className="bi bi-tools text-4xl text-blue-600" />
          <h1 className="mt-2 text-xl font-semibold">Procurement Field Service</h1>
        </div>

        <div className="card">
          <div className="card-body p-5">
            {params.error && <div className="alert alert-danger">Username atau password salah.</div>}
            <form action={loginAction}>
              <div className="mb-3">
                <label className="form-label" htmlFor="username">
                  Username
                </label>
                <input
                  id="username"
                  name="username"
                  className="form-control"
                  required
                  autoFocus
                  autoComplete="username"
                />
              </div>
              <div className="mb-4">
                <label className="form-label" htmlFor="password">
                  Password
                </label>
                <input
                  id="password"
                  name="password"
                  type="password"
                  className="form-control"
                  required
                  autoComplete="current-password"
                />
              </div>
              <input type="hidden" name="callbackUrl" value={params.callbackUrl ?? ''} />
              <button className="btn btn-primary w-full">Masuk</button>
            </form>
          </div>
        </div>

        <QuickLogin callbackUrl={params.callbackUrl} />
      </div>
    </div>
  );
}
