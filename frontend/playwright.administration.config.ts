import { defineConfig } from "@playwright/test";
import base from "./playwright.config";

export default defineConfig({ ...base,
  webServer: {
    command: "npm run dev -- --hostname localhost --port 3100",
    url: "http://localhost:3100/login",
    reuseExistingServer: false,
    timeout: 120_000,
    env: {
      NEXT_TELEMETRY_DISABLED: "1", SORERUNAPP_TEST_OUTPUT: "administration",
      NEXT_PUBLIC_API_BASE_URL: "http://localhost:8000",
      NEXT_PUBLIC_PRIVACY_NOTICE_VERSION: "dev-v1", NEXT_PUBLIC_TERMS_VERSION: "dev-v1",
      LANDING_INDEXABLE: "false", SITE_URL: "http://localhost:3100",
    },
  },
});
