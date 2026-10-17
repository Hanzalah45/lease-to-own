"use client";

import { useState } from "react";
import { Modal } from "@/components/ui/Modal";
import { money } from "@/components/applications/wizard/types";
import { ApiError } from "@/lib/api";
import { getExtraPaymentOptions, payExtra, type ExtraPaymentOptions } from "@/lib/payments";
import { getStripe } from "@/lib/stripe-client";

type Method = "bank" | "card";

const sleep = (ms: number) => new Promise((resolve) => setTimeout(resolve, ms));

/** The card fee in cents on a bank-price amount, rounded half up like the server (CardPricing::feeCents). */
function cardFeeCents(bankCents: number, percent: number): number {
  return Math.floor((bankCents * Math.round(percent * 100) + 5000) / 10000);
}

/**
 * The customer pays extra toward the early purchase price (client, Joel, 2026-10-16: "how are they able to make extra
 * payments toward the principal when they want to?"; contract Section 19, "Additional Funds"). The exact price of each
 * method is shown before paying and the server checks the total again, so what is shown is what is charged. A bank
 * payment is accepted at once and lowers the early purchase price when it clears, in a few business days.
 */
export function ExtraPaymentModal({
  leaseId,
  options,
  onClose,
  onDone,
}: {
  leaseId: number | string;
  options: ExtraPaymentOptions;
  onClose: () => void;
  /** Called when the payment is accepted (or being confirmed), with a sentence for the customer. */
  onDone: (notice: string) => void;
}) {
  const methods = options.available_methods;
  const [amountText, setAmountText] = useState("");
  const [method, setMethod] = useState<Method | null>(methods.includes("bank") ? "bank" : (methods[0] ?? null));
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);

  const amount = Number(amountText);
  const bankCents = Number.isFinite(amount) ? Math.round(amount * 100) : 0;
  const validAmount = amountText.trim() !== "" && Number.isFinite(amount) && amount >= options.min && amount <= options.max;
  const cardFee = cardFeeCents(bankCents, options.card_fee_percent) / 100;
  const totalCents = bankCents + (method === "card" ? Math.round(cardFee * 100) : 0);

  // The window cannot be dismissed while the payment is in flight, or its answer would be lost.
  const close = () => {
    if (!busy) onClose();
  };

  async function handlePay() {
    if (!validAmount || !method || busy) return;
    setBusy(true);
    setError(null);
    try {
      const result = await payExtra(leaseId, { amount: bankCents / 100, method, expectedTotalCents: totalCents });

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
        // Stripe tells us the card went through a moment later: wait for the price to show the new amount.
        for (let attempt = 0; attempt < 5; attempt++) {
          await sleep(2000);
          const fresh = await getExtraPaymentOptions(leaseId).catch(() => null);
          if (fresh && fresh.epo_today < options.epo_today) {
            onDone(`Your extra payment of ${money(totalCents / 100)} was received. Thank you.`);
            return;
          }
        }
        onDone("Your extra payment is being confirmed. Your early purchase price updates in a moment.");
        return;
      }

      onDone(
        result.status === "processing"
          ? `Your bank payment of ${money(totalCents / 100)} is processing. It usually clears in 1 to 4 business days, and your early purchase price drops when it does.`
          : `Your extra payment of ${money(totalCents / 100)} was received. Thank you.`,
      );
    } catch (err) {
      setError(err instanceof ApiError ? err.message : err instanceof Error ? err.message : "The payment could not be made. Please try again.");
      setBusy(false);
    }
  }

  return (
    <Modal title="Make an extra payment" onClose={close} maxWidthClassName="max-w-md">
      <div className="space-y-5">
        <p className="text-sm text-neutral-600">
          An extra payment goes toward your early purchase price, which is{" "}
          <span className="font-semibold text-neutral-900">{money(options.epo_today)}</span> today (it excludes tax). It lowers that
          price by the amount you pay.
        </p>

        <div>
          <label htmlFor="extra-amount" className="mb-1 block text-xs font-bold uppercase tracking-wide text-neutral-500">
            Amount
          </label>
          <div className="flex gap-2">
            <div className="relative flex-1">
              <span className="pointer-events-none absolute left-3 top-1/2 -translate-y-1/2 text-sm text-neutral-400">$</span>
              <input
                id="extra-amount"
                type="number"
                inputMode="decimal"
                min={options.min}
                max={options.max}
                step="0.01"
                value={amountText}
                disabled={busy}
                onChange={(e) => {
                  setAmountText(e.target.value);
                  setError(null);
                }}
                placeholder={`${options.min.toFixed(2)} to ${options.max.toFixed(2)}`}
                className="w-full rounded-md border border-neutral-300 py-2 pl-7 pr-3 text-sm"
              />
            </div>
            <button
              type="button"
              disabled={busy}
              onClick={() => {
                setAmountText(options.max.toFixed(2));
                setError(null);
              }}
              className="font-heading whitespace-nowrap rounded-md border border-neutral-300 px-3 py-2 text-xs font-bold text-neutral-700 hover:bg-neutral-50 disabled:opacity-50"
            >
              Whole price
            </button>
          </div>
          <p className="mt-1 text-xs text-neutral-500">
            From {money(options.min)} up to {money(options.max)}, the whole early purchase price.
          </p>
        </div>

        <div className="space-y-2">
          {methods.map((m) => {
            const total = validAmount ? (bankCents + (m === "card" ? Math.round(cardFee * 100) : 0)) / 100 : null;

            return (
              <label
                key={m}
                className={`flex cursor-pointer items-start gap-3 rounded-lg border p-3 ${
                  method === m ? "border-red-600 bg-red-50" : "border-neutral-200 hover:bg-neutral-50"
                }`}
              >
                <input
                  type="radio"
                  name="extra-payment-method"
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
                    {m === "bank" ? "Bank account" : "Card"}
                    {total !== null ? `: ${money(total)}` : ""}
                  </span>
                  <span className="block text-xs text-neutral-500">
                    {m === "bank"
                      ? "No fee. Takes 1 to 4 business days to clear."
                      : `Adds a ${options.card_fee_percent}% card fee${validAmount ? ` (${money(cardFee)})` : ""}. Paid right away.`}
                  </span>
                </span>
              </label>
            );
          })}
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
            disabled={busy || !validAmount || !method}
            className="font-heading rounded-md bg-red-600 px-3.5 py-2 text-sm font-bold text-white hover:bg-red-700 disabled:opacity-50"
          >
            {busy ? "Processing…" : validAmount && method ? `Pay ${money(totalCents / 100)}` : "Pay"}
          </button>
        </div>
      </div>
    </Modal>
  );
}
