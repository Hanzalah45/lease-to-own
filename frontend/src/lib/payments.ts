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
