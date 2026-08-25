"use client";

import { useState } from "react";
import { SectionHeading } from "@/components/dashboard/SectionHeading";

export interface ActivityRow {
  id: number;
  status: "funded" | "needs_info" | "approved" | "declined" | "withdrawn";
  customer: string;
  location: string;
  price: string;
  updated: string;
}

const STATUS_STYLE: Record<ActivityRow["status"], { dot: string; label: string }> = {
  funded: { dot: "bg-green-500", label: "Funded" },
  needs_info: { dot: "bg-amber-500", label: "Needs info" },
  approved: { dot: "bg-blue-500", label: "Approved" },
  declined: { dot: "bg-neutral-400", label: "Declined" },
  withdrawn: { dot: "bg-neutral-300", label: "Withdrawn" },
};

const FILTERS = ["All", "Needs info", "Approved", "Funded", "Declined", "Withdrawn"] as const;

export function RecentActivityTable({ rows }: { rows: ActivityRow[] }) {
  const [filter, setFilter] = useState<(typeof FILTERS)[number]>("All");

  const filtered =
    filter === "All"
      ? rows
      : rows.filter((r) => STATUS_STYLE[r.status].label.toLowerCase() === filter.toLowerCase());

  return (
    <div className="overflow-hidden rounded-xl border border-neutral-200 bg-white p-4 sm:p-5">
      <div className="mb-4 flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
        <SectionHeading title="Recent activity" />
        <span className="text-xs text-neutral-400">Sample data — live once Milestone 5 is wired up</span>
      </div>

      <div className="mb-4 flex flex-wrap gap-1.5">
        {FILTERS.map((f) => (
          <button
            key={f}
            onClick={() => setFilter(f)}
            className={`font-heading rounded-full px-3 py-1 text-xs font-bold uppercase tracking-wide transition ${
              filter === f ? "bg-red-600 text-white" : "bg-neutral-100 text-neutral-600 hover:bg-neutral-200"
            }`}
          >
            {f}
          </button>
        ))}
      </div>

      <div className="-mx-4 overflow-x-auto px-4 sm:mx-0 sm:px-0">
        <table className="w-full min-w-[520px] text-left text-sm">
          <thead>
            <tr className="font-heading border-b border-neutral-200 text-xs font-bold uppercase tracking-wide text-neutral-400">
              <th className="pb-2 font-medium">Status</th>
              <th className="pb-2 font-medium">Customer</th>
              <th className="pb-2 font-medium">Price</th>
              <th className="pb-2 font-medium">Updated</th>
            </tr>
          </thead>
          <tbody>
            {filtered.map((row) => (
              <tr key={row.id} className="border-b border-neutral-100 last:border-0">
                <td className="py-2.5">
                  <span className="inline-flex items-center gap-1.5 text-xs font-medium text-neutral-700">
                    <span className={`h-1.5 w-1.5 rounded-full ${STATUS_STYLE[row.status].dot}`} />
                    {STATUS_STYLE[row.status].label}
                  </span>
                </td>
                <td className="py-2.5">
                  <p className="font-medium text-neutral-900">{row.customer}</p>
                  <p className="text-xs text-neutral-400">{row.location}</p>
                </td>
                <td className="py-2.5 text-neutral-700">{row.price}</td>
                <td className="py-2.5 text-neutral-500">{row.updated}</td>
              </tr>
            ))}
            {filtered.length === 0 && (
              <tr>
                <td colSpan={4} className="py-6 text-center text-sm text-neutral-400">
                  Nothing here yet.
                </td>
              </tr>
            )}
          </tbody>
        </table>
      </div>
    </div>
  );
}
