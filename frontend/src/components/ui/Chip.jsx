export function Chip({ active, children, onClick }) {
  return (
    <div
      onClick={onClick}
      className={`inline-flex items-center mr-1.5 mb-1.5 px-3.5 py-2 rounded-full text-sm font-medium border cursor-pointer select-none transition-colors ${
        active ? "bg-dp-solid text-dp-on-solid border-dp-solid" : "bg-dp-panel text-dp-muted-2 border-dp-border hover:border-dp-ink/40"
      }`}
    >
      {children}
    </div>
  );
}
