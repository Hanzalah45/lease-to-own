export function StatusTag({ color, label }: { color: string; label: string }) {
  return (
    <span
      className="font-heading inline-flex items-center gap-1.5 text-xs font-bold uppercase tracking-wide"
      style={{ color }}
    >
      <span className="h-1.5 w-1.5 rounded-full" style={{ backgroundColor: color }} />
      {label}
    </span>
  );
}
