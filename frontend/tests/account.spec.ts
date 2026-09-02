import { execSync } from "node:child_process";
import { test, expect } from "@playwright/test";
import { ACCOUNTS, login } from "./helpers";

const PHP = "C:\\laragon\\bin\\php\\php-8.3.30-Win32-vs16-x64\\php.exe";
const BACKEND_DIR = "C:\\laragon\\www\\lease-to-own\\backend";

/**
 * The seeded password "password" has no digit, so it can never be reached
 * again through this feature's own validatePassword() rule (letter + number
 * required) — that's correct, not a bug. Reset straight through the backend
 * instead of relying on the UI to reach an intentionally-invalid value.
 */
function resetCustomerPassword() {
  execSync(
    `"${PHP}" artisan tinker --execute="App\\Models\\User::where('email','${ACCOUNTS.customer.email}')->first()->update(['password'=>Hash::make('${ACCOUNTS.customer.password}')]);"`,
    { cwd: BACKEND_DIR },
  );
}

/**
 * Self-service profile/password update — added because no authenticated user
 * (customer, admin, or even super_admin) previously had any way to change
 * their own name/email/phone/password. Customers saw a hard "contact your
 * dealer" message, and super_admin was excluded even from the admin list a
 * super_admin could otherwise edit other admins through.
 */
test.describe("Customer self-service profile", () => {
  test.beforeEach(async ({ page }) => {
    await login(page, ACCOUNTS.customer.email, ACCOUNTS.customer.password);
    await page.goto("/customer/account");
  });

  test("updating the phone number persists across a reload", async ({ page }) => {
    const phoneInput = page.getByPlaceholder("(000) 000-0000");
    // Idempotent across repeat runs: alternate between two values instead of
    // always targeting the same one (which would be a no-op — and the Save
    // button stays disabled with nothing dirty to save — on a second run).
    const current = await phoneInput.inputValue();
    const target = current === "(713) 555-0142" ? "(713) 555-0199" : "(713) 555-0142";

    await phoneInput.fill(target);
    await page.getByRole("button", { name: "Save changes" }).click();
    await expect(page.getByText("Saved.")).toBeVisible();

    await page.reload();
    await expect(page.getByPlaceholder("(000) 000-0000")).toHaveValue(target);
  });

  test("wrong current password is rejected; correct one actually changes it", async ({ page }) => {
    const TEMP_PASSWORD = "TempRegressPass123";

    try {
      await page.getByLabel("Current password").fill("definitely-wrong");
      await page.getByLabel("New password", { exact: true }).fill(TEMP_PASSWORD);
      await page.getByLabel("Confirm new password").fill(TEMP_PASSWORD);
      await page.getByRole("button", { name: "Change password" }).click();
      await expect(page.getByText("Current password is incorrect.")).toBeVisible();

      await page.getByLabel("Current password").fill(ACCOUNTS.customer.password);
      await page.getByRole("button", { name: "Change password" }).click();
      await expect(page.getByText("Password changed.")).toBeVisible();

      // Prove it's real: log out and back in with the new password.
      await page.context().clearCookies();
      await login(page, ACCOUNTS.customer.email, TEMP_PASSWORD);
    } finally {
      // Reset straight through the backend — see resetCustomerPassword().
      resetCustomerPassword();
    }
  });
});

test.describe("Admin self-service profile — reachable regardless of role", () => {
  test("super_admin (excluded from the admin-users list) can still reach and use their own account page", async ({ page }) => {
    await login(page, ACCOUNTS.superAdmin.email, ACCOUNTS.superAdmin.password);
    await page.goto("/admin/account");
    await expect(page.getByText("My Account")).toBeVisible();
    await expect(page.getByRole("button", { name: "Save changes" })).toBeVisible();
  });

  test("a restricted admin (no admin_permissions grant this) can still reach their own account page", async ({ page }) => {
    await login(page, ACCOUNTS.restrictedAdmin.email, ACCOUNTS.restrictedAdmin.password);
    await page.goto("/admin/account");
    await expect(page.getByText(/does not include/i)).not.toBeVisible();
    await expect(page.getByRole("button", { name: "Save changes" })).toBeVisible();
  });
});
