import "server-only";
import { cache } from "react";
import { unstable_rethrow } from "next/navigation";
import { validateApiOrigin } from "@/lib/config";
import { landingPublicationSchema } from "./schemas";

// React cache deduplicates metadata/page within one render, not across HTTP requests.
export const readPublicLanding = cache(async () => {
  const origin = validateApiOrigin(process.env.NEXT_PUBLIC_API_BASE_URL);
  const response = await fetch(`${origin}/api/v1/public/landing-page`, {
    cache: "no-store", credentials: "omit", redirect: "error",
    headers: { Accept: "application/json" }, signal: AbortSignal.timeout(10000),
  }).catch((error: unknown) => {
    unstable_rethrow(error);
    const cause: unknown = error instanceof Error ? error.cause : undefined;
    const code: unknown = cause && typeof cause === "object" ? Reflect.get(cause, "code") : undefined;
    // Log only bounded technical codes, never the URL, response body or exception.
    console.error("Public landing transport failed.", { code: typeof code === "string" && code.length <= 48 && /^(?:E[A-Z_]+|UND_ERR_[A-Z_]+)$/.test(code) ? code : error instanceof Error && error.name === "TimeoutError" ? "TIMEOUT" : "UNAVAILABLE" });
    throw error;
  });
  if (!response.ok) {
    console.error("Public landing API rejected the request.", { status: response.status });
    throw new Error("PUBLIC_CONTENT_UNAVAILABLE");
  }
  const envelope: unknown = await response.json().catch((error: unknown) => {
    unstable_rethrow(error);
    console.error("Public landing response could not be read.");
    throw error;
  });
  const result = landingPublicationSchema.safeParse(envelope && typeof envelope === "object" ? Reflect.get(envelope, "data") : undefined);
  if (!result.success) {
    console.error("Public landing API returned an invalid contract.");
    throw new Error("PUBLIC_CONTENT_INVALID");
  }
  return result.data.publication;
});

export function publicSiteOrigin() {
  // Canonical never comes from an untrusted Host header. Production must configure this.
  return validateApiOrigin(process.env.SITE_URL);
}

export function landingIndexable() {
  if (process.env.NODE_ENV !== "production" || process.env.LANDING_INDEXABLE !== "true") return false;
  const site = new URL(publicSiteOrigin());
  return site.protocol === "https:" && !["localhost", "127.0.0.1", "[::1]"].includes(site.hostname) && !site.hostname.endsWith(".test") && !site.hostname.endsWith(".localhost");
}
