import { test, expect } from "@playwright/test";
import { ACCOUNTS, login } from "./helpers";

test.describe("Admin Accounts — CRUD (super_admin only)", () => {
  test.beforeEach(async ({ page }) => {
    await login(page, ACCOUNTS.superAdmin.email, ACCOUNTS.superAdmin.password);
    await page.goto("/admin/admin-users");
    await expect(page.getByRole("heading", { name: "Admin users" })).toBeVisible();
  });

  test("New admin form rejects an invalid email and a weak password", async ({ page }) => {
    await page.getByRole("button", { name: "New admin" }).click();
    await expect(page.getByRole("heading", { name: "New admin" })).toBeVisible();

    await page.locator("#admin-email").fill("not-an-email");
    await page.locator("#admin-password").fill("short");
    await page.locator("#admin-name").click();

    await expect(page.getByText("Enter a valid email address.")).toBeVisible();
    await expect(page.getByText(/at least 8 characters/i)).toBeVisible();
    await page.getByRole("button", { name: "Cancel" }).click();
  });

  test("full lifecycle: create a restricted admin, verify the restriction shows, then delete", async ({ page }) => {
    const email = `pwtest-${Date.now()}@outdoorfix.test`;

    await page.getByRole("button", { name: "New admin" }).click();
    await page.locator("#admin-name").fill("Playwright Test Admin");
    await page.locator("#admin-email").fill(email);
    await page.locator("#admin-password").fill("TestAdminPass123");
    await page.getByLabel("Equipment tracking", { exact: false }).check();
    await page.getByRole("button", { name: "Create admin" }).click();

    const row = page.locator("tr", { hasText: email });
    await expect(row).toBeVisible();
    await expect(row.getByText(/restricted:.*equipment tracking/i)).toBeVisible();

    // The new restricted admin can actually sign in with what was just set.
    await page.context().clearCookies();
    await login(page, email, "TestAdminPass123");
    await page.goto("/admin/dashboard");
    await expect(page.getByText(/restricted to 1 area/i)).toBeVisible();

    // Clean up — sign back in as super_admin and remove the test account.
    await page.context().clearCookies();
    await login(page, ACCOUNTS.superAdmin.email, ACCOUNTS.superAdmin.password);
    await page.goto("/admin/admin-users");
    const rowAgain = page.locator("tr", { hasText: email });
    await rowAgain.getByTitle("Remove admin").click();
    const removeDialog = page.getByRole("dialog", { name: "Remove admin" });
    await expect(removeDialog).toBeVisible();
    await removeDialog.getByRole("button", { name: "Remove", exact: true }).click();
    await expect(page.locator("tr", { hasText: email })).not.toBeVisible();
  });
});
