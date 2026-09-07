import { apiFetch, ApiError } from "@/lib/api";
import type { AuthUser, LoginResponse, RegisterResponse, UserRole } from "@/types/auth";

const TOKEN_COOKIE = "auth_token";
const ROLE_COOKIE = "auth_role";

function readCookie(name: string): string | null {
  if (typeof document === "undefined") return null;
  const match = document.cookie.match(new RegExp(`(?:^|; )${name}=([^;]*)`));
  return match ? decodeURIComponent(match[1]) : null;
}

function writeCookie(name: string, value: string): void {
  if (typeof document === "undefined") return;
  // 7-day expiry; adjust once "remember me" / refresh flows are designed.
  document.cookie = `${name}=${encodeURIComponent(value)}; path=/; max-age=${60 * 60 * 24 * 7}; SameSite=Lax`;
}

function deleteCookie(name: string): void {
  if (typeof document === "undefined") return;
  document.cookie = `${name}=; path=/; max-age=0`;
}

export function getToken(): string | null {
  return readCookie(TOKEN_COOKIE);
}

export function setToken(token: string): void {
  writeCookie(TOKEN_COOKIE, token);
}

export function clearToken(): void {
  deleteCookie(TOKEN_COOKIE);
  deleteCookie(ROLE_COOKIE);
}

/**
 * Mirrors the user's role into a readable cookie so middleware.ts can gate
 * /customer, /admin routes without an extra API round trip.
 */
export function setRole(role: UserRole): void {
  writeCookie(ROLE_COOKIE, role);
}

export function getRole(): UserRole | null {
  return (readCookie(ROLE_COOKIE) as UserRole | null) ?? null;
}

export async function login(email: string, password: string): Promise<LoginResponse> {
  const data = await apiFetch<LoginResponse>("/auth/login", {
    method: "POST",
    body: { email, password },
  });
  setToken(data.token);
  setRole(data.user.role);
  return data;
}

/** No token comes back — the account is "pending" until the emailed link is verified, so there's nothing to sign in with yet. */
export async function register(payload: {
  name: string;
  email: string;
  phone?: string;
  password: string;
  password_confirmation: string;
}): Promise<RegisterResponse> {
  return apiFetch<RegisterResponse>("/auth/register", {
    method: "POST",
    body: payload,
  });
}

export async function verifyEmail(params: {
  id: string;
  hash: string;
  expires: string;
  signature: string;
}): Promise<{ message: string }> {
  return apiFetch<{ message: string }>("/auth/email/verify", {
    method: "POST",
    body: params,
  });
}

export async function resendVerificationEmail(email: string): Promise<{ message: string }> {
  return apiFetch<{ message: string }>("/auth/email/resend", {
    method: "POST",
    body: { email },
  });
}

export async function logout(): Promise<void> {
  const token = getToken();
  if (token) {
    await apiFetch("/auth/logout", { method: "POST", token }).catch(() => undefined);
  }
  clearToken();
}

export async function getCurrentUser(): Promise<AuthUser | null> {
  const token = getToken();
  if (!token) return null;

  try {
    const data = await apiFetch<{ user: AuthUser }>("/auth/me", { token });
    return data.user;
  } catch (err) {
    // Only a genuine "this token is invalid" response should log the user
    // out — a transient network hiccup here must not wipe a token we just
    // set moments ago (e.g. right after login/register).
    if (err instanceof ApiError && err.status === 401) {
      clearToken();
    }
    return null;
  }
}

export async function forgotPassword(email: string): Promise<{ message: string }> {
  return apiFetch<{ message: string }>("/auth/forgot-password", {
    method: "POST",
    body: { email },
  });
}

export async function resetPassword(payload: {
  email: string;
  token: string;
  password: string;
  password_confirmation: string;
}): Promise<{ message: string }> {
  return apiFetch<{ message: string }>("/auth/reset-password", {
    method: "POST",
    body: payload,
  });
}

/** Sets a first real password for a guest-originated customer's shadow account — see AccountSetupSigner on the backend. */
export async function setUpAccount(payload: {
  id: string;
  hash: string;
  expires: string;
  signature: string;
  password: string;
  password_confirmation: string;
}): Promise<{ message: string }> {
  return apiFetch<{ message: string }>("/auth/account-setup", {
    method: "POST",
    body: payload,
  });
}

export async function updateMyProfile(payload: {
  name?: string;
  phone?: string | null;
  password?: string;
  password_confirmation?: string;
  current_password?: string;
}): Promise<AuthUser> {
  const data = await apiFetch<{ user: AuthUser }>("/me", {
    method: "PUT",
    token: getToken(),
    body: payload,
  });
  return data.user;
}

export async function updateMyAvatar(file: File): Promise<AuthUser> {
  const form = new FormData();
  form.append("avatar", file);
  const data = await apiFetch<{ user: AuthUser }>("/me/avatar", {
    method: "POST",
    token: getToken(),
    body: form,
  });
  return data.user;
}

export async function removeMyAvatar(): Promise<AuthUser> {
  const data = await apiFetch<{ user: AuthUser }>("/me/avatar", {
    method: "DELETE",
    token: getToken(),
  });
  return data.user;
}

export function dashboardPathForRole(role: UserRole): string {
  switch (role) {
    case "customer":
      return "/customer/dashboard";
    case "admin":
    case "super_admin":
      return "/admin/dashboard";
  }
}
