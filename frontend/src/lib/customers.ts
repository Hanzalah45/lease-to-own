import { apiFetch } from "@/lib/api";
import { getToken } from "@/lib/auth";
import type { Application } from "@/types/application";
import type { AuthUser } from "@/types/auth";
import type { RiskProfile } from "@/types/risk-profile";

export interface CustomerDetail extends AuthUser {
  // Each entry carries its own lease_agreement (with equipment, contract,
  // payments) and info_requests — the same shape the application detail
  // page renders, so this page can show it inline without a second fetch.
  applications?: Application[];
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

/**
 * Resends the "Set up your account" link to a guest customer who has made their
 * first payment but never set a password (or whose 14-day link expired).
 */
export async function resendAccountSetup(id: number): Promise<string> {
  const data = await apiFetch<{ message: string }>(`/admin/customers/${id}/resend-account-setup`, {
    method: "POST",
    token: getToken(),
  });
  return data.message;
}

export async function deleteCustomer(id: number): Promise<void> {
  await apiFetch<void>(`/admin/customers/${id}`, {
    method: "DELETE",
    token: getToken(),
  });
}
