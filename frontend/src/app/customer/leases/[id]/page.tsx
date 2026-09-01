"use client";

import Link from "next/link";
import { useParams } from "next/navigation";
import { useEffect, useState } from "react";
import { useAuth } from "@/context/AuthContext";
import { money } from "@/components/applications/wizard/types";
import { getMyLeaseAgreement } from "@/lib/lease-agreements";
import { ApiError } from "@/lib/api";
import type { LeaseAgreement } from "@/types/lease-agreement";

function num(value: string | number | null | undefined): number {
  const n = Number(value ?? 0);
  return Number.isFinite(n) ? n : 0;
}

export default function CustomerLeaseDetailPage() {
  const params = useParams<{ id: string }>();
  const { user } = useAuth();
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

  const totalRentalPrice = num(lease.total_rental_purchase_price);
  const paidToDate = num(lease.rental_payments_paid_to_date);
  const progress = lease.term_months ? Math.round((lease.payments_made / lease.term_months) * 100) : 0;
  const paymentsRemaining = lease.term_months - lease.payments_made;

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
              {user?.name ?? "—"}
            </h1>
            <p className="text-sm text-neutral-400">
              {lease.equipment_unit?.model ?? "Equipment"} · {lease.term_months}-month lease ·{" "}
              <span className="font-semibold text-green-600">{lease.ownership_status === "owned" ? "Paid Off" : "Active"}</span>
            </p>
          </div>
          <Link
            href="/customer/dashboard"
            className="font-heading self-start whitespace-nowrap rounded-md border border-red-600 bg-white px-4 py-2 text-sm font-bold text-red-600 hover:bg-red-50"
          >
            ← Back to Dashboard
          </Link>
        </div>
      </div>

      <div className="rounded-xl border border-neutral-200 bg-neutral-100 px-5 py-3.5 text-sm text-neutral-700">
        <span className="font-bold">Renewal due day: {lease.payment_due_day ?? "—"}</span> · Individual lease term is 1
        month. Keeping the equipment past your due date without notice automatically renews the lease for another
        month at the same terms.
      </div>

      <div className="grid grid-cols-1 gap-6 lg:grid-cols-2">
        <div className="rounded-xl border-l-4 border-green-500 bg-green-50 p-5">
          <div className="mb-2 flex items-center gap-2">
            <span className="h-4 w-1 shrink-0 rounded-full bg-green-500" />
            <h2 className="font-heading text-base font-bold uppercase tracking-wide text-neutral-900">
              Path 1 · Full-term ownership
            </h2>
          </div>
          <p className="mb-4 text-sm text-neutral-600">Keep paying monthly — you own it once the full rental price is paid.</p>

          <div className="mb-1 flex items-center justify-between text-xs font-bold uppercase tracking-wide text-neutral-500">
            <span>Progress</span>
            <span>{progress}%</span>
          </div>
          <div className="mb-4 h-1.5 w-full overflow-hidden rounded-full bg-white">
            <div className="h-full rounded-full bg-green-500" style={{ width: `${progress}%` }} />
          </div>

          <div className="space-y-2 text-sm">
            <div className="flex items-center justify-between">
              <span className="text-neutral-500">Total rental purchase price</span>
              <span className="font-semibold text-neutral-900">{money(totalRentalPrice)}</span>
            </div>
            <div className="flex items-center justify-between">
              <span className="text-neutral-500">Paid to date</span>
              <span className="font-semibold text-green-600">{money(paidToDate)}</span>
            </div>
            <div className="flex items-center justify-between">
              <span className="text-neutral-500">Payments made</span>
              <span className="font-semibold text-neutral-900">
                {lease.payments_made} of {lease.term_months}
              </span>
            </div>
            <div className="flex items-center justify-between">
              <span className="text-neutral-500">Payments remaining</span>
              <span className="font-semibold text-neutral-900">{paymentsRemaining}</span>
            </div>
          </div>
        </div>

        <div className="rounded-xl border-l-4 border-red-500 bg-red-50 p-5">
          <div className="mb-2 flex items-center gap-2">
            <span className="h-4 w-1 shrink-0 rounded-full bg-red-500" />
            <h2 className="font-heading text-base font-bold uppercase tracking-wide text-neutral-900">
              Path 2 · Early purchase option
            </h2>
          </div>
          <p className="mb-4 text-sm text-neutral-600">Buy out the equipment today instead of finishing the full term.</p>

          <p className="font-heading text-4xl font-black text-red-600">{money(lease.epo_today)}</p>
          <p className="mb-4 text-sm text-neutral-500">Excludes tax — recalculates live as you pay</p>

          <div className="mb-4 flex items-center justify-between text-sm">
            <span className="text-neutral-500">Pricing rule applied</span>
            <span className="font-semibold text-neutral-900">
              {lease.payments_made <= 3 ? "Within 90 days" : "After 90 days"}
            </span>
          </div>

          <Link
            href={`/customer/leases/${lease.id}/epo`}
            className="font-heading block rounded-md bg-red-600 py-2.5 text-center text-sm font-bold text-white hover:bg-red-700"
          >
            View full payoff schedule →
          </Link>
        </div>
      </div>

      <div className="grid grid-cols-1 gap-6 lg:grid-cols-2">
        <div className="rounded-xl border border-neutral-200 bg-white p-5">
          <div className="mb-4 flex items-center gap-2">
            <span className="h-4 w-1 shrink-0 rounded-full bg-red-600" />
            <h2 className="font-heading text-base font-bold uppercase tracking-wide text-neutral-900">
              Monthly payment breakdown
            </h2>
          </div>
          <div className="space-y-2.5 text-sm">
            <div className="flex items-center justify-between">
              <span className="text-neutral-500">Rental payment</span>
              <span className="font-semibold text-neutral-900">{money(num(lease.monthly_rental_payment))}</span>
            </div>
            <div className="flex items-center justify-between">
              <span className="text-neutral-500">Sales tax</span>
              <span className="font-semibold text-neutral-900">{money(lease.sales_tax_amount)}</span>
            </div>
            <div className="flex items-center justify-between">
              <span className="text-neutral-500">LDW (Loss Damage Waiver)</span>
              <span className="font-semibold text-green-600">{lease.ldw_selected ? "Included · Active" : "Not enrolled"}</span>
            </div>
            <div className="flex items-center justify-between">
              <span className="text-neutral-500">Promo applied</span>
              <span className="font-semibold text-neutral-900">{lease.promo_code ?? "—"}</span>
            </div>
            <div className="flex items-center justify-between">
              <span className="text-neutral-500">AutoPay</span>
              <span className="font-semibold text-neutral-900">{lease.autopay_enabled ? "Yes" : "No"}</span>
            </div>
          </div>
          <div className="mt-4 flex items-center justify-between rounded-md bg-red-50 px-4 py-3">
            <span className="text-sm font-bold text-red-700">Total monthly</span>
            <span className="font-heading text-lg font-black text-red-600">{money(lease.total_monthly_payment)}</span>
          </div>
        </div>

        <div className="rounded-xl border border-neutral-200 bg-white p-5">
          <div className="mb-4 flex items-center gap-2">
            <span className="h-4 w-1 shrink-0 rounded-full bg-red-600" />
            <h2 className="font-heading text-base font-bold uppercase tracking-wide text-neutral-900">
              Initial payment &amp; equipment
            </h2>
          </div>
          <div className="space-y-2.5 text-sm">
            <div className="flex items-center justify-between">
              <span className="text-neutral-500">Security deposit paid</span>
              <span className="font-semibold text-neutral-900">{money(num(lease.security_deposit))}</span>
            </div>
            <div className="flex items-center justify-between">
              <span className="text-neutral-500">Total initial payment</span>
              <span className="font-semibold text-neutral-900">{money(lease.total_monthly_payment + num(lease.security_deposit))}</span>
            </div>
            <div className="flex items-center justify-between">
              <span className="text-neutral-500">Make / model</span>
              <span className="font-semibold text-neutral-900">{lease.equipment_unit?.model ?? "—"}</span>
            </div>
            <div className="flex items-center justify-between">
              <span className="text-neutral-500">Serial #</span>
              <span className="font-semibold text-neutral-900">{lease.equipment_unit?.serial_number ?? "—"}</span>
            </div>
            <div className="flex items-center justify-between">
              <span className="text-neutral-500">Delivered</span>
              <span className="font-semibold text-neutral-900">{lease.equipment_unit?.delivery_date ?? "—"}</span>
            </div>
            <div className="flex items-center justify-between">
              <span className="text-neutral-500">Cash price</span>
              <span className="font-semibold text-neutral-900">{money(num(lease.cash_price))}</span>
            </div>
          </div>
        </div>
      </div>
    </div>
  );
}
