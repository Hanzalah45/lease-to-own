import { money } from "@/components/applications/wizard/types";

const CHART_HEIGHT = 160;

export function EpoChart({ schedule }: { schedule: { month: number; value: number }[] }) {
  if (schedule.length === 0) {
    return <p className="text-sm text-neutral-400">Enter a cash price, term, and monthly rental to preview the payoff schedule.</p>;
  }
  const max = Math.max(...schedule.map((s) => s.value), 1);

  return (
    <div className="-mx-4 overflow-x-auto px-4 sm:mx-0 sm:px-0">
      <div className="flex items-end gap-2" style={{ height: CHART_HEIGHT, minWidth: schedule.length * 44 }}>
        {schedule.map((s) => {
          const height = Math.max((s.value / max) * CHART_HEIGHT, 3);
          return (
            <div
              key={s.month}
              className="group relative flex flex-1 flex-col items-center justify-end gap-2"
              style={{ height: CHART_HEIGHT }}
              title={`Month ${s.month}: ${money(s.value)}`}
            >
              <div className="w-9 rounded-t bg-gradient-to-t from-red-600 to-red-400" style={{ height }} />
              <span className="text-[10px] font-medium uppercase text-neutral-400">Mo {s.month}</span>
            </div>
          );
        })}
      </div>
    </div>
  );
}
