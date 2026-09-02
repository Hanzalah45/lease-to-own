import { test, expect } from "@playwright/test";
import { ACCOUNTS, clearSession } from "./helpers";

test.beforeEach(async ({ page }) => {
  await clearSession(page);
});

test.describe("Login page", () => {
  test("rejects an invalid email format before hitting the API", async ({ page }) => {
    await page.goto("/login");
    await page.locator("#email").fill("notanemail");
    await page.locator("#password").click(); // blur email
    await expect(page.getByText("Enter a valid email address.")).toBeVisible();
    await expect(page.getByRole("button", { name: /sign in/i })).toBeDisabled();
  });

  test("requires both fields before enabling submit", async ({ page }) => {
    await page.goto("/login");
    await expect(page.getByRole("button", { name: /sign in/i })).toBeDisabled();
    await page.locator("#email").fill(ACCOUNTS.superAdmin.email);
    await expect(page.getByRole("button", { name: /sign in/i })).toBeDisabled();
    await page.locator("#password").fill(ACCOUNTS.superAdmin.password);
    await expect(page.getByRole("button", { name: /sign in/i })).toBeEnabled();
  });

  test("shows the backend's incorrect-password error inline on the password field", async ({ page }) => {
    await page.goto("/login");
    await page.locator("#email").fill(ACCOUNTS.superAdmin.email);
    await page.locator("#password").fill("definitely-wrong-password");
    await page.getByRole("button", { name: /sign in/i }).click();
    await expect(page.getByText("Incorrect password.")).toBeVisible();
  });

  test("password field has a working show/hide toggle", async ({ page }) => {
    await page.goto("/login");
    const passwordInput = page.locator("#password");
    await passwordInput.fill("mypassword123");
    await expect(passwordInput).toHaveAttribute("type", "password");

    await page.getByRole("button", { name: /show password/i }).click();
    await expect(passwordInput).toHaveAttribute("type", "text");
    await expect(passwordInput).toHaveValue("mypassword123");

    await page.getByRole("button", { name: /hide password/i }).click();
    await expect(passwordInput).toHaveAttribute("type", "password");
  });

  test("successfully signs in and lands on the correct dashboard for the role", async ({ page }) => {
    await page.goto("/login");
    await page.locator("#email").fill(ACCOUNTS.superAdmin.email);
    await page.locator("#password").fill(ACCOUNTS.superAdmin.password);
    await page.getByRole("button", { name: /sign in/i }).click();
    await expect(page).toHaveURL(/\/admin\/dashboard/);
  });
});

test.describe("Register page", () => {
  test("rejects a name containing digits", async ({ page }) => {
    await page.goto("/register");
    await page.locator("#name").fill("John123");
    await page.locator("#email").click();
    await expect(page.getByText(/can only contain letters, spaces, hyphens, and apostrophes/i)).toBeVisible();
  });

  test("rejects an invalid email format", async ({ page }) => {
    await page.goto("/register");
    await page.locator("#email").fill("not-an-email");
    await page.locator("#phone").click();
    await expect(page.getByText("Enter a valid email address.")).toBeVisible();
  });

  test("enforces password complexity — length and letter+number", async ({ page }) => {
    await page.goto("/register");
    await page.locator("#password").fill("short1");
    await page.locator("#password_confirmation").click();
    await expect(page.getByText(/at least 8 characters/i)).toBeVisible();

    await page.locator("#password").fill("alllettersnonumber");
    await page.locator("#password_confirmation").click();
    await expect(page.getByText(/must include at least one letter and one number/i)).toBeVisible();
  });

  test("flags mismatched password confirmation", async ({ page }) => {
    await page.goto("/register");
    await page.locator("#password").fill("ValidPass123");
    await page.locator("#password_confirmation").fill("DoesNotMatch123");
    await page.locator("#name").click();
    await expect(page.getByText("Passwords do not match.")).toBeVisible();
  });

  test("both password fields have working show/hide toggles independently", async ({ page }) => {
    await page.goto("/register");
    await page.locator("#password").fill("secretpass1");
    await page.locator("#password_confirmation").fill("secretpass2");

    const toggles = page.getByRole("button", { name: /show password/i });
    await expect(toggles).toHaveCount(2);
    await toggles.first().click();
    await expect(page.locator("#password")).toHaveAttribute("type", "text");
    await expect(page.locator("#password_confirmation")).toHaveAttribute("type", "password");
  });

  test("submit stays disabled until every required field is valid", async ({ page }) => {
    await page.goto("/register");
    const submit = page.getByRole("button", { name: /create account/i });
    await expect(submit).toBeDisabled();

    await page.locator("#name").fill("Jane Doe");
    await page.locator("#email").fill("jane.doe.e2e@outdoorfix.test");
    await page.locator("#password").fill("ValidPass123");
    await page.locator("#password_confirmation").fill("ValidPass123");
    await expect(submit).toBeEnabled();
  });
});
