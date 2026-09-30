import { apiFetch } from "@/lib/api";
import { getToken } from "@/lib/auth";

export interface QuickbooksStatus {
  connected: boolean;
  realm_id?: string;
  connected_by?: string;
  connected_at?: string;
  access_token_expires_at?: string;
  refresh_token_expires_at?: string;
  needs_reconnect?: boolean;
}

export async function getQuickbooksStatus(): Promise<QuickbooksStatus> {
  const data = await apiFetch<{ data: QuickbooksStatus }>("/admin/quickbooks/status", { token: getToken() });
  return data.data;
}

/** Returns Intuit's consent-screen URL — the caller navigates the browser there directly. */
export async function startQuickbooksConnect(): Promise<string> {
  const data = await apiFetch<{ data: { url: string } }>("/admin/quickbooks/connect", {
    method: "POST",
    token: getToken(),
  });
  return data.data.url;
}

export async function disconnectQuickbooks(): Promise<void> {
  await apiFetch<void>("/admin/quickbooks/disconnect", { method: "DELETE", token: getToken() });
}
