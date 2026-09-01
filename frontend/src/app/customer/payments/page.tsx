"use client";

import { useEffect, useMemo, useState } from "react";
import { money } from "@/components/applications/wizard/types";
import { listMyPayments } from "@/lib/payments";
import { ApiError } from "@/lib/api";
import type { Payment } from "@/types/lease-agreement";

const STATUS_LABEL: Record<Payment["status"], string> = {
  pending: "Scheduled",
  paid: "Paid",
  failed: "Failed",
  refunded: "Refunded",
};

const STATUS_COLOR: Record<Payment["status"], string> = {
  pending: "text-neutral-500",
  paid: "text-green-700",
  failed: "text-red-600",
  refunded: "text-amber-600",
};

function num(value: string | number | null | undefined): number {
  const n = Number(value ?? 0);
  return Number.isFinite(n) ? n : 0;
}

export default function CustomerPaymentsPage() {
  const [payments, setPayments] = useState<Payment[]>([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);

  useEffect(() => {
    listMyPayments()
      .then(setPayments)
      .catch((err) => setError(err instanceof ApiError ? err.message : "Could not load your payments."))
      .finally(() => setLoading(false));
  }, []);

  const nextPayment = useMemo(
    () => payments.filter((p) => p.status === "pending").sort((a, b) => a.due_date.localeCompare(b.due_date))[0],
    [payments],
  );

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
        <p className="text-sm text-neutral-400">Payment history and upcoming due dates.</p>
      </div>

      {error && <p className="text-sm text-red-600">{error}</p>}

      <div className="flex flex-col gap-4 rounded-xl border border-neutral-200 bg-white p-5 sm:flex-row sm:items-center sm:justify-between">
        <div>
          <div className="mb-1 flex items-center gap-2">
            <span className="h-4 w-1 shrink-0 rounded-full bg-red-600" />
            <h2 className="font-heading text-base font-bold uppercase tracking-wide text-neutral-900">Next payment</h2>
          </div>
          <p className="text-sm text-neutral-600">
            {loading
              ? "Loading…"
              : nextPayment
                ? `${money(num(nextPayment.amount))} due ${new Date(nextPayment.due_date).toLocaleDateString()}`
                : "No upcoming payment scheduled."}
          </p>
        </div>
        <div className="flex shrink-0 gap-2">
          <button
            title="Live payment processing (Stripe/QuickBooks) ships once accounting integration (Milestone 7) is wired up"
            className="font-heading rounded-md border border-red-600 bg-white px-4 py-2 text-sm font-bold text-red-600 hover:bg-red-50"
          >
            Manage Autopay
          </button>
          <button
            title="Live payment processing (Stripe/QuickBooks) ships once accounting integration (Milestone 7) is wired up"
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
                <th className="pb-2 font-medium">Due date</th>
                <th className="pb-2 font-medium">Amount</th>
                <th className="pb-2 font-medium">Method</th>
                <th className="pb-2 font-medium">Status</th>
              </tr>
            </thead>
            <tbody>
              {loading ? (
                <tr>
                  <td colSpan={4} className="py-6 text-center text-sm text-neutral-400">Loading…</td>
                </tr>
              ) : payments.length === 0 ? (
                <tr>
                  <td colSpan={4} className="py-6 text-center text-sm text-neutral-400">No payments yet.</td>
                </tr>
              ) : (
                payments.map((row) => (
                  <tr key={row.id} className="border-b border-neutral-100 last:border-0">
                    <td className="py-3 text-neutral-700">{new Date(row.due_date).toLocaleDateString()}</td>
                    <td className="py-3 font-semibold text-neutral-900">{money(num(row.amount))}</td>
                    <td className="py-3 text-neutral-600">{row.method ?? "—"}</td>
                    <td className="py-3">
                      <span className={`inline-flex items-center gap-1.5 text-xs font-bold uppercase tracking-wide ${STATUS_COLOR[row.status]}`}>
                        <span className={`h-1.5 w-1.5 rounded-full ${row.status === "paid" ? "bg-green-500" : row.status === "failed" ? "bg-red-500" : "bg-neutral-300"}`} />
                        {STATUS_LABEL[row.status]}
                      </span>
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
