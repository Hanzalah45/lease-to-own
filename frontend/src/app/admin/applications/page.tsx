"use client";

import Link from "next/link";
import { useMemo, useState } from "react";
import { useAuth } from "@/context/AuthContext";
import { PageHeroHeader } from "@/components/layout/PageHeroHeader";
import { StatusTag } from "@/components/dashboard/StatusTag";
import { PlusIcon, SearchIcon } from "@/components/icons";

type AppStatus =
  | "submitted"
  | "passed"
  | "funded"
  | "under_review"
  | "completed"
  | "needs_info"
  | "approved"
  | "declined"
  | "processed"
  | "withdrawn";

interface AppRow {
  id: number;
  status: AppStatus;
  customer: string;
  detail: string;
  price: string;
  createdBy: string;
  updated: string;
}

const STATUS_STYLE: Record<AppStatus, { color: string; label: string }> = {
  submitted: { color: "#404040", label: "Submitted" },
  passed: { color: "#D97706", label: "Passed" },
  funded: { color: "#16A34A", label: "Funded" },
  under_review: { color: "#D97706", label: "Under review" },
  completed: { color: "#0D9488", label: "Completed" },
  needs_info: { color: "#D97706", label: "Needs info" },
  approved: { color: "#2563EB", label: "Approved" },
  declined: { color: "#DC2626", label: "Declined" },
  processed: { color: "#7C3AED", label: "Processed" },
  withdrawn: { color: "#A3A3A3", label: "Withdrawn" },
};

const FILTERS: { key: "all" | AppStatus | "to_complete"; label: string; count: number }[] = [
  { key: "all", label: "All", count: 50 },
  { key: "needs_info", label: "Needs Info", count: 3 },
  { key: "approved", label: "Approved", count: 6 },
  { key: "to_complete", label: "To Complete", count: 3 },
  { key: "processed", label: "Processed", count: 1 },
  { key: "declined", label: "Declined", count: 6 },
  { key: "funded", label: "Funded", count: 34 },
  { key: "withdrawn", label: "Withdrawn", count: 1 },
];

const INITIAL_ROWS: AppRow[] = [
  { id: 1, status: "submitted", customer: "Robert Kirkland", detail: "Conroe, TX", price: "$7,399", createdBy: "Robert Kirkland", updated: "8/6/2026" },
  { id: 2, status: "passed", customer: "Dell Rowan", detail: "CAT 259D3 Skid Steer · Willis, TX", price: "$6,910", createdBy: "Admin", updated: "8/3/2026" },
  { id: 3, status: "funded", customer: "Robert Kirkland", detail: "Conroe, TX", price: "$7,399", createdBy: "Outdoor Fix", updated: "8/6/2026" },
  { id: 4, status: "under_review", customer: "Loyd Ellis", detail: "Houston, TX", price: "$6,000", createdBy: "Loyd Ellis", updated: "8/5/2026" },
  { id: 5, status: "completed", customer: "Randy Ladere", detail: "Brenham, TX", price: "$4,798", createdBy: "Admin", updated: "7/31/2026" },
  { id: 6, status: "needs_info", customer: "Brandon Palmer", detail: "Dayton, TX", price: "$5,763", createdBy: "Outdoor Fix LLC", updated: "8/5/2026" },
  { id: 7, status: "approved", customer: "Kirk Austin", detail: "Bayton, TX", price: "$4,388", createdBy: "Outdoor Fix LLC", updated: "8/3/2026" },
  { id: 8, status: "declined", customer: "Brett Deyo", detail: "Wylie, TX", price: "$7,599", createdBy: "Outdoor Fix", updated: "7/31/2026" },
  { id: 9, status: "processed", customer: "Randy Ladere", detail: "Brenham, TX", price: "$4,798", createdBy: "Outdoor Fix LLC", updated: "7/31/2026" },
  { id: 10, status: "withdrawn", customer: "Randy Ladere", detail: "Brenham, TX", price: "$4,798", createdBy: "Outdoor Fix LLC", updated: "7/31/2026" },
];

