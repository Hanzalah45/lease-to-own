import { test, expect } from "@playwright/test";
import { ACCOUNTS, login } from "./helpers";

/**
 * Covers the application wizard's Equipment and Lease steps only — no
 * submission, so no rows are written to the live dev database. Each test
 * is a direct regression check for a bug found and fixed this session.
 */
test.describe("New application wizard", () => {
  test.beforeEach(async ({ page }) => {
    await login(page, ACCOUNTS.admin.email, ACCOUNTS.admin.password);
    await page.goto("/admin/applications/new");
    await expect(page.getByLabel("Sales Person Name")).toBeVisible();
  });

  test("rejects a Sales Person Name with digits (regression: field had zero validation)", async ({ page }) => {
    await page.getByLabel("Sales Person Name").fill("John123");
    await expect(page.getByText(/can only contain letters, spaces, hyphens, and apostrophes/i)).toBeVisible();
  });

  test("rejects a Description over the character limit (regression: field had zero validation)", async ({ page }) => {
    await page.getByLabel("Description").fill("x".repeat(1001));
    await expect(page.getByText(/1000 characters or fewer/i)).toBeVisible();
  });

  test("default Payment Due Day does not block advancing past the Lease step (regression: default was \"15th\", which failed its own digit-only validator on every new application)", async ({ page }) => {
    // Equipment step — fill everything required, leave Sales Person/Description blank (both optional).
    await page.getByLabel("Cash Price / Retail").fill("2500");
    await page.getByLabel("Year").fill("2026");
    await page.getByLabel("Make").fill("Worldlawn");
    await page.getByLabel("Model").fill("WM-52");
    await page.getByLabel("Serial #").fill("SN-12345");
    await page.getByRole("button", { name: "Next →" }).click();

    // Lease step — fill the other required fields, but deliberately do NOT
    // touch Payment Due Day, so it stays at whatever INITIAL_WIZARD_STATE ships.
    await expect(page.getByLabel("Monthly Rental Payment")).toBeVisible();
    await page.getByLabel("Monthly Rental Payment").fill("150");
    await page.getByRole("button", { name: "Next →" }).click();

    // If Payment Due Day's default value fails validation, goNext() refuses
    // to advance and we're still looking at the Lease step.
    await expect(page.getByText("Renter contact information")).toBeVisible();
    await expect(page.getByLabel("Payment Due Day")).not.toBeVisible();
  });

  test("Payment Due Day rejects out-of-range values once touched", async ({ page }) => {
    await page.getByLabel("Cash Price / Retail").fill("2500");
    await page.getByLabel("Year").fill("2026");
    await page.getByLabel("Make").fill("Worldlawn");
    await page.getByLabel("Model").fill("WM-52");
    await page.getByLabel("Serial #").fill("SN-12345");
    await page.getByRole("button", { name: "Next →" }).click();

    await expect(page.getByLabel("Payment Due Day")).toBeVisible();
    await page.getByLabel("Payment Due Day").fill("32");
    await expect(page.getByText(/between 1 and 31/i)).toBeVisible();
  });
});
