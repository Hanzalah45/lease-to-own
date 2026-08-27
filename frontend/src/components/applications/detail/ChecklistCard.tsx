"use client";

import { SectionHeading } from "@/components/dashboard/SectionHeading";
import { CheckIcon } from "@/components/icons";
import type { ChecklistItem } from "@/components/applications/detail/types";

export function ChecklistCard({ items, onToggle }: { items: ChecklistItem[]; onToggle: (index: number) => void }) {
  return (
    <div className="rounded-xl border border-neutral-200 bg-white p-5">
      <SectionHeading title="Checklist" />
      <div className="mt-4 space-y-2.5">
        {items.map((item, i) => (
          <button
            key={item.label}
            onClick={() => onToggle(i)}
            className="flex w-full items-center gap-2.5 text-left text-sm text-neutral-700"
          >
            <span
              className={`flex h-4 w-4 shrink-0 items-center justify-center rounded ${
                item.done ? "bg-green-600 text-white" : "border border-neutral-300"
              }`}
            >
              {item.done && <CheckIcon className="h-3 w-3" />}
            </span>
            {item.label}
          </button>
        ))}
      </div>
    </div>
  );
}
