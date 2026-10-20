import { apiFetch } from "@/lib/api";
import { getToken } from "@/lib/auth";

export type AutopayMethodType = "card" | "bank";

/** What the customer is shown next to the AutoPay tick box (client, Joel, 2026-10-20). */
export interface AutopayOffer {
  /** The version of the words below; sent back when the box is ticked so the words agreed to are the words that were on screen. */
  version: number;
  wording: string;
  title: string;
  amounts: { bank: number; card: number; card_fee_percent: number };
  billing_cycle: string;
  /** The first payment AutoPay would charge, when there is one scheduled. */
  first_payment: { due_date: string; bank: number; card: number } | null;
  terms: { heading: string | null; text: string }[];
}

/** Where a bank account stands while it waits for its deposits: when they should arrive, and Stripe's page for confirming them. */
export interface PendingBank {
  since: string;
  arrives_by: string | null;
  /** amounts: two deposits, enter their amounts. descriptor_code: one deposit, enter the code in its description. */
  kind: "amounts" | "descriptor_code";
  verify_url: string | null;
}

/** What Stripe said when the customer asked whether the deposits were confirmed. */
export interface PendingBankCheck {
  status: PaymentMethodsStatus;
  result: { state: "verified" | "waiting" | "failed"; message: string };
}

export interface PaymentMethodsStatus {
  /** False when the lease has AutoPay off: the saved methods are then only what the customer pays with. Absent on a few admin-only responses (treated as on). */
  autopay_enabled?: boolean;
  bank_account_added: boolean;
  /** A bank account typed in by hand that is waiting for its two small test deposits to be confirmed (client, Joel, 2026-10-19 checklist, section 2). Not yet chargeable. */
  bank_pending?: PendingBank | null;
  card_added: boolean;
  /** A locked method is still on file but cannot be used: the customer is told to contact us, never why (see MethodLockController). */
  bank_locked?: boolean;
  card_locked?: boolean;
  /** A request to turn AutoPay off that is waiting for its day (three business days after it was made); AutoPay keeps charging until then. */
  autopay_revocation?: { requested_at: string; effective_on: string } | null;
  can_revoke_autopay?: boolean;
  autopay_notice_days?: number;
  autopay_primary_method: "ach" | "card" | null;
  /** The AutoPay tick box and its words; null when it is not offered (AutoPay already on, or no saved method yet). */
  autopay_offer?: AutopayOffer | null;
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

/** The customer ticks the AutoPay box (logged in). */
export async function agreeToAutopay(leaseAgreementId: number, wordingVersion: number): Promise<PaymentMethodsStatus> {
  const data = await apiFetch<{ data: PaymentMethodsStatus }>(`/customer/lease-agreements/${leaseAgreementId}/autopay-agreement`, {
    method: "POST",
    token: getToken(),
    body: { accepted: true, wording_version: wordingVersion },
  });
  return data.data;
}

/** The customer asks to turn AutoPay off: it takes effect three business days later (the AutoPay authorization's notice). */
export async function revokeAutopay(leaseAgreementId: number): Promise<PaymentMethodsStatus> {
  const data = await apiFetch<{ data: PaymentMethodsStatus }>(`/customer/lease-agreements/${leaseAgreementId}/autopay/revoke`, {
    method: "POST",
    token: getToken(),
    body: { confirm: true },
  });
  return data.data;
}

/** The customer keeps AutoPay on after asking to turn it off, before the day it would have stopped. */
export async function keepAutopay(leaseAgreementId: number): Promise<PaymentMethodsStatus> {
  const data = await apiFetch<{ data: PaymentMethodsStatus }>(`/customer/lease-agreements/${leaseAgreementId}/autopay/keep`, {
    method: "POST",
    token: getToken(),
  });
  return data.data;
}

/** The same, for a guest-originated customer through the emailed signed link. */
export async function agreeToAutopaySigned(params: SignedPaymentMethodLinkParams, wordingVersion: number): Promise<PaymentMethodsStatus> {
  const data = await apiFetch<{ data: PaymentMethodsStatus }>("/payment-methods/verify-autopay", {
    method: "POST",
    body: { ...params, accepted: true, wording_version: wordingVersion },
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

/** The customer removes a saved bank account or card. It also comes off their Stripe profile. The server refuses, in words for the customer, when removing it would break something. */
export async function removePaymentMethod(leaseAgreementId: number, type: AutopayMethodType): Promise<PaymentMethodsStatus> {
  const data = await apiFetch<{ data: PaymentMethodsStatus }>(`/customer/lease-agreements/${leaseAgreementId}/payment-methods/remove`, {
    method: "POST",
    token: getToken(),
    body: { type, confirm: true },
  });
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

// ---- a bank account that needs two small test deposits ----

/** The bank account was typed in by hand and Stripe sent the deposits: the account waits until they are confirmed. */
export async function startBankPending(leaseAgreementId: number, setupIntentId: string): Promise<PaymentMethodsStatus> {
  const data = await apiFetch<{ data: PaymentMethodsStatus }>(`/customer/lease-agreements/${leaseAgreementId}/payment-methods/bank-pending`, {
    method: "POST",
    token: getToken(),
    body: { setup_intent_id: setupIntentId },
  });
  return data.data;
}

/** "I confirmed the deposits": asks Stripe, and the account is saved if it is confirmed. */
export async function checkBankPending(leaseAgreementId: number): Promise<PendingBankCheck> {
  const data = await apiFetch<{ data: PaymentMethodsStatus; result: PendingBankCheck["result"] }>(
    `/customer/lease-agreements/${leaseAgreementId}/payment-methods/bank-pending/check`,
    { method: "POST", token: getToken() },
  );
  return { status: data.data, result: data.result };
}

export async function cancelBankPending(leaseAgreementId: number): Promise<PaymentMethodsStatus> {
  const data = await apiFetch<{ data: PaymentMethodsStatus }>(`/customer/lease-agreements/${leaseAgreementId}/payment-methods/bank-pending/cancel`, {
    method: "POST",
    token: getToken(),
  });
  return data.data;
}

export async function startBankPendingSigned(params: SignedPaymentMethodLinkParams, setupIntentId: string): Promise<PaymentMethodsStatus> {
  const data = await apiFetch<{ data: PaymentMethodsStatus }>("/payment-methods/verify-bank-pending", {
    method: "POST",
    body: { ...params, setup_intent_id: setupIntentId },
  });
  return data.data;
}

export async function checkBankPendingSigned(params: SignedPaymentMethodLinkParams): Promise<PendingBankCheck> {
  const data = await apiFetch<{ data: PaymentMethodsStatus; result: PendingBankCheck["result"] }>("/payment-methods/verify-bank-pending-check", {
    method: "POST",
    body: params,
  });
  return { status: data.data, result: data.result };
}

export async function cancelBankPendingSigned(params: SignedPaymentMethodLinkParams): Promise<PaymentMethodsStatus> {
  const data = await apiFetch<{ data: PaymentMethodsStatus }>("/payment-methods/verify-bank-pending-cancel", { method: "POST", body: params });
  return data.data;
}
