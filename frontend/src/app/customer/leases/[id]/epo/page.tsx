"use client";

import Link from "next/link";
import { useParams } from "next/navigation";
import { useEffect, useState } from "react";
import { money } from "@/components/applications/wizard/types";
import { EpoChart } from "@/components/applications/wizard/EpoChart";
import { getMyLeaseAgreement } from "@/lib/lease-agreements";
import { ApiError } from "@/lib/api";
import type { LeaseAgreement } from "@/types/lease-agreement";

export default function CustomerEpoSchedulePage() {
  const params = useParams<{ id: string }>();
  const [lease, setLease] = useState<LeaseAgreement | null>(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);

  useEffect(() => {
    getMyLeaseAgreement(params.id)
      .then(setLease)
      .catch((err) => setError(err instanceof ApiError ? err.message : "Could not load this lease."))
      .finally(() => setLoading(false));
  }, [params.id]);

  if (loading) return <p className="py-12 text-center text-sm text-neutral-400">Loading…</p>;
  if (error || !lease) return <p className="py-12 text-center text-sm text-red-600">{error ?? "Lease not found."}</p>;

  const paymentsRemaining = lease.term_months - lease.payments_made;
  const schedule = (lease.epo_schedule ?? []).filter((p) => p.month === 1 || p.month % 3 === 0);

  return (
    <div className="space-y-6">
      <div
        className="-mx-4 -mt-4 px-4 pb-6 pt-4 sm:-mx-8 sm:-mt-6 sm:px-8 sm:pt-6 lg:-mx-16 lg:px-16"
        style={{
          background:
            "linear-gradient(to left, rgba(220,38,38,0.24) 0%, rgba(220,38,38,0.10) 35%, transparent 65%)",
        }}
      >
        <div className="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
          <div>
            <h1 className="text-3xl font-black uppercase tracking-tight text-neutral-900 sm:text-4xl">
              Early Purchase Option Schedule
            </h1>
            <p className="text-sm text-neutral-400">Buying out your lease early instead of finishing all your monthly payments.</p>
          </div>
          <Link
            href={`/customer/leases/${lease.id}`}
            className="font-heading self-start whitespace-nowrap rounded-md border border-red-600 bg-white px-4 py-2 text-sm font-bold text-red-600 hover:bg-red-50"
          >
            ← Back to Dashboard
          </Link>
        </div>
      </div>

      <div className="grid grid-cols-1 gap-6 sm:grid-cols-3">
        <div className="rounded-xl border border-neutral-200 bg-white px-5 py-4">
          <p className="font-heading text-3xl font-black text-red-600">{money(lease.epo_today)}</p>
          <p className="mt-1 text-xs font-semibold uppercase tracking-wide text-neutral-500">Buyout price today</p>
          <div className="mt-3 h-1 w-full overflow-hidden rounded-full bg-neutral-100">
            <div className="h-full rounded-full bg-red-600" style={{ width: "70%" }} />
          </div>
        </div>
        <div className="rounded-xl border border-neutral-200 bg-white px-5 py-4">
          <p className="font-heading text-3xl font-black text-neutral-900">
            {lease.payments_made} of {lease.term_months}
          </p>
          <p className="mt-1 text-xs font-semibold uppercase tracking-wide text-neutral-500">Payments made</p>
          <div className="mt-3 h-1 w-full overflow-hidden rounded-full bg-neutral-100">
            <div
              className="h-full rounded-full bg-neutral-900"
              style={{ width: `${lease.term_months ? Math.round((lease.payments_made / lease.term_months) * 100) : 0}%` }}
            />
          </div>
        </div>
        <div className="rounded-xl border border-neutral-200 bg-white px-5 py-4">
          <p className="font-heading text-3xl font-black text-green-600">{paymentsRemaining}</p>
          <p className="mt-1 text-xs font-semibold uppercase tracking-wide text-neutral-500">Payments remaining</p>
          <div className="mt-3 h-1 w-full overflow-hidden rounded-full bg-neutral-100">
            <div
              className="h-full rounded-full bg-green-600"
              style={{ width: `${lease.term_months ? Math.round((paymentsRemaining / lease.term_months) * 100) : 0}%` }}
            />
          </div>
        </div>
      </div>

      <div className="rounded-xl border border-neutral-200 bg-white p-5">
        <div className="mb-2 flex items-center gap-2">
          <span className="h-4 w-1 shrink-0 rounded-full bg-red-600" />
          <h2 className="font-heading text-base font-bold uppercase tracking-wide text-neutral-900">Payoff schedule</h2>
        </div>
        <p className="mb-6 text-xs text-neutral-400">Buyout price by month, updated live as payments post. Excludes tax.</p>
        <EpoChart schedule={schedule} />
      </div>
    </div>
  );
}
