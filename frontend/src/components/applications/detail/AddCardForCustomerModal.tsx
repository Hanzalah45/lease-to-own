"use client";

import { useEffect, useState } from "react";
import { Elements, PaymentElement, useElements, useStripe } from "@stripe/react-stripe-js";
import { Modal } from "@/components/ui/Modal";
import { money } from "@/components/applications/wizard/types";
import { ApiError } from "@/lib/api";
import { saveCardAsStaff, startCardSetupAsStaff } from "@/lib/payments";
import { getStripe } from "@/lib/stripe-client";
import type { Application } from "@/types/application";

/**
 * Staff add the customer's card to their AutoPay (client, Joel, 2026-10-14): a
 * customer who cannot manage the card form on their own is helped by phone.
 * The card is typed into Stripe's own form, so the number goes straight to
 * Stripe and never reaches this site; only Stripe's setup id is sent back.
 */
export function AddCardForCustomerModal({
  application,
  replacing = false,
  onClose,
  onSaved,
}: {
  application: Application;
  /** The customer already has a card: the new one replaces it and the old one is removed from their Stripe profile. */
  replacing?: boolean;
  onClose: () => void;
  onSaved: (card: { brand: string | null; last4: string | null; replaced: boolean }) => void;
}) {
  const lease = application.lease_agreement;
  const customerName = application.customer?.name ?? "the customer";
  const monthly = lease?.pricing?.monthly;

  const [clientSecret, setClientSecret] = useState<string | null>(null);
  const [startError, setStartError] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);

  // Keyed on the id, not the lease object: the page reloads its data now and then, and a new object must not
  // start a second card setup and wipe what the admin has typed.
  const leaseId = lease?.id;
  useEffect(() => {
    let cancelled = false;
    if (leaseId === undefined) return;
    startCardSetupAsStaff(leaseId)
      .then((secret) => {
        if (!cancelled) setClientSecret(secret);
      })
      .catch((err) => {
        if (!cancelled) setStartError(err instanceof ApiError ? err.message : "Could not start the card form.");
      });
    return () => {
      cancelled = true;
    };
  }, [leaseId]);

  // The window cannot be dismissed while the card is being saved, or the answer would be lost.
  const close = () => {
    if (!busy) onClose();
  };

  return (
    <Modal title={replacing ? "Change the customer's card" : "Add a card for the customer"} onClose={close} maxWidthClassName="max-w-lg">
      <div className="space-y-4">
        <p className="text-sm text-neutral-600">
          Enter the {replacing ? "new " : ""}card that <span className="font-semibold text-neutral-900">{customerName}</span> gives you.
          It goes straight to Stripe, nothing is stored on this site. Only {replacing ? "change it" : "add it"} with their agreement.
          {replacing && " The card now on file is removed once the new one is saved."}
        </p>
        {monthly && (
          <p className="rounded-lg bg-neutral-50 px-3 py-2 text-xs text-neutral-600">
            Paid by card, the monthly payment is {money(monthly.card)} (includes a {money(monthly.card_fee)} card fee), instead of{" "}
            {money(monthly.bank)} by bank.
          </p>
        )}

        {startError ? (
          <p role="alert" className="text-sm text-red-600">
            {startError}
          </p>
        ) : !clientSecret ? (
          <p className="text-sm text-neutral-400">Loading the card form…</p>
        ) : (
          <Elements stripe={getStripe()} options={{ clientSecret }}>
            <CardForm leaseId={lease!.id} busy={busy} setBusy={setBusy} onCancel={close} onSaved={onSaved} />
          </Elements>
        )}
      </div>
    </Modal>
  );
}

function CardForm({
  leaseId,
  busy,
  setBusy,
  onCancel,
  onSaved,
}: {
  leaseId: number;
  busy: boolean;
  setBusy: (busy: boolean) => void;
  onCancel: () => void;
  onSaved: (card: { brand: string | null; last4: string | null; replaced: boolean }) => void;
}) {
  const stripe = useStripe();
  const elements = useElements();
  const [agreed, setAgreed] = useState(false);
  const [error, setError] = useState<string | null>(null);

  async function handleSave() {
    if (!stripe || !elements || !agreed || busy) return;
    setBusy(true);
    setError(null);
    try {
      const { error: submitError } = await elements.submit();
      if (submitError) throw new Error(submitError.message ?? "Could not save the card.");

      const { error: confirmError, setupIntent } = await stripe.confirmSetup({ elements, redirect: "if_required" });
      if (confirmError) throw new Error(confirmError.message ?? "Could not save the card.");
      if (!setupIntent?.id) throw new Error("Could not save the card.");

      onSaved(await saveCardAsStaff(leaseId, setupIntent.id));
    } catch (err) {
      setError(err instanceof Error ? err.message : "Could not save the card.");
      setBusy(false);
    }
  }

  return (
    <div className="space-y-4">
      {/* Cards only, entered by staff: no Link sign-up or Link bank option in this form. */}
      <PaymentElement options={{ layout: "tabs", wallets: { link: "never", applePay: "never", googlePay: "never" } }} />

      <label className="flex cursor-pointer items-start gap-3 text-sm text-neutral-700">
        <input type="checkbox" className="mt-1" checked={agreed} onChange={(e) => setAgreed(e.target.checked)} />
        <span>The customer agreed to this card being charged for their monthly payments.</span>
      </label>

      {error && (
        <p role="alert" className="text-sm text-red-600">
          {error}
        </p>
      )}

      <div className="flex justify-end gap-2">
        <button
          onClick={onCancel}
          disabled={busy}
          className="font-heading rounded-md border border-neutral-300 px-3.5 py-2 text-sm font-bold text-neutral-700 hover:bg-neutral-50 disabled:opacity-50"
        >
          Cancel
        </button>
        <button
          onClick={handleSave}
          disabled={!stripe || !agreed || busy}
          className="font-heading rounded-md bg-red-600 px-3.5 py-2 text-sm font-bold text-white hover:bg-red-700 disabled:opacity-50"
        >
          {busy ? "Saving…" : "Save card"}
        </button>
      </div>
    </div>
  );
}
