"use client";

import { useEffect, useMemo, useState } from "react";
import Link from "next/link";
import { money } from "@/components/applications/wizard/types";
import { formatDateOnly } from "@/lib/dates";
import { PayNowModal } from "@/components/payments/PayNowModal";
import { firstMonthMirrors } from "@/lib/application-progress";
import { listMyPayments } from "@/lib/payments";
import { downloadReceipt, listMyReceipts, type MyReceipt } from "@/lib/receipts";
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
  const [paying, setPaying] = useState<Payment | null>(null);
  const [notice, setNotice] = useState<string | null>(null);
  const [receipts, setReceipts] = useState<MyReceipt[]>([]);
  const [downloadingId, setDownloadingId] = useState<number | null>(null);

  function load() {
    return listMyPayments()
      .then(setPayments)
      .catch((err) => setError(err instanceof ApiError ? err.message : "Could not load your payments."))
      .finally(() => setLoading(false));
  }

  useEffect(() => {
    void load();
  }, []);

  // The receipts are a convenience: the page works without them.
  useEffect(() => {
    listMyReceipts()
      .then(setReceipts)
      .catch(() => {});
  }, [payments]);

  async function downloadMine(receipt: MyReceipt) {
    setDownloadingId(receipt.id);
    try {
      await downloadReceipt(receipt.id, receipt.receipt_number, false);
    } catch (err) {
      setError(err instanceof Error ? err.message : "Could not download the receipt.");
    } finally {
      setDownloadingId(null);
    }
  }

  // The first month's record row is bookkeeping: its money is the tracking fee and first month payment, which is listed once.
  const history = useMemo(() => {
    const hidden = new Set(firstMonthMirrors(payments).map((f) => f.mirror.id));
    return payments.filter((p) => !hidden.has(p.id));
  }, [payments]);

  const nextPayment = useMemo(
    // An extra payment still clearing at the bank is not a payment that is coming up.
    () => payments.filter((p) => p.status === "pending" && p.type !== "extra_payment").sort((a, b) => a.due_date.localeCompare(b.due_date))[0],
    [payments],
  );

  // The oldest due monthly payment the customer can pay from here, or the reason one that is due cannot be paid yet.
  const due = useMemo(
    () => payments.filter((p) => p.pay).sort((a, b) => a.due_date.localeCompare(b.due_date)),
    [payments],
  );
  const payable = due.find((p) => p.pay?.payable);
  const waiting = payable ? undefined : due.find((p) => p.pay?.blocker);

  // AutoPay setup is per-lease — most customers only ever have one, so the
  // soonest pending payment's lease (falling back to the most recent payment
  // at all) is a reasonable single lease to manage it for from this page.
  const autopayLeaseId = (nextPayment ?? payments[0])?.lease_agreement_id;
  // With AutoPay off the page only saves what the customer pays with, so it is not called "Autopay" (client, Joel, 2026-10-16).
  const autopayOn = (nextPayment ?? payments[0])?.lease_agreement?.autopay_enabled !== false;

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
      {notice && (
        <p role="status" className="rounded-lg border border-green-200 bg-green-50 px-4 py-3 text-sm font-medium text-green-800">
          {notice}
        </p>
      )}

      <div className="flex flex-col gap-4 rounded-xl border border-neutral-200 bg-white p-5 sm:flex-row sm:items-center sm:justify-between">
        <div>
          <div className="mb-1 flex items-center gap-2">
            <span className="h-4 w-1 shrink-0 rounded-full bg-red-600" />
            <h2 className="font-heading text-base font-bold uppercase tracking-wide text-neutral-900">
              {payable ? "Payment due" : "Next payment"}
            </h2>
          </div>
          <p className="text-sm text-neutral-600">
            {loading
              ? "Loading…"
              : payable
                ? `${money(num(payable.amount))} due ${formatDateOnly(payable.due_date)}${
                    payable.status === "failed" ? ", the last attempt did not go through" : ""
                  }`
                : nextPayment
                  ? `${money(num(nextPayment.amount))} due ${formatDateOnly(nextPayment.due_date)}`
                  : "No upcoming payment scheduled."}
          </p>
          {!loading && waiting?.pay?.blocker && <p className="mt-1 text-xs text-amber-700">{waiting.pay.blocker}</p>}
        </div>
        <div className="flex shrink-0 gap-2">
          {payable && (
            <button
              onClick={() => setPaying(payable)}
              className="font-heading rounded-md bg-red-600 px-4 py-2 text-sm font-bold text-white hover:bg-red-700"
            >
              Pay now
            </button>
          )}
          {autopayLeaseId ? (
            <Link
              href={`/customer/leases/${autopayLeaseId}/autopay`}
              className="font-heading rounded-md border border-red-600 bg-white px-4 py-2 text-sm font-bold text-red-600 hover:bg-red-50"
            >
              {autopayOn ? "Manage Autopay" : "Payment methods"}
            </Link>
          ) : (
            <button
              disabled
              title="No lease on file yet"
              className="font-heading cursor-not-allowed rounded-md border border-neutral-300 bg-white px-4 py-2 text-sm font-bold text-neutral-400"
            >
              Manage Autopay
            </button>
          )}
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
                <th className="pb-2" />
              </tr>
            </thead>
            <tbody>
              {loading ? (
                <tr>
                  <td colSpan={5} className="py-6 text-center text-sm text-neutral-400">Loading…</td>
                </tr>
              ) : history.length === 0 ? (
                <tr>
                  <td colSpan={5} className="py-6 text-center text-sm text-neutral-400">No payments yet.</td>
                </tr>
              ) : (
                history.map((row) => (
                  <tr key={row.id} className="border-b border-neutral-100 last:border-0">
                    <td className="py-3 text-neutral-700">{formatDateOnly(row.due_date)}</td>
                    <td className="py-3 font-semibold text-neutral-900">
                      {money(num(row.amount) + num(row.card_fee_amount))}
                      {num(row.card_fee_amount) > 0 && (
                        <span className="ml-1.5 text-xs font-normal text-neutral-400">includes {money(num(row.card_fee_amount))} card fee</span>
                      )}
                      {/* Paid in parts: what has been paid so far and what is left (client, Joel, 2026-10-19 checklist, section 7). */}
                      {row.status !== "paid" && row.status !== "refunded" && (row.parts ?? []).some((x) => x.status === "paid") && (
                        <span className="mt-0.5 block text-xs font-normal text-neutral-500">
                          {money(num(row.amount) - num(row.remaining_amount))} paid so far, {money(num(row.remaining_amount))} still due
                        </span>
                      )}
                      {row.type === "late_fee" && (
                        <span className="ml-2 rounded-full bg-amber-100 px-2 py-0.5 text-[10px] font-bold uppercase tracking-wide text-amber-700">
                          Late fee
                        </span>
                      )}
                      {row.type === "deposit" && (
                        <span className="ml-2 rounded-full bg-blue-100 px-2 py-0.5 text-[10px] font-bold uppercase tracking-wide text-blue-700">
                          Deposit
                        </span>
                      )}
                      {row.type === "pickup_balance" && (
                        <span className="ml-2 rounded-full bg-purple-100 px-2 py-0.5 text-[10px] font-bold uppercase tracking-wide text-purple-700">
                          Pickup balance
                        </span>
                      )}
                      {row.type === "extra_payment" && (
                        <span className="ml-2 rounded-full bg-green-100 px-2 py-0.5 text-[10px] font-bold uppercase tracking-wide text-green-700">
                          Extra payment
                        </span>
                      )}
                    </td>
                    <td className="py-3 text-neutral-600">{row.method === "split" ? "More than one" : (row.method ?? "—")}</td>
                    <td className="py-3">
                      <span className={`inline-flex items-center gap-1.5 text-xs font-bold uppercase tracking-wide ${STATUS_COLOR[row.status]}`}>
                        <span className={`h-1.5 w-1.5 rounded-full ${row.status === "paid" ? "bg-green-500" : row.status === "failed" ? "bg-red-500" : "bg-neutral-300"}`} />
                        {/* A pending payment with a charge already started is being processed by the bank, not just scheduled. */}
                        {row.status === "pending" && row.stripe_payment_intent_id ? "Processing" : STATUS_LABEL[row.status]}
                      </span>
                    </td>
                    <td className="py-3 text-right">
                      {row.pay?.payable && (
                        <button
                          onClick={() => setPaying(row)}
                          className="font-heading rounded-md border border-red-600 px-3 py-1.5 text-xs font-bold text-red-600 hover:bg-red-50"
                        >
                          Pay now
                        </button>
                      )}
                    </td>
                  </tr>
                ))
              )}
            </tbody>
          </table>
        </div>
      </div>

      {receipts.length > 0 && (
        <div className="overflow-hidden rounded-xl border border-neutral-200 bg-white p-4 sm:p-5">
          <div className="mb-1 flex items-center gap-2">
            <span className="h-4 w-1 shrink-0 rounded-full bg-red-600" />
            <h2 className="font-heading text-base font-bold uppercase tracking-wide text-neutral-900">Receipts</h2>
          </div>
          <p className="mb-3 text-xs text-neutral-500">A receipt is written for every payment you make and every refund. Download one as a PDF.</p>
          <ul className="divide-y divide-neutral-100">
            {receipts.map((r) => (
              <li key={r.id} className="flex flex-wrap items-center justify-between gap-x-4 gap-y-1 py-2.5">
                <div className="min-w-0">
                  <p className="text-sm font-semibold text-neutral-900">
                    {r.receipt_number}
                    <span className="font-normal text-neutral-500"> · {r.kind === "refund" ? "Refund of " : ""}{r.description}</span>
                  </p>
                  <p className="text-xs text-neutral-400">{new Date(r.issued_at).toLocaleDateString()}</p>
                </div>
                <div className="flex items-center gap-4">
                  <span className={`text-sm font-semibold tabular-nums ${r.kind === "refund" ? "text-red-600" : "text-neutral-900"}`}>
                    {r.kind === "refund" ? "−" : ""}
                    {money(num(r.amount))}
                  </span>
                  <button
                    onClick={() => void downloadMine(r)}
                    disabled={downloadingId === r.id}
                    className="font-heading rounded-md border border-neutral-300 px-3 py-1.5 text-xs font-bold text-neutral-700 hover:bg-neutral-50 disabled:opacity-50"
                  >
                    {downloadingId === r.id ? "Downloading…" : "Download"}
                  </button>
                </div>
              </li>
            ))}
          </ul>
        </div>
      )}

      {paying && (
        <PayNowModal
          payment={paying}
          onClose={() => setPaying(null)}
          onDone={(message) => {
            setPaying(null);
            setNotice(message);
            void load();
          }}
        />
      )}
    </div>
  );
}
