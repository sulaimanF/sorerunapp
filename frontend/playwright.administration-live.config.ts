import { defineConfig, devices } from "@playwright/test";

const useBuild = process.env.SORERUNAPP_STAGE11_BROWSER_BUILD === "1";

export default defineConfig({
  testDir: "./tests/administration-live", workers: 1, retries: 0, timeout: 150_000,
  expect: { timeout: 20_000 }, reporter: "list",
  use: { baseURL: "http://localhost:3130", trace: "off", screenshot: "off", video: "off", ...devices["Desktop Chrome"] },
  webServer: [
    { command: "php -S localhost:8130 -t ../backend/public ../backend/tests/Support/stage11-live-router.php", url: "http://localhost:8130/__stage11/health", reuseExistingServer: false, timeout: 60_000 },
    { command: `npm run ${useBuild ? "start" : "dev"} -- --hostname localhost --port 3130`, url: "http://localhost:3130/login", reuseExistingServer: false, timeout: 120_000,
      env: { NEXT_TELEMETRY_DISABLED: "1", NEXT_PUBLIC_API_BASE_URL: "http://localhost:8130", SITE_URL: "http://localhost:3130", LANDING_INDEXABLE: "false", SORERUNAPP_TEST_OUTPUT: useBuild ? "administration-build" : "administration-live" } },
  ],
});
