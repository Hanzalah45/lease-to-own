"use client";

import Link from "next/link";
import { useEffect, useState } from "react";
import { DataTable, type DataTableColumn } from "@/components/dashboard/DataTable";
import { MetricCard } from "@/components/dashboard/MetricCard";
import { StatusTag } from "@/components/dashboard/StatusTag";
import { money } from "@/components/applications/wizard/types";
import { listApplications } from "@/lib/applications";
import { ApiError } from "@/lib/api";
import type { Application, ApplicationStatus } from "@/types/application";

const STATUS_STYLE: Partial<Record<ApplicationStatus, { color: string; label: string }>> = {
  submitted: { color: "#171717", label: "Submitted" },
  under_review: { color: "#2563EB", label: "Under review" },
  needs_info: { color: "#D97706", label: "Needs info" },
  approved: { color: "#16A34A", label: "Approved" },
};

function pct(value: number, total: number): number {
  return total === 0 ? 0 : Math.round((value / total) * 100);
}

export function ApplicationReviewPanel() {
  const [applications, setApplications] = useState<Application[]>([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);

  useEffect(() => {
    listApplications()
      .then(setApplications)
      .catch((err) =>
        setError(
          err instanceof ApiError && err.status === 403
            ? "Your admin account does not include application review."
            : "Could not load applications.",
        ),
      )
      .finally(() => setLoading(false));
  }, []);

  if (loading) return <p className="py-6 text-sm text-neutral-500">Loading applications…</p>;
  if (error) return <p className="py-6 text-sm text-neutral-500">{error}</p>;

  const needsInfo = applications.filter((a) => a.status === "needs_info");
  const underReview = applications.filter((a) => a.status === "under_review");
  const approved = applications.filter((a) => a.status === "approved");
  const total = applications.length;

  const queue = [...needsInfo, ...underReview]
    .sort((a, b) => new Date(b.created_at).getTime() - new Date(a.created_at).getTime())
    .slice(0, 6);

  const columns: DataTableColumn<Application>[] = [
    {
      key: "status",
      header: "Status",
      render: (r) => {
        const style = STATUS_STYLE[r.status] ?? { color: "#737373", label: r.status };
        return <StatusTag color={style.color} label={style.label} />;
      },
    },
    {
      key: "customer",
      header: "Customer",
      render: (r) => (
        <>
          <p className="font-medium text-neutral-900">{r.customer?.name ?? "—"}</p>
          <p className="text-xs text-neutral-400">
            {r.lease_agreement?.equipment_unit?.model ?? "—"}
            {r.customer?.customer_profile?.city ? ` · ${r.customer.customer_profile.city}, ${r.customer.customer_profile.state ?? ""}` : ""}
          </p>
        </>
      ),
    },
    {
      key: "price",
      header: "Price",
      render: (r) => <span className="text-neutral-700">{r.lease_agreement ? money(Number(r.lease_agreement.cash_price)) : "—"}</span>,
    },
    {
      key: "submitted",
      header: "Submitted",
      render: (r) => <span className="text-neutral-500">{new Date(r.created_at).toLocaleDateString()}</span>,
    },
    {
      key: "action",
      header: "",
      render: (r) => (
        <Link href={`/admin/applications/${r.id}`} className="text-sm font-semibold text-red-600 hover:underline">
          Review →
        </Link>
      ),
    },
  ];

  return (
    <div className="space-y-6">
      <div className="grid grid-cols-1 gap-3 sm:grid-cols-3">
        <MetricCard value={needsInfo.length} label="Needs info" barColor="#D97706" barPercent={pct(needsInfo.length, total)} />
        <MetricCard value={underReview.length} label="Under review" barColor="#2563EB" barPercent={pct(underReview.length, total)} />
        <MetricCard value={approved.length} label="Approved" barColor="#16A34A" barPercent={pct(approved.length, total)} />
      </div>
      <DataTable
        title="Applications awaiting review"
        action={
          <Link href="/admin/applications" className="font-heading text-xs font-bold uppercase tracking-wide text-red-600 hover:underline">
            View all
          </Link>
        }
        columns={columns}
        rows={queue}
        emptyLabel="Nothing needs review right now."
      />
    </div>
  );
}
