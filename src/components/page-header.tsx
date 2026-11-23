import type { ReactNode } from 'react';

export type PageHeaderProps = {
  title: string;
  subtitle?: string;
  icon?: string;
  breadcrumb?: { label: string; href?: string }[];
  actions?: ReactNode;
};

/**
 * Consistent page heading used across the portal body.
 */
export default function PageHeader({
  title,
  subtitle,
  icon,
  breadcrumb,
  actions,
}: PageHeaderProps) {
  return (
    <div className="page-header">
      <div className="min-w-0">
        {breadcrumb && breadcrumb.length > 0 ? (
          <nav className="breadcrumb" aria-label="Breadcrumb">
            <a href="/dashboard">Beranda</a>
            {breadcrumb.map((b) => (
              <span key={b.label} className="flex items-center gap-1.5">
                <i className="bi bi-chevron-right sep text-[10px]" />
                {b.href ? <a href={b.href}>{b.label}</a> : <span className="text-slate-500">{b.label}</span>}
              </span>
            ))}
          </nav>
        ) : null}

        <div className="flex items-center gap-3">
          {icon ? (
            <span className="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-blue-600/10 text-lg text-blue-600">
              <i className={`bi ${icon}`} />
            </span>
          ) : null}
          <div className="min-w-0">
            <h1 className="page-title truncate">{title}</h1>
            {subtitle ? <p className="page-subtitle">{subtitle}</p> : null}
          </div>
        </div>
      </div>

      {actions ? <div className="flex flex-wrap items-center gap-2">{actions}</div> : null}
    </div>
  );
}
