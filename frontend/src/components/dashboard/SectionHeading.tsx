export function SectionHeading({ title, subtitle }: { title: string; subtitle?: string }) {
  return (
    <div className="flex items-start gap-2">
      <span className="mt-1 h-4 w-1 shrink-0 rounded-full bg-red-600" />
      <div>
        <h2 className="text-lg font-bold uppercase tracking-wide text-neutral-900">{title}</h2>
        {subtitle && <p className="text-xs text-neutral-400">{subtitle}</p>}
      </div>
    </div>
  );
}