export default function AdminApplicationsPage() {
  const { user } = useAuth();
  const isSuperAdmin = user?.role === "super_admin";
  const restrictions = user?.admin_permissions?.map((p) => p.permission) ?? [];
  const canReview = isSuperAdmin || restrictions.length === 0 || restrictions.includes("application_review");

  const [rows, setRows] = useState<AppRow[]>(INITIAL_ROWS);
  const [activeFilter, setActiveFilter] = useState<(typeof FILTERS)[number]["key"]>("all");
  const [search, setSearch] = useState("");

  const filtered = useMemo(() => {
    return rows.filter((row) => {
      const matchesFilter =
        activeFilter === "all" ||
        (activeFilter === "to_complete" ? row.status === "completed" : row.status === activeFilter);
      const q = search.trim().toLowerCase();
      const matchesSearch =
        !q || row.customer.toLowerCase().includes(q) || row.detail.toLowerCase().includes(q);
      return matchesFilter && matchesSearch;
    });
  }, [rows, activeFilter, search]);

  function decide(id: number, next: "approved" | "declined") {
    setRows((prev) => prev.map((row) => (row.id === id ? { ...row, status: next } : row)));
  }

  return (
    <div className="space-y-6">
      <PageHeroHeader
        title="Applications"
        subtitle="Outdoor Fix · Willis — 50 total"
        action={
          <Link
            href="/admin/applications/new"
            className="font-heading flex items-center gap-1.5 self-start rounded-md bg-red-600 px-4 py-2 text-sm font-bold text-white hover:bg-red-700"
          >
            <PlusIcon className="h-4 w-4" />
            New Application
          </Link>
        }
      />

      <div className="relative">
        <SearchIcon className="pointer-events-none absolute left-3.5 top-1/2 h-4 w-4 -translate-y-1/2 text-neutral-400" />
        <input
          value={search}
          onChange={(e) => setSearch(e.target.value)}
          placeholder="Search applications — customer name, city, phone, email..."
          className="w-full rounded-md border border-neutral-200 bg-white py-2.5 pl-10 pr-3 text-sm text-neutral-800 placeholder:text-neutral-400 focus:border-red-300 focus:outline-none"
        />
      </div>

      <div className="flex flex-wrap gap-2">
        {FILTERS.map((f) => (
          <button
            key={f.key}
            onClick={() => setActiveFilter(f.key)}
            className={`font-heading rounded-full px-3.5 py-1.5 text-xs font-bold uppercase tracking-wide transition ${
              activeFilter === f.key ? "bg-red-600 text-white" : "bg-neutral-100 text-neutral-600 hover:bg-neutral-200"
            }`}
          >
            {f.label} {f.count}
          </button>
        ))}
      </div>

      <div className="overflow-hidden rounded-xl border border-neutral-200 bg-white p-4 sm:p-5">
        <div className="-mx-4 overflow-x-auto px-4 sm:mx-0 sm:px-0">
          <table className="w-full min-w-[720px] text-left text-sm">
            <thead>
              <tr className="font-heading border-b border-neutral-200 text-xs font-bold uppercase tracking-wide text-neutral-400">
                <th className="pb-2 font-medium">Status</th>
                <th className="pb-2 font-medium">Customer</th>
                <th className="pb-2 font-medium">Price</th>
                <th className="pb-2 font-medium">Created by</th>
                <th className="pb-2 font-medium">Updated</th>
                <th className="pb-2 font-medium"></th>
              </tr>
            </thead>
            <tbody>
              {filtered.map((row) => (
                <tr key={row.id} className="border-b border-neutral-100 last:border-0">
                  <td className="py-3">
                    <StatusTag color={STATUS_STYLE[row.status].color} label={STATUS_STYLE[row.status].label} />
                  </td>
                  <td className="py-3">
                    <p className="font-medium text-neutral-900">{row.customer}</p>
                    <p className="text-xs text-neutral-400">{row.detail}</p>
                  </td>
                  <td className="py-3 text-neutral-700">{row.price}</td>
                  <td className="py-3 text-neutral-600">{row.createdBy}</td>
                  <td className="py-3 text-neutral-500">{row.updated}</td>
                  <td className="py-3 text-right">
                    {row.status === "submitted" && canReview ? (
                      <div className="flex justify-end gap-2">
                        <button
                          onClick={() => decide(row.id, "approved")}
                          className="font-heading flex items-center gap-1 rounded-md bg-green-600 px-3 py-1.5 text-xs font-bold text-white hover:bg-green-700"
                        >
                          ✓ Accept
                        </button>
                        <button
                          onClick={() => decide(row.id, "declined")}
                          className="font-heading flex items-center gap-1 rounded-md border border-red-200 bg-red-50 px-3 py-1.5 text-xs font-bold text-red-600 hover:bg-red-100"
                        >
                          ✕ Decline
                        </button>
                      </div>
                    ) : row.status === "submitted" ? (
                      <span className="text-xs text-neutral-400" title="Requires application review access">
                        Awaiting review
                      </span>
                    ) : (
                      <Link href={`/admin/applications/${row.id}`} className="font-heading text-xs font-bold text-red-600 hover:underline">
                        View →
                      </Link>
                    )}
                  </td>
                </tr>
              ))}
              {filtered.length === 0 && (
                <tr>
                  <td colSpan={6} className="py-6 text-center text-sm text-neutral-400">
                    No applications match this filter.
                  </td>
                </tr>
              )}
            </tbody>
          </table>
        </div>
      </div>
    </div>
  );
}
