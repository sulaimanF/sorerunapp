import { defineConfig, devices } from "@playwright/test";

export default defineConfig({
  testDir: "./tests/landing", fullyParallel: false, workers: 1, timeout: 60000,
  expect: { timeout: 15000 }, retries: 0, reporter: "list",
  use: { baseURL: "http://localhost:3110", trace: "off", screenshot: "off", video: "off", ...devices["Desktop Chrome"] },
  webServer: [
    { command: "node tests/support/landing-api-server.mjs", url: "http://localhost:18100/health", reuseExistingServer: false, env: { LANDING_FIXTURE_SERVER: "true" } },
    { command: "npm run dev -- --hostname localhost --port 3110", url: "http://localhost:3110/login", reuseExistingServer: false, timeout: 120000,
      env: { NEXT_PUBLIC_API_BASE_URL: "http://localhost:18100", SITE_URL: "http://localhost:3110", LANDING_INDEXABLE: "false", SORERUNAPP_TEST_OUTPUT: "landing", NEXT_TELEMETRY_DISABLED: "1" } },
  ],
});
