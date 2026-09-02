import { test, expect } from "@playwright/test";
import { ACCOUNTS, login } from "./helpers";

/**
 * Regression coverage for two real gaps found while auditing the portal:
 * 1. markPaymentStatus() existed in lib/payments.ts and the backend endpoint
 *    worked, but no page anywhere actually called it — there was no way for
 *    an admin to mark a payment paid or failed. Fixed by wiring real buttons
 *    into PaymentTrackingPanel (used by both the dashboard tab and
 *    /admin/payments).
 * 2. Those buttons initially fired with no confirmation (Mark Paid) or a
 *    native window.confirm() (Mark Failed) — inconsistent with every other
 *    destructive/consequential action in the app, which uses the shared
 *    Modal component. Fixed by routing both through a real confirm dialog.
 * This mutates real rows in the dev DB, same as the rest of this suite.
 */
test.describe("Admin payments — marking a payment", () => {
  test.beforeEach(async ({ page }) => {
    await login(page, ACCOUNTS.superAdmin.email, ACCOUNTS.superAdmin.password);
    await page.goto("/admin/payments");
    await expect(page.getByText("Overdue & upcoming payments")).toBeVisible();
  });

  test("Mark Paid opens a confirm dialog; cancelling makes no API call", async ({ page }) => {
    await page.getByRole("button", { name: "Mark Paid" }).first().click();
    const dialog = page.getByRole("dialog", { name: "Mark payment paid" });
    await expect(dialog).toBeVisible();

    let calledDuringCancel = false;
    const trap = (r: import("@playwright/test").Request) => {
      if (/\/admin\/payments\/\d+$/.test(r.url())) calledDuringCancel = true;
    };
    page.on("request", trap);
    await dialog.getByRole("button", { name: "Cancel" }).click();
    await expect(dialog).not.toBeVisible();
    page.off("request", trap);
    expect(calledDuringCancel).toBe(false);
  });

  test("confirming Mark Paid actually calls the API and persists (not just a local UI flip)", async ({ page }) => {
    await page.getByRole("button", { name: "Mark Paid" }).first().click();
    const dialog = page.getByRole("dialog", { name: "Mark payment paid" });
    await expect(dialog).toBeVisible();

    const [response] = await Promise.all([
      page.waitForResponse((r) => /\/admin\/payments\/\d+$/.test(r.url()) && r.request().method() === "PUT"),
      dialog.getByRole("button", { name: "Mark Paid", exact: true }).click(),
    ]);
    expect(response.status()).toBe(200);
    const body = await response.json();
    // The controller returns $payment->fresh() — a value read back from the
    // DB after the write, not just the in-memory object — so this is proof
    // the status actually persisted, not merely an optimistic UI flip.
    expect(body.data.status).toBe("paid");

    await expect(dialog).not.toBeVisible();
    await expect(page.getByText("Could not update that payment")).not.toBeVisible();
  });
});
