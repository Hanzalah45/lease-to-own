"use client";

import { money } from "@/components/applications/wizard/types";
import { SAMPLE_LEASES } from "@/lib/sample-lease";

const activeLease = SAMPLE_LEASES.find((l) => l.status === "active") ?? SAMPLE_LEASES[0];

const PAYMENT_HISTORY = [
  { id: 1, date: "8/15/2026", amount: activeLease.totalMonthly, method: "Checking •••• 7920", status: "Paid" },
  { id: 2, date: "7/15/2026", amount: activeLease.totalMonthly, method: "Checking •••• 7920", status: "Paid" },
  { id: 3, date: "6/15/2026", amount: activeLease.totalMonthly, method: "Checking •••• 7920", status: "Paid" },
  { id: 4, date: "5/15/2026", amount: activeLease.totalMonthly, method: "Checking •••• 7920", status: "Paid" },
  { id: 5, date: "4/15/2026", amount: activeLease.totalMonthly, method: "Checking •••• 7920", status: "Paid" },
  { id: 6, date: "7/20/2026", amount: activeLease.totalDue, method: "Mastercard •••• 2896", status: "Initial payment" },
];

export default function CustomerPaymentsPage() {
  return (
    <div className="space-y-6">
      <div
        className="-mx-4 -mt-4 px-4 pb-6 pt-4 sm:-mx-8 sm:-mt-6 sm:px-8 sm:pt-6 lg:-mx-16 lg:px-16"
        style={{
          background:
            "linear-gradient(to left, rgba(220,38,38,0.24) 0%, rgba(220,38,38,0.10) 35%, transparent 65%)",
        }}
      >
        <h1 className="text-3xl font-black uppercase tracking-tight text-neutral-900 sm:text-4xl">Payments</h1>
        <p className="text-sm text-neutral-400">Payment history and AutoPay settings.</p>
      </div>

      <div className="flex flex-col gap-4 rounded-xl border border-neutral-200 bg-white p-5 sm:flex-row sm:items-center sm:justify-between">
        <div>
          <div className="mb-1 flex items-center gap-2">
            <span className="h-4 w-1 shrink-0 rounded-full bg-red-600" />
            <h2 className="font-heading text-base font-bold uppercase tracking-wide text-neutral-900">Next payment</h2>
          </div>
          <p className="text-sm text-neutral-600">
            {money(activeLease.totalMonthly)} due next cycle · AutoPay {activeLease.autopay ? "enabled" : "disabled"}
            {activeLease.autopay ? " (Checking •••• 7920)" : ""}
          </p>
        </div>
        <div className="flex shrink-0 gap-2">
          <button
            title="Autopay management ships once payments (Milestone 7) are wired up"
            className="font-heading rounded-md border border-red-600 bg-white px-4 py-2 text-sm font-bold text-red-600 hover:bg-red-50"
          >
            Manage Autopay
          </button>
          <button
            title="Payments ship once accounting integration (Milestone 7) is wired up"
            className="font-heading rounded-md bg-red-600 px-4 py-2 text-sm font-bold text-white hover:bg-red-700"
          >
            Make a Payment
          </button>
        </div>
      </div>

      <div className="overflow-hidden rounded-xl border border-neutral-200 bg-white p-4 sm:p-5">
        <div className="mb-4 flex items-center gap-2">
          <span className="h-4 w-1 shrink-0 rounded-full bg-red-600" />
          <h2 className="font-heading text-base font-bold uppercase tracking-wide text-neutral-900">Payment history</h2>
        </div>
        <div className="-mx-4 overflow-x-auto px-4 sm:mx-0 sm:px-0">
          <table className="w-full min-w-[520px] text-left text-sm">
            <thead>
              <tr className="font-heading border-b border-neutral-200 text-xs font-bold uppercase tracking-wide text-neutral-400">
                <th className="pb-2 font-medium">Date</th>
                <th className="pb-2 font-medium">Amount</th>
                <th className="pb-2 font-medium">Method</th>
                <th className="pb-2 font-medium">Status</th>
              </tr>
            </thead>
            <tbody>
              {PAYMENT_HISTORY.map((row) => (
                <tr key={row.id} className="border-b border-neutral-100 last:border-0">
                  <td className="py-3 text-neutral-700">{row.date}</td>
                  <td className="py-3 font-semibold text-neutral-900">{money(row.amount)}</td>
                  <td className="py-3 text-neutral-600">{row.method}</td>
                  <td className="py-3">
                    <span className="inline-flex items-center gap-1.5 text-xs font-bold uppercase tracking-wide text-green-700">
                      <span className="h-1.5 w-1.5 rounded-full bg-green-500" />
                      {row.status}
                    </span>
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      </div>
    </div>
  );
}
