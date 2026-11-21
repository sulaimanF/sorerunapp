import type { NextConfig } from "next";

const nextConfig: NextConfig = {
  distDir: process.env.SORERUNAPP_TEST_OUTPUT === "administration" ? ".next/stage11-browser" : process.env.SORERUNAPP_TEST_OUTPUT === "administration-live" ? ".next/stage11-live" : process.env.SORERUNAPP_TEST_OUTPUT === "administration-build" ? ".next/stage11-build" : process.env.SORERUNAPP_TEST_OUTPUT === "landing-manual" ? ".next/landing-manual" : process.env.SORERUNAPP_TEST_OUTPUT === "landing-live" ? ".next/landing-live" : process.env.SORERUNAPP_TEST_OUTPUT === "landing-build" ? ".next/landing-build" : process.env.SORERUNAPP_TEST_OUTPUT === "landing" ? ".next/landing-browser" : process.env.SORERUNAPP_TEST_OUTPUT === "browser"
    ? ".next/stage09-browser"
    : process.env.SORERUNAPP_TEST_OUTPUT === "build" ? ".next/stage09-build"
    : process.env.SORERUNAPP_TEST_OUTPUT === "live" ? ".next/stage09-live" : ".next",
  async headers() {
    return [
      {
        source: "/:path*",
        headers: [
          { key: "Referrer-Policy", value: "no-referrer" },
          { key: "X-Content-Type-Options", value: "nosniff" },
        ],
      },
    ];
  },
  cacheComponents: true,
  partialPrefetching: true,
  turbopack: {
    rules: {
      "*.css": {
        loaders: ["@tailwindcss/turbopack"],
        as: "*.css",
      },
    },
  },
};

export default nextConfig;
