import type { ReactNode } from "react";
import { SectionHeading } from "@/components/dashboard/SectionHeading";
import { PencilIcon } from "@/components/icons";

export function DetailCard({
  title,
  editable,
  canEdit = true,
  onEdit,
  rows,
  note,
}: {
  title: string;
  editable?: boolean;
  canEdit?: boolean;
  onEdit?: () => void;
  rows: { label: string; value: ReactNode }[];
  note?: ReactNode;
}) {
  return (
    <div className="rounded-xl border border-neutral-200 bg-white p-5">
      <div className="mb-4 flex items-center justify-between">
        <SectionHeading title={title} />
        {editable && (
          <button
            onClick={canEdit ? onEdit : undefined}
            disabled={!canEdit}
            title={canEdit ? "Edit" : "You don't have permission to edit this section"}
            className={`rounded p-1 ${
              canEdit ? "text-neutral-400 hover:bg-neutral-100 hover:text-neutral-700" : "cursor-not-allowed text-neutral-200"
            }`}
          >
            <PencilIcon className="h-4 w-4" />
          </button>
        )}
      </div>
      <div className="space-y-2.5 text-sm">
        {rows.map((r, i) => (
          <div key={i} className="flex items-start justify-between gap-4">
            <span className="text-neutral-500">{r.label}</span>
            <span className="text-right font-semibold text-neutral-900">{r.value}</span>
          </div>
        ))}
      </div>
      {note && <p className="mt-3 text-xs text-neutral-500">{note}</p>}
    </div>
  );
}
