"use client";

import Link from "next/link";
import { useEffect, useState } from "react";
import { useAuth } from "@/context/AuthContext";
import { PageHeroHeader } from "@/components/layout/PageHeroHeader";
import { FromCell, actorLabel } from "@/components/applications/detail/PaymentHistoryModal";
import { money } from "@/components/applications/wizard/types";
import { methodLabel } from "@/lib/application-progress";
import { ApiError } from "@/lib/api";
import { listAuditLog, type AuditLogFilters, type AuditLogPage } from "@/lib/payments";
import type { Payment } from "@/types/lease-agreement";

/** The kinds of action the trail holds, as the filter offers them. */
const FAMILIES: Array<{ value: string; label: string }> = [
  { value: "", label: "Everything" },
  { value: "payment", label: "Payments paid, failed or changed" },
  { value: "charge", label: "Charges started" },
  { value: "refund", label: "Refunds" },
  { value: "chargeback", label: "Chargebacks" },
  { value: "note", label: "Notes" },
  { value: "method", label: "Saved cards and bank accounts" },
  { value: "autopay", label: "AutoPay" },
  { value: "lease", label: "Leases ended" },
];

const WHO: Array<{ value: NonNullable<AuditLogFilters["who"]>; label: string }> = [
  { value: "", label: "Anyone" },
  { value: "staff", label: "Staff" },
  { value: "customer", label: "Customers" },
  { value: "system", label: "The system" },
];

/**
 * The payment audit trail as one list (client, Joel, 2026-10-19 checklist, section 9): who did what to which payment, when, with what
 * method and from where. It can be read and filtered here, and nothing in the portal can change or delete a line of it.
 */
export default function AdminAuditLogPage() {
  const { user } = useAuth();
  const isSuperAdmin = user?.role === "super_admin";
  const restrictions = user?.admin_permissions?.map((p) => p.permission) ?? [];
  // Mirrors User::hasAdminPermission() on the backend, like the Payments page.
  const canSee = isSuperAdmin || restrictions.length === 0 || restrictions.includes("payment_tracking");

  const [action, setAction] = useState("");
  const [who, setWho] = useState<NonNullable<AuditLogFilters["who"]>>("");
  const [from, setFrom] = useState("");
  const [to, setTo] = useState("");
  const [page, setPage] = useState(1);
  const [result, setResult] = useState<AuditLogPage | null>(null);
  const [error, setError] = useState<string | null>(null);

  const rangeIsValid = !from || !to || to >= from;

  useEffect(() => {
    if (!canSee || !rangeIsValid) return;
    let cancelled = false;
    listAuditLog({ action, who, from, to }, page)
      .then((data) => {
        if (cancelled) return;
        setResult(data);
        setError(null);
      })
      .catch((err) => {
        if (!cancelled) setError(err instanceof ApiError ? err.message : "Could not load the audit log.");
      });

    return () => {
      cancelled = true;
    };
  }, [canSee, rangeIsValid, action, who, from, to, page]);

  if (!canSee) {
    return (
      <div className="space-y-3">
        <h1 className="text-2xl font-bold uppercase tracking-tight">Audit log</h1>
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

  return (
    <div className="space-y-6">
      <PageHeroHeader
        title="Audit log"
        subtitle="Every payment action: who did it, when, with what method and from where."
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
          <label htmlFor="audit-action" className="mb-1 block text-xs font-bold uppercase tracking-wide text-neutral-500">
            What
          </label>
          <select id="audit-action" value={action} onChange={(e) => change(setAction)(e.target.value)} className="rounded-md border border-neutral-300 bg-white px-3 py-2 text-sm">
            {FAMILIES.map((f) => (
              <option key={f.value} value={f.value}>
                {f.label}
              </option>
            ))}
          </select>
        </div>
        <div>
          <label htmlFor="audit-who" className="mb-1 block text-xs font-bold uppercase tracking-wide text-neutral-500">
            Who
          </label>
          <select id="audit-who" value={who} onChange={(e) => change(setWho)(e.target.value as typeof who)} className="rounded-md border border-neutral-300 bg-white px-3 py-2 text-sm">
            {WHO.map((w) => (
              <option key={w.value} value={w.value}>
                {w.label}
              </option>
            ))}
          </select>
        </div>
        <div>
          <label htmlFor="audit-from" className="mb-1 block text-xs font-bold uppercase tracking-wide text-neutral-500">
            From
          </label>
          <input id="audit-from" type="date" value={from} onChange={(e) => change(setFrom)(e.target.value)} className="rounded-md border border-neutral-300 px-3 py-2 text-sm" />
        </div>
        <div>
          <label htmlFor="audit-to" className="mb-1 block text-xs font-bold uppercase tracking-wide text-neutral-500">
            To
          </label>
          <input id="audit-to" type="date" value={to} min={from || undefined} onChange={(e) => change(setTo)(e.target.value)} className="rounded-md border border-neutral-300 px-3 py-2 text-sm" />
        </div>
        {!rangeIsValid && <p className="text-xs text-red-600">The end date cannot be before the start date.</p>}
      </div>

      {error && <p className="text-sm text-red-600">{error}</p>}
      {!result && !error ? <p className="py-6 text-sm text-neutral-500">Loading the audit log…</p> : null}

      {result && (
        <div className="overflow-hidden rounded-xl border border-neutral-200 bg-white p-4 sm:p-5">
          <div className="-mx-4 overflow-x-auto px-4 sm:mx-0 sm:px-0">
            <table className="w-full min-w-[820px] text-left text-sm">
              <thead>
                <tr className="font-heading border-b border-neutral-200 text-xs font-bold uppercase tracking-wide text-neutral-400">
                  <th className="pb-2 font-medium">When</th>
                  <th className="pb-2 font-medium">Who</th>
                  <th className="pb-2 font-medium">What happened</th>
                  <th className="pb-2 font-medium">Method</th>
                  <th className="pb-2 text-right font-medium">Amount</th>
                  <th className="pb-2 pl-4 font-medium">From</th>
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
                  result.data.map((a) => (
                    <tr key={a.id} className="border-b border-neutral-100 align-top last:border-0">
                      <td className="py-2.5 pr-3 whitespace-nowrap text-xs text-neutral-500">{new Date(a.created_at).toLocaleString()}</td>
                      <td className="py-2.5 pr-3 text-neutral-800">{actorLabel(a)}</td>
                      <td className="py-2.5 pr-3 text-neutral-700">
                        {a.summary}
                        {a.lease_agreement?.customer && (
                          <span className="block text-xs text-neutral-400">
                            <Link href={`/admin/applications/${a.lease_agreement.application_id}`} className="hover:underline">
                              {a.lease_agreement.customer.name}
                            </Link>
                            {a.payment_id ? ` · payment #${a.payment_id}` : ""}
                          </span>
                        )}
                      </td>
                      <td className="py-2.5 pr-3 text-neutral-600">{a.method ? methodLabel(a.method as Payment["method"]) : "—"}</td>
                      <td className="py-2.5 text-right tabular-nums text-neutral-700">{a.amount ? money(Number(a.amount)) : "—"}</td>
                      <td className="py-2.5 pl-4 text-xs">
                        <FromCell entry={a} />
                      </td>
                    </tr>
                  ))
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
                Page {result.current_page} of {result.last_page} ({result.total} lines)
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
