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

/** A QuickBooks account as the pickers list it. */
export interface QuickbooksAccount {
  id: string;
  name: string;
  type: string;
  subtype: string | null;
}

export interface QuickbooksAccountSlot {
  key: string;
  label: string;
  help: string;
  default_name: string;
  /** The QuickBooks account type used when the portal creates the account. */
  type: string;
  selected: { id: string; name: string } | null;
}

export interface QuickbooksSyncRow {
  payment_id: number;
  lease_id: number;
  customer: string | null;
  type: string;
  paid_date: string | null;
  amount: number;
  entry_id: string | null;
  synced_at: string | null;
  error: string | null;
}

export interface QuickbooksSync {
  connected: boolean;
  enabled: boolean;
  sync_from: string | null;
  earliest_paid_date: string | null;
  ready: boolean;
  missing: string[];
  accounts: QuickbooksAccountSlot[];
  counts: { synced: number; waiting: number; failed: number; skipped: number };
  last_synced_at: string | null;
  last_error: string | null;
  recent: QuickbooksSyncRow[];
  failed: QuickbooksSyncRow[];
}

export interface QuickbooksRunResult {
  sent: number;
  skipped: number;
  failed: number;
  reversed: number;
  stopped: string | null;
}

export async function getQuickbooksSync(): Promise<QuickbooksSync> {
  const data = await apiFetch<{ data: QuickbooksSync }>("/admin/quickbooks/sync", { token: getToken() });
  return data.data;
}

export async function getQuickbooksAccounts(): Promise<QuickbooksAccount[]> {
  const data = await apiFetch<{ data: QuickbooksAccount[] }>("/admin/quickbooks/accounts", { token: getToken() });
  return data.data;
}

export async function saveQuickbooksSync(input: {
  sync_enabled: boolean;
  sync_from: string | null;
  accounts: Record<string, string>;
}): Promise<QuickbooksSync> {
  const data = await apiFetch<{ data: QuickbooksSync }>("/admin/quickbooks/sync", {
    method: "PUT",
    token: getToken(),
    body: input,
  });
  return data.data;
}

export async function createMissingQuickbooksAccounts(): Promise<QuickbooksSync> {
  const data = await apiFetch<{ data: QuickbooksSync }>("/admin/quickbooks/accounts/create-missing", {
    method: "POST",
    token: getToken(),
  });
  return data.data;
}

export async function runQuickbooksSync(retryFailed = false): Promise<{ data: QuickbooksSync; result: QuickbooksRunResult }> {
  return apiFetch<{ data: QuickbooksSync; result: QuickbooksRunResult }>(
    retryFailed ? "/admin/quickbooks/sync/retry" : "/admin/quickbooks/sync/run",
    { method: "POST", token: getToken() },
  );
}
