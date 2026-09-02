import { apiFetch } from "@/lib/api";
import { getToken } from "@/lib/auth";
import type { Contract } from "@/types/lease-agreement";

export async function signLease(leaseAgreementId: number): Promise<Contract> {
  const data = await apiFetch<{ data: Contract }>("/customer/contracts", {
    method: "POST",
    token: getToken(),
    body: { lease_agreement_id: leaseAgreementId },
  });
  return data.data;
}
