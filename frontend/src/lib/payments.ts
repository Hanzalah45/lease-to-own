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
