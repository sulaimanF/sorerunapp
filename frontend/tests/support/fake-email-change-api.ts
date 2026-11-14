import { expect, type BrowserContext } from "@playwright/test";
import type { EmailChangeStatus } from "../../src/features/auth/schemas";
import { API, fixtureApi } from "./fake-member-api";

export const changeRequestId = "d6578996-20ac-4d4e-ab72-ef8d187fe122";
export const oldEmail = "email-stage09@example.test";
export const newEmail = "pending-stage09@example.test";
export const changeLink = `/verify-email-change#request_id=${changeRequestId}&email=${encodeURIComponent(newEmail)}&token=${"a".repeat(64)}`;
export async function emailChangeFixture(context: BrowserContext) {
  await fixtureApi(context, { loggedIn: true });
  const state = {
    loggedIn: true, email: oldEmail, ownerId: 42,
    pending: null as EmailChangeStatus["pending"], cooldownUntil: 0,
    requests: 0, confirms: 0, logins: 0, error: 0, wrongOwner: false, readFails: false,
    delay: 0, fieldError: false, readDelay: 0, reads: 0,
  };
  const meta = { request_id: changeRequestId };
  // All these routes are fixtures; no traffic is sent to Laravel or a mail provider.
  await context.route(`${API}/api/v1/**`, async route => {
    const request = route.request();
    const path = new URL(request.url()).pathname;
    const respond = (status: number, data: unknown, headers = {}) => route.fulfill({ status, headers: {
      "Access-Control-Allow-Origin": "http://localhost:3100", "Access-Control-Allow-Credentials": "true",
      "Access-Control-Allow-Headers": "Accept, Content-Type, X-XSRF-TOKEN", "Access-Control-Allow-Methods": "GET, POST, PATCH, OPTIONS",
      "Access-Control-Expose-Headers": "Retry-After, X-Request-ID", "Cache-Control": "no-store", ...headers,
    }, contentType: "application/json", body: JSON.stringify(data) });
    const fail = (status: number, code: string, message: string, fields = {}) => respond(status, { error: { code, message, fields }, meta }, status === 429 ? { "Retry-After": "73" } : {});
    if (request.method() === "OPTIONS") return respond(200, {});
    if (request.method() === "POST") expect(request.headers()["x-xsrf-token"]).toBe("csrf-before-login+");
    if (path.endsWith("/auth/login")) {
      expect(request.postDataJSON().email).toBe(state.email);
      state.loggedIn = true; state.logins++;
    }
    if (!state.loggedIn) return fail(401, "UNAUTHENTICATED", "Silakan masuk untuk melanjutkan.");
    if (path.endsWith("/auth/me") || path.endsWith("/auth/login")) return respond(200, { data: {
      user: { id: state.ownerId, full_name: "Anggota Email Uji", email: state.email, email_verified: true }, roles: ["member"], profile_complete: true,
      membership: { status: "active", member_number: "SRC-000042", joined_at: "2026-10-18T10:00:00Z" },
    }, meta });
    if (path.endsWith("/me/profile")) {
      state.reads++;
      if (state.readDelay) await new Promise(resolve => setTimeout(resolve, state.readDelay));
      if (state.readFails) return fail(503, "SERVICE_UNAVAILABLE", "Status email belum dapat dibaca.");
      return respond(200, { data: { full_name: "Anggota Email Uji", birth_date: "2000-01-01", phone: "+6281234567890", city: "Kota Uji", gender: null,
        profile_complete: true, updated_at: "2026-11-11T00:00:00Z",
        email_change: { active_email: state.email, pending: state.pending, retry_after: Math.max(0, Math.ceil((state.cooldownUntil - Date.now()) / 1000)) },
      }, meta });
    }
    if (path.endsWith("/me/email-change") || path.endsWith("/me/email-change/verify")) {
      const verify = path.endsWith("/verify");
      if (verify) state.confirms++; else state.requests++;
      if (state.delay) await new Promise(resolve => setTimeout(resolve, state.delay));
      if (state.error === -1) return route.abort("failed");
      if (state.error) return fail(state.error, state.error === 429 ? "RATE_LIMITED" : "CSRF_MISMATCH", "Permintaan belum dapat diproses.");
      if (state.wrongOwner && verify) return fail(422, "EMAIL_CHANGE_INVALID", "Tautan tidak sesuai dengan akun ini, kedaluwarsa, atau sudah digunakan.");
      if (state.fieldError) return fail(422, "PASSWORD_CONFIRMATION_FAILED", "Password akun saat ini tidak sesuai.", { password: ["Password akun saat ini tidak sesuai."] });
      if (verify) {
        expect(request.postDataJSON()).toEqual({ request_id: changeRequestId, email: newEmail, token: "a".repeat(64) });
        state.email = newEmail; state.loggedIn = false;
        return respond(200, { data: { result: "email_changed" }, meta });
      }
      state.pending = { email: request.postDataJSON().email, expires_at: new Date(Date.now() + 3600_000).toISOString(), status: "pending" };
      state.cooldownUntil = Date.now() + 3000;
      return respond(202, { data: { result: "email_change_requested" }, meta }, { "Retry-After": "3" });
    }
    return route.fallback();
  });
  return state;
}
