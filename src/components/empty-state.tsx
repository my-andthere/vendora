/**
 * Shared empty / placeholder state for pages that are not implemented yet.
 */
export default function EmptyState({
  icon = 'bi-inbox',
  title = 'Belum ada data',
  text = 'Segera tersedia.',
}: {
  icon?: string;
  title?: string;
  text?: string;
}) {
  return (
    <div className="empty-state">
      <span className="empty-state-icon">
        <i className={`bi ${icon}`} />
      </span>
      <p className="empty-state-title">{title}</p>
      <p className="empty-state-text">{text}</p>
    </div>
  );
}
