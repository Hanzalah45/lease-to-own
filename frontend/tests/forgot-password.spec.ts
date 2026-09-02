import { execSync } from "node:child_process";
import { readFileSync } from "node:fs";
import { test, expect } from "@playwright/test";
import { ACCOUNTS, login } from "./helpers";

const PHP = "C:\\laragon\\bin\\php\\php-8.3.30-Win32-vs16-x64\\php.exe";
const BACKEND_DIR = "C:\\laragon\\www\\lease-to-own\\backend";
const LOG_PATH = `${BACKEND_DIR}\\storage\\logs\\laravel.log`;

function resetCustomerPassword() {
  execSync(
    `"${PHP}" artisan tinker --execute="App\\Models\\User::where('email','${ACCOUNTS.customer.email}')->first()->update(['password'=>Hash::make('${ACCOUNTS.customer.password}')]);"`,
    { cwd: BACKEND_DIR },
  );
}

/**
 * MAIL_MAILER=log — the reset email is never actually delivered, it's
 * written to storage/logs/laravel.log. Pull the freshest reset URL for the
 * given email out of that file, the same way a human would read the log in
 * this dev-only mail mode.
 */
function latestResetLinkFor(email: string): { token: string; email: string } {
  const log = readFileSync(LOG_PATH, "utf8");
  const escapedEmail = encodeURIComponent(email).replace(/[.*+?^${}()|[\]\\]/g, "\\$&");
  const pattern = new RegExp(`reset-password\\?token=([a-f0-9]+)&amp;email=${escapedEmail}`, "g");
  const matches = [...log.matchAll(pattern)];
  const last = matches.at(-1);
  if (!last) throw new Error(`No reset link found in laravel.log for ${email}`);
  return { token: last[1], email };
}

test.describe("Forgot / reset password", () => {
  test("requesting a reset shows the same generic message regardless of outcome", async ({ page }) => {
    await page.goto("/login");
    await page.getByRole("link", { name: "Forgot password?" }).click();
    await expect(page).toHaveURL(/\/forgot-password/);

    // Regression: this button shipped without the disabled-until-valid guard
    // every other auth form in the app has (login, register).
    const submit = page.getByRole("button", { name: "Send reset link" });
    await expect(submit).toBeDisabled();

    await page.locator("#email").fill(ACCOUNTS.customer.email);
    await expect(submit).toBeEnabled();
    await submit.click();
    await expect(page.getByText(/password reset link has been sent/i)).toBeVisible();
  });

  test("a reset link missing its token shows an error instead of a broken form", async ({ page }) => {
    await page.goto("/reset-password");
    await expect(page.getByText(/reset link is incomplete/i)).toBeVisible();
    await expect(page.getByRole("button", { name: /reset password/i })).not.toBeVisible();
  });

  test("full round trip: request link, use it, log in with the new password", async ({ page, request }) => {
    const TEMP_PASSWORD = "ForgotFlowTest123";
    try {
      const before = await request.post("http://localhost:8000/api/auth/forgot-password", {
        headers: { "Content-Type": "application/json", Accept: "application/json" },
        data: { email: ACCOUNTS.customer.email },
      });
      expect(before.ok()).toBeTruthy();

      const { token, email } = latestResetLinkFor(ACCOUNTS.customer.email);

      await page.goto(`/reset-password?token=${token}&email=${encodeURIComponent(email)}`);
      await expect(page.getByText(`For ${email}`)).toBeVisible();

      const submit = page.getByRole("button", { name: /reset password/i });
      await expect(submit).toBeDisabled();

      await page.getByPlaceholder("Letter + number, 8+ chars").fill(TEMP_PASSWORD);
      await page.getByLabel("Confirm new password").fill(TEMP_PASSWORD);
      await expect(submit).toBeEnabled();
      await submit.click();
      await expect(page.getByText(/password has been reset/i)).toBeVisible();

      // Prove it's real: the new password actually logs in.
      await login(page, ACCOUNTS.customer.email, TEMP_PASSWORD);
    } finally {
      resetCustomerPassword();
    }
  });
});
