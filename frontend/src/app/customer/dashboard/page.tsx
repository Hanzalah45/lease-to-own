"use client";

import Link from "next/link";
import { useAuth } from "@/context/AuthContext";
import { PlusIcon } from "@/components/icons";
import { money } from "@/components/applications/wizard/types";
import { SAMPLE_LEASES, type LeaseStatus } from "@/lib/sample-lease";

const STATUS_LABEL: Record<LeaseStatus, { label: string; color: string }> = {
  active: { label: "Active", color: "text-green-600" },
  paid_off: { label: "Paid Off", color: "text-blue-600" },
  needs_info: { label: "Needs Info", color: "text-amber-600" },
};

export default function CustomerDashboardPage() {
  const { user } = useAuth();

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
            <p className="font-heading text-sm font-bold uppercase tracking-wide text-red-600">Welcome back</p>
            <h1 className="mt-1 text-3xl font-black uppercase tracking-tight text-neutral-900 sm:text-4xl">
              {user?.name ?? "Robert Kirkland"}
            </h1>
            <p className="text-sm text-neutral-400">You have {SAMPLE_LEASES.length} leases with Outdoor Fix.</p>
          </div>
          <Link
            href="/admin/applications/new"
            className="font-heading flex items-center gap-1.5 self-start rounded-md bg-red-600 px-4 py-2 text-sm font-bold text-white hover:bg-red-700"
          >
            <PlusIcon className="h-4 w-4" />
            New Application
          </Link>
        </div>
      </div>

      <div className="grid grid-cols-1 gap-6 sm:grid-cols-2 lg:grid-cols-3">
        {SAMPLE_LEASES.map((lease) => {
          const status = STATUS_LABEL[lease.status];
          return (
            <div key={lease.id} className="rounded-xl border border-neutral-200 bg-white p-5">
              <div className="mb-4 flex items-center gap-2">
                <span className="h-4 w-1 shrink-0 rounded-full bg-red-600" />
                <h2 className="font-heading text-base font-bold uppercase tracking-wide text-neutral-900">
                  {lease.equipment}
                </h2>
              </div>
              <p className={`mb-4 text-xs font-bold uppercase tracking-wide ${status.color}`}>{status.label}</p>

              <div className="space-y-2.5 text-sm">
                <div className="flex items-center justify-between">
                  <span className="text-neutral-500">Term</span>
                  <span className="font-semibold text-neutral-900">{lease.term} months</span>
                </div>
                <div className="flex items-center justify-between">
                  <span className="text-neutral-500">Monthly</span>
                  <span className="font-semibold text-neutral-900">{money(lease.totalMonthly)}</span>
                </div>
                <div className="flex items-center justify-between">
                  <span className="text-neutral-500">Payments made</span>
                  <span className="font-semibold text-neutral-900">
                    {lease.paymentsMade} of {lease.term}
                  </span>
                </div>
              </div>

              <div className="mt-3 h-1 w-full overflow-hidden rounded-full bg-neutral-100">
                <div
                  className="h-full rounded-full bg-neutral-900"
                  style={{ width: `${Math.round((lease.paymentsMade / lease.term) * 100)}%` }}
                />
              </div>

              <Link
                href={`/customer/leases/${lease.id}`}
                className="font-heading mt-4 block rounded-md bg-red-600 py-2.5 text-center text-sm font-bold text-white hover:bg-red-700"
              >
                View Details →
              </Link>
            </div>
          );
        })}
      </div>
    </div>
  );
}
