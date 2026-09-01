import { apiFetch } from "@/lib/api";
import { getToken } from "@/lib/auth";
import type { LeaseAgreement } from "@/types/lease-agreement";

export async function listMyLeaseAgreements(): Promise<LeaseAgreement[]> {
  const data = await apiFetch<{ data: LeaseAgreement[] }>("/customer/lease-agreements", { token: getToken() });
  return data.data;
}

export async function getMyLeaseAgreement(id: number | string): Promise<LeaseAgreement> {
  const data = await apiFetch<{ data: LeaseAgreement }>(`/customer/lease-agreements/${id}`, { token: getToken() });
  return data.data;
}

export async function listLeaseAgreements(): Promise<LeaseAgreement[]> {
  const data = await apiFetch<{ data: LeaseAgreement[] }>("/admin/lease-agreements", { token: getToken() });
  return data.data;
}

export async function getLeaseAgreement(id: number | string): Promise<LeaseAgreement> {
  const data = await apiFetch<{ data: LeaseAgreement }>(`/admin/lease-agreements/${id}`, { token: getToken() });
  return data.data;
}
