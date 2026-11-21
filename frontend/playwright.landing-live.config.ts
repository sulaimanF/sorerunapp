import { defineConfig, devices } from "@playwright/test";

const production = process.env.LANDING_PRODUCTION_TEST === "true";
export default defineConfig({
  testDir: "./tests/landing-live", workers: 1, retries: 0, timeout: 120_000,
  expect: { timeout: 20_000 }, reporter: "list",
  use: { baseURL: "http://localhost:3111", trace: "off", screenshot: "off", video: "off", ...devices["Desktop Chrome"] },
  webServer: [
    { command: "php -S localhost:8110 -t ../backend/public ../backend/tests/Support/stage10-live-router.php", url: "http://localhost:8110/up", reuseExistingServer: false, timeout: 60_000 },
    { command: production ? "npm run start -- --hostname localhost --port 3111" : "npm run dev -- --hostname localhost --port 3111", url: "http://localhost:3111/login", reuseExistingServer: false, timeout: 120_000,
      env: { NEXT_TELEMETRY_DISABLED: "1", NEXT_PUBLIC_API_BASE_URL: "http://localhost:8110", SITE_URL: production ? "https://src.example.invalid" : "http://localhost:3111", LANDING_INDEXABLE: production ? "true" : "false", SORERUNAPP_TEST_OUTPUT: production ? "landing-build" : "landing-live" } },
  ],
});
