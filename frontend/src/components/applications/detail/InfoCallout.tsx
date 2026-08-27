import type { ComponentType, ReactNode, SVGProps } from "react";

const TONES = {
  blue: { bg: "bg-blue-50", border: "border-blue-500", iconBg: "bg-blue-100", iconColor: "text-blue-600", dot: "bg-blue-400", text: "text-blue-700" },
  green: { bg: "bg-green-50", border: "border-green-500", iconBg: "bg-green-100", iconColor: "text-green-600", dot: "bg-green-400", text: "text-green-700" },
  amber: { bg: "bg-amber-50", border: "border-amber-500", iconBg: "bg-amber-100", iconColor: "text-amber-600", dot: "bg-amber-400", text: "text-amber-700" },
  purple: { bg: "bg-purple-50", border: "border-purple-500", iconBg: "bg-purple-100", iconColor: "text-purple-600", dot: "bg-purple-400", text: "text-purple-700" },
  teal: { bg: "bg-teal-50", border: "border-teal-500", iconBg: "bg-teal-100", iconColor: "text-teal-600", dot: "bg-teal-400", text: "text-teal-700" },
} as const;

export function InfoCallout({
  tone,
  icon: Icon,
  title,
  description,
  items,
}: {
  tone: keyof typeof TONES;
  icon: ComponentType<SVGProps<SVGSVGElement>>;
  title: string;
  description: string;
  items?: ReactNode[];
}) {
  const t = TONES[tone];
  return (
    <div className={`flex gap-3 rounded-xl border-l-4 ${t.border} ${t.bg} p-4 sm:p-5`}>
      <span className={`flex h-9 w-9 shrink-0 items-center justify-center rounded-full ${t.iconBg} ${t.iconColor}`}>
        <Icon className="h-4 w-4" />
      </span>
      <div className="flex-1">
        <p className="text-sm font-bold text-neutral-900">{title}</p>
        <p className="mt-0.5 text-sm text-neutral-600">{description}</p>
        {items && items.length > 0 && (
          <div className="mt-3 space-y-2">
            {items.map((item, i) => (
              <div key={i} className="flex items-center gap-2 rounded-md bg-white/60 px-3 py-2 text-xs text-neutral-600">
                <span className={`h-1.5 w-1.5 shrink-0 rounded-full ${t.dot}`} />
                {item}
              </div>
            ))}
          </div>
        )}
      </div>
    </div>
  );
}
