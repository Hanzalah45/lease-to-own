"use client";

import { useState, type ReactNode } from "react";
import { getStripe } from "@/lib/stripe-client";
import { formatDateOnly } from "@/lib/dates";
import { ApiError } from "@/lib/api";
import { money } from "@/components/applications/wizard/types";
import type {
  ChargeChoice,
  ChargeDepositResult,
  DepositPaymentStatus,
  DepositPaymentSummary,
  PayMethod,
} from "@/lib/deposit-payment";
import { AlertCircleIcon, CheckCircleIcon, ClockIcon } from "@/components/icons";

interface DepositPaymentCardProps {
  status: DepositPaymentStatus;
  onStatusChange: (status: DepositPaymentStatus) => void;
  onChargeDeposit: (choice: ChargeChoice) => Promise<ChargeDepositResult>;
  onChargeBalance: (choice: ChargeChoice) => Promise<ChargeDepositResult>;
  onRefreshStatus: () => Promise<DepositPaymentStatus>;
}

type ChargeKind = "deposit" | "balance" | "full";

const METHOD_LABEL: Record<PayMethod, string> = { bank: "Bank account", card: "Card" };

function errorMessage(err: unknown, fallback: string): string {
  if (err instanceof ApiError) return err.message;
  if (err instanceof Error) return err.message;
  return fallback;
}

function sleep(ms: number): Promise<void> {
  return new Promise((resolve) => setTimeout(resolve, ms));
}

function cents(amount: number): number {
  return Math.round(amount * 100);
}

function chargedTotal(payment: DepositPaymentSummary): number {
  return Number(payment.amount) + Number(payment.card_fee_amount ?? 0);
}

