"use client";

import Link from "next/link";
import { useState } from "react";
import { Modal } from "@/components/ui/Modal";
import { money } from "@/components/applications/wizard/types";
import { ApiError } from "@/lib/api";
import { formatDateOnly } from "@/lib/dates";
import { listMyPayments, payMyPayment } from "@/lib/payments";
import { getStripe } from "@/lib/stripe-client";
import type { Payment } from "@/types/lease-agreement";

type Method = "bank" | "card";

const sleep = (ms: number) => new Promise((resolve) => setTimeout(resolve, ms));

/**
 * "Pay now": the customer pays a due monthly payment with a saved bank account
 * or card (client, Joel, 2026-10-15). Both prices are shown before paying and
 * the server checks the total again, so what is shown is exactly what is
 * charged. A bank payment is accepted at once and clears in a few business days;
 * a card that asks for an extra confirmation shows it here.
 */
export function PayNowModal({
  payment,
  onClose,
  onDone,
}: {
  payment: Payment;
  onClose: () => void;
  /** Called when the payment is accepted (or being confirmed), with a sentence for the customer. */
  onDone: (notice: string) => void;
}) {
  const pay = payment.pay;
  const available = pay?.available_methods ?? [];
  const [method, setMethod] = useState<Method | null>(available.includes("bank") ? "bank" : (available[0] ?? null));
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);

  // Paying part of what is due (client, Joel, 2026-10-19 checklist, section 7): the rest stays due and can be paid later.
  const chargeable = pay?.chargeable_amount ?? pay?.prices.bank ?? 0;
  const minPiece = pay?.min_piece ?? 5;
  const canPartPay = !!pay?.can_pay_in_parts && chargeable > minPiece;
  const [partial, setPartial] = useState(false);
  const [amountText, setAmountText] = useState("");
  const typed = Number(amountText);
  const partAmountError = !partial
    ? null
    : !/^\d+(\.\d{1,2})?$/.test(amountText.trim()) || typed <= 0
      ? "Enter dollars and cents, like 25.50."
      : typed < minPiece
        ? `A part is at least ${money(minPiece)}.`
        : Math.round(typed * 100) >= Math.round(chargeable * 100)
          ? "That is everything that is due. Choose to pay everything instead."
          : null;
  const bankAmount = partial && !partAmountError ? typed : chargeable;
  const feeFor = (amount: number) => Math.floor((Math.round(amount * 100) * (pay?.card_fee_bps ?? 300) + 5000) / 10000) / 100;
  const priceFor = (m: Method): number => (partial ? (partAmountError ? 0 : bankAmount + (m === "card" ? feeFor(bankAmount) : 0)) : (pay?.prices[m] ?? 0));
  const total = pay && method && !(partial && partAmountError) ? priceFor(method) : null;

  // The window cannot be dismissed while the payment is in flight, or its answer would be lost.
  const close = () => {
    if (!busy) onClose();
  };

  async function handlePay() {
    if (!pay || !method || total === null || busy) return;
    setBusy(true);
    setError(null);
    try {
      const result = await payMyPayment(payment.id, {
        method,
        expectedTotalCents: Math.round(total * 100),
        amount: partial ? typed : undefined,
      });

      if (result.status === "failed") {
        setError(result.message ?? "The payment did not go through. Please try again.");
        setBusy(false);
        return;
      }

      if (result.status === "requires_action" && result.client_secret) {
        const stripe = await getStripe();
        if (!stripe) throw new Error("Stripe failed to load. Please refresh and try again.");
        const { error: confirmError } = await stripe.confirmCardPayment(result.client_secret);
        if (confirmError) {
          setError(confirmError.message ?? "The card could not be confirmed. Please try again.");
          setBusy(false);
          return;
        }
        // Stripe tells us the card went through a moment later: wait for it to show as paid.
        for (let attempt = 0; attempt < 5; attempt++) {
          await sleep(2000);
          const rows = await listMyPayments().catch(() => []);
          if (rows.find((p) => p.id === payment.id)?.status === "paid") {
            onDone(`Your payment of ${money(total)} was received. Thank you.`);
            return;
          }
        }
        onDone("Your payment is being confirmed. It will show as paid in a moment.");
        return;
      }

      const rest = partial ? ` ${money(chargeable - typed)} is still due.` : "";
      onDone(
        result.status === "processing"
          ? `Your bank payment of ${money(total)} is processing. It usually clears in 1 to 4 business days.${rest}`
          : `Your payment of ${money(total)} was received. Thank you.${rest}`,
      );
    } catch (err) {
      setError(err instanceof ApiError ? err.message : err instanceof Error ? err.message : "The payment could not be made. Please try again.");
      setBusy(false);
    }
  }

  return (
    <Modal title="Pay now" onClose={close} maxWidthClassName="max-w-md">
      {!pay ? (
        <p className="text-sm text-neutral-600">This payment cannot be paid here right now.</p>
      ) : available.length === 0 ? (
        <div className="space-y-4">
          <p className="text-sm text-neutral-600">Add a bank account or a card first, then come back to pay.</p>
          <Link
            href={`/customer/leases/${payment.lease_agreement_id}/autopay`}
            className="font-heading inline-block rounded-md bg-red-600 px-3.5 py-2 text-sm font-bold text-white hover:bg-red-700"
          >
            Add a bank account or card
          </Link>
        </div>
      ) : (
        <div className="space-y-5">
          <p className="text-sm text-neutral-600">
            Your monthly payment due <span className="font-semibold text-neutral-900">{formatDateOnly(payment.due_date)}</span>. Choose
            how you want to pay.
          </p>

          {canPartPay && (
            <fieldset className="space-y-2" disabled={busy}>
              <legend className="mb-1 text-xs font-bold uppercase tracking-wide text-neutral-500">How much</legend>
              {(pay.remaining_amount ?? chargeable) < Number(payment.amount) && (
                <p className="text-xs text-neutral-500">
                  You have already paid {money(Number(payment.amount) - (pay.remaining_amount ?? chargeable))} of {money(Number(payment.amount))}.
                </p>
              )}
              <label className={`flex cursor-pointer items-start gap-3 rounded-lg border p-3 ${!partial ? "border-red-600 bg-red-50" : "border-neutral-200 hover:bg-neutral-50"}`}>
                <input type="radio" name="pay-now-amount" className="mt-1" checked={!partial} onChange={() => setPartial(false)} />
                <span className="text-sm font-semibold text-neutral-900">Everything that is due ({money(chargeable)} before any fee)</span>
              </label>
              <label className={`flex cursor-pointer items-start gap-3 rounded-lg border p-3 ${partial ? "border-red-600 bg-red-50" : "border-neutral-200 hover:bg-neutral-50"}`}>
                <input type="radio" name="pay-now-amount" className="mt-1" checked={partial} onChange={() => setPartial(true)} />
                <span className="flex-1">
                  <span className="block text-sm font-semibold text-neutral-900">Part of it now</span>
                  <span className="block text-xs text-neutral-500">The rest stays due and you can pay it later.</span>
                  {partial && (
                    <span className="mt-2 flex items-center gap-1.5">
                      <span className="text-sm text-neutral-500">$</span>
                      <input
                        type="text"
                        inputMode="decimal"
                        aria-label="Amount to pay now"
                        value={amountText}
                        maxLength={10}
                        onChange={(e) => setAmountText(e.target.value)}
                        placeholder="0.00"
                        className="w-32 rounded-md border border-neutral-300 px-3 py-1.5 text-sm tabular-nums"
                      />
                    </span>
                  )}
                  {partial && amountText !== "" && partAmountError && <span className="mt-1 block text-xs text-red-600">{partAmountError}</span>}
                </span>
              </label>
            </fieldset>
          )}

          <div className="space-y-2">
            {available.map((m) => (
              <label
                key={m}
                className={`flex cursor-pointer items-start gap-3 rounded-lg border p-3 ${
                  method === m ? "border-red-600 bg-red-50" : "border-neutral-200 hover:bg-neutral-50"
                }`}
              >
                <input
                  type="radio"
                  name="pay-now-method"
                  className="mt-1"
                  checked={method === m}
                  disabled={busy}
                  onChange={() => {
                    setMethod(m);
                    setError(null);
                  }}
                />
                <span className="text-sm">
                  <span className="font-semibold text-neutral-900">
                    {m === "bank" ? "Bank account" : "Card"}: {money(priceFor(m))}
                  </span>
                  <span className="block text-xs text-neutral-500">
                    {m === "bank"
                      ? "No fee. Takes 1 to 4 business days to clear."
                      : `Includes a ${money(partial && !partAmountError ? feeFor(bankAmount) : pay.prices.card_fee)} card fee. Paid right away.`}
                  </span>
                </span>
              </label>
            ))}
          </div>

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
              onClick={handlePay}
              disabled={busy || total === null}
              className="font-heading rounded-md bg-red-600 px-3.5 py-2 text-sm font-bold text-white hover:bg-red-700 disabled:opacity-50"
            >
              {busy ? "Processing…" : `Pay ${total !== null ? money(total) : ""}`}
            </button>
          </div>
        </div>
      )}
    </Modal>
  );
}
