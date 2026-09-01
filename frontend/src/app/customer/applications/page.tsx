"use client";

import Link from "next/link";
import { useEffect, useState } from "react";
import { PageHeroHeader } from "@/components/layout/PageHeroHeader";
import { StatusTag } from "@/components/dashboard/StatusTag";
import { PlusIcon } from "@/components/icons";
import { listMyApplications } from "@/lib/applications";
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

export default function CustomerApplicationsPage() {
  const [applications, setApplications] = useState<Application[]>([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);

  useEffect(() => {
    listMyApplications()
      .then(setApplications)
      .catch((err) => setError(err instanceof ApiError ? err.message : "Could not load your applications."))
      .finally(() => setLoading(false));
  }, []);

  return (
    <div className="space-y-6">
      <PageHeroHeader
        title="My Applications"
        subtitle="Every application you've submitted, and its current status."
        action={
          <Link
            href="/customer/applications/new"
            className="font-heading flex items-center gap-1.5 self-start rounded-md bg-red-600 px-4 py-2 text-sm font-bold text-white hover:bg-red-700"
          >
            <PlusIcon className="h-4 w-4" />
            New Application
          </Link>
        }
      />

      {error && <p className="text-sm text-red-600">{error}</p>}

      <div className="overflow-hidden rounded-xl border border-neutral-200 bg-white p-4 sm:p-5">
        <div className="-mx-4 overflow-x-auto px-4 sm:mx-0 sm:px-0">
          <table className="w-full min-w-[560px] text-left text-sm">
            <thead>
              <tr className="font-heading border-b border-neutral-200 text-xs font-bold uppercase tracking-wide text-neutral-400">
                <th className="pb-2 font-medium">Status</th>
                <th className="pb-2 font-medium">Equipment</th>
                <th className="pb-2 font-medium">Price</th>
                <th className="pb-2 font-medium">Updated</th>
                <th className="pb-2 font-medium"></th>
              </tr>
            </thead>
            <tbody>
              {loading ? (
                <tr>
                  <td colSpan={5} className="py-6 text-center text-sm text-neutral-400">Loading…</td>
                </tr>
              ) : applications.length === 0 ? (
                <tr>
                  <td colSpan={5} className="py-8 text-center text-sm text-neutral-400">
                    No applications yet — click &ldquo;New Application&rdquo; to get started.
                  </td>
                </tr>
              ) : (
                applications.map((app) => (
                  <tr key={app.id} className="border-b border-neutral-100 last:border-0">
                    <td className="py-3">
                      <StatusTag color={STATUS_STYLE[app.status].color} label={STATUS_STYLE[app.status].label} />
                    </td>
                    <td className="py-3 text-neutral-700">{app.lease_agreement?.equipment_unit?.model ?? "—"}</td>
                    <td className="py-3 text-neutral-700">
                      {app.lease_agreement ? money(Number(app.lease_agreement.cash_price)) : "—"}
                    </td>
                    <td className="py-3 text-neutral-500">{new Date(app.updated_at).toLocaleDateString()}</td>
                    <td className="py-3 text-right">
                      <Link href={`/customer/applications/${app.id}`} className="font-heading text-xs font-bold text-red-600 hover:underline">
                        View →
                      </Link>
                    </td>
                  </tr>
                ))
              )}
            </tbody>
          </table>
        </div>
      </div>
    </div>
  );
}
