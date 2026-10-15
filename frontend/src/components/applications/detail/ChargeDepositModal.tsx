"use client";

import { useState } from "react";
import { Modal } from "@/components/ui/Modal";
import { money } from "@/components/applications/wizard/types";
import { ApiError } from "@/lib/api";
import { chargeDepositAsStaff, chargePickupBalanceAsStaff } from "@/lib/payments";
import type { Payment } from "@/types/lease-agreement";
import type { Application } from "@/types/application";

type Method = "bank" | "card";

/**
 * Staff charge the security deposit (client, Joel, 2026-10-12), or the tracking
 * fee and first month (2026-10-16), to the customer's saved card or bank account
 * after the customer agreed. The amounts come from the server's own price
 * summary, and the server checks the total again before charging, so what is
 * shown here is exactly what is charged.
 */
export function ChargeDepositModal({
  application,
  kind = "deposit",
  onClose,
  onCharged,
  onRefused,
}: {
  application: Application;
  kind?: "deposit" | "pickup_balance";
  onClose: () => void;
  onCharged: (payment: Payment) => void;
  /** The server answered with an error: the page reloads, since a decline leaves a failed payment and a note behind and a 409 means the amounts changed. */
  onRefused: () => void;
}) {
  const lease = application.lease_agreement;
  const price = lease?.pricing?.[kind];
  const what = kind === "deposit" ? "security deposit" : "tracking fee and first month";
  const customerName = application.customer?.name ?? "the customer";

  const options: { method: Method; label: string; total: number; note: string }[] = [];
  // The bank account comes first: it is the base price, so nobody is charged the card fee by default.
  if (price && lease?.stripe_bank_payment_method_id) {
    options.push({ method: "bank", label: "Saved bank account", total: price.bank, note: "no card fee, settles in a few business days" });
  }
  if (price && lease?.stripe_card_payment_method_id) {
    options.push({
      method: "card",
      label: "Saved card",
      total: price.card,
      note: `includes the ${lease.pricing?.card_fee_percent ?? 3}% card fee (${money(price.card_fee)})`,
    });
  }

  const [method, setMethod] = useState<Method | null>(options[0]?.method ?? null);
  const [agreed, setAgreed] = useState(false);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);

  const chosen = options.find((o) => o.method === method) ?? null;

  async function handleCharge() {
    if (!chosen || !agreed || busy) return;
    setBusy(true);
    setError(null);
    try {
      const charge = kind === "deposit" ? chargeDepositAsStaff : chargePickupBalanceAsStaff;
      const payment = await charge(application.id, {
        method: chosen.method,
        expectedTotalCents: Math.round(chosen.total * 100),
        customerAgreed: agreed,
      });
      onCharged(payment);
    } catch (err) {
      setError(err instanceof ApiError ? err.message : `Could not charge the ${what}.`);
      setBusy(false);
      // A decline writes a failed payment and a note on the server, and a 409 means the amounts moved:
      // either way the page behind this window is out of date.
      if (err instanceof ApiError) onRefused();
    }
  }

  // The window cannot be dismissed while the charge is in flight, or a decline would never be seen.
  const close = () => {
    if (!busy) onClose();
  };

  return (
    <Modal title={kind === "deposit" ? "Charge deposit" : "Charge tracking fee and first month"} onClose={close} maxWidthClassName="max-w-lg">
      {!chosen ? (
        <p className="text-sm text-red-600">This customer has no saved card or bank account to charge.</p>
      ) : (
        <div className="space-y-5">
          <p className="text-sm text-neutral-600">
            Charge the {what} for <span className="font-semibold text-neutral-900">{customerName}</span> to the
            payment method they have saved. The charge happens right away.
          </p>

          <div className="space-y-2">
            {options.map((o) => (
              <label
                key={o.method}
                className={`flex cursor-pointer items-start gap-3 rounded-lg border p-3 ${
                  method === o.method ? "border-red-600 bg-red-50" : "border-neutral-200 hover:bg-neutral-50"
                }`}
              >
                <input
                  type="radio"
                  name="charge-method"
                  className="mt-1"
                  checked={method === o.method}
                  onChange={() => {
                    // The tick was given for one amount: a different method means a different total.
                    setMethod(o.method);
                    setAgreed(false);
                    setError(null);
                  }}
                />
                <span className="text-sm">
                  <span className="font-semibold text-neutral-900">
                    {o.label}: {money(o.total)}
                  </span>
                  <span className="block text-xs text-neutral-500">{o.note}</span>
                </span>
              </label>
            ))}
          </div>

          <label className="flex cursor-pointer items-start gap-3 text-sm text-neutral-700">
            <input type="checkbox" className="mt-1" checked={agreed} onChange={(e) => setAgreed(e.target.checked)} />
            <span>
              The customer has agreed to this charge of <span className="font-semibold">{money(chosen.total)}</span>.
            </span>
          </label>

          {error && (
            <p role="alert" className="text-sm text-red-600">
              {error}
            </p>
          )}

          <div className="flex justify-end gap-2">
            <button
              onClick={onClose}
              disabled={busy}
              className="font-heading rounded-md border border-neutral-300 px-3.5 py-2 text-sm font-bold text-neutral-700 hover:bg-neutral-50 disabled:opacity-50"
            >
              Cancel
            </button>
            <button
              onClick={handleCharge}
              disabled={!agreed || busy}
              className="font-heading rounded-md bg-red-600 px-3.5 py-2 text-sm font-bold text-white hover:bg-red-700 disabled:opacity-50"
            >
              {busy ? "Charging…" : `Charge ${money(chosen.total)}`}
            </button>
          </div>
        </div>
      )}
    </Modal>
  );
}
