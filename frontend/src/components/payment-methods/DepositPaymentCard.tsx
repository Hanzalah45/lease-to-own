"use client";

import { useState, type ReactNode } from "react";
import { getStripe } from "@/lib/stripe-client";
import { ApiError } from "@/lib/api";
import { money } from "@/components/applications/wizard/types";
import type { ChargeDepositResult, DepositPaymentStatus, DepositPaymentSummary } from "@/lib/deposit-payment";
import { AlertCircleIcon, CheckCircleIcon, ClockIcon } from "@/components/icons";

interface DepositPaymentCardProps {
  status: DepositPaymentStatus;
  onStatusChange: (status: DepositPaymentStatus) => void;
  onChargeDeposit: () => Promise<ChargeDepositResult>;
  onChargeBalance: () => Promise<ChargeDepositResult>;
  onRefreshStatus: () => Promise<DepositPaymentStatus>;
}

type ChargeKind = "deposit" | "balance" | "full";

function errorMessage(err: unknown, fallback: string): string {
  if (err instanceof ApiError) return err.message;
  if (err instanceof Error) return err.message;
  return fallback;
}

function sleep(ms: number): Promise<void> {
  return new Promise((resolve) => setTimeout(resolve, ms));
}

/**
 * Real Stripe charging for the deposit and, separately, the tracking-fee +
 * first-month "pickup balance" (client, Joel, 2026-10-01 / split 2026-10-02
 * so "pay deposit only" can defer the latter until the customer is ready for
 * pickup). Shared by both the guest signed-link page and the authenticated
 * customer portal page, same reasoning as AutopaySetupCard: the Stripe
 * confirmation/polling logic is intricate enough that duplicating it twice
 * would be a real bug risk.
 */
