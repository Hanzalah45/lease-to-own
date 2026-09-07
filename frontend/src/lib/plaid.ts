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

/** The signed params carried by a "Request bank verification" email link — see BankVerificationSigner on the backend. */
export interface SignedLinkParams {
  id: string;
  hash: string;
  expires: string;
  signature: string;
}

/** Unauthenticated counterpart to getPlaidLinkToken() — used by /verify-bank, reached from the emailed signed link rather than a login session. */
export async function getSignedPlaidLinkToken(params: SignedLinkParams): Promise<string> {
  const data = await apiFetch<{ link_token: string }>("/plaid/verify-link-token", {
    method: "POST",
    body: params,
  });
  return data.link_token;
}

/** Unauthenticated counterpart to exchangePlaidPublicToken() — used by /verify-bank. */
export async function exchangeSignedPlaidPublicToken(
  params: SignedLinkParams,
  publicToken: string,
): Promise<{ verified_at: string; accounts: PlaidAccount[] }> {
  const data = await apiFetch<{ data: { verified_at: string; accounts: PlaidAccount[] } }>("/plaid/verify-exchange", {
    method: "POST",
    body: { ...params, public_token: publicToken },
  });
  return data.data;
}
