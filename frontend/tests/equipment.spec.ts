import { test, expect } from "@playwright/test";
import { ACCOUNTS, login } from "./helpers";

test.describe("Equipment tracking — CRUD", () => {
  test.beforeEach(async ({ page }) => {
    await login(page, ACCOUNTS.superAdmin.email, ACCOUNTS.superAdmin.password);
    await page.goto("/admin/equipment");
    await expect(page.getByText("Equipment Tracking")).toBeVisible();
  });

  test("Add Unit form rejects empty required fields", async ({ page }) => {
    await page.getByRole("button", { name: "Add Unit" }).click();
    await expect(page.getByRole("heading", { name: "Add unit" })).toBeVisible();

    // Touch and blur both required fields without typing anything.
    await page.locator("#equipment-model").click();
    await page.locator("#equipment-serial").click();
    await page.locator("#equipment-model").click();

    await expect(page.getByText("Model is required.")).toBeVisible();
    await expect(page.getByRole("button", { name: "Add Unit" }).last()).toBeDisabled();
    await page.getByRole("button", { name: "Cancel" }).click();
  });

  test("full lifecycle: create a unit, edit it, then delete it", async ({ page }) => {
    const serial = `PWTEST-${Date.now()}`;

    await page.getByRole("button", { name: "Add Unit" }).click();
    await page.locator("#equipment-model").fill("Playwright Test Mower");
    await page.locator("#equipment-serial").fill(serial);
    await page.getByRole("button", { name: "Add Unit" }).last().click();

    const row = page.locator("tr", { hasText: serial });
    await expect(row).toBeVisible();
    await expect(row.getByText("Playwright Test Mower")).toBeVisible();

    // Edit — rename the model, confirm the table reflects it.
    await row.getByTitle("Edit").click();
    await expect(page.getByRole("heading", { name: "Edit unit" })).toBeVisible();
    await page.locator("#equipment-model").fill("Playwright Test Mower (Edited)");
    await page.getByRole("button", { name: "Save Changes" }).click();
    await expect(row.getByText("Playwright Test Mower (Edited)")).toBeVisible();

    // Delete — confirm the row is gone afterwards.
    await row.getByTitle("Delete").click();
    const deleteDialog = page.getByRole("dialog", { name: "Delete unit" });
    await expect(deleteDialog).toBeVisible();
    await deleteDialog.getByRole("button", { name: "Delete", exact: true }).click();
    await expect(page.locator("tr", { hasText: serial })).not.toBeVisible();
  });
});
