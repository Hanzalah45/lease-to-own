"use client";

import Image from "next/image";
import { money } from "@/components/applications/wizard/types";
import { EPO_SCHEDULE_FULL, CUSTOMER_ADDRESS, CUSTOMER_COUNTY, CUSTOMER_NAME, CUSTOMER_OWN_RENT, type SampleLease } from "@/lib/sample-lease";

function Row({ label, value }: { label: string; value: string }) {
  return (
    <div className="flex items-center justify-between border-b border-neutral-100 py-2.5 last:border-0">
      <span className="text-sm text-neutral-500">{label}</span>
      <span className="text-sm font-bold text-neutral-900">{value}</span>
    </div>
  );
}

function SubHeader({ children }: { children: string }) {
  return (
    <div className="rounded-md bg-neutral-100 px-3 py-2 text-xs font-bold uppercase tracking-wide text-neutral-500">
      {children}
    </div>
  );
}

export function LeaseAgreementDocument({ lease }: { lease: SampleLease }) {
  return (
    <div className="space-y-6">
      <div className="rounded-xl border border-neutral-200 bg-white p-6 print:break-inside-avoid">
        <div className="mb-4 flex items-center justify-between">
          <Image
            src="/prostartLeasing.png"
            alt="Outdoor Fix"
            width={110}
            height={71}
            className="h-10 w-auto rounded bg-neutral-950 p-1.5"
          />
          <h2 className="font-heading text-base font-bold uppercase tracking-wide text-neutral-900">
            Customer information &amp; authorization
          </h2>
        </div>
        <div className="border-t-2 border-neutral-900 pt-4">
          <SubHeader>Contact information</SubHeader>
          <div className="mt-2 grid grid-cols-1 gap-x-8 sm:grid-cols-2">
            <Row label="Renter Name" value={CUSTOMER_NAME} />
            <Row label="Mailing Address" value={CUSTOMER_ADDRESS} />
            <Row label="Own / Rent" value={CUSTOMER_OWN_RENT} />
            <Row label="County" value={CUSTOMER_COUNTY} />
          </div>
        </div>
      </div>

      <div className="rounded-xl border border-neutral-200 bg-white p-6 print:break-inside-avoid">
        <h2 className="font-heading mb-2 border-b-2 border-neutral-900 pb-3 text-base font-bold uppercase tracking-wide text-neutral-900">
          Lease information summary
        </h2>

        <div className="mt-4">
          <SubHeader>Description of leased property</SubHeader>
          <div className="mt-2 grid grid-cols-1 gap-x-8 sm:grid-cols-2">
            <Row label="Cash Price / Retail" value={money(lease.cashPrice)} />
            <Row label="Make" value={lease.equipment} />
            <Row label="Condition" value={lease.condition} />
            <Row label="Serial # / VIN" value={lease.serial} />
          </div>
        </div>

        <div className="mt-5">
          <SubHeader>Lease details</SubHeader>
          <div className="mt-2 grid grid-cols-1 gap-x-8 sm:grid-cols-2">
            <Row label="Months to Ownership" value={String(lease.term)} />
            <Row label="Payment Due Day" value="15th" />
            <Row label="Rental Payment" value={money(lease.monthlyRental)} />
            <Row label="Sales Tax" value={money(lease.salesTax)} />
            <Row label="Total Monthly Payment" value={money(lease.totalMonthly)} />
            <Row label="Security Deposit" value={money(lease.securityDeposit)} />
            <Row label="TOTAL DUE" value={money(lease.totalDue)} />
            <Row label="AutoPay" value={lease.autopay ? "Yes · Checking" : "No"} />
          </div>
        </div>

        <p className="mt-5 text-xs italic leading-relaxed text-neutral-400">
          This transaction is a lease/rental-purchase agreement. You may cancel the lease without penalty after the
          first payment. Individual lease term is 1 month. You will not own the property until the total amount
          necessary to acquire ownership is paid in full or you exercise the early purchase option in accordance
          with the lease.
        </p>
      </div>

      <div className="rounded-xl border border-neutral-200 bg-white p-6 print:break-inside-avoid">
        <h2 className="font-heading mb-2 border-b-2 border-neutral-900 pb-3 text-base font-bold uppercase tracking-wide text-neutral-900">
          Product info &amp; early purchase option
        </h2>

        <div className="mt-4 grid grid-cols-1 gap-x-8 sm:grid-cols-2">
          <Row label="State" value="TX" />
          <Row label="Dealer" value="Outdoor Fix" />
          <Row label="Cash Price" value={money(lease.cashPrice)} />
          <Row label="Product Type" value="Mower" />
          <Row label="Tax Rate" value="8.25%" />
          <Row label="LDW" value={lease.ldw ? "Yes" : "No"} />
          <Row label="Total Paying Today" value="$0.00" />
          <Row label="Promo" value={lease.promo} />
        </div>
        <p className="mt-3 text-xs italic text-neutral-400">
          *Additional funds paid over required deposit, first payment and other fees will reduce monthly payment.
        </p>
      </div>

      <div className="rounded-xl border border-neutral-200 bg-white p-6 print:break-inside-avoid">
        <SubHeader>Early purchase option chart</SubHeader>
        <p className="mt-2 text-xs text-neutral-400">
          EPO price after each rental renewal payment, assuming on-time payments. Excludes tax.
        </p>
        <div className="mt-3 grid grid-cols-2 gap-x-6 gap-y-1 text-sm sm:grid-cols-3 lg:grid-cols-6 print:grid-cols-6">
          {EPO_SCHEDULE_FULL.slice(0, lease.term).map((row) => (
            <div key={row.month} className="flex items-center justify-between border-b border-neutral-100 py-1.5">
              <span className="text-neutral-400">{row.month}</span>
              <span className="font-semibold text-neutral-800">{row.value.toFixed(2)}</span>
            </div>
          ))}
        </div>
      </div>

      <div className="rounded-xl border border-neutral-200 bg-white p-6 print:break-inside-avoid">
        <div className="grid grid-cols-1 gap-4 sm:grid-cols-3">
          <div>
            <p className="text-xs font-bold uppercase tracking-wide text-neutral-400">Signed by</p>
            <p className="mt-1 text-sm font-semibold text-neutral-900">{lease.signed ? CUSTOMER_NAME : "—"}</p>
          </div>
          <div>
            <p className="text-xs font-bold uppercase tracking-wide text-neutral-400">Timestamp</p>
            <p className="mt-1 text-sm font-semibold text-neutral-900">{lease.signed ? lease.signedAt : "—"}</p>
          </div>
          <div>
            <p className="text-xs font-bold uppercase tracking-wide text-neutral-400">Status</p>
            <p className={`mt-1 text-sm font-semibold ${lease.signed ? "text-green-600" : "text-amber-600"}`}>
              {lease.signed ? "Signed & legally valid" : "Awaiting signature"}
            </p>
          </div>
        </div>
      </div>
    </div>
  );
}
