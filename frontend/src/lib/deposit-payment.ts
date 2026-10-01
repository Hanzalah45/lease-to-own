import { apiFetch } from "@/lib/api";
import { getToken } from "@/lib/auth";
import type { SignedPaymentMethodLinkParams } from "@/lib/payment-methods";

export interface DepositPaymentStatus {
  amount_due: number;
  breakdown: {
    security_deposit: number;
    tracking_device_fee: number;
    first_month_payment: number;
  };
  chargeable_method: "bank" | "card" | null;
  deposit_payment: {
    id: number;
    status: "pending" | "paid" | "failed";
    method: string | null;
    amount: string;
    paid_date: string | null;
    created_at: string;
  } | null;
  already_marked_received: boolean;
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
