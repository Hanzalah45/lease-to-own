"use client";

import Link from "next/link";
import { useEffect, useState } from "react";
import { useAuth } from "@/context/AuthContext";
import { PageHeroHeader } from "@/components/layout/PageHeroHeader";
import { money } from "@/components/applications/wizard/types";
import { ApiError } from "@/lib/api";
import { formatDateOnly } from "@/lib/dates";
import { listAutopayLog, type AutopayLogFilters, type AutopayLogPage } from "@/lib/payments";

const STATUS_FILTERS: Array<{ value: NonNullable<AutopayLogFilters["status"]>; label: string }> = [
  { value: "", label: "Any result" },
  { value: "succeeded", label: "Succeeded" },
  { value: "failed", label: "Failed" },
  { value: "processing", label: "Processing" },
  { value: "initiated", label: "Started, no answer yet" },
];

const STATUS_STYLE: Record<string, { label: string; className: string }> = {
  succeeded: { label: "Succeeded", className: "bg-green-100 text-green-700" },
  failed: { label: "Failed", className: "bg-red-100 text-red-700" },
  processing: { label: "Processing", className: "bg-amber-100 text-amber-700" },
  initiated: { label: "No answer yet", className: "bg-neutral-100 text-neutral-600" },
};

const TYPE_LABEL: Record<string, string> = { rental: "Monthly payment", extra_payment: "Extra payment" };

/**
 * The AutoPay log (client, Joel, 2026-10-19 checklist, section 5): every try at collecting a monthly payment through Stripe, whether
 * AutoPay made it or the customer pressed Pay now, with the method, the amount, how it ended and the bank's reason. Read only.
 */
