import { apiFetch } from "@/lib/api";
import { getToken } from "@/lib/auth";
import type { SignedPaymentMethodLinkParams } from "@/lib/payment-methods";
import type { PricingSummary } from "@/types/lease-agreement";

export interface DepositPaymentSummary {
  id: number;
  status: "pending" | "paid" | "failed";
  method: string | null;
  /** The bank price. What was actually charged is amount + card_fee_amount. */
  amount: string;
  card_fee_amount: string;
  paid_date: string | null;
  created_at: string;
}

export type PayMethod = "bank" | "card";

/**
 * "Pay deposit only" (client, Joel, 2026-10-02) splits what used to be one
 * bundled deposit charge into two independently chargeable pieces — the
 * security deposit now, the tracking fee + first month deferred until the
 * customer is ready for pickup. Dual pricing (2026-10-05): every piece costs
 * more by card than by bank, shown in `prices`.
 */
export interface DepositPaymentStatus {
  security_deposit: {
    amount: number;
    payment: DepositPaymentSummary | null;
    received: boolean;
  };
  pickup_balance: {
    amount: number;
    breakdown: {
      tracking_device_fee: number;
      first_month_payment: number;
    };
    payment: DepositPaymentSummary | null;
    received: boolean;
  };
  amount_due_full: number;
  chargeable_method: PayMethod | null;
  /** Which saved methods the customer can actually pick from. */
  available_methods: PayMethod[];
  prices: PricingSummary;
}

export interface ChargeDepositResult {
  payment: { id: number; status: string; method: string | null };
  requires_action: boolean;
  client_secret: string | null;
}

/** What the customer chose and the exact total they were shown: the server refuses (409) if either no longer matches. */
export interface ChargeChoice {
  method: PayMethod;
  expectedTotalCents: number;
}

function choiceBody(choice?: ChargeChoice): Record<string, unknown> {
  return choice ? { method: choice.method, expected_total_cents: choice.expectedTotalCents } : {};
}

export async function getDepositPaymentStatus(leaseAgreementId: number): Promise<DepositPaymentStatus> {
  const data = await apiFetch<{ data: DepositPaymentStatus }>(`/customer/lease-agreements/${leaseAgreementId}/deposit-payment`, {
    token: getToken(),
  });
  return data.data;
}

export async function chargeDepositPayment(leaseAgreementId: number, choice?: ChargeChoice): Promise<ChargeDepositResult> {
  const data = await apiFetch<{ data: ChargeDepositResult }>(
    `/customer/lease-agreements/${leaseAgreementId}/deposit-payment/charge`,
    { method: "POST", token: getToken(), body: choiceBody(choice) },
  );
  return data.data;
}

export async function chargeBalancePayment(leaseAgreementId: number, choice?: ChargeChoice): Promise<ChargeDepositResult> {
  const data = await apiFetch<{ data: ChargeDepositResult }>(
    `/customer/lease-agreements/${leaseAgreementId}/deposit-payment/charge-balance`,
    { method: "POST", token: getToken(), body: choiceBody(choice) },
  );
  return data.data;
}

export async function getSignedDepositPaymentStatus(params: SignedPaymentMethodLinkParams): Promise<DepositPaymentStatus> {
  const data = await apiFetch<{ data: DepositPaymentStatus }>("/deposit-payments/verify-show", {
    method: "POST",
    body: params,
  });
  return data.data;
}

export async function chargeSignedDepositPayment(params: SignedPaymentMethodLinkParams, choice?: ChargeChoice): Promise<ChargeDepositResult> {
  const data = await apiFetch<{ data: ChargeDepositResult }>("/deposit-payments/verify-charge", {
    method: "POST",
    body: { ...params, ...choiceBody(choice) },
  });
  return data.data;
}

export async function chargeSignedBalancePayment(params: SignedPaymentMethodLinkParams, choice?: ChargeChoice): Promise<ChargeDepositResult> {
  const data = await apiFetch<{ data: ChargeDepositResult }>("/deposit-payments/verify-charge-balance", {
    method: "POST",
    body: { ...params, ...choiceBody(choice) },
  });
  return data.data;
}
