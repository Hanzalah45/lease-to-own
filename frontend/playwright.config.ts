import { defineConfig, devices } from "@playwright/test";

/**
 * Assumes the frontend (npm run dev, :3000) and backend (php artisan serve,
 * :8000) are already running — same as local dev. We deliberately don't
 * manage server lifecycle here: the backend's start command is
 * machine-specific (see .claude/launch.json, gitignored), so there's no
 * portable "just start everything" command to give Playwright.
 */
export default defineConfig({
  testDir: "./tests",
  // Serialized on purpose: against a dev server (not a production build),
  // several tests navigating to a not-yet-compiled route at once makes
  // Next.js's on-demand compiler slow enough to blow past the login
  // helper's waitForURL timeout — a suite-infra flake, not an app bug.
  fullyParallel: false,
  workers: 1,
  forbidOnly: !!process.env.CI,
  retries: process.env.CI ? 1 : 0,
  // php artisan serve is single-threaded — deep into a long sequential run
  // (30+ tests, each logging in fresh) it occasionally takes a dev-server
  // request several seconds to get scheduled. The default 5s expect timeout
  // is too tight for that; 15s has proven reliable across repeated full runs.
  expect: { timeout: 15000 },
  reporter: [["html", { open: "never" }], ["list"]],
  use: {
    baseURL: "http://localhost:3000",
    trace: "retain-on-failure",
    screenshot: "only-on-failure",
  },
  projects: [
    {
      name: "chromium",
      use: { ...devices["Desktop Chrome"] },
    },
  ],
});