export default function AdminAutopayLogPage() {
  const { user } = useAuth();
  const isSuperAdmin = user?.role === "super_admin";
  const restrictions = user?.admin_permissions?.map((p) => p.permission) ?? [];
  // Mirrors User::hasAdminPermission() on the backend, like the Payments page.
  const canSee = isSuperAdmin || restrictions.length === 0 || restrictions.includes("payment_tracking");

  const [status, setStatus] = useState<NonNullable<AutopayLogFilters["status"]>>("");
  const [method, setMethod] = useState<NonNullable<AutopayLogFilters["method"]>>("");
  const [startedBy, setStartedBy] = useState<NonNullable<AutopayLogFilters["startedBy"]>>("");
  const [from, setFrom] = useState("");
  const [to, setTo] = useState("");
  const [page, setPage] = useState(1);
  const [result, setResult] = useState<AutopayLogPage | null>(null);
  const [error, setError] = useState<string | null>(null);

  const rangeIsValid = !from || !to || to >= from;

  useEffect(() => {
    if (!canSee || !rangeIsValid) return;
    let cancelled = false;
    listAutopayLog({ status, method, startedBy, from, to }, page)
      .then((data) => {
        if (cancelled) return;
        setResult(data);
        setError(null);
      })
      .catch((err) => {
        if (!cancelled) setError(err instanceof ApiError ? err.message : "Could not load the AutoPay log.");
      });

    return () => {
      cancelled = true;
    };
  }, [canSee, rangeIsValid, status, method, startedBy, from, to, page]);

  if (!canSee) {
    return (
      <div className="space-y-3">
        <h1 className="text-2xl font-bold uppercase tracking-tight">AutoPay log</h1>
        <p className="max-w-prose text-sm text-neutral-500">
          Your admin account is restricted and does not include payment tracking. Ask a super admin to add the Payment Tracking
          permission to your account.
        </p>
      </div>
    );
  }

  const change = <T,>(setter: (value: T) => void) => (value: T) => {
    setter(value);
    setPage(1);
  };
  const selectClass = "rounded-md border border-neutral-300 bg-white px-3 py-2 text-sm";
  const labelClass = "mb-1 block text-xs font-bold uppercase tracking-wide text-neutral-500";

  return (
    <div className="space-y-6">
      <PageHeroHeader
        title="AutoPay log"
        subtitle="Every try at collecting a monthly payment: when, how much, which method, and how it ended."
        action={
          <Link
            href="/admin/payments"
            className="font-heading self-start whitespace-nowrap rounded-md border border-red-600 bg-white px-4 py-2 text-sm font-bold text-red-600 hover:bg-red-50"
          >
            ← Payments
          </Link>
        }
      />

      <div className="flex flex-wrap items-end gap-3 rounded-xl border border-neutral-200 bg-white p-4">
        <div>
          <label htmlFor="log-status" className={labelClass}>
            Result
          </label>
          <select id="log-status" value={status} onChange={(e) => change(setStatus)(e.target.value as typeof status)} className={selectClass}>
            {STATUS_FILTERS.map((s) => (
              <option key={s.value} value={s.value}>
                {s.label}
              </option>
            ))}
          </select>
        </div>
        <div>
          <label htmlFor="log-method" className={labelClass}>
            Method
          </label>
          <select id="log-method" value={method} onChange={(e) => change(setMethod)(e.target.value as typeof method)} className={selectClass}>
            <option value="">Either</option>
            <option value="bank">Bank account</option>
            <option value="card">Card</option>
          </select>
        </div>
        <div>
          <label htmlFor="log-by" className={labelClass}>
            Started by
          </label>
          <select id="log-by" value={startedBy} onChange={(e) => change(setStartedBy)(e.target.value as typeof startedBy)} className={selectClass}>
            <option value="">Anyone</option>
            <option value="autopay">AutoPay</option>
            <option value="customer">The customer (Pay now)</option>
          </select>
        </div>
        <div>
          <label htmlFor="log-from" className={labelClass}>
            From
          </label>
          <input id="log-from" type="date" value={from} onChange={(e) => change(setFrom)(e.target.value)} className="rounded-md border border-neutral-300 px-3 py-2 text-sm" />
        </div>
        <div>
          <label htmlFor="log-to" className={labelClass}>
            To
          </label>
          <input id="log-to" type="date" value={to} min={from || undefined} onChange={(e) => change(setTo)(e.target.value)} className="rounded-md border border-neutral-300 px-3 py-2 text-sm" />
        </div>
        {!rangeIsValid && <p className="text-xs text-red-600">The end date cannot be before the start date.</p>}
      </div>

      {error && <p className="text-sm text-red-600">{error}</p>}
      {!result && !error ? <p className="py-6 text-sm text-neutral-500">Loading the AutoPay log…</p> : null}

      {result && (
        <div className="overflow-hidden rounded-xl border border-neutral-200 bg-white p-4 sm:p-5">
          <div className="-mx-4 overflow-x-auto px-4 sm:mx-0 sm:px-0">
            <table className="w-full min-w-[860px] text-left text-sm">
              <thead>
                <tr className="font-heading border-b border-neutral-200 text-xs font-bold uppercase tracking-wide text-neutral-400">
                  <th className="pb-2 font-medium">When</th>
                  <th className="pb-2 font-medium">Customer</th>
                  <th className="pb-2 font-medium">Payment</th>
                  <th className="pb-2 font-medium">Method</th>
                  <th className="pb-2 text-right font-medium">Amount</th>
                  <th className="pb-2 pl-4 font-medium">Result</th>
                </tr>
              </thead>
              <tbody>
                {result.data.length === 0 ? (
                  <tr>
                    <td colSpan={6} className="py-6 text-center text-sm text-neutral-400">
                      Nothing matches these filters.
                    </td>
                  </tr>
                ) : (
                  result.data.map((a) => {
                    const s = STATUS_STYLE[a.status] ?? { label: a.status, className: "bg-neutral-100 text-neutral-600" };

                    return (
                      <tr key={a.id} className="border-b border-neutral-100 align-top last:border-0">
                        <td className="py-2.5 pr-3 whitespace-nowrap text-xs text-neutral-500">{new Date(a.created_at).toLocaleString()}</td>
                        <td className="py-2.5 pr-3 text-neutral-800">
                          {a.application_id ? (
                            <Link href={`/admin/applications/${a.application_id}`} className="font-medium hover:underline">
                              {a.customer ?? "Customer"}
                            </Link>
                          ) : (
                            (a.customer ?? "—")
                          )}
                        </td>
                        <td className="py-2.5 pr-3 text-neutral-700">
                          {TYPE_LABEL[a.payment_type ?? ""] ?? "Payment"}
                          <span className="block text-xs text-neutral-400">
                            {a.due_date ? `due ${formatDateOnly(a.due_date)} · ` : ""}try {a.attempt_no}, round {a.round}
                          </span>
                        </td>
                        <td className="py-2.5 pr-3 text-neutral-600">
                          {a.method === "bank" ? "Bank account" : "Card"}
                          <span className="block text-xs text-neutral-400">{a.started_by === "customer" ? "Pay now" : "AutoPay"}</span>
                        </td>
                        <td className="py-2.5 text-right tabular-nums text-neutral-700">
                          {money(a.amount)}
                          {a.fee > 0 && <span className="block text-xs text-neutral-400">{money(a.fee)} card fee</span>}
                        </td>
                        <td className="py-2.5 pl-4">
                          <span className={`rounded-full px-2.5 py-0.5 text-xs font-bold ${s.className}`}>{s.label}</span>
                          {(a.failure_message || a.failure_code) && (
                            <span className="mt-1 block max-w-xs text-xs text-red-600">
                              {a.failure_message ?? a.failure_code}
                              {a.failure_message && a.failure_code ? ` (${a.failure_code})` : ""}
                            </span>
                          )}
                        </td>
                      </tr>
                    );
                  })
                )}
              </tbody>
            </table>
          </div>
          {result.last_page > 1 && (
            <div className="mt-4 flex flex-wrap items-center justify-center gap-3 text-sm text-neutral-500">
              <button
                onClick={() => setPage(Math.max(1, page - 1))}
                disabled={page === 1}
                className="font-semibold text-red-600 hover:underline disabled:text-neutral-300 disabled:no-underline"
              >
                Previous
              </button>
              <span>
                Page {result.current_page} of {result.last_page} ({result.total} tries)
              </span>
              <button
                onClick={() => setPage(Math.min(result.last_page, page + 1))}
                disabled={page === result.last_page}
                className="font-semibold text-red-600 hover:underline disabled:text-neutral-300 disabled:no-underline"
              >
                Next
              </button>
            </div>
          )}
        </div>
      )}
    </div>
  );
}
