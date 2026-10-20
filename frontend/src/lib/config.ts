import { ApiError } from "./errors";

export function validateApiOrigin(value: string | undefined): string {
  try {
    if (!value) throw new Error();
    const url = new URL(value);
    if (
      !["http:", "https:"].includes(url.protocol) ||
      url.username ||
      url.password ||
      url.pathname !== "/" ||
      url.search ||
      url.hash
    )
      throw new Error();
    return url.origin;
  } catch {
    throw new ApiError(
      "Konfigurasi layanan belum tersedia. Hubungi pengurus.",
      503,
      "CONFIGURATION_UNAVAILABLE",
    );
  }
}

export function apiOrigin(): string {
  return validateApiOrigin(process.env.NEXT_PUBLIC_API_BASE_URL);
}

export function registrationPolicies() {
  const privacy = process.env.NEXT_PUBLIC_PRIVACY_NOTICE_VERSION;
  const terms = process.env.NEXT_PUBLIC_TERMS_VERSION;
  if (!privacy || !terms || privacy.length > 64 || terms.length > 64) {
    throw new ApiError(
      "Layanan pendaftaran belum tersedia. Hubungi pengurus.",
      503,
      "CONFIGURATION_UNAVAILABLE",
    );
  }
  return { privacy_notice_version: privacy, terms_version: terms };
}
