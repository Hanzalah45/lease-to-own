"use client";

import { useState } from "react";
import { Elements, PaymentElement, useElements, useStripe } from "@stripe/react-stripe-js";
import { getStripe } from "@/lib/stripe-client";
import { ApiError } from "@/lib/api";
import { money } from "@/components/applications/wizard/types";
import { Modal } from "@/components/ui/Modal";
import { formatDateOnly } from "@/lib/dates";
import type { AutopayMethodType, PaymentMethodsStatus, PendingBankCheck } from "@/lib/payment-methods";
import { AlertCircleIcon, BuildingIcon, CheckCircleIcon, CreditCardIcon } from "@/components/icons";

interface AutopaySetupCardProps {
  status: PaymentMethodsStatus;
  onStatusChange: (status: PaymentMethodsStatus) => void;
  onCreateSetupIntent: (type: AutopayMethodType) => Promise<string>;
  onConfirm: (type: AutopayMethodType, setupIntentId: string) => Promise<PaymentMethodsStatus>;
  onSetPrimary: (type: AutopayMethodType) => Promise<PaymentMethodsStatus>;
  /** A bank account that needs two small test deposits: remember it, ask whether it was confirmed, or drop it. */
  onBankPending?: (setupIntentId: string) => Promise<PaymentMethodsStatus>;
  onBankPendingCheck?: () => Promise<PendingBankCheck>;
  onBankPendingCancel?: () => Promise<PaymentMethodsStatus>;
  /** The customer removes a saved bank account or card (only the logged-in portal offers it). */
  onRemove?: (type: AutopayMethodType) => Promise<PaymentMethodsStatus>;
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
  onBankPending,
  onBankPendingCheck,
  onBankPendingCancel,
  onRemove,
  billingName,
  billingEmail,
}: AutopaySetupCardProps) {
  const [cardFormSecret, setCardFormSecret] = useState<string | null>(null);
  const [cardStarting, setCardStarting] = useState(false);
  const [cardError, setCardError] = useState<string | null>(null);

  const [bankBusy, setBankBusy] = useState(false);
  const [bankError, setBankError] = useState<string | null>(null);

  const [removing, setRemoving] = useState<AutopayMethodType | null>(null);
  const [removeBusy, setRemoveBusy] = useState(false);
  const [removeError, setRemoveError] = useState<string | null>(null);

  const [pendingBusy, setPendingBusy] = useState(false);
  const [pendingMessage, setPendingMessage] = useState<string | null>(null);

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

      const setupIntentId = confirmed.setupIntent?.id;
      if (!setupIntentId) throw new Error("Could not confirm the bank account.");

      // An account typed in by hand cannot be confirmed at once: Stripe sends two small deposits and the account waits until their
      // amounts are confirmed (client, Joel, 2026-10-19 checklist, section 2).
      if (
        onBankPending &&
        confirmed.setupIntent?.status === "requires_action" &&
        confirmed.setupIntent.next_action?.type === "verify_with_microdeposits"
      ) {
        onStatusChange(await onBankPending(setupIntentId));
        return;
      }

      // The server checks this SetupIntent actually succeeded (a bank still
      // waiting on microdeposit verification can't be charged later) and
      // reads the saved payment method from it.
      onStatusChange(await onConfirm("bank", setupIntentId));
    } catch (err) {
      setBankError(errorMessage(err, "Could not link the bank account."));
    } finally {
      setBankBusy(false);
    }
  }

  async function confirmRemove() {
    if (!removing || !onRemove) return;
    setRemoveBusy(true);
    setRemoveError(null);
    try {
      onStatusChange(await onRemove(removing));
      setRemoving(null);
    } catch (err) {
      // The server says why in words for the customer (for example that AutoPay needs another method first).
      setRemoveError(errorMessage(err, "Could not remove it. Please try again."));
    } finally {
      setRemoveBusy(false);
    }
  }

  async function checkPending() {
    if (!onBankPendingCheck) return;
    setPendingBusy(true);
    setPendingMessage(null);
    try {
      const checked = await onBankPendingCheck();
      onStatusChange(checked.status);
      // Still waiting or failed: say so. Confirmed: the account shows as added.
      if (checked.result.state !== "verified") setPendingMessage(checked.result.message);
    } catch (err) {
      setPendingMessage(errorMessage(err, "Could not check the bank account."));
    } finally {
      setPendingBusy(false);
    }
  }

  async function dropPending() {
    if (!onBankPendingCancel) return;
    setPendingBusy(true);
    setPendingMessage(null);
    try {
      onStatusChange(await onBankPendingCancel());
    } catch (err) {
      setPendingMessage(errorMessage(err, "Could not drop the bank account."));
    } finally {
      setPendingBusy(false);
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
  // A lease with AutoPay off still saves a bank account or card: it is what the customer pays with from their Payments page.
  // Calling it "AutoPay" there made customers think saving a card turned AutoPay on (client, Joel, 2026-10-16).
  const autopayOn = status.autopay_enabled !== false;

  return (
    <div className="space-y-4">
      {!autopayOn && (
        <p className="rounded-lg border border-neutral-200 bg-neutral-50 px-4 py-3 text-sm text-neutral-600">
          AutoPay is off for your lease. What you save here is used when you press Pay on your Payments page. Nothing is charged
          automatically.
        </p>
      )}
      <MethodRow
        icon={<BuildingIcon className="h-5 w-5" />}
        label="Bank account (ACH)"
        added={status.bank_account_added}
        pending={!!status.bank_pending}
        locked={status.bank_locked}
        hint={status.monthly_prices ? `${money(status.monthly_prices.bank)} a month, no fee` : undefined}
      >
        {status.bank_pending && (
          <div className="mb-3 rounded-lg border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-900">
            <p className="font-semibold">Confirm your bank account</p>
            <p className="mt-1 text-xs leading-relaxed">
              Stripe sent {status.bank_pending.kind === "descriptor_code" ? "a small deposit" : "two small deposits"} to your bank account
              {status.bank_pending.arrives_by ? `, which should arrive by ${formatDateOnly(status.bank_pending.arrives_by)}` : ", which usually arrive in 1 to 2 business days"}.
              When {status.bank_pending.kind === "descriptor_code" ? "it shows" : "they show"} on your statement, open the confirmation page and enter{" "}
              {status.bank_pending.kind === "descriptor_code" ? "the code in its description" : "the two amounts"}, then come back here and press
              &ldquo;I confirmed it&rdquo;. Nothing is charged to this account until it is confirmed.
            </p>
            <div className="mt-3 flex flex-wrap items-center gap-2">
              {status.bank_pending.verify_url && (
                <a
                  href={status.bank_pending.verify_url}
                  target="_blank"
                  rel="noopener noreferrer"
                  className="font-heading rounded-md border border-amber-300 bg-white px-3 py-1.5 text-xs font-bold uppercase tracking-wide text-amber-900 hover:bg-amber-100"
                >
                  Open the confirmation page
                </a>
              )}
              <button
                onClick={checkPending}
                disabled={pendingBusy}
                className="font-heading rounded-md bg-neutral-900 px-3 py-1.5 text-xs font-bold uppercase tracking-wide text-white hover:bg-neutral-800 disabled:opacity-50"
              >
                {pendingBusy ? "Checking…" : "I confirmed it"}
              </button>
              <button onClick={dropPending} disabled={pendingBusy} className="text-xs font-semibold text-amber-900 underline disabled:opacity-50">
                Use a different account
              </button>
            </div>
          </div>
        )}
        {pendingMessage && (
          <p role="status" className="mb-3 text-xs font-medium text-amber-800">
            {pendingMessage}
          </p>
        )}
        {/* A saved account can be replaced (client, Joel, 2026-10-16): the one on file stays until the new one is linked. */}
        {!status.bank_pending && (
        <button
          onClick={startBank}
          disabled={bankBusy}
          className={`font-heading rounded-md px-3.5 py-2 text-sm font-bold uppercase tracking-wide disabled:opacity-50 ${
            status.bank_account_added
              ? "border border-neutral-300 bg-white text-neutral-700 hover:bg-neutral-50"
              : "bg-neutral-900 text-white hover:bg-neutral-800"
          }`}
        >
          {bankBusy ? "Connecting…" : status.bank_account_added ? "Change bank account" : "Link bank account"}
        </button>
        )}
        {status.bank_account_added && (
          <p className="mt-2 text-xs text-neutral-500">Your current account stays on file until you finish linking the new one.</p>
        )}
        {bankError && <p className="mt-2 text-xs text-red-600">{bankError}</p>}
        {status.bank_account_added && onRemove && (
          <button
            onClick={() => {
              setRemoveError(null);
              setRemoving("bank");
            }}
            className="mt-2 block text-xs font-semibold text-neutral-500 hover:text-red-600 hover:underline"
          >
            Remove this bank account
          </button>
        )}
      </MethodRow>

      <MethodRow
        icon={<CreditCardIcon className="h-5 w-5" />}
        label={autopayOn ? "Backup card" : "Card"}
        added={status.card_added}
        locked={status.card_locked}
        hint={status.monthly_prices ? `${money(status.monthly_prices.card)} a month, includes ${money(status.monthly_prices.card_fee)} card fee` : undefined}
      >
        {!cardFormSecret && (
          <>
            {/* A saved card can be replaced (2026-10-15: a customer added the wrong card and had no way to change it). */}
            <button
              onClick={startCard}
              disabled={cardStarting}
              className={`font-heading rounded-md px-3.5 py-2 text-sm font-bold uppercase tracking-wide disabled:opacity-50 ${
                status.card_added
                  ? "border border-neutral-300 bg-white text-neutral-700 hover:bg-neutral-50"
                  : "bg-neutral-900 text-white hover:bg-neutral-800"
              }`}
            >
              {cardStarting ? "Starting…" : status.card_added ? "Change card" : "Add a card"}
            </button>
            {cardError && <p className="mt-2 text-xs text-red-600">{cardError}</p>}
          </>
        )}
        {cardFormSecret && (
          <>
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
            {status.card_added && (
              <button onClick={() => setCardFormSecret(null)} className="mt-2 text-xs font-semibold text-neutral-500 hover:underline">
                Keep my current card
              </button>
            )}
          </>
        )}
        {status.card_added && onRemove && !cardFormSecret && (
          <button
            onClick={() => {
              setRemoveError(null);
              setRemoving("card");
            }}
            className="mt-2 block text-xs font-semibold text-neutral-500 hover:text-red-600 hover:underline"
          >
            Remove this card
          </button>
        )}
      </MethodRow>

      {removing && (
        <Modal title={removing === "bank" ? "Remove your bank account?" : "Remove your card?"} onClose={() => !removeBusy && setRemoving(null)} maxWidthClassName="max-w-md">
          <div className="space-y-4">
            <p className="text-sm text-neutral-600">
              It will be taken off your account and can no longer be used for payments. Anything already being processed is not affected. To pay with
              {removing === "bank" ? " a bank account" : " a card"} again you will need to add one.
            </p>
            {removeError && (
              <p role="alert" className="text-sm text-red-600">
                {removeError}
              </p>
            )}
            <div className="flex justify-end gap-2">
              <button
                onClick={() => setRemoving(null)}
                disabled={removeBusy}
                className="font-heading rounded-md border border-neutral-300 px-3.5 py-2 text-sm font-bold text-neutral-700 hover:bg-neutral-50 disabled:opacity-50"
              >
                Keep it
              </button>
              <button
                onClick={confirmRemove}
                disabled={removeBusy}
                className="font-heading rounded-md bg-red-600 px-3.5 py-2 text-sm font-bold text-white hover:bg-red-700 disabled:opacity-50"
              >
                {removeBusy ? "Removing…" : "Remove it"}
              </button>
            </div>
          </div>
        </Modal>
      )}

      {bothAdded && autopayOn && (
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
  pending,
  locked,
  hint,
  children,
}: {
  icon: React.ReactNode;
  label: string;
  added: boolean;
  /** A bank account typed in by hand that waits for its two small deposits to be confirmed. */
  pending?: boolean;
  locked?: boolean;
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
          ) : pending ? (
            <span className="mt-1 inline-flex items-center gap-1 text-xs font-semibold text-amber-700">
              <AlertCircleIcon className="h-3.5 w-3.5" />
              Waiting to be confirmed
            </span>
          ) : (
            <span className="mt-1 inline-flex items-center gap-1 text-xs font-semibold text-amber-700">
              <AlertCircleIcon className="h-3.5 w-3.5" />
              Not added yet
            </span>
          )}
        </div>
      </div>
      {added && locked && (
        <p role="status" className="mt-3 rounded-lg bg-red-50 px-3 py-2 text-xs font-medium text-red-700">
          This one is locked, so it cannot be used for payments right now. Please contact us, or add a different one below.
        </p>
      )}
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
