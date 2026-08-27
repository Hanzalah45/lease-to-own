"use client";

import Link from "next/link";
import { useParams } from "next/navigation";
import { money } from "@/components/applications/wizard/types";
import { epoAt, getLease, SAMPLE_LEASES } from "@/lib/sample-lease";

export default function CustomerLeaseDetailPage() {
  const params = useParams<{ id: string }>();
  const lease = getLease(Number(params.id)) ?? SAMPLE_LEASES[0];

  const totalRentalPrice = lease.monthlyRental * lease.term;
  const paidToDate = lease.monthlyRental * lease.paymentsMade;
  const progress = Math.round((lease.paymentsMade / lease.term) * 100);
  const paymentsRemaining = lease.term - lease.paymentsMade;
  const buyoutToday = epoAt(Math.max(1, lease.paymentsMade));

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
              Robert Kirkland
            </h1>
            <p className="text-sm text-neutral-400">
              {lease.equipment} · {lease.term}-month lease ·{" "}
              <span className="font-semibold text-green-600">
                {lease.status === "active" ? "Active" : lease.status === "paid_off" ? "Paid Off" : "Needs Info"}
              </span>
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
        <span className="font-bold">Renewal due day: 15th</span> · Individual lease term is 1 month. Keeping the
        equipment past your due date without notice automatically renews the lease for another month at the same
        terms.
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
                {lease.paymentsMade} of {lease.term}
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

          <p className="font-heading text-4xl font-black text-red-600">{money(buyoutToday)}</p>
          <p className="mb-4 text-sm text-neutral-500">Excludes tax — recalculates live as you pay</p>

          <div className="mb-4 flex items-center justify-between text-sm">
            <span className="text-neutral-500">Pricing rule applied</span>
            <span className="font-semibold text-neutral-900">After 90 days</span>
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
              <span className="font-semibold text-neutral-900">{money(lease.monthlyRental)}</span>
            </div>
            <div className="flex items-center justify-between">
              <span className="text-neutral-500">Sales tax</span>
              <span className="font-semibold text-neutral-900">{money(lease.salesTax)}</span>
            </div>
            <div className="flex items-center justify-between">
              <span className="text-neutral-500">LDW (Loss Damage Waiver)</span>
              <span className="font-semibold text-green-600">{lease.ldw ? "Included · Active" : "Not enrolled"}</span>
            </div>
            <div className="flex items-center justify-between">
              <span className="text-neutral-500">Promo applied</span>
              <span className="font-semibold text-neutral-900">{lease.promo}</span>
            </div>
            <div className="flex items-center justify-between">
              <span className="text-neutral-500">AutoPay</span>
              <span className="font-semibold text-neutral-900">{lease.autopay ? "Yes · Checking" : "No"}</span>
            </div>
          </div>
          <div className="mt-4 flex items-center justify-between rounded-md bg-red-50 px-4 py-3">
            <span className="text-sm font-bold text-red-700">Total monthly</span>
            <span className="font-heading text-lg font-black text-red-600">{money(lease.totalMonthly)}</span>
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
              <span className="font-semibold text-neutral-900">{money(lease.securityDeposit)}</span>
            </div>
            <div className="flex items-center justify-between">
              <span className="text-neutral-500">Total initial payment</span>
              <span className="font-semibold text-neutral-900">{money(lease.totalDue)}</span>
            </div>
            <div className="flex items-center justify-between">
              <span className="text-neutral-500">Make / model</span>
              <span className="font-semibold text-neutral-900">{lease.equipment}</span>
            </div>
            <div className="flex items-center justify-between">
              <span className="text-neutral-500">Serial #</span>
              <span className="font-semibold text-neutral-900">{lease.serial}</span>
            </div>
            <div className="flex items-center justify-between">
              <span className="text-neutral-500">Delivered</span>
              <span className="font-semibold text-neutral-900">{lease.delivered}</span>
            </div>
            <div className="flex items-center justify-between">
              <span className="text-neutral-500">Cash price</span>
              <span className="font-semibold text-neutral-900">{money(lease.cashPrice)}</span>
            </div>
          </div>
        </div>
      </div>
    </div>
  );
}
