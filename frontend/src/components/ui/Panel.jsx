export function Panel({ label, children, className = "" }) {
  return (
    <div className={`bg-dp-panel rounded-2xl border border-dp-border p-4 shadow-[0_2px_10px_rgba(0,0,0,0.02)] ${className}`}>
      {label && (
        <div className="text-[11px] font-semibold text-dp-muted mb-3 uppercase tracking-wider">
          {label}
        </div>
      )}
      {children}
    </div>
  );
}
