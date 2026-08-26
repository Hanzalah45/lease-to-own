import { apiFetch, ApiError } from "@/lib/api";
import type { AuthUser, LoginResponse, UserRole } from "@/types/auth";

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

export async function register(payload: {
  name: string;
  email: string;
  phone?: string;
  password: string;
  password_confirmation: string;
}): Promise<LoginResponse> {
  const data = await apiFetch<LoginResponse>("/auth/register", {
    method: "POST",
    body: payload,
  });
  setToken(data.token);
  setRole(data.user.role);
  return data;
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

export function dashboardPathForRole(role: UserRole): string {
  switch (role) {
    case "customer":
      return "/customer/dashboard";
    case "admin":
    case "super_admin":
      return "/admin/dashboard";
  }
}
