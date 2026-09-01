import { apiFetch } from "@/lib/api";
import { getToken } from "@/lib/auth";

export interface PlaidAccount {
  name: string;
  mask: string | null;
  subtype: string | null;
}

export interface PlaidStatus {
  connected: boolean;
  verified_at: string | null;
}

export async function getPlaidLinkToken(): Promise<string> {
  const data = await apiFetch<{ link_token: string }>("/customer/plaid/link-token", {
    method: "POST",
    token: getToken(),
  });
  return data.link_token;
}

export async function exchangePlaidPublicToken(
  publicToken: string,
): Promise<{ verified_at: string; accounts: PlaidAccount[] }> {
  const data = await apiFetch<{ data: { verified_at: string; accounts: PlaidAccount[] } }>(
    "/customer/plaid/exchange",
    { method: "POST", token: getToken(), body: { public_token: publicToken } },
  );
  return data.data;
}

export async function getPlaidStatus(): Promise<PlaidStatus> {
  const data = await apiFetch<{ data: PlaidStatus }>("/customer/plaid/status", { token: getToken() });
  return data.data;
}
