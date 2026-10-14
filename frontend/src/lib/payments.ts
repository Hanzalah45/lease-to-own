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
  const data = await apiFetch<{ data: { payment: Payment } }>(`/admin/applications/${applicationId}/charge-deposit`, {
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
): Promise<{ brand: string | null; last4: string | null }> {
  const data = await apiFetch<{ data: { card: { brand: string | null; last4: string | null } } }>(`/admin/lease-agreements/${leaseAgreementId}/card`, {
    method: "POST",
    token: getToken(),
    body: { setup_intent_id: setupIntentId, customer_agreed: true },
  });
  return data.data.card;
}
