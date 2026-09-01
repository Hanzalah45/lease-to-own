"use client";

import Link from "next/link";
import { useParams } from "next/navigation";
import { useEffect, useState } from "react";
import { StatusPipeline } from "@/components/applications/detail/StatusPipeline";
import { DetailCard } from "@/components/applications/detail/DetailCard";
import { getMyApplication } from "@/lib/applications";
import { money } from "@/components/applications/wizard/types";
import { ApiError } from "@/lib/api";
import type { Application } from "@/types/application";
import type { AppStatus } from "@/components/applications/detail/types";

const BADGE_STYLE: Record<AppStatus, { label: string; color: string }> = {
  submitted: { label: "Submitted", color: "bg-neutral-700 text-white" },
  under_review: { label: "Under Review", color: "bg-amber-500 text-white" },
  needs_info: { label: "Needs Info", color: "bg-amber-500 text-white" },
  approved: { label: "Approved", color: "bg-blue-600 text-white" },
  completed: { label: "Completed", color: "bg-teal-500 text-white" },
  processed: { label: "Processed", color: "bg-purple-600 text-white" },
  funded_paid: { label: "Funded", color: "bg-green-600 text-white" },
  declined: { label: "Application Declined", color: "bg-neutral-100 text-red-700 border border-red-200" },
  withdrawn: { label: "Withdrawn", color: "bg-neutral-200 text-neutral-700" },
};

function num(value: string | number | null | undefined): number {
  const n = Number(value ?? 0);
  return Number.isFinite(n) ? n : 0;
}

export default function CustomerApplicationDetailPage() {
  const params = useParams<{ id: string }>();
  const [application, setApplication] = useState<Application | null>(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);

  useEffect(() => {
    getMyApplication(params.id)
      .then(setApplication)
      .catch((err) => setError(err instanceof ApiError ? err.message : "Could not load this application."))
      .finally(() => setLoading(false));
  }, [params.id]);

  if (loading) return <p className="py-12 text-center text-sm text-neutral-400">Loading…</p>;
  if (error || !application) return <p className="py-12 text-center text-sm text-red-600">{error ?? "Application not found."}</p>;

  const lease = application.lease_agreement;
  const equipment = lease?.equipment_unit;
  const badge = BADGE_STYLE[application.status];

  return (
    <div className="space-y-6">
      <div>
        <Link href="/customer/applications" className="font-heading text-xs font-bold uppercase tracking-wide text-red-600 hover:underline">
          ← My Applications
        </Link>
        <div className="mt-1 flex items-center gap-3">
          <h1 className="text-3xl font-black uppercase tracking-tight text-neutral-900 sm:text-4xl">Application #{application.id}</h1>
          <span className={`font-heading rounded-full px-3 py-1 text-xs font-bold uppercase tracking-wide ${badge.color}`}>{badge.label}</span>
        </div>
        <p className="text-sm text-neutral-400">Submitted {new Date(application.created_at).toLocaleDateString()}</p>
      </div>

      {application.status !== "declined" && application.status !== "withdrawn" && <StatusPipeline status={application.status} />}

      {application.status === "declined" && (
        <div className="rounded-xl border border-red-200 bg-red-50 px-5 py-4">
          <p className="text-sm font-bold text-red-700">Application Declined</p>
          <p className="text-xs text-neutral-500">{application.status_notes ?? "Contact Outdoor Fix for details."}</p>
        </div>
      )}

      {lease && (
        <div className="grid grid-cols-1 gap-6 sm:grid-cols-2">
          <DetailCard
            title="Equipment"
            rows={[
              { label: "Make / model", value: equipment?.model ?? "—" },
              { label: "Cash price", value: money(num(lease.cash_price)) },
              { label: "Condition", value: equipment?.condition_notes ?? "—" },
            ]}
          />
          <DetailCard
            title="Lease terms"
            rows={[
              { label: "Term", value: `${lease.term_months} months` },
              { label: "Monthly rental", value: money(num(lease.monthly_rental_payment)) },
              { label: "Total monthly", value: money(lease.total_monthly_payment) },
              { label: "Security deposit", value: money(num(lease.security_deposit)) },
            ]}
          />
        </div>
      )}

      <p className="text-sm text-neutral-400">
        An Outdoor Fix representative will reach out as your application moves through review. Once it&rsquo;s funded, it will appear on your{" "}
        <Link href="/customer/dashboard" className="font-semibold text-red-600 hover:underline">My Lease</Link> page.
      </p>
    </div>
  );
}
