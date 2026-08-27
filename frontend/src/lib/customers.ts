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

export interface CustomerPayload {
  name: string;
  email: string;
  phone?: string;
  password?: string;
  status?: string;
  address_line_1?: string;
  city?: string;
  state?: string;
  zip?: string;
  date_of_birth?: string;
  internal_notes?: string;
}

export async function createCustomer(payload: CustomerPayload): Promise<AuthUser> {
  const data = await apiFetch<{ data: AuthUser }>("/admin/customers", {
    method: "POST",
    token: getToken(),
    body: payload,
  });
  return data.data;
}

export async function updateCustomer(id: number, payload: Partial<CustomerPayload>): Promise<AuthUser> {
  const data = await apiFetch<{ data: AuthUser }>(`/admin/customers/${id}`, {
    method: "PUT",
    token: getToken(),
    body: payload,
  });
  return data.data;
}

export async function deleteCustomer(id: number): Promise<void> {
  await apiFetch<void>(`/admin/customers/${id}`, {
    method: "DELETE",
    token: getToken(),
  });
}
