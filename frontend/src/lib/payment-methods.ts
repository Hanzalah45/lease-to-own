import { apiFetch } from "@/lib/api";
import { getToken } from "@/lib/auth";

export type AutopayMethodType = "card" | "bank";

export interface PaymentMethodsStatus {
  bank_account_added: boolean;
  card_added: boolean;
  autopay_primary_method: "ach" | "card" | null;
  /** Dual pricing (client, 2026-10-05): the monthly payment by bank vs by card. Absent on a few admin-only responses. */
  monthly_prices?: { bank: number; card: number; card_fee: number; card_fee_percent: number };
}

/** Only present on the guest signed-link show() response — see PublicPaymentMethodController::show(). */
export interface SignedPaymentMethodsStatus extends PaymentMethodsStatus {
  customer_name: string;
  customer_email: string;
}

/** The signed params carried by a "set up AutoPay" email link — see PaymentMethodSigner on the backend. */
export interface SignedPaymentMethodLinkParams {
  id: string;
  lease: string;
  hash: string;
  expires: string;
  signature: string;
}

export async function getSignedPaymentMethodsStatus(params: SignedPaymentMethodLinkParams): Promise<SignedPaymentMethodsStatus> {
  const data = await apiFetch<{ data: SignedPaymentMethodsStatus }>("/payment-methods/verify-show", {
    method: "POST",
    body: params,
  });
  return data.data;
}

export async function createSignedSetupIntent(
  params: SignedPaymentMethodLinkParams,
  type: AutopayMethodType,
): Promise<string> {
  const data = await apiFetch<{ data: { client_secret: string } }>("/payment-methods/verify-setup-intent", {
    method: "POST",
    body: { ...params, type },
  });
  return data.data.client_secret;
}

export async function confirmSignedPaymentMethod(
  params: SignedPaymentMethodLinkParams,
  type: AutopayMethodType,
  setupIntentId: string,
): Promise<PaymentMethodsStatus> {
  const data = await apiFetch<{ data: PaymentMethodsStatus }>("/payment-methods/verify-confirm", {
    method: "POST",
    body: { ...params, type, setup_intent_id: setupIntentId },
  });
  return data.data;
}

export async function setSignedPrimaryMethod(
  params: SignedPaymentMethodLinkParams,
  type: AutopayMethodType,
): Promise<PaymentMethodsStatus> {
  const data = await apiFetch<{ data: PaymentMethodsStatus }>("/payment-methods/verify-primary", {
    method: "POST",
    body: { ...params, type },
  });
  return data.data;
}

export async function getPaymentMethodsStatus(leaseAgreementId: number): Promise<PaymentMethodsStatus> {
  const data = await apiFetch<{ data: PaymentMethodsStatus }>(`/customer/lease-agreements/${leaseAgreementId}/payment-methods`, {
    token: getToken(),
  });
  return data.data;
}

export async function createSetupIntent(leaseAgreementId: number, type: AutopayMethodType): Promise<string> {
  const data = await apiFetch<{ data: { client_secret: string } }>(
    `/customer/lease-agreements/${leaseAgreementId}/payment-methods/setup-intent`,
    { method: "POST", token: getToken(), body: { type } },
  );
  return data.data.client_secret;
}

export async function confirmPaymentMethod(
  leaseAgreementId: number,
  type: AutopayMethodType,
  setupIntentId: string,
): Promise<PaymentMethodsStatus> {
  const data = await apiFetch<{ data: PaymentMethodsStatus }>(
    `/customer/lease-agreements/${leaseAgreementId}/payment-methods/confirm`,
    { method: "POST", token: getToken(), body: { type, setup_intent_id: setupIntentId } },
  );
  return data.data;
}

export async function setPrimaryMethod(leaseAgreementId: number, type: AutopayMethodType): Promise<PaymentMethodsStatus> {
  const data = await apiFetch<{ data: PaymentMethodsStatus }>(
    `/customer/lease-agreements/${leaseAgreementId}/payment-methods/primary`,
    { method: "POST", token: getToken(), body: { type } },
  );
  return data.data;
}

/** Admin-only: clears a lease's stored payment method for one type so the customer can be sent a fresh link to re-add it. */
export async function adminClearPaymentMethod(leaseAgreementId: number, type: AutopayMethodType): Promise<PaymentMethodsStatus> {
  const data = await apiFetch<{ data: PaymentMethodsStatus }>(
    `/admin/lease-agreements/${leaseAgreementId}/payment-methods/clear`,
    { method: "POST", token: getToken(), body: { type } },
  );
  return data.data;
}
