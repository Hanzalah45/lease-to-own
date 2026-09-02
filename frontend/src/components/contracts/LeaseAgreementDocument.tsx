"use client";

import Image from "next/image";
import { money } from "@/components/applications/wizard/types";
import type { LeaseAgreement } from "@/types/lease-agreement";

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

function num(value: string | number | null | undefined): number {
  const n = Number(value ?? 0);
  return Number.isFinite(n) ? n : 0;
}

export function LeaseAgreementDocument({
  lease,
  customerName,
  customerAddress,
}: {
  lease: LeaseAgreement;
  customerName: string;
  customerAddress: string;
}) {
  const equipment = lease.equipment_unit;
  const cashPrice = num(lease.cash_price);
  const monthlyRental = num(lease.monthly_rental_payment);
  const salesTax = num(lease.sales_tax_amount);
  const totalMonthly = num(lease.total_monthly_payment);
  const securityDeposit = num(lease.security_deposit);
  const totalDueToday = securityDeposit + totalMonthly;
  const taxRatePct = (num(lease.sales_tax_rate) * 100).toFixed(2);
  const signed = !!lease.contract;

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
            <Row label="Renter Name" value={customerName} />
            <Row label="Mailing Address" value={customerAddress || "—"} />
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
            <Row label="Cash Price / Retail" value={money(cashPrice)} />
            <Row label="Make" value={equipment?.model ?? "—"} />
            <Row label="Serial # / VIN" value={equipment?.serial_number ?? "—"} />
            <Row label="Description or Damage to Property" value={equipment?.condition_notes || "None noted"} />
          </div>
        </div>

        <div className="mt-5">
          <SubHeader>Lease details</SubHeader>
          <div className="mt-2 grid grid-cols-1 gap-x-8 sm:grid-cols-2">
            <Row label="Months to Ownership" value={String(lease.term_months)} />
            <Row label="Payment Due Day" value={lease.payment_due_day ?? "—"} />
            <Row label="Rental Payment" value={money(monthlyRental)} />
            <Row label="Sales Tax" value={money(salesTax)} />
            <Row label="Total Monthly Payment" value={money(totalMonthly)} />
            <Row label="Security Deposit" value={money(securityDeposit)} />
            <Row label="TOTAL DUE TODAY" value={money(totalDueToday)} />
            <Row label="AutoPay" value={lease.autopay_enabled ? "Yes" : "No"} />
            <Row label="Total Rental-Purchase Price" value={money(num(lease.total_rental_purchase_price))} />
          </div>
        </div>

        <p className="mt-5 text-xs italic leading-relaxed text-neutral-400">
          <strong>2. Lease Term &amp; Payment Schedule.</strong> This Agreement is for one month. It begins on the
          effective date of this Agreement and expires one month later. You can renew the Agreement for additional
          one-month terms at your option by making a monthly rental renewal payment on or before the expiration
          date. The Agreement will also renew if you continue to possess the Property until you notify us that you
          want to end the rental and make the Property available for pickup.
        </p>
        <p className="mt-3 text-xs italic leading-relaxed text-neutral-400">
          <strong>3. Rental-Purchase Ownership.</strong> If you renew this Agreement for {lease.term_months} months
          in a row, you will have paid the Total Rental-Purchase Price of {money(num(lease.total_rental_purchase_price))},
          not including taxes or fees, and you will obtain ownership of the Property after the final payment. Or, you
          can exercise an early purchase option (&quot;EPO&quot;). Any time within 90 days of the effective date of
          this Agreement, your EPO price will be the Cash Price less all Rental Payments paid to date (excludes
          taxes and fees). After that time, your EPO price will be the Cash Price less 50% of Rental Payments
          scheduled to date, plus any Rental Payments still owed and any additional funds. You will not own the
          Property unless you pay the Total Rental-Purchase Price or exercise an EPO. The Total Rental-Purchase
          Price does not include other charges such as late fees, disclosed below. Taxes are also due at the time
          of exercising an EPO.
        </p>
      </div>

      <div className="rounded-xl border border-neutral-200 bg-white p-6 print:break-inside-avoid">
        <h2 className="font-heading mb-2 border-b-2 border-neutral-900 pb-3 text-base font-bold uppercase tracking-wide text-neutral-900">
          Product info &amp; early purchase option
        </h2>

        <div className="mt-4 grid grid-cols-1 gap-x-8 sm:grid-cols-2">
          <Row label="Dealer" value="Outdoor Fix" />
          <Row label="Cash Price" value={money(cashPrice)} />
          <Row label="Tax Rate" value={`${taxRatePct}%`} />
          <Row label="LDW" value={lease.ldw_selected ? "Yes" : "No"} />
          <Row label="Total Paying Today" value={money(totalDueToday)} />
          <Row label="Promo" value={lease.promo_code ?? "—"} />
        </div>
        <p className="mt-3 text-xs italic text-neutral-400">
          *Additional funds paid over required deposit, first payment and other fees will reduce monthly payment.
        </p>
      </div>

      {lease.epo_schedule && (
        <div className="rounded-xl border border-neutral-200 bg-white p-6 print:break-inside-avoid">
          <SubHeader>Early purchase option chart</SubHeader>
          <p className="mt-2 text-xs text-neutral-400">
            EPO price after each rental renewal payment, assuming on-time payments. Excludes tax.
          </p>
          <div className="mt-3 grid grid-cols-2 gap-x-6 gap-y-1 text-sm sm:grid-cols-3 lg:grid-cols-6 print:grid-cols-6">
            {lease.epo_schedule
              .filter((row) => row.month === 1 || row.month % 3 === 0)
              .map((row) => (
                <div key={row.month} className="flex items-center justify-between border-b border-neutral-100 py-1.5">
                  <span className="text-neutral-400">{row.month}</span>
                  <span className="font-semibold text-neutral-800">{row.value.toFixed(2)}</span>
                </div>
              ))}
          </div>
        </div>
      )}

      <div className="rounded-xl border border-neutral-200 bg-white p-6 print:break-inside-avoid">
        <div className="grid grid-cols-1 gap-4 sm:grid-cols-3">
          <div>
            <p className="text-xs font-bold uppercase tracking-wide text-neutral-400">Signed by</p>
            <p className="mt-1 text-sm font-semibold text-neutral-900">{signed ? customerName : "—"}</p>
          </div>
          <div>
            <p className="text-xs font-bold uppercase tracking-wide text-neutral-400">Timestamp</p>
            <p className="mt-1 text-sm font-semibold text-neutral-900">
              {lease.contract ? new Date(lease.contract.signed_at).toLocaleString() : "—"}
            </p>
          </div>
          <div>
            <p className="text-xs font-bold uppercase tracking-wide text-neutral-400">Status</p>
            <p className={`mt-1 text-sm font-semibold ${signed ? "text-green-600" : "text-amber-600"}`}>
              {signed ? "Signed & legally valid" : "Awaiting signature"}
            </p>
          </div>
        </div>
      </div>
    </div>
  );
}
