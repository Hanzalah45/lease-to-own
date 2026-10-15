import { apiFetch } from "@/lib/api";
import { getToken } from "@/lib/auth";
import type { Payment } from "@/types/lease-agreement";

export async function listMyPayments(): Promise<Payment[]> {
  const data = await apiFetch<{ data: Payment[] }>("/customer/payments", { token: getToken() });
  return data.data;
}

export async function listPayments(leaseAgreementId?: number | string): Promise<Payment[]> {
  const query = leaseAgreementId ? `?lease_agreement_id=${leaseAgreementId}` : "";
  const data = await apiFetch<{ data: Payment[] }>(`/admin/payments${query}`, { token: getToken() });
  return data.data;
}

/** Staff: stop automatic charging on one lease until resumed. */
export async function pauseAutopay(leaseAgreementId: number | string): Promise<string | null> {
  const data = await apiFetch<{ data: { autopay_paused_at: string | null } }>(`/admin/lease-agreements/${leaseAgreementId}/autopay/pause`, {
    method: "POST",
    token: getToken(),
  });
  return data.data.autopay_paused_at;
}

export async function resumeAutopay(leaseAgreementId: number | string): Promise<void> {
  await apiFetch(`/admin/lease-agreements/${leaseAgreementId}/autopay/resume`, { method: "POST", token: getToken() });
}

/** Staff: charge a failed monthly payment again (a fresh round, primary method first). */
export async function retryAutopayCharge(paymentId: number | string): Promise<Payment> {
  const data = await apiFetch<{ data: { payment: Payment } }>(`/admin/payments/${paymentId}/retry-autopay`, {
    method: "POST",
    token: getToken(),
  });
  return data.data.payment;
}

/**
 * Staff: charge the security deposit to the customer's saved bank account or
 * card after the customer agreed. The server refuses (409) when the total no
 * longer matches the one shown, and when the deposit is already paid or in flight.
 */
export async function chargeDepositAsStaff(
  applicationId: number | string,
  choice: { method: "bank" | "card"; expectedTotalCents: number; customerAgreed: boolean },
): Promise<Payment> {
  return chargeAsStaff(applicationId, "charge-deposit", choice);
}

/** Staff: the same for the tracking fee and first month, which a customer who already took the mower may still owe. */
export async function chargePickupBalanceAsStaff(
  applicationId: number | string,
  choice: { method: "bank" | "card"; expectedTotalCents: number; customerAgreed: boolean },
): Promise<Payment> {
  return chargeAsStaff(applicationId, "charge-pickup-balance", choice);
}

async function chargeAsStaff(
  applicationId: number | string,
  path: "charge-deposit" | "charge-pickup-balance",
  choice: { method: "bank" | "card"; expectedTotalCents: number; customerAgreed: boolean },
): Promise<Payment> {
  const data = await apiFetch<{ data: { payment: Payment } }>(`/admin/applications/${applicationId}/${path}`, {
    method: "POST",
    token: getToken(),
    body: {
      method: choice.method,
      expected_total_cents: choice.expectedTotalCents,
      customer_agreed: choice.customerAgreed,
    },
  });
  return data.data.payment;
}

export async function markPaymentStatus(
  id: number | string,
  status: "pending" | "paid" | "failed" | "refunded",
  method?: "ach" | "card" | "cash" | "other",
): Promise<Payment> {
  const data = await apiFetch<{ data: Payment }>(`/admin/payments/${id}`, {
    method: "PUT",
    token: getToken(),
    body: { status, method },
  });
  return data.data;
}

/** Staff: starts saving a card for a customer. Returns the Stripe client secret for the card form. */
export async function startCardSetupAsStaff(leaseAgreementId: number | string): Promise<string> {
  const data = await apiFetch<{ data: { client_secret: string } }>(`/admin/lease-agreements/${leaseAgreementId}/card/setup-intent`, {
    method: "POST",
    token: getToken(),
  });
  return data.data.client_secret;
}

/** Staff: saves the card that Stripe has just set up as the customer's card for AutoPay. The card number never reaches our server. */
export async function saveCardAsStaff(
  leaseAgreementId: number | string,
  setupIntentId: string,
): Promise<{ brand: string | null; last4: string | null; replaced: boolean }> {
  const data = await apiFetch<{ data: { card: { brand: string | null; last4: string | null }; replaced: boolean } }>(`/admin/lease-agreements/${leaseAgreementId}/card`, {
    method: "POST",
    token: getToken(),
    body: { setup_intent_id: setupIntentId, customer_agreed: true },
  });
  return { ...data.data.card, replaced: data.data.replaced };
}

/**
 * Staff: a short-lived page for the customer to link their own bank account on the office computer (their own bank login,
 * never seen by staff). The page does the saving; nothing is saved by this call.
 */
export async function startBankLinkAsStaff(leaseAgreementId: number | string): Promise<{ url: string; expires_in_minutes: number }> {
  const data = await apiFetch<{ data: { url: string; expires_in_minutes: number } }>(`/admin/lease-agreements/${leaseAgreementId}/bank-link`, {
    method: "POST",
    token: getToken(),
  });
  return data.data;
}

/** What staff may see about a saved AutoPay method: never more than brand, last four and expiry (card) or bank name and last four. */
export interface StaffMethodInfo {
  /** True when Stripe could not be reached, so the details are not known right now. */
  unavailable?: boolean;
  brand?: string | null;
  last4?: string | null;
  exp_month?: number | null;
  exp_year?: number | null;
  funding?: string | null;
  bank_name?: string | null;
  account_type?: string | null;
}

export interface StaffAutopayMethods {
  autopay_enabled: boolean;
  primary: "ach" | "card" | null;
  bank: StaffMethodInfo | null;
  card: StaffMethodInfo | null;
}

/** Staff: the bank account and card AutoPay would use, as Stripe describes them. */
export async function getAutopayMethodsAsStaff(leaseAgreementId: number | string): Promise<StaffAutopayMethods> {
  const data = await apiFetch<{ data: StaffAutopayMethods }>(`/admin/lease-agreements/${leaseAgreementId}/autopay-methods`, {
    token: getToken(),
  });
  return data.data;
}

export interface PayMyPaymentResult {
  payment: Payment;
  /** paid, processing (a bank debit that settles in days), failed, or requires_action (the card wants an extra confirmation on screen). */
  status: "paid" | "processing" | "failed" | "requires_action";
  message: string | null;
  client_secret: string | null;
}

/**
 * Customer: pays a due monthly payment with a saved bank account or card ("Pay now"). The server refuses (409) when the
 * total no longer matches the one shown, and never charges twice for a press that got no answer.
 */
export async function payMyPayment(
  paymentId: number | string,
  choice: { method: "bank" | "card"; expectedTotalCents: number },
): Promise<PayMyPaymentResult> {
  const data = await apiFetch<{ data: PayMyPaymentResult }>(`/customer/payments/${paymentId}/pay`, {
    method: "POST",
    token: getToken(),
    body: { method: choice.method, expected_total_cents: choice.expectedTotalCents },
  });
  return data.data;
}
