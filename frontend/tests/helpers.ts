import type { Page } from "@playwright/test";

/**
 * Seeded accounts from backend/database/seeders/DatabaseSeeder.php — all
 * share the password "password123" (needs a letter + a number to satisfy
 * validatePassword, same as the self-service password change form). Don't
 * hardcode a different value here; if the seeder changes, these should too.
 */
export const ACCOUNTS = {
  superAdmin: { email: "superadmin@outdoorfix.test", password: "password123" },
  admin: { email: "admin@outdoorfix.test", password: "password123" },
  // Restricted to equipment_tracking + payment_tracking only — see DatabaseSeeder.
  restrictedAdmin: { email: "restricted.admin@outdoorfix.test", password: "password123" },
  customer: { email: "customer@outdoorfix.test", password: "password123" },
} as const;

export async function login(page: Page, email: string, password: string) {
  await page.goto("/login");
  await page.locator("#email").fill(email);
  await page.locator("#password").fill(password);
  await page.getByRole("button", { name: /sign in/i }).click();
  await page.waitForURL(/\/(admin|customer)\//, { timeout: 10000 });
}

export async function clearSession(page: Page) {
  await page.context().clearCookies();
}
