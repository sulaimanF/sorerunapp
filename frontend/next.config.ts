import type { NextConfig } from "next";

const nextConfig: NextConfig = {
  distDir: process.env.SORERUNAPP_TEST_OUTPUT === "browser"
    ? ".next/stage09-browser"
    : process.env.SORERUNAPP_TEST_OUTPUT === "build" ? ".next/stage09-build"
    : process.env.SORERUNAPP_TEST_OUTPUT === "live" ? ".next/stage09-live" : ".next",
  async redirects() {
    return [{ source: "/", destination: "/login", permanent: false }];
  },
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
