import { defineConfig, devices } from "@playwright/test";

export default defineConfig({
  testDir: "./tests/live",
  workers: 1,
  retries: 0,
  timeout: 90_000,
  expect: { timeout: 20_000 },
  reporter: "list",
  use: {
    baseURL: "http://localhost:3119", trace: "off", screenshot: "off", video: "off",
    ...devices["Desktop Chrome"],
    launchOptions: process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH ? { executablePath: process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH } : {},
  },
  webServer: [
    { command: `"${process.env.MAILPIT_EXECUTABLE_PATH ?? "mailpit"}" --listen 127.0.0.1:8029 --smtp 127.0.0.1:1029 --database .next/stage09-mailpit.db`, url: "http://127.0.0.1:8029", reuseExistingServer: false, timeout: 30_000 },
    { command: "php -S localhost:8109 -t ../backend/public ../backend/tests/Support/stage09-live-router.php", url: "http://localhost:8109/up", reuseExistingServer: false, timeout: 30_000 },
    { command: "npm run dev -- --hostname localhost --port 3119", url: "http://localhost:3119/login", reuseExistingServer: false, timeout: 120_000,
      env: { NEXT_TELEMETRY_DISABLED: "1", SORERUNAPP_TEST_OUTPUT: "live", NEXT_PUBLIC_API_BASE_URL: "http://localhost:8109", NEXT_PUBLIC_PRIVACY_NOTICE_VERSION: "dev-v1", NEXT_PUBLIC_TERMS_VERSION: "dev-v1" } },
  ],
});
