export function MetricCard({
  value,
  label,
  barColor,
  barPercent,
}: {
  value: string | number;
  label: string;
  barColor: string;
  barPercent: number;
}) {
  return (
    <div className="rounded-xl border border-neutral-200 bg-white px-5 py-4">
      <p className="font-heading text-3xl font-black text-neutral-900">{value}</p>
      <p className="mt-1 text-xs font-semibold uppercase tracking-wide text-neutral-500">{label}</p>
      <div className="mt-3 h-1 w-full overflow-hidden rounded-full bg-neutral-100">
        <div
          className="h-full rounded-full"
          style={{ width: `${barPercent}%`, backgroundColor: barColor }}
        />
      </div>
    </div>
  );
}
