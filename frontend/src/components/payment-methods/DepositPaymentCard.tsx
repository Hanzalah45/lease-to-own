"use client";

import { useState } from "react";
import { getStripe } from "@/lib/stripe-client";
import { ApiError } from "@/lib/api";
import { money } from "@/components/applications/wizard/types";
import type { ChargeDepositResult, DepositPaymentStatus } from "@/lib/deposit-payment";
import { AlertCircleIcon, CheckCircleIcon, ClockIcon } from "@/components/icons";

interface DepositPaymentCardProps {
  status: DepositPaymentStatus;
  onStatusChange: (status: DepositPaymentStatus) => void;
  onCharge: () => Promise<ChargeDepositResult>;
  onRefreshStatus: () => Promise<DepositPaymentStatus>;
}

function errorMessage(err: unknown, fallback: string): string {
  if (err instanceof ApiError) return err.message;
  if (err instanceof Error) return err.message;
  return fallback;
}

function sleep(ms: number): Promise<void> {
  return new Promise((resolve) => setTimeout(resolve, ms));
}

/**
 * Real Stripe deposit charging (client, Joel, 2026-10-01) — shared by both
 * the guest signed-link page and the authenticated customer portal page,
 * same reasoning as AutopaySetupCard: the Stripe confirmation/polling logic
 * is intricate enough that duplicating it twice would be a real bug risk.
 */
export function DepositPaymentCard({ status, onStatusChange, onCharge, onRefreshStatus }: DepositPaymentCardProps) {
  const [charging, setCharging] = useState(false);
  const [error, setError] = useState<string | null>(null);

  async function pollUntilResolved() {
    for (let attempt = 0; attempt < 5; attempt++) {
      await sleep(2500);
      const next = await onRefreshStatus();
      onStatusChange(next);
      if (next.deposit_payment && next.deposit_payment.status !== "pending") return;
    }
  }

  async function handleCharge() {
    setError(null);
    setCharging(true);
    try {
      const result = await onCharge();

      if (result.requires_action && result.client_secret) {
        const stripe = await getStripe();
        if (!stripe) throw new Error("Stripe failed to load. Please refresh and try again.");

        const { error: confirmError } = await stripe.confirmCardPayment(result.client_secret);
        if (confirmError) throw new Error(confirmError.message ?? "Could not confirm the payment.");

        onStatusChange(await onRefreshStatus());
        return;
      }

      if (result.payment.status === "pending") {
        onStatusChange(await onRefreshStatus());
        await pollUntilResolved();
        return;
      }

      onStatusChange(await onRefreshStatus());
    } catch (err) {
      setError(errorMessage(err, "Could not process the payment. Please try again."));
    } finally {
      setCharging(false);
    }
  }

  if (status.already_marked_received) {
    return null;
  }

  const payment = status.deposit_payment;

  return (
    <div className="rounded-xl border border-neutral-200 bg-white p-5">
      <h3 className="font-heading text-sm font-bold uppercase tracking-wide text-neutral-900">Pay your deposit</h3>

      <dl className="mt-3 space-y-1.5 text-sm">
        <div className="flex items-center justify-between">
          <dt className="text-neutral-500">Security deposit</dt>
          <dd className="font-semibold text-neutral-900">{money(status.breakdown.security_deposit)}</dd>
        </div>
        <div className="flex items-center justify-between">
          <dt className="text-neutral-500">Tracking device fee</dt>
          <dd className="font-semibold text-neutral-900">{money(status.breakdown.tracking_device_fee)}</dd>
        </div>
        <div className="flex items-center justify-between border-b border-neutral-100 pb-1.5">
          <dt className="text-neutral-500">First month&rsquo;s payment</dt>
          <dd className="font-semibold text-neutral-900">{money(status.breakdown.first_month_payment)}</dd>
        </div>
        <div className="flex items-center justify-between pt-0.5">
          <dt className="font-semibold text-neutral-700">Total due</dt>
          <dd className="font-heading text-base font-bold text-neutral-900">{money(status.amount_due)}</dd>
        </div>
      </dl>

      <div className="mt-4">
        {payment?.status === "paid" ? (
          <p className="flex items-center gap-1.5 text-sm font-semibold text-green-700">
            <CheckCircleIcon className="h-4 w-4 shrink-0" />
            Paid {payment.paid_date ? `on ${new Date(payment.paid_date).toLocaleDateString()}` : ""}
          </p>
        ) : payment?.status === "pending" ? (
          <p className="flex items-center gap-1.5 text-sm font-medium text-amber-700">
            <ClockIcon className="h-4 w-4 shrink-0" />
            {payment.method === "ach"
              ? "Your bank payment is processing — this usually clears in 1-4 business days. We'll let you know once it's confirmed."
              : "Processing…"}
          </p>
        ) : (
          <>
            {payment?.status === "failed" && (
              <p className="mb-2 flex items-center gap-1.5 text-sm font-medium text-red-600">
                <AlertCircleIcon className="h-4 w-4 shrink-0" />
                That payment didn&rsquo;t go through. You can try again below.
              </p>
            )}
            <button
              onClick={handleCharge}
              disabled={charging || !status.chargeable_method}
              className="font-heading w-full rounded-md bg-red-600 py-3 text-sm font-bold text-white hover:bg-red-700 disabled:cursor-not-allowed disabled:opacity-40"
            >
              {charging ? "Processing…" : `Pay ${money(status.amount_due)} now`}
            </button>
            {!status.chargeable_method && (
              <p className="mt-2 text-xs text-neutral-400">Add a bank account or card above before paying.</p>
            )}
            {error && <p className="mt-2 text-xs text-red-600">{error}</p>}
          </>
        )}
      </div>
    </div>
  );
}
