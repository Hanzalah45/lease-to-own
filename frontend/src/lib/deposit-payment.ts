import { apiFetch } from "@/lib/api";
import { getToken } from "@/lib/auth";
import type { SignedPaymentMethodLinkParams } from "@/lib/payment-methods";

export interface DepositPaymentSummary {
  id: number;
  status: "pending" | "paid" | "failed";
  method: string | null;
  amount: string;
  paid_date: string | null;
  created_at: string;
}

/**
 * "Pay deposit only" (client, Joel, 2026-10-02) splits what used to be one
 * bundled deposit charge into two independently chargeable pieces — the
 * security deposit now, the tracking fee + first month deferred until the
 * customer is ready for pickup.
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
  chargeable_method: "bank" | "card" | null;
}

export interface ChargeDepositResult {
  payment: { id: number; status: string; method: string | null };
  requires_action: boolean;
  client_secret: string | null;
}

export async function getDepositPaymentStatus(leaseAgreementId: number): Promise<DepositPaymentStatus> {
  const data = await apiFetch<{ data: DepositPaymentStatus }>(`/customer/lease-agreements/${leaseAgreementId}/deposit-payment`, {
    token: getToken(),
  });
  return data.data;
}

export async function chargeDepositPayment(leaseAgreementId: number): Promise<ChargeDepositResult> {
  const data = await apiFetch<{ data: ChargeDepositResult }>(
    `/customer/lease-agreements/${leaseAgreementId}/deposit-payment/charge`,
    { method: "POST", token: getToken() },
  );
  return data.data;
}

export async function chargeBalancePayment(leaseAgreementId: number): Promise<ChargeDepositResult> {
  const data = await apiFetch<{ data: ChargeDepositResult }>(
    `/customer/lease-agreements/${leaseAgreementId}/deposit-payment/charge-balance`,
    { method: "POST", token: getToken() },
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

export async function chargeSignedDepositPayment(params: SignedPaymentMethodLinkParams): Promise<ChargeDepositResult> {
  const data = await apiFetch<{ data: ChargeDepositResult }>("/deposit-payments/verify-charge", {
    method: "POST",
    body: params,
  });
  return data.data;
}

export async function chargeSignedBalancePayment(params: SignedPaymentMethodLinkParams): Promise<ChargeDepositResult> {
  const data = await apiFetch<{ data: ChargeDepositResult }>("/deposit-payments/verify-charge-balance", {
    method: "POST",
    body: params,
  });
  return data.data;
}