/**
 * Real Stripe charging for the deposit and, separately, the tracking-fee +
 * first-month "pickup balance" (client, Joel, 2026-10-01 / split 2026-10-02
 * so "pay deposit only" can defer the latter until the customer is ready for
 * pickup). Dual pricing (2026-10-05): the customer picks bank or card for
 * each payment and sees both prices first; card costs more. Shared by both
 * the guest signed-link page and the authenticated customer portal page, same
 * reasoning as AutopaySetupCard: the Stripe confirmation/polling logic is
 * intricate enough that duplicating it twice would be a real bug risk.
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
  const [chosen, setChosen] = useState<PayMethod | null>(null);

  // The selected method defaults to the customer's AutoPay primary and must
  // always be one that is actually on file.
  const method: PayMethod | null =
    chosen && status.available_methods.includes(chosen)
      ? chosen
      : status.chargeable_method && status.available_methods.includes(status.chargeable_method)
        ? status.chargeable_method
        : (status.available_methods[0] ?? null);

  /**
   * Charges one piece (deposit or balance), confirms 3DS/polls an ACH charge
   * to resolution, and returns the resulting payment's final status. Reused
   * by both the single-charge buttons and "pay in full"'s sequencing below —
   * each charge is always its own independent PaymentIntent (see
   * StripeDepositPaymentService), never combined into one.
   */
  async function chargeAndResolve(
    chargeFn: (choice: ChargeChoice) => Promise<ChargeDepositResult>,
    choice: ChargeChoice,
    select: (s: DepositPaymentStatus) => DepositPaymentSummary | null,
  ): Promise<string | undefined> {
    const result = await chargeFn(choice);

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

  async function run(kind: ChargeKind, work: (m: PayMethod) => Promise<void>) {
    if (!method) return;
    setError(null);
    setCharging(kind);
    try {
      await work(method);
    } catch (err) {
      // 409: the price or method changed since this screen loaded. Reload it
      // so the customer sees the new total before deciding again.
      if (err instanceof ApiError && err.status === 409) {
        onStatusChange(await onRefreshStatus().catch(() => status));
      }
      setError(errorMessage(err, "Could not process the payment. Please try again."));
    } finally {
      setCharging(null);
    }
  }

  const depositChoice = (m: PayMethod): ChargeChoice => ({ method: m, expectedTotalCents: cents(status.prices.deposit[m]) });
  const balanceChoice = (m: PayMethod): ChargeChoice => ({ method: m, expectedTotalCents: cents(status.prices.pickup_balance[m]) });

  const handlePayDepositOnly = () =>
    run("deposit", async (m) => {
      await chargeAndResolve(onChargeDeposit, depositChoice(m), (s) => s.security_deposit.payment);
    });

  const handlePayBalanceOnly = () =>
    run("balance", async (m) => {
      await chargeAndResolve(onChargeBalance, balanceChoice(m), (s) => s.pickup_balance.payment);
    });

  const handlePayInFull = () =>
    run("full", async (m) => {
      const depositOutcome = await chargeAndResolve(onChargeDeposit, depositChoice(m), (s) => s.security_deposit.payment);
      if (depositOutcome !== "paid") {
        setError("The deposit payment didn't go through. The remaining balance was not charged.");
        return;
      }
      await chargeAndResolve(onChargeBalance, balanceChoice(m), (s) => s.pickup_balance.payment);
    });

  const depositDone = status.security_deposit.received || status.security_deposit.payment?.status === "paid";
  const balanceDone = status.pickup_balance.received || status.pickup_balance.payment?.status === "paid";

  if (depositDone && balanceDone) {
    return null;
  }

  const busy = charging !== null;
  const { prices } = status;
  const feePercent = prices.card_fee_percent;
  const selected = method ?? "bank";

  return (
    <div className="rounded-xl border border-neutral-200 bg-white p-5">
      <h3 className="font-heading text-sm font-bold uppercase tracking-wide text-neutral-900">Pay your deposit</h3>

      <div className="mt-3 text-sm">
        <div className="grid grid-cols-[1fr_auto_auto] gap-x-5 gap-y-1.5">
          <span />
          <span className="text-right text-xs font-bold uppercase tracking-wide text-neutral-500">Bank (ACH)</span>
          <span className="text-right text-xs font-bold uppercase tracking-wide text-neutral-500">Card</span>

          <span className="text-neutral-500">Security deposit</span>
          <span className="text-right font-semibold text-neutral-900">{money(prices.deposit.bank)}</span>
          <span className="text-right font-semibold text-neutral-900">{money(prices.deposit.card)}</span>

          <span className="text-neutral-500">
            Tracking fee ({money(status.pickup_balance.breakdown.tracking_device_fee)}) + first month
          </span>
          <span className="text-right font-semibold text-neutral-900">{money(prices.pickup_balance.bank)}</span>
          <span className="text-right font-semibold text-neutral-900">{money(prices.pickup_balance.card)}</span>

          <span className="border-t border-neutral-100 pt-1.5 font-semibold text-neutral-700">Total due</span>
          <span className="font-heading border-t border-neutral-100 pt-1.5 text-right text-base font-bold text-neutral-900">{money(prices.full.bank)}</span>
          <span className="font-heading border-t border-neutral-100 pt-1.5 text-right text-base font-bold text-neutral-900">{money(prices.full.card)}</span>
        </div>
        <p className="mt-2 text-xs text-neutral-400">
          Paying by card costs {feePercent}% more than paying by bank. The difference is a card processing fee.
        </p>
      </div>

      {status.available_methods.length > 1 && !(depositDone && balanceDone) && (
        <fieldset className="mt-4">
          <legend className="mb-1.5 text-xs font-bold uppercase tracking-wide text-neutral-500">Pay with</legend>
          <div className="flex gap-3">
            {status.available_methods.map((m) => (
              <label
                key={m}
                className={`flex flex-1 cursor-pointer items-center gap-2 rounded-md border px-3.5 py-2.5 text-sm font-semibold ${
                  selected === m ? "border-red-600 bg-red-50 text-red-700" : "border-neutral-300 text-neutral-700 hover:bg-neutral-50"
                }`}
              >
                <input
                  type="radio"
                  name="deposit-pay-method"
                  checked={selected === m}
                  disabled={busy}
                  onChange={() => setChosen(m)}
                  className="accent-red-600"
                />
                <span>
                  {METHOD_LABEL[m]}
                  <span className="block text-xs font-normal text-neutral-500">{m === "card" ? `${feePercent}% card fee` : "No fee"}</span>
                </span>
              </label>
            ))}
          </div>
        </fieldset>
      )}

      {!depositDone && (
        <StatusOrActions label="Security deposit" payment={status.security_deposit.payment}>
          <div className="mt-3 space-y-2">
            <button
              onClick={handlePayInFull}
              disabled={busy || !method}
              className="font-heading w-full rounded-md bg-red-600 py-3 text-sm font-bold text-white hover:bg-red-700 disabled:cursor-not-allowed disabled:opacity-40"
            >
              {charging === "full" ? "Processing…" : `Pay in full: ${money(prices.full[selected])}`}
            </button>
            <p className="text-center text-xs text-neutral-400">Covers the deposit, tracking fee, and first month. Pick up anytime.</p>
            <button
              onClick={handlePayDepositOnly}
              disabled={busy || !method}
              className="font-heading w-full rounded-md border border-neutral-300 py-3 text-sm font-bold text-neutral-700 hover:bg-neutral-50 disabled:cursor-not-allowed disabled:opacity-40"
            >
              {charging === "deposit" ? "Processing…" : `Pay deposit only: ${money(prices.deposit[selected])}`}
            </button>
            <p className="text-center text-xs text-neutral-400">
              Holds your lease now. Pay the tracking fee and first month ({money(prices.pickup_balance[selected])}) later, whenever
              you&rsquo;re ready for pickup.
            </p>
          </div>
        </StatusOrActions>
      )}

      {depositDone && status.security_deposit.payment?.status === "paid" && (
        <StatusOrActions label="Security deposit" payment={status.security_deposit.payment}>
          {null}
        </StatusOrActions>
      )}

      {depositDone && !balanceDone && (
        <StatusOrActions label="Tracking fee & first month" payment={status.pickup_balance.payment}>
          <button
            onClick={handlePayBalanceOnly}
            disabled={busy || !method}
            className="font-heading mt-3 w-full rounded-md bg-red-600 py-3 text-sm font-bold text-white hover:bg-red-700 disabled:cursor-not-allowed disabled:opacity-40"
          >
            {charging === "balance" ? "Processing…" : `Pay remaining balance: ${money(prices.pickup_balance[selected])}`}
          </button>
          <p className="mt-2 text-center text-xs text-neutral-400">Due whenever you&rsquo;re ready to pick up your equipment.</p>
        </StatusOrActions>
      )}

      {!method && <p className="mt-2 text-xs text-neutral-400">Add a bank account or card above before paying.</p>}
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
    const fee = Number(payment.card_fee_amount ?? 0);
    return (
      <p className="mt-4 flex items-center gap-1.5 text-sm font-semibold text-green-700">
        <CheckCircleIcon className="h-4 w-4 shrink-0" />
        <span>
          {label} paid: {money(chargedTotal(payment))}
          {payment.paid_date ? ` on ${formatDateOnly(payment.paid_date)}` : ""}
          {fee > 0 ? ` (includes a ${money(fee)} card fee)` : ""}
        </span>
      </p>
    );
  }

  if (payment?.status === "pending") {
    return (
      <p className="mt-4 flex items-center gap-1.5 text-sm font-medium text-amber-700">
        <ClockIcon className="h-4 w-4 shrink-0" />
        {payment.method === "ach"
          ? "Your bank payment is processing. This usually clears in 1-4 business days. We'll let you know once it's confirmed."
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
