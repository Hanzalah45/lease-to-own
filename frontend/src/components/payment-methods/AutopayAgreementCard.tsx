"use client";

import { useState } from "react";
import { money } from "@/components/applications/wizard/types";
import { ApiError } from "@/lib/api";
import { formatDateOnly } from "@/lib/dates";
import type { AutopayOffer, PaymentMethodsStatus } from "@/lib/payment-methods";

/**
 * The AutoPay tick box (client, Joel, 2026-10-16 asked for it, 2026-10-20 wrote the words): a customer who signed without AutoPay and has
 * saved a bank account or a card turns AutoPay on here, without signing the contract again. The authorization the words refer to is shown
 * in full above the box, because a lease signed without AutoPay does not contain that section. What was agreed is kept by the server.
 * Shown while the server offers it (`offer` is null otherwise); once it is ticked the card stays to say so.
 */
export function AutopayAgreementCard({
  offer,
  onAgree,
  onAgreed,
}: {
  offer: AutopayOffer | null;
  /** Records the agreement, with the version of the words that was on screen. */
  onAgree: (wordingVersion: number) => Promise<PaymentMethodsStatus>;
  onAgreed: (next: PaymentMethodsStatus) => void;
}) {
  const [ticked, setTicked] = useState(false);
  const [saving, setSaving] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [done, setDone] = useState(false);

  if (done) {
    return (
      <div role="status" className="rounded-xl border border-green-200 bg-green-50 p-5">
        <p className="font-heading text-base font-bold text-green-800">AutoPay is on</p>
        <p className="mt-1 text-sm text-green-800">
          Thank you. AutoPay charges the first payment that falls due on or after today, using the payment method you saved. We emailed
          you a copy of what you agreed to.
        </p>
      </div>
    );
  }
  if (!offer) return null;

  async function handleAgree() {
    if (!offer) return;
    setError(null);
    setSaving(true);
    try {
      const next = await onAgree(offer.version);
      setDone(true);
      onAgreed(next);
    } catch (err) {
      setError(err instanceof ApiError ? err.message : "Could not turn on AutoPay. Please try again.");
    } finally {
      setSaving(false);
    }
  }

  return (
    <div className="rounded-xl border border-neutral-200 bg-white p-5">
      <h2 className="font-heading text-lg font-bold uppercase tracking-tight text-neutral-900">Turn on AutoPay</h2>
      <p className="mt-1 text-sm text-neutral-500">
        You can turn AutoPay on here with one tick box. You do not need to sign your lease again.
      </p>

      <div className="mt-4 rounded-lg border border-neutral-200 bg-neutral-50 p-4">
        <p className="font-heading text-sm font-bold uppercase tracking-wide text-neutral-700">{offer.title}</p>
        <dl className="mt-2 space-y-1.5 text-sm">
          <Row label="Payment amount, paid by bank account (ACH)" value={money(offer.amounts.bank)} />
          <Row label={`Payment amount, paid by card (${offer.amounts.card_fee_percent}% card processing fee included)`} value={money(offer.amounts.card)} />
          <Row label="Billing cycle" value={offer.billing_cycle} />
          <Row label="Payment frequency" value="Monthly" />
          {offer.first_payment && (
            <Row
              label="First payment AutoPay would charge"
              value={`${formatDateOnly(offer.first_payment.due_date)}: ${money(offer.first_payment.bank)} by bank, ${money(offer.first_payment.card)} by card`}
            />
          )}
        </dl>
        <div className="mt-3 space-y-2 border-t border-neutral-200 pt-3 text-xs leading-relaxed text-neutral-600">
          {offer.terms.map((term, i) => (
            <p key={i}>
              {term.heading && <strong className="text-neutral-800">{term.heading}. </strong>}
              {term.text}
            </p>
          ))}
        </div>
      </div>

      <label className="mt-4 flex cursor-pointer items-start gap-3 text-sm leading-relaxed text-neutral-800">
        <input
          type="checkbox"
          checked={ticked}
          onChange={(e) => setTicked(e.target.checked)}
          className="mt-1 h-4 w-4 shrink-0 accent-red-600"
        />
        <span>{offer.wording}</span>
      </label>

      {error && <p className="mt-3 text-sm text-red-600">{error}</p>}

      <button
        onClick={handleAgree}
        disabled={!ticked || saving}
        className="font-heading mt-4 rounded-md bg-red-600 px-4 py-2.5 text-sm font-bold uppercase tracking-wide text-white hover:bg-red-700 disabled:opacity-50"
      >
        {saving ? "Turning on…" : "Turn on AutoPay"}
      </button>
    </div>
  );
}

/** A label and its value on one line when they fit, the value under the label when they do not (a phone). */
function Row({ label, value }: { label: string; value: string }) {
  return (
    <div className="flex flex-wrap items-baseline justify-between gap-x-4 gap-y-0.5">
      <dt className="min-w-[11rem] flex-1 text-neutral-600">{label}</dt>
      <dd className="text-right font-semibold tabular-nums text-neutral-900">{value}</dd>
    </div>
  );
}
