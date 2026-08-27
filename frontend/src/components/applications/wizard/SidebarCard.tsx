import type { ReactNode } from "react";

export function SidebarCard({
  title,
  rows,
}: {
  title: string;
  rows: { label: string; value: ReactNode; highlight?: boolean }[];
}) {
  return (
    <div className="rounded-xl bg-neutral-950 p-6 text-white">
      <h3 className="font-heading mb-5 text-sm font-bold uppercase tracking-wide">{title}</h3>
      <div className="space-y-3.5">
        {rows.map((r, i) => (
          <div key={i} className="flex items-center justify-between text-sm">
            <span className="text-neutral-400">{r.label}</span>
            <span className={`font-heading font-bold ${r.highlight ? "text-red-500" : "text-white"}`}>{r.value}</span>
          </div>
        ))}
      </div>
    </div>
  );
}
