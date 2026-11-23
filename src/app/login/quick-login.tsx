import { loginAction } from './actions';

const QUICK_ACCOUNTS: { username: string; password: string; label: string; icon: string }[] = [
  { username: 'admin', password: 'admin123', label: 'Administrator', icon: 'bi-shield-lock' },
  { username: 'noc', password: 'noc123', label: 'NOC', icon: 'bi-broadcast' },
  { username: 'procurement', password: 'procurement123', label: 'Procurement', icon: 'bi-clipboard-data' },
  { username: 'inventory', password: 'inventory123', label: 'Inventory', icon: 'bi-box-seam' },
  { username: 'foc', password: 'foc123', label: 'FOC', icon: 'bi-clipboard-check' },
  { username: 'nam', password: 'nam123', label: 'NAM', icon: 'bi-diagram-3' },
  { username: 'vendor1', password: 'vendor123', label: 'Vendor 1', icon: 'bi-tools' },
  { username: 'vendor2', password: 'vendor123', label: 'Vendor 2', icon: 'bi-tools' },
  { username: 'vendor3', password: 'vendor123', label: 'Vendor 3', icon: 'bi-tools' },
  { username: 'vendor4', password: 'vendor123', label: 'Vendor 4', icon: 'bi-tools' },
];

export default function QuickLogin({ callbackUrl }: { callbackUrl?: string }) {
  return (
    <details className="card group mt-4 overflow-hidden">
      <summary className="flex cursor-pointer list-none items-center justify-between px-5 py-3.5 transition hover:bg-slate-50 [&::-webkit-details-marker]:hidden">
        <span className="flex items-center gap-2.5 text-sm font-semibold text-slate-700">
          <span className="flex h-7 w-7 items-center justify-center rounded-md bg-amber-100 text-amber-600">
            <i className="bi bi-lightning-charge-fill" />
          </span>
          Login Cepat
        </span>
        <span className="flex items-center gap-2">
          <span className="rounded-full bg-amber-100 px-2 py-0.5 text-[10px] font-semibold tracking-wide text-amber-700 uppercase">
            Testing
          </span>
          <i className="bi bi-chevron-down text-xs text-slate-400 transition open:rotate-180" />
        </span>
      </summary>

      <div className="border-t border-slate-200 px-5 py-4">
        <p className="mb-3 text-xs text-slate-500">
          Klik role untuk masuk otomatis tanpa mengetik kredensial.
        </p>
        <div className="grid grid-cols-2 gap-2 sm:grid-cols-3">
          {QUICK_ACCOUNTS.map((a) => (
            <form key={a.username} action={loginAction}>
              <input type="hidden" name="username" value={a.username} />
              <input type="hidden" name="password" value={a.password} />
              <input type="hidden" name="callbackUrl" value={callbackUrl ?? ''} />
              <button
                type="submit"
                className="group/btn flex w-full items-start gap-2 rounded-lg border border-slate-200 bg-white px-3 py-2 text-left transition hover:border-blue-300 hover:bg-blue-50/60 hover:shadow-sm"
              >
                <i className={`bi ${a.icon} mt-0.5 text-slate-400 group-hover/btn:text-blue-600`} />
                <span className="min-w-0">
                  <span className="block truncate text-xs font-semibold text-slate-700">{a.label}</span>
                  <span className="block truncate text-[10px] text-slate-400">{a.username}</span>
                </span>
              </button>
            </form>
          ))}
        </div>
      </div>
    </details>
  );
}
