"use client";

import { useState } from "react";
import { Elements, PaymentElement, useElements, useStripe } from "@stripe/react-stripe-js";
import { getStripe } from "@/lib/stripe-client";
import { ApiError } from "@/lib/api";
import { money } from "@/components/applications/wizard/types";
import type { AutopayMethodType, PaymentMethodsStatus } from "@/lib/payment-methods";
import { AlertCircleIcon, BuildingIcon, CheckCircleIcon, CreditCardIcon } from "@/components/icons";

interface AutopaySetupCardProps {
  status: PaymentMethodsStatus;
  onStatusChange: (status: PaymentMethodsStatus) => void;
  onCreateSetupIntent: (type: AutopayMethodType) => Promise<string>;
  onConfirm: (type: AutopayMethodType, setupIntentId: string) => Promise<PaymentMethodsStatus>;
  onSetPrimary: (type: AutopayMethodType) => Promise<PaymentMethodsStatus>;
  billingName: string;
  billingEmail: string;
}

function errorMessage(err: unknown, fallback: string): string {
  if (err instanceof ApiError) return err.message;
  if (err instanceof Error) return err.message;
  return fallback;
}

/**
 * The AutoPay bank-account + backup-card setup step (client, Joel,
 * 2026-10-01) — shared by both the guest signed-link page and the
 * authenticated customer portal page, which otherwise duplicate their JSX
 * independently (same pattern as sign-contract vs customer/contracts/[id]/
 * sign): the Stripe SDK wiring itself is intricate enough that duplicating
 * it exactly twice would be a real bug risk, so it lives here once and both
 * pages pass in their own API bindings.
 */
export function AutopaySetupCard({
  status,
  onStatusChange,
  onCreateSetupIntent,
  onConfirm,
  onSetPrimary,
  billingName,
  billingEmail,
}: AutopaySetupCardProps) {
  const [cardFormSecret, setCardFormSecret] = useState<string | null>(null);
  const [cardStarting, setCardStarting] = useState(false);
  const [cardError, setCardError] = useState<string | null>(null);

  const [bankBusy, setBankBusy] = useState(false);
  const [bankError, setBankError] = useState<string | null>(null);

  const [primaryBusy, setPrimaryBusy] = useState(false);
  const [primaryError, setPrimaryError] = useState<string | null>(null);

  async function startCard() {
    setCardError(null);
    setCardStarting(true);
    try {
      setCardFormSecret(await onCreateSetupIntent("card"));
    } catch (err) {
      setCardError(errorMessage(err, "Could not start card setup."));
    } finally {
      setCardStarting(false);
    }
  }

  async function startBank() {
    setBankError(null);
    setBankBusy(true);
    try {
      const clientSecret = await onCreateSetupIntent("bank");
      const stripe = await getStripe();
      if (!stripe) throw new Error("Stripe failed to load. Please refresh and try again.");

      const result = await stripe.collectBankAccountForSetup({
        clientSecret,
        params: {
          payment_method_type: "us_bank_account",
          payment_method_data: { billing_details: { name: billingName, email: billingEmail } },
        },
      });
      if (result.error) throw new Error(result.error.message ?? "Could not link the bank account.");

      // Customer closed Stripe's dialog without finishing — not an error, just nothing to confirm yet.
      if (result.setupIntent?.status === "requires_payment_method") return;

      const confirmed = await stripe.confirmUsBankAccountSetup(clientSecret);
      if (confirmed.error) throw new Error(confirmed.error.message ?? "Could not confirm the bank account.");

      // The server checks this SetupIntent actually succeeded (a bank still
      // waiting on microdeposit verification can't be charged later) and
      // reads the saved payment method from it.
      const setupIntentId = confirmed.setupIntent?.id;
      if (!setupIntentId) throw new Error("Could not confirm the bank account.");

      onStatusChange(await onConfirm("bank", setupIntentId));
    } catch (err) {
      setBankError(errorMessage(err, "Could not link the bank account."));
    } finally {
      setBankBusy(false);
    }
  }

  async function choosePrimary(type: AutopayMethodType) {
    setPrimaryError(null);
    setPrimaryBusy(true);
    try {
      onStatusChange(await onSetPrimary(type));
    } catch (err) {
      setPrimaryError(errorMessage(err, "Could not update your AutoPay method."));
    } finally {
      setPrimaryBusy(false);
    }
  }

  const bothAdded = status.bank_account_added && status.card_added;

  return (
    <div className="space-y-4">
      <MethodRow
        icon={<BuildingIcon className="h-5 w-5" />}
        label="Bank account (ACH)"
        added={status.bank_account_added}
        hint={status.monthly_prices ? `${money(status.monthly_prices.bank)} a month, no fee` : undefined}
      >
        {!status.bank_account_added && (
          <>
            <button
              onClick={startBank}
              disabled={bankBusy}
              className="font-heading rounded-md bg-neutral-900 px-3.5 py-2 text-sm font-bold uppercase tracking-wide text-white hover:bg-neutral-800 disabled:opacity-50"
            >
              {bankBusy ? "Connecting…" : "Link bank account"}
            </button>
            {bankError && <p className="mt-2 text-xs text-red-600">{bankError}</p>}
          </>
        )}
      </MethodRow>

      <MethodRow
        icon={<CreditCardIcon className="h-5 w-5" />}
        label="Backup card"
        added={status.card_added}
        hint={status.monthly_prices ? `${money(status.monthly_prices.card)} a month, includes ${money(status.monthly_prices.card_fee)} card fee` : undefined}
      >
        {!status.card_added && !cardFormSecret && (
          <>
            <button
              onClick={startCard}
              disabled={cardStarting}
              className="font-heading rounded-md bg-neutral-900 px-3.5 py-2 text-sm font-bold uppercase tracking-wide text-white hover:bg-neutral-800 disabled:opacity-50"
            >
              {cardStarting ? "Starting…" : "Add a card"}
            </button>
            {cardError && <p className="mt-2 text-xs text-red-600">{cardError}</p>}
          </>
        )}
        {!status.card_added && cardFormSecret && (
          <Elements stripe={getStripe()} options={{ clientSecret: cardFormSecret }}>
            <CardSetupForm
              onSaved={(setupIntentId) =>
                onConfirm("card", setupIntentId).then((next) => {
                  setCardFormSecret(null);
                  onStatusChange(next);
                })
              }
            />
          </Elements>
        )}
      </MethodRow>

      {bothAdded && (
        <div className="rounded-xl border border-neutral-200 bg-white p-5">
          <h3 className="font-heading text-sm font-bold uppercase tracking-wide text-neutral-900">
            Which one should AutoPay use first?
          </h3>
          <p className="mt-1 text-xs text-neutral-500">
            If that one ever fails, AutoPay automatically falls back to the other and charges that method&rsquo;s price.
            Paying from your bank account is cheaper: a card costs {status.monthly_prices?.card_fee_percent ?? 3}% more.
          </p>
          <div className="mt-3 flex gap-3">
            {(["ach", "card"] as const).map((method) => (
              <label
                key={method}
                className={`flex flex-1 items-center gap-2 rounded-md border px-3.5 py-2.5 text-sm font-semibold ${
                  status.autopay_primary_method === method
                    ? "border-red-600 bg-red-50 text-red-700"
                    : "border-neutral-300 text-neutral-700 hover:bg-neutral-50"
                }`}
              >
                <input
                  type="radio"
                  name="autopay-primary"
                  checked={status.autopay_primary_method === method}
                  disabled={primaryBusy}
                  onChange={() => choosePrimary(method === "ach" ? "bank" : "card")}
                  className="accent-red-600"
                />
                <span>
                  {method === "ach" ? "Bank account" : "Card"}
                  {status.monthly_prices && (
                    <span className="block text-xs font-normal text-neutral-500">
                      {method === "ach"
                        ? `${money(status.monthly_prices.bank)} a month, no fee`
                        : `${money(status.monthly_prices.card)} a month, includes ${money(status.monthly_prices.card_fee)} card fee`}
                    </span>
                  )}
                </span>
              </label>
            ))}
          </div>
          {primaryError && <p className="mt-2 text-xs text-red-600">{primaryError}</p>}
        </div>
      )}
    </div>
  );
}

