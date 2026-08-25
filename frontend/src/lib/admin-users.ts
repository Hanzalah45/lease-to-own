import { apiFetch } from "@/lib/api";
import { getToken } from "@/lib/auth";
import type { AdminPermissionKey, AuthUser } from "@/types/auth";

export const ADMIN_PERMISSIONS: { key: AdminPermissionKey; label: string }[] = [
  { key: "application_review", label: "Application review" },
  { key: "risk_assessment", label: "Risk assessment" },
  { key: "contract_generation", label: "Contract generation" },
  { key: "equipment_tracking", label: "Equipment tracking" },
  { key: "payment_tracking", label: "Payment tracking" },
];

export async function listAdmins(): Promise<AuthUser[]> {
  const data = await apiFetch<{ data: AuthUser[] }>("/admin/admin-users", { token: getToken() });
  return data.data;
}

export async function createAdmin(payload: {
  name: string;
  email: string;
  phone?: string;
  password: string;
  permissions: AdminPermissionKey[];
}): Promise<AuthUser> {
  const data = await apiFetch<{ data: AuthUser }>("/admin/admin-users", {
    method: "POST",
    token: getToken(),
    body: payload,
  });
  return data.data;
}

export async function updateAdmin(
  id: number,
  payload: Partial<{ name: string; phone: string; status: string; permissions: AdminPermissionKey[] }>,
): Promise<AuthUser> {
  const data = await apiFetch<{ data: AuthUser }>(`/admin/admin-users/${id}`, {
    method: "PUT",
    token: getToken(),
    body: payload,
  });
  return data.data;
}
