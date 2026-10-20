"use client";

import { useState } from "react";
import { Modal } from "@/components/ui/Modal";
import { money } from "@/components/applications/wizard/types";
import { ApiError } from "@/lib/api";
import { formatDateOnly } from "@/lib/dates";
import { methodLabel, paymentTotal } from "@/lib/application-progress";
import { REFUND_REASONS, refundPayment, type RefundReason } from "@/lib/payments";
import type { Payment } from "@/types/lease-agreement";

/** Cents, so adding and comparing amounts never drifts. */
const cents = (dollars: number) => Math.round(dollars * 100);

/**
 * Staff give money back for a paid payment (client, Joel, 2026-10-19: "we have to make sure that I'm able to give a refund"):
 * everything that is left, or an amount they type, with the reason. A payment taken through Stripe goes back through Stripe to the
 * card or bank account it came from; one recorded by hand only gets the refund recorded, because no money moves from here.
 */
export function RefundModal({
  payment,
  onClose,
  onRefunded,
}: {
  payment: Payment;
  onClose: () => void;
  onRefunded: (notice: string) => void;
}) {
  // A payment that was paid in parts is refunded one part at a time, each the way it was paid.
  const paidParts = (payment.parts ?? []).filter((x) => x.status === "paid" && (x.refundable_amount ?? 0) > 0);
  const isSplit = (payment.parts ?? []).some((x) => x.status === "paid");
  const [partId, setPartId] = useState<number | null>(paidParts[0]?.id ?? null);
  const part = paidParts.find((x) => x.id === partId) ?? null;
  // While the payment is still open, a part can only be taken back whole: it simply stops counting as paid.
  const open = payment.status !== "paid";

  const left = isSplit ? (part?.refundable_amount ?? 0) : (payment.refundable_amount ?? paymentTotal(payment));
  const refundedSoFar = isSplit ? (part?.refunded_total ?? 0) : (payment.refunded_total ?? 0);
  const throughStripe = isSplit ? part?.method === "card" || part?.method === "ach" : !!payment.stripe_payment_intent_id;
  const payMethod = isSplit ? part?.method : payment.method;

  const [wholeOnly] = useState(open);
  const [everything, setEverything] = useState(true);
  const [partText, setPartText] = useState("");
  const [reason, setReason] = useState<RefundReason>("customer_request");
  const [note, setNote] = useState("");
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);

  const typed = Number(partText);
  const amount = everything || wholeOnly ? left : typed;
  const amountError = everything || wholeOnly
    ? null
    : !partText.trim() || !Number.isFinite(typed) || typed <= 0
      ? "Enter the amount to refund."
      : !/^\d+(\.\d{1,2})?$/.test(partText.trim())
        ? "Use dollars and cents, like 25.50."
        : cents(typed) > cents(left)
          ? `You can refund at most ${money(left)} of this payment.`
          : null;
  const noteMissing = reason === "other" && !note.trim();
  const ready = !amountError && !noteMissing && amount > 0 && (!isSplit || !!part);

  const close = () => {
    if (!busy) onClose();
  };

  async function handleRefund() {
    if (!ready || busy) return;
    setBusy(true);
    setError(null);
    try {
      const updated = await refundPayment(payment.id, { amount, reason, note: note.trim(), partId: part?.id });
      const last = updated.refunds?.[updated.refunds.length - 1];
      onRefunded(
        !throughStripe
          ? `Refund of ${money(amount)} recorded.`
          : last?.status === "succeeded"
            ? `${money(amount)} refunded through Stripe. It goes back to the customer's ${payMethod === "ach" ? "bank account" : "card"}.`
            : `A refund of ${money(amount)} was started. It shows as done here once the bank confirms it.`,
      );
    } catch (err) {
      setError(err instanceof ApiError ? err.message : "Could not make this refund. Please try again.");
      setBusy(false);
    }
  }

  return (
    <Modal title="Refund this payment" onClose={close} maxWidthClassName="max-w-lg">
      <div className="space-y-5">
        <dl className="space-y-1 rounded-lg bg-neutral-50 px-4 py-3 text-sm">
          <div className="flex justify-between gap-4">
            <dt className="text-neutral-500">
              Paid {payment.paid_date ? formatDateOnly(payment.paid_date) : ""}
              {payment.method ? ` · ${methodLabel(payment.method)}` : ""}
            </dt>
            <dd className="font-semibold tabular-nums text-neutral-900">{money(paymentTotal(payment))}</dd>
          </div>
          {refundedSoFar > 0 && (
            <div className="flex justify-between gap-4">
              <dt className="text-neutral-500">Already refunded</dt>
              <dd className="tabular-nums text-neutral-700">{money(refundedSoFar)}</dd>
            </div>
          )}
          <div className="flex justify-between gap-4 border-t border-neutral-200 pt-1">
            <dt className="font-semibold text-neutral-700">Can still be refunded</dt>
            <dd className="font-semibold tabular-nums text-neutral-900">{money(left)}</dd>
          </div>
        </dl>

        {isSplit && (
          <fieldset className="space-y-2" disabled={busy}>
            <legend className="mb-1 text-xs font-bold uppercase tracking-wide text-neutral-500">Which part</legend>
            {paidParts.map((x) => (
              <label
                key={x.id}
                className={`flex cursor-pointer items-start gap-3 rounded-lg border p-3 ${partId === x.id ? "border-red-600 bg-red-50" : "border-neutral-200 hover:bg-neutral-50"}`}
              >
                <input type="radio" name="refund-part" className="mt-1" checked={partId === x.id} onChange={() => setPartId(x.id)} />
                <span className="text-sm">
                  <span className="font-semibold text-neutral-900">
                    {money(Number(x.amount) + Number(x.card_fee_amount ?? 0))} by {x.method === "ach" ? "bank account" : x.method === "other" ? "another way" : x.method}
                  </span>
                  <span className="block text-xs text-neutral-500">
                    {x.paid_date ? `Paid ${formatDateOnly(x.paid_date)}. ` : ""}Can still be refunded: {money(x.refundable_amount ?? 0)}.
                  </span>
                </span>
              </label>
            ))}
            {paidParts.length === 0 && <p className="text-sm text-neutral-500">Every part of this payment has already been refunded.</p>}
            {open && <p className="text-xs text-neutral-500">The payment is not fully paid yet, so a part can only be refunded in full. It then counts as due again.</p>}
          </fieldset>
        )}

        <div className="space-y-2">
          <label
            className={`flex cursor-pointer items-start gap-3 rounded-lg border p-3 ${everything || wholeOnly ? "border-red-600 bg-red-50" : "border-neutral-200 hover:bg-neutral-50"}`}
          >
            <input type="radio" name="refund-amount" className="mt-1" checked={everything || wholeOnly} disabled={busy || wholeOnly} onChange={() => setEverything(true)} />
            <span className="text-sm font-semibold text-neutral-900">Refund everything that is left ({money(left)})</span>
          </label>
          {!wholeOnly && (
          <label
            className={`flex cursor-pointer items-start gap-3 rounded-lg border p-3 ${!everything ? "border-red-600 bg-red-50" : "border-neutral-200 hover:bg-neutral-50"}`}
          >
            <input type="radio" name="refund-amount" className="mt-1" checked={!everything} disabled={busy} onChange={() => setEverything(false)} />
            <span className="flex-1">
              <span className="block text-sm font-semibold text-neutral-900">Refund part of it</span>
              {!everything && (
                <span className="mt-2 flex items-center gap-1.5">
                  <span className="text-sm text-neutral-500">$</span>
                  <input
                    type="text"
                    inputMode="decimal"
                    aria-label="Amount to refund"
                    value={partText}
                    maxLength={10}
                    disabled={busy}
                    onChange={(e) => setPartText(e.target.value)}
                    className="w-32 rounded-md border border-neutral-300 px-3 py-1.5 text-sm tabular-nums"
                    placeholder="0.00"
                  />
                </span>
              )}
              {!everything && partText !== "" && amountError && <span className="mt-1 block text-xs text-red-600">{amountError}</span>}
            </span>
          </label>
          )}
        </div>

        <div>
          <label htmlFor="refund-reason" className="mb-1 block text-xs font-bold uppercase tracking-wide text-neutral-500">
            Why
          </label>
          <select
            id="refund-reason"
            value={reason}
            disabled={busy}
            onChange={(e) => setReason(e.target.value as RefundReason)}
            className="w-full rounded-md border border-neutral-300 bg-white px-3 py-2 text-sm"
          >
            {REFUND_REASONS.map((r) => (
              <option key={r.value} value={r.value}>
                {r.label}
              </option>
            ))}
          </select>
        </div>

        <div>
          <label htmlFor="refund-note" className="mb-1 block text-xs font-bold uppercase tracking-wide text-neutral-500">
            Note {reason === "other" ? "(required)" : "(optional)"}
          </label>
          <textarea
            id="refund-note"
            value={note}
            maxLength={500}
            rows={2}
            disabled={busy}
            onChange={(e) => setNote(e.target.value)}
            className="w-full rounded-md border border-neutral-300 px-3 py-2 text-sm"
          />
        </div>

        <ul className="list-disc space-y-1 rounded-lg bg-neutral-50 py-3 pl-9 pr-4 text-xs text-neutral-600">
          {throughStripe ? (
            <>
              <li>The money goes back through Stripe to the {payment.method === "ach" ? "bank account" : "card"} the customer paid with.</li>
              <li>
                {payment.method === "ach"
                  ? "A bank account refund takes several business days. It shows as on its way here until the bank confirms it."
                  : "It usually shows on the customer's statement within 5 to 10 business days."}
              </li>
            </>
          ) : (
            <li>This payment was recorded by hand, so no money moves from here. Give the money back yourself, then record it here.</li>
          )}
          {Number(payment.card_fee_amount) > 0 && <li>The card fee the customer paid is part of what can be refunded.</li>}
          {payment.type === "extra_payment" && <li>The refunded part also comes off the customer&rsquo;s Additional Funds, so the early purchase price goes back up.</li>}
          <li>A refund cannot be undone.</li>
        </ul>

        {error && (
          <p role="alert" className="text-sm text-red-600">
            {error}
          </p>
        )}

        <div className="flex justify-end gap-2">
          <button
            onClick={close}
            disabled={busy}
            className="font-heading rounded-md border border-neutral-300 px-3.5 py-2 text-sm font-bold text-neutral-700 hover:bg-neutral-50 disabled:opacity-50"
          >
            Cancel
          </button>
          <button
            onClick={handleRefund}
            disabled={busy || !ready}
            className="font-heading rounded-md bg-red-600 px-3.5 py-2 text-sm font-bold text-white hover:bg-red-700 disabled:opacity-50"
          >
            {busy ? "Refunding…" : ready ? `${throughStripe ? "Refund" : "Record refund of"} ${money(amount)}` : throughStripe ? "Refund" : "Record refund"}
          </button>
        </div>
      </div>
    </Modal>
  );
}
