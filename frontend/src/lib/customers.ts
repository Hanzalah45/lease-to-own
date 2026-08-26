import { apiFetch } from "@/lib/api";
import { getToken } from "@/lib/auth";
import type { Application } from "@/types/application";
import type { AuthUser } from "@/types/auth";
import type { LeaseAgreement } from "@/types/lease-agreement";
import type { RiskProfile } from "@/types/risk-profile";

export interface CustomerDetail extends AuthUser {
  applications?: Application[];
  lease_agreements?: LeaseAgreement[];
  risk_profile?: RiskProfile | null;
}

export async function listCustomers(): Promise<AuthUser[]> {
  const data = await apiFetch<{ data: AuthUser[] }>("/admin/customers", { token: getToken() });
  return data.data;
}

export async function getCustomer(id: number): Promise<CustomerDetail> {
  const data = await apiFetch<{ data: CustomerDetail }>(`/admin/customers/${id}`, { token: getToken() });
  return data.data;
}
