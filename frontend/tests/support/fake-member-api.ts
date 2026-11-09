import { expect, type Page, type BrowserContext } from "@playwright/test";

export const API = "http://localhost:8000";
export const FRONTEND = "http://localhost:3100";
export const fixtureName = "Anggota Browser Uji";
export const email = "browser-stage06@example.test";
export const payload = `src-member:v1:${"c".repeat(43)}`;
export const signed = `${API}/api/v1/auth/email-verification/42/${"a".repeat(40)}?expires=1999999999&v=1&signature=${"b".repeat(64)}`;
const meta = { request_id: "d6578996-20ac-4d4e-ab72-ef8d187fe122" };

type FakeState = {
  loggedIn: boolean;
  active: boolean;
  cardDenied: boolean;
  logoutFails: boolean;
  logoutStatus: number;
  sessionFails: boolean;
  resendRateLimited: boolean;
  loginCount: number;
  verifyCount: number;
  cardCount: number;
  csrf: string;
  cardDelay: number;
};
export async function fixtureApi(
  context: BrowserContext,
  initial: Partial<FakeState> = {},
) {
  const state: FakeState = {
    loggedIn: false,
    active: true,
    cardDenied: false,
    logoutFails: false,
    logoutStatus: 204,
    sessionFails: false,
    resendRateLimited: false,
    loginCount: 0,
    verifyCount: 0,
    cardCount: 0,
    csrf: "csrf-before-login+",
    cardDelay: 0,
    ...initial,
  };
  const session = () => ({
    user: {
      id: 42,
      full_name: fixtureName,
      email,
      email_verified: state.active,
    },
    roles: ["member"],
    profile_complete: true,
    membership: {
      status: state.active ? "active" : "pending_verification",
      member_number: state.active ? "SRC-000042" : null,
      joined_at: state.active ? "2026-10-18T10:00:00Z" : null,
    },
  });
  async function cookies() {
    await context.addCookies([
      {
        name: "XSRF-TOKEN",
        value: encodeURIComponent(state.csrf),
        domain: "localhost",
        path: "/",
        sameSite: "Lax",
      },
      {
        name: "sorerunapp_session",
        value: state.loggedIn
          ? "synthetic-member-session"
          : "synthetic-guest-session",
        domain: "localhost",
        path: "/",
        httpOnly: true,
        sameSite: "Lax",
      },
    ]);
  }
  await cookies();
  // Every API request is intercepted; these tests never contact a real Laravel/database.
  await context.route(`${API}/**`, async (route) => {
    const request = route.request();
    const path = new URL(request.url()).pathname;
    const headers = {
      "Access-Control-Allow-Origin": FRONTEND,
      "Access-Control-Allow-Credentials": "true",
      "Access-Control-Allow-Headers": "Accept, Content-Type, X-XSRF-TOKEN",
      "Access-Control-Allow-Methods": "GET, POST, OPTIONS",
      "Access-Control-Expose-Headers": "Retry-After, X-Request-ID",
      "Cache-Control": "no-store",
      "X-Request-ID": meta.request_id,
    };
    const respond = (status: number, body?: unknown, extra = {}) =>
      route.fulfill({
        status,
        headers: { ...headers, ...extra },
        ...(status === 204
          ? {}
          : { contentType: "application/json", body: JSON.stringify(body) }),
      });
    const error = (status: number, code: string, message: string, extra = {}) =>
      respond(status, { error: { code, message, fields: {} }, meta }, extra);
    if (request.method() === "OPTIONS") return respond(204);
    if (path === "/sanctum/csrf-cookie") {
      await cookies();
      return respond(204);
    }
    if (
      request.method() === "POST" &&
      request.headers()["x-xsrf-token"] !== state.csrf
    )
      return error(419, "CSRF_MISMATCH", "Sesi permintaan berubah.");
    if (path === "/api/v1/auth/register") {
      const body = request.postDataJSON();
      expect(body.birth_date).toBe("2015-01-01");
      expect(body.privacy_notice_version).toBe("dev-v1");
      expect(body.terms_version).toBe("dev-v1");
      expect(body.password_confirmation).toBe(body.password);
      return respond(201, { data: { result: "registration_received" }, meta });
    }
    if (path === "/api/v1/auth/login") {
      state.loginCount++;
      if (request.postDataJSON().password !== "Test-only-password-123")
        return error(
          401,
          "INVALID_CREDENTIALS",
          "Email atau password tidak sesuai.",
        );
      if (!state.active) return error(403, "EMAIL_NOT_VERIFIED", "Verifikasi email Anda sebelum masuk.");
      state.loggedIn = true;
      state.csrf = "csrf-after-login+";
      await cookies();
      return respond(200, { data: session(), meta });
    }
    if (path.startsWith("/api/v1/auth/email-verification/")) {
      if (path.endsWith("/resend")) {
        expect(request.postDataJSON()).toEqual({ email: expect.any(String) });
        return state.resendRateLimited
          ? error(429, "RATE_LIMITED", "Tunggu sebelum mengirim kembali.", { "Retry-After": "65" })
          : respond(202, { data: { result: "verification_requested" }, meta }, { "Retry-After": "60" });
      }
      if (request.method() === "GET") return respond(200, { data: { result: "verification_link_ready" }, meta });
      expect(request.method()).toBe("POST");
      expect(request.postDataJSON()).toEqual({});
      state.verifyCount++;
      const result = state.active ? "already_verified" : "email_verified";
      state.active = true;
      return respond(200, { data: { result }, meta });
    }
    if (path === "/api/v1/auth/me" && state.sessionFails) return route.abort("failed");
    if (!state.loggedIn) return error(401, "UNAUTHENTICATED", "Silakan masuk untuk melanjutkan.");
    if (!state.active) {
      state.loggedIn = false;
      return error(403, "EMAIL_NOT_VERIFIED", "Verifikasi email Anda sebelum masuk.");
    }
    if (path === "/api/v1/auth/me") return respond(200, { data: session(), meta });
    if (path === "/api/v1/me/membership/card") {
      state.cardCount++;
      if (state.cardDelay)
        await new Promise((resolve) => setTimeout(resolve, state.cardDelay));
      if (!state.active || state.cardDenied)
        return error(
          403,
          "MEMBERSHIP_CARD_UNAVAILABLE",
          "Kartu keanggotaan belum tersedia.",
        );
      return respond(200, {
        data: {
          community_name: "Sore Running Club",
          full_name: fixtureName,
          member_number: "SRC-000042",
          qr_payload: payload,
          issued_at: "2026-10-18T10:00:00+00:00",
        },
        meta,
      });
    }
    if (path === "/api/v1/auth/logout") {
      if (state.logoutFails) return route.abort("failed");
      if (state.logoutStatus !== 204) return respond(state.logoutStatus, { data: {}, meta });
      state.loggedIn = false;
      state.csrf = "csrf-after-logout+";
      await cookies();
      return respond(204);
    }
    return error(404, "NOT_FOUND", "Endpoint fixture tidak dikenal.");
  });
  return state;
}
export async function login(page: Page, returnPath = "/beranda") {
  await page.goto("/login");
  await page.getByRole("textbox", { name: "Email", exact: true }).fill(email);
  await page.getByLabel(/^Password/).fill("Test-only-password-123");
  await page.getByRole("button", { name: "Masuk", exact: true }).click();
  // auth-navigation: fixed landing, then explicit member navigation.
  await expect(page).toHaveURL(/\/beranda$/);
  if (returnPath !== "/beranda") await page.goto(returnPath);
  await expect(page).toHaveURL(new RegExp(`${returnPath}$`));
}
