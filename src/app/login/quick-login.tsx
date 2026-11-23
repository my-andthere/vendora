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
    <div className="card mt-3">
      <div className="card-body p-3">
        <div className="mb-2 flex items-center gap-2">
          <i className="bi bi-lightning-charge-fill text-amber-500" />
          <span className="font-semibold">Login Cepat (Testing)</span>
        </div>
        <p className="mb-2 text-xs text-slate-500">Klik role untuk langsung masuk tanpa mengetik.</p>
        <div className="grid grid-cols-2 gap-2">
          {QUICK_ACCOUNTS.map((a) => (
            <form key={a.username} action={loginAction}>
              <input type="hidden" name="username" value={a.username} />
              <input type="hidden" name="password" value={a.password} />
              <input type="hidden" name="callbackUrl" value={callbackUrl ?? ''} />
              <button type="submit" className="btn btn-outline-secondary w-full justify-start text-left text-xs">
                <i className={`bi ${a.icon}`} /> {a.label}
                <span className="block text-[10px] font-normal text-slate-500">{a.username}</span>
              </button>
            </form>
          ))}
        </div>
      </div>
    </div>
  );
}