function MethodRow({
  icon,
  label,
  added,
  hint,
  children,
}: {
  icon: React.ReactNode;
  label: string;
  added: boolean;
  hint?: string;
  children?: React.ReactNode;
}) {
  return (
    <div className="rounded-xl border border-neutral-200 bg-white p-5">
      <div className="flex items-center gap-3">
        <span className="flex h-10 w-10 shrink-0 items-center justify-center rounded-full bg-neutral-100 text-neutral-500">
          {icon}
        </span>
        <div className="flex-1">
          <p className="font-heading text-sm font-bold text-neutral-900">{label}</p>
          {hint && <p className="text-xs text-neutral-500">{hint}</p>}
          {added ? (
            <span className="mt-1 inline-flex items-center gap-1 text-xs font-semibold text-green-700">
              <CheckCircleIcon className="h-3.5 w-3.5" />
              Added
            </span>
          ) : (
            <span className="mt-1 inline-flex items-center gap-1 text-xs font-semibold text-amber-700">
              <AlertCircleIcon className="h-3.5 w-3.5" />
              Not added yet
            </span>
          )}
        </div>
      </div>
      {children && <div className="mt-3">{children}</div>}
    </div>
  );
}

function CardSetupForm({ onSaved }: { onSaved: (setupIntentId: string) => void }) {
  const stripe = useStripe();
  const elements = useElements();
  const [submitting, setSubmitting] = useState(false);
  const [error, setError] = useState<string | null>(null);

  async function handleSubmit() {
    if (!stripe || !elements) return;
    setSubmitting(true);
    setError(null);
    try {
      const { error: submitError } = await elements.submit();
      if (submitError) throw new Error(submitError.message ?? "Could not save the card.");

      const { error: confirmError, setupIntent } = await stripe.confirmSetup({
        elements,
        redirect: "if_required",
      });
      if (confirmError) throw new Error(confirmError.message ?? "Could not save the card.");

      if (!setupIntent?.id) throw new Error("Could not save the card.");

      onSaved(setupIntent.id);
    } catch (err) {
      setError(errorMessage(err, "Could not save the card."));
    } finally {
      setSubmitting(false);
    }
  }

  return (
    <div className="space-y-3">
      <PaymentElement />
      {error && <p className="text-xs text-red-600">{error}</p>}
      <button
        onClick={handleSubmit}
        disabled={submitting || !stripe}
        className="font-heading rounded-md bg-neutral-900 px-3.5 py-2 text-sm font-bold uppercase tracking-wide text-white hover:bg-neutral-800 disabled:opacity-50"
      >
        {submitting ? "Saving…" : "Save card"}
      </button>
    </div>
  );
}
