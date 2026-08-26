import type { ReactNode } from "react";
import { SectionHeading } from "@/components/dashboard/SectionHeading";

export interface DataTableColumn<T> {
  key: string;
  header: string;
  render: (row: T) => ReactNode;
}

export function DataTable<T extends { id: string | number }>({
  title,
  action,
  columns,
  rows,
  emptyLabel = "Nothing here yet.",
}: {
  title: string;
  action?: ReactNode;
  columns: DataTableColumn<T>[];
  rows: T[];
  emptyLabel?: string;
}) {
  return (
    <div className="overflow-hidden rounded-xl border border-neutral-200 bg-white p-4 sm:p-5">
      <div className="mb-4 flex items-center justify-between">
        <SectionHeading title={title} />
        {action}
      </div>

      <div className="-mx-4 overflow-x-auto px-4 sm:mx-0 sm:px-0">
        <table className="w-full min-w-[560px] text-left text-sm">
          <thead>
            <tr className="font-heading border-b border-neutral-200 text-xs font-bold uppercase tracking-wide text-neutral-400">
              {columns.map((col) => (
                <th key={col.key} className="pb-2 font-medium">
                  {col.header}
                </th>
              ))}
            </tr>
          </thead>
          <tbody>
            {rows.map((row) => (
              <tr key={row.id} className="border-b border-neutral-100 last:border-0">
                {columns.map((col) => (
                  <td key={col.key} className="py-2.5">
                    {col.render(row)}
                  </td>
                ))}
              </tr>
            ))}
            {rows.length === 0 && (
              <tr>
                <td colSpan={columns.length} className="py-6 text-center text-sm text-neutral-400">
                  {emptyLabel}
                </td>
              </tr>
            )}
          </tbody>
        </table>
      </div>
    </div>
  );
}
