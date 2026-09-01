"use client";

import Link from "next/link";
import { useEffect, useMemo, useState } from "react";
import { useAuth } from "@/context/AuthContext";
import { PageHeroHeader } from "@/components/layout/PageHeroHeader";
import { StatusTag } from "@/components/dashboard/StatusTag";
import { PlusIcon, SearchIcon } from "@/components/icons";
import { listApplications, updateApplication } from "@/lib/applications";
import { money } from "@/components/applications/wizard/types";
import { ApiError } from "@/lib/api";
import type { Application, ApplicationStatus } from "@/types/application";

const STATUS_STYLE: Record<ApplicationStatus, { color: string; label: string }> = {
  submitted: { color: "#404040", label: "Submitted" },
  under_review: { color: "#D97706", label: "Under review" },
  needs_info: { color: "#D97706", label: "Needs info" },
  approved: { color: "#2563EB", label: "Approved" },
  completed: { color: "#0D9488", label: "Completed" },
  processed: { color: "#7C3AED", label: "Processed" },
  funded_paid: { color: "#16A34A", label: "Funded" },
  declined: { color: "#DC2626", label: "Declined" },
  withdrawn: { color: "#A3A3A3", label: "Withdrawn" },
};

const FILTERS: { key: "all" | ApplicationStatus; label: string }[] = [
  { key: "all", label: "All" },
  { key: "needs_info", label: "Needs Info" },
  { key: "approved", label: "Approved" },
  { key: "completed", label: "To Complete" },
  { key: "processed", label: "Processed" },
  { key: "declined", label: "Declined" },
  { key: "funded_paid", label: "Funded" },
  { key: "withdrawn", label: "Withdrawn" },
];

export default function AdminApplicationsPage() {
  const { user } = useAuth();
  const isSuperAdmin = user?.role === "super_admin";
  const restrictions = user?.admin_permissions?.map((p) => p.permission) ?? [];
  const canReview = isSuperAdmin || restrictions.length === 0 || restrictions.includes("application_review");

  const [rows, setRows] = useState<Application[]>([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);
  const [activeFilter, setActiveFilter] = useState<(typeof FILTERS)[number]["key"]>("all");
  const [search, setSearch] = useState("");
  const [actingId, setActingId] = useState<number | null>(null);

  useEffect(() => {
    listApplications()
      .then(setRows)
      .catch((err) => setError(err instanceof ApiError ? err.message : "Could not load applications."))
      .finally(() => setLoading(false));
  }, []);

  const filtered = useMemo(() => {
    return rows.filter((row) => {
      const matchesFilter = activeFilter === "all" || row.status === activeFilter;
      const q = search.trim().toLowerCase();
      const detail = row.lease_agreement?.equipment_unit?.model ?? "";
      const matchesSearch =
        !q || (row.customer?.name ?? "").toLowerCase().includes(q) || detail.toLowerCase().includes(q);
      return matchesFilter && matchesSearch;
    });
  }, [rows, activeFilter, search]);

  async function decide(id: number, next: "approved" | "declined") {
    setActingId(id);
    try {
      const updated = await updateApplication(id, { status: next });
      setRows((prev) => prev.map((row) => (row.id === id ? updated : row)));
    } catch {
      // leave the row as-is — the admin can retry
    } finally {
      setActingId(null);
    }
  }

  return (
    <div className="space-y-6">
      <PageHeroHeader
        title="Applications"
        subtitle={`Outdoor Fix · ${rows.length} total`}
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
          placeholder="Search applications — customer name, equipment..."
          className="w-full rounded-md border border-neutral-200 bg-white py-2.5 pl-10 pr-3 text-sm text-neutral-800 placeholder:text-neutral-400 focus:border-red-300 focus:outline-none"
        />
      </div>

      <div className="flex flex-wrap gap-2">
        {FILTERS.map((f) => {
          const count = f.key === "all" ? rows.length : rows.filter((r) => r.status === f.key).length;
          return (
            <button
              key={f.key}
              onClick={() => setActiveFilter(f.key)}
              className={`font-heading rounded-full px-3.5 py-1.5 text-xs font-bold uppercase tracking-wide transition ${
                activeFilter === f.key ? "bg-red-600 text-white" : "bg-neutral-100 text-neutral-600 hover:bg-neutral-200"
              }`}
            >
              {f.label} {count}
            </button>
          );
        })}
      </div>

      {error && <p className="text-sm text-red-600">{error}</p>}

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
              {loading ? (
                <tr>
                  <td colSpan={6} className="py-6 text-center text-sm text-neutral-400">
                    Loading…
                  </td>
                </tr>
              ) : (
                filtered.map((row) => (
                  <tr key={row.id} className="border-b border-neutral-100 last:border-0">
                    <td className="py-3">
                      <StatusTag color={STATUS_STYLE[row.status].color} label={STATUS_STYLE[row.status].label} />
                    </td>
                    <td className="py-3">
                      <p className="font-medium text-neutral-900">{row.customer?.name ?? "—"}</p>
                      <p className="text-xs text-neutral-400">{row.lease_agreement?.equipment_unit?.model ?? "—"}</p>
                    </td>
                    <td className="py-3 text-neutral-700">
                      {row.lease_agreement ? money(Number(row.lease_agreement.cash_price)) : "—"}
                    </td>
                    <td className="py-3 text-neutral-600">{row.reviewed_by?.name ?? "Outdoor Fix"}</td>
                    <td className="py-3 text-neutral-500">{new Date(row.updated_at).toLocaleDateString()}</td>
                    <td className="py-3 text-right">
                      {row.status === "submitted" && canReview ? (
                        <div className="flex justify-end gap-2">
                          <button
                            onClick={() => decide(row.id, "approved")}
                            disabled={actingId === row.id}
                            className="font-heading flex items-center gap-1 rounded-md bg-green-600 px-3 py-1.5 text-xs font-bold text-white hover:bg-green-700 disabled:opacity-60"
                          >
                            ✓ Accept
                          </button>
                          <button
                            onClick={() => decide(row.id, "declined")}
                            disabled={actingId === row.id}
                            className="font-heading flex items-center gap-1 rounded-md border border-red-200 bg-red-50 px-3 py-1.5 text-xs font-bold text-red-600 hover:bg-red-100 disabled:opacity-60"
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
                ))
              )}
              {!loading && filtered.length === 0 && (
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
