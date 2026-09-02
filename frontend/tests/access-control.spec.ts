import { test, expect } from "@playwright/test";
import { ACCOUNTS, login, clearSession } from "./helpers";

/**
 * Role/permission boundaries across the admin portal. Each restricted-admin
 * test is a regression check: before this pass, "My Applications" and
 * "Customer Accounts" were shown to every admin regardless of the
 * application_review permission, and /admin/applications and /admin/customers
 * had no page-level guard — a restricted admin hitting either directly got a
 * raw API-error banner instead of a clean "you don't have this" message.
 */
test.describe("Section-level role redirects (middleware)", () => {
  test.beforeEach(async ({ page }) => {
    await clearSession(page);
  });

  test("unauthenticated visitor hitting an admin page is sent to /login", async ({ page }) => {
    await page.goto("/admin/dashboard");
    await expect(page).toHaveURL(/\/login/);
  });

  test("a signed-in customer hitting /admin/* is redirected to their own dashboard", async ({ page }) => {
    await login(page, ACCOUNTS.customer.email, ACCOUNTS.customer.password);
    await page.goto("/admin/dashboard");
    await expect(page).toHaveURL(/\/customer\/dashboard/);
  });

  test("a signed-in admin hitting /customer/* is redirected to their own dashboard", async ({ page }) => {
    await login(page, ACCOUNTS.admin.email, ACCOUNTS.admin.password);
    await page.goto("/customer/dashboard");
    await expect(page).toHaveURL(/\/admin\/dashboard/);
  });
});

test.describe("Admin nav — shown items match actual permissions", () => {
  test("super_admin sees every nav item, including Admin Accounts", async ({ page }) => {
    await login(page, ACCOUNTS.superAdmin.email, ACCOUNTS.superAdmin.password);
    const nav = page.locator("header nav");
    await expect(nav.getByRole("link", { name: "Dashboard" })).toBeVisible();
    await expect(nav.getByRole("link", { name: "My Applications" })).toBeVisible();
    await expect(nav.getByRole("link", { name: "Customer Accounts" })).toBeVisible();
    await expect(nav.getByRole("link", { name: "Equipment" })).toBeVisible();
    await expect(nav.getByRole("link", { name: "Payments" })).toBeVisible();
    await expect(nav.getByRole("link", { name: "Admin Accounts" })).toBeVisible();
  });

  test("an unrestricted admin sees every item except Admin Accounts", async ({ page }) => {
    await login(page, ACCOUNTS.admin.email, ACCOUNTS.admin.password);
    const nav = page.locator("header nav");
    await expect(nav.getByRole("link", { name: "My Applications" })).toBeVisible();
    await expect(nav.getByRole("link", { name: "Customer Accounts" })).toBeVisible();
    await expect(nav.getByRole("link", { name: "Equipment" })).toBeVisible();
    await expect(nav.getByRole("link", { name: "Payments" })).toBeVisible();
    await expect(nav.getByRole("link", { name: "Admin Accounts" })).not.toBeVisible();

    // Defense in depth: even a direct hit on the super-admin-only URL bounces away.
    await page.goto("/admin/admin-users");
    await expect(page).toHaveURL(/\/admin\/dashboard/);
  });

  test("a restricted admin (equipment_tracking + payment_tracking only) sees only those two items (regression: My Applications used to show unconditionally)", async ({ page }) => {
    await login(page, ACCOUNTS.restrictedAdmin.email, ACCOUNTS.restrictedAdmin.password);
    const nav = page.locator("header nav");
    await expect(nav.getByRole("link", { name: "Dashboard" })).toBeVisible();
    await expect(nav.getByRole("link", { name: "Equipment" })).toBeVisible();
    await expect(nav.getByRole("link", { name: "Payments" })).toBeVisible();
    await expect(nav.getByRole("link", { name: "My Applications" })).not.toBeVisible();
    await expect(nav.getByRole("link", { name: "Customer Accounts" })).not.toBeVisible();
    await expect(nav.getByRole("link", { name: "Admin Accounts" })).not.toBeVisible();
  });
});

test.describe("Restricted admin — direct navigation to a page outside their permissions", () => {
  test.beforeEach(async ({ page }) => {
    await login(page, ACCOUNTS.restrictedAdmin.email, ACCOUNTS.restrictedAdmin.password);
  });

  test("hitting /admin/applications directly shows a clean restricted message, not a broken table", async ({ page }) => {
    await page.goto("/admin/applications");
    await expect(page.getByText(/does not include application review/i)).toBeVisible();
    await expect(page.getByPlaceholder(/search/i)).not.toBeVisible();
  });

  test("hitting /admin/customers directly shows a clean restricted message, not a broken table", async ({ page }) => {
    await page.goto("/admin/customers");
    await expect(page.getByText(/does not include application review/i)).toBeVisible();
  });

  test("hitting /admin/admin-users directly redirects away (super_admin only)", async ({ page }) => {
    await page.goto("/admin/admin-users");
    await expect(page).toHaveURL(/\/admin\/dashboard/);
  });

  test("Equipment and Payments — the two permitted areas — render real content", async ({ page }) => {
    await page.goto("/admin/equipment");
    await expect(page.getByText(/does not include/i)).not.toBeVisible();
    await expect(page.getByText("Equipment Tracking", { exact: false })).toBeVisible();

    await page.goto("/admin/payments");
    await expect(page.getByText(/does not include/i)).not.toBeVisible();
    await expect(page.getByText("Collected this month", { exact: false })).toBeVisible();
  });
});