export function DepositPaymentCard({
  status,
  onStatusChange,
  onChargeDeposit,
  onChargeBalance,
  onRefreshStatus,
}: DepositPaymentCardProps) {
  const [charging, setCharging] = useState<ChargeKind | null>(null);
  const [error, setError] = useState<string | null>(null);

  /**
   * Charges one piece (deposit or balance), confirms 3DS/polls an ACH charge
   * to resolution, and returns the resulting payment's final status. Reused
   * by both the single-charge buttons and "pay in full"'s sequencing below —
   * each charge is always its own independent PaymentIntent (see
   * StripeDepositPaymentService), never combined into one.
   */
  async function chargeAndResolve(
    chargeFn: () => Promise<ChargeDepositResult>,
    select: (s: DepositPaymentStatus) => DepositPaymentSummary | null,
  ): Promise<string | undefined> {
    const result = await chargeFn();

    if (result.requires_action && result.client_secret) {
      const stripe = await getStripe();
      if (!stripe) throw new Error("Stripe failed to load. Please refresh and try again.");
      const { error: confirmError } = await stripe.confirmCardPayment(result.client_secret);
      if (confirmError) throw new Error(confirmError.message ?? "Could not confirm the payment.");
    }

    let next = await onRefreshStatus();
    onStatusChange(next);
    let current = select(next);

    for (let attempt = 0; current?.status === "pending" && attempt < 5; attempt++) {
      await sleep(2500);
      next = await onRefreshStatus();
      onStatusChange(next);
      current = select(next);
    }

    return current?.status ?? result.payment.status;
  }

  async function handlePayDepositOnly() {
    setError(null);
    setCharging("deposit");
    try {
      await chargeAndResolve(onChargeDeposit, (s) => s.security_deposit.payment);
    } catch (err) {
      setError(errorMessage(err, "Could not process the deposit payment. Please try again."));
    } finally {
      setCharging(null);
    }
  }

  async function handlePayBalanceOnly() {
    setError(null);
    setCharging("balance");
    try {
      await chargeAndResolve(onChargeBalance, (s) => s.pickup_balance.payment);
    } catch (err) {
      setError(errorMessage(err, "Could not process the payment. Please try again."));
    } finally {
      setCharging(null);
    }
  }

  async function handlePayInFull() {
    setError(null);
    setCharging("full");
    try {
      const depositOutcome = await chargeAndResolve(onChargeDeposit, (s) => s.security_deposit.payment);
      if (depositOutcome !== "paid") {
        setError("The deposit payment didn't go through. The remaining balance was not charged.");
        return;
      }
      await chargeAndResolve(onChargeBalance, (s) => s.pickup_balance.payment);
    } catch (err) {
      setError(errorMessage(err, "Could not process the payment. Please try again."));
    } finally {
      setCharging(null);
    }
  }

  const depositDone = status.security_deposit.received || status.security_deposit.payment?.status === "paid";
  const balanceDone = status.pickup_balance.received || status.pickup_balance.payment?.status === "paid";

  if (depositDone && balanceDone) {
    return null;
  }

  const busy = charging !== null;

  return (
    <div className="rounded-xl border border-neutral-200 bg-white p-5">
      <h3 className="font-heading text-sm font-bold uppercase tracking-wide text-neutral-900">Pay your deposit</h3>

      <dl className="mt-3 space-y-1.5 text-sm">
        <div className="flex items-center justify-between">
          <dt className="text-neutral-500">Security deposit</dt>
          <dd className="font-semibold text-neutral-900">{money(status.security_deposit.amount)}</dd>
        </div>
        <div className="flex items-center justify-between">
          <dt className="text-neutral-500">Tracking device fee</dt>
          <dd className="font-semibold text-neutral-900">{money(status.pickup_balance.breakdown.tracking_device_fee)}</dd>
        </div>
        <div className="flex items-center justify-between border-b border-neutral-100 pb-1.5">
          <dt className="text-neutral-500">First month&rsquo;s payment</dt>
          <dd className="font-semibold text-neutral-900">{money(status.pickup_balance.breakdown.first_month_payment)}</dd>
        </div>
        <div className="flex items-center justify-between pt-0.5">
          <dt className="font-semibold text-neutral-700">Total due</dt>
          <dd className="font-heading text-base font-bold text-neutral-900">{money(status.amount_due_full)}</dd>
        </div>
      </dl>

      {!depositDone && (
        <StatusOrActions label="Security deposit" payment={status.security_deposit.payment}>
          <div className="mt-3 space-y-2">
            <button
              onClick={handlePayInFull}
              disabled={busy || !status.chargeable_method}
              className="font-heading w-full rounded-md bg-red-600 py-3 text-sm font-bold text-white hover:bg-red-700 disabled:cursor-not-allowed disabled:opacity-40"
            >
              {charging === "full" ? "Processing…" : `Pay in full: ${money(status.amount_due_full)}`}
            </button>
            <p className="text-center text-xs text-neutral-400">Covers the deposit, tracking fee, and first month. Pick up anytime.</p>
            <button
              onClick={handlePayDepositOnly}
              disabled={busy || !status.chargeable_method}
              className="font-heading w-full rounded-md border border-neutral-300 py-3 text-sm font-bold text-neutral-700 hover:bg-neutral-50 disabled:cursor-not-allowed disabled:opacity-40"
            >
              {charging === "deposit" ? "Processing…" : `Pay deposit only: ${money(status.security_deposit.amount)}`}
            </button>
            <p className="text-center text-xs text-neutral-400">
              Holds your lease now. Pay the ${status.pickup_balance.amount.toFixed(0)} tracking fee and first month later, whenever
              you&rsquo;re ready for pickup.
            </p>
          </div>
        </StatusOrActions>
      )}

      {depositDone && !balanceDone && (
        <StatusOrActions label="Tracking fee & first month" payment={status.pickup_balance.payment}>
          <button
            onClick={handlePayBalanceOnly}
            disabled={busy || !status.chargeable_method}
            className="font-heading mt-3 w-full rounded-md bg-red-600 py-3 text-sm font-bold text-white hover:bg-red-700 disabled:cursor-not-allowed disabled:opacity-40"
          >
            {charging === "balance" ? "Processing…" : `Pay remaining balance: ${money(status.pickup_balance.amount)}`}
          </button>
          <p className="mt-2 text-center text-xs text-neutral-400">Due whenever you&rsquo;re ready to pick up your equipment.</p>
        </StatusOrActions>
      )}

      {!status.chargeable_method && <p className="mt-2 text-xs text-neutral-400">Add a bank account or card above before paying.</p>}
      {error && <p className="mt-2 text-xs text-red-600">{error}</p>}
    </div>
  );
}

function StatusOrActions({
  label,
  payment,
  children,
}: {
  label: string;
  payment: DepositPaymentSummary | null;
  children: ReactNode;
}) {
  if (payment?.status === "paid") {
    return (
      <p className="mt-4 flex items-center gap-1.5 text-sm font-semibold text-green-700">
        <CheckCircleIcon className="h-4 w-4 shrink-0" />
        {label} paid {payment.paid_date ? `on ${new Date(payment.paid_date).toLocaleDateString()}` : ""}
      </p>
    );
  }

  if (payment?.status === "pending") {
    return (
      <p className="mt-4 flex items-center gap-1.5 text-sm font-medium text-amber-700">
        <ClockIcon className="h-4 w-4 shrink-0" />
        {payment.method === "ach"
          ? "Your bank payment is processing — this usually clears in 1-4 business days. We'll let you know once it's confirmed."
          : "Processing…"}
      </p>
    );
  }

  return (
    <div>
      {payment?.status === "failed" && (
        <p className="mt-4 flex items-center gap-1.5 text-sm font-medium text-red-600">
          <AlertCircleIcon className="h-4 w-4 shrink-0" />
          That payment didn&rsquo;t go through. You can try again below.
        </p>
      )}
      {children}
    </div>
  );
}
