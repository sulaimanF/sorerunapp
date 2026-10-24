import { test, expect, type BrowserContext, type Page } from "@playwright/test";
import type { MemberProfile } from "../../src/features/members/schemas";

const API = "http://localhost:8000";
const FRONTEND = "http://localhost:3100";
const meta = { request_id: "c9340265-31f2-4f5c-a951-7631bf8b2c44" };
const initialName = "Anggota Profil Browser";
const errors = new WeakMap<Page, string[]>();
test.beforeEach(({ page }) => {
  const seen: string[] = [];
  errors.set(page, seen);
  page.on("pageerror", (error) => seen.push(error.message));
  page.on("console", (message) => {
    if (message.type() === "error" && /hydration|hydrated/i.test(message.text())) seen.push(message.text());
  });
});
test.afterEach(({ page }) => expect(errors.get(page) ?? []).toEqual([]));

async function fixture(context: BrowserContext) {
  const state: {
    loggedIn: boolean; ownerId: number; email: string; active: boolean; verified: boolean;
    profile: MemberProfile; patches: number; completed: number; reads: number; readFails: boolean;
    validationFails: boolean; expireOnSave: boolean; commitThenDisconnect: boolean;
    holdPatch: Promise<void> | null;
  } = {
    loggedIn: true, ownerId: 42, email: "profile-stage07@example.test", active: true, verified: true,
    profile: { full_name: initialName, birth_date: "2012-02-29", phone: "+6281234567890", city: "Kota Uji", gender: null, profile_complete: true, updated_at: "2026-10-24T00:00:00Z" },
    patches: 0, completed: 0, reads: 0, readFails: false, validationFails: false, expireOnSave: false,
    commitThenDisconnect: false, holdPatch: null,
  };
  const csrf = "profile-xsrf+";
  await context.addCookies([
    { name: "XSRF-TOKEN", value: encodeURIComponent(csrf), domain: "localhost", path: "/", sameSite: "Lax" },
    { name: "sorerunapp_session", value: "synthetic-stage07-session", domain: "localhost", path: "/", sameSite: "Lax", httpOnly: true },
  ]);
  const session = () => ({
    user: { id: state.ownerId, full_name: state.profile.full_name, email: state.email, email_verified: state.verified },
    roles: ["member"], profile_complete: state.profile.profile_complete,
    membership: { status: state.active ? "active" : "pending_verification", member_number: state.active ? "SRC-000042" : null, joined_at: state.active ? "2026-10-18T10:00:00Z" : null },
  });
  // All API traffic is synthetic; these tests do not contact Laravel, MySQL or an email provider.
  await context.route(`${API}/**`, async (route) => {
    const request = route.request();
    const path = new URL(request.url()).pathname;
    const respond = (status: number, body?: unknown) => route.fulfill({
      status,
      headers: { "Access-Control-Allow-Origin": FRONTEND, "Access-Control-Allow-Credentials": "true", "Access-Control-Allow-Headers": "Accept, Content-Type, X-XSRF-TOKEN", "Access-Control-Allow-Methods": "GET, POST, PATCH, OPTIONS", "Access-Control-Expose-Headers": "Retry-After, X-Request-ID", "Cache-Control": "no-store", "X-Request-ID": meta.request_id },
      ...(status === 204 ? {} : { contentType: "application/json", body: JSON.stringify(body) }),
    });
    const fail = (status: number, code: string, message: string, fields = {}) => respond(status, { error: { code, message, fields }, meta });
    if (request.method() === "OPTIONS" || path === "/sanctum/csrf-cookie") return respond(204);
    if (["POST", "PATCH"].includes(request.method()) && request.headers()["x-xsrf-token"] !== csrf)
      return fail(419, "CSRF_MISMATCH", "Sesi permintaan berubah.");
    if (path === "/api/v1/auth/login") {
      expect(request.postDataJSON().email).toBe(state.email);
      state.loggedIn = true;
      return respond(200, { data: session(), meta });
    }
    if (!state.loggedIn) return fail(401, "UNAUTHENTICATED", "Silakan masuk untuk melanjutkan.");
    if (path === "/api/v1/auth/me") return respond(200, { data: session(), meta });
    if (path === "/api/v1/auth/logout") { state.loggedIn = false; return respond(204); }
    if (path === "/api/v1/me/membership/card") return respond(200, { data: { community_name: "Sore Running Club", full_name: state.profile.full_name, member_number: "SRC-000042", qr_payload: `src-member:v1:${"c".repeat(43)}`, issued_at: "2026-10-18T10:00:00Z" }, meta });
    if (path === "/api/v1/me/profile" && request.method() === "GET") {
      state.reads++;
      if (state.readFails) return fail(503, "SERVICE_UNAVAILABLE", "Profil belum dapat dimuat.");
      return respond(200, { data: state.profile, meta });
    }
    if (path === "/api/v1/me/profile" && request.method() === "PATCH") {
      const owner = state.ownerId;
      const before = state.profile;
      const body = request.postDataJSON();
      expect(Object.keys(body).sort()).toEqual(["birth_date", "city", "full_name", "gender", "phone"]);
      state.patches++;
      if (state.holdPatch) await state.holdPatch;
      state.completed++;
      if (state.expireOnSave) { state.loggedIn = false; return fail(401, "SESSION_EXPIRED", "Sesi sudah berakhir."); }
      if (state.validationFails) return fail(422, "VALIDATION_FAILED", "Periksa kembali data yang Anda isi.", { city: ["Domisili perlu diperbaiki oleh anggota."] });
      const saved: MemberProfile = { ...before, ...body, phone: body.phone.startsWith("0") ? `+62${body.phone.slice(1)}` : body.phone, profile_complete: true, updated_at: new Date(Date.UTC(2026, 9, 24, 0, 0, state.patches)).toISOString() };
      if (owner === state.ownerId) {
        state.profile = saved;
        if (state.verified) state.active = true;
      }
      if (state.commitThenDisconnect) return route.abort("failed");
      if (!request.failure()) return respond(200, { data: saved, meta });
      return;
    }
    return fail(404, "NOT_FOUND", "Endpoint fixture tidak tersedia.");
  });
  return state;
}

async function openProfile(page: Page) {
  await page.goto("/profil");
  await expect(page.getByRole("form", { name: "Form profil anggota" })).toBeVisible();
}
const save = (page: Page) => page.getByRole("button", { name: "Simpan Perubahan", exact: true }).click();

test("profile saves normalized biodata and refreshes the account and card without replacing QR", async ({ page, context }) => {
  const state = await fixture(context);
  await openProfile(page);
  await expect(page.getByRole("textbox", { name: "Tanggal lahir", exact: true })).toHaveValue("29/02/2012");
  const name = "Anggota <script>nama</script> Uji";
  await page.getByLabel("Nama lengkap").fill(name);
  await page.getByLabel("Nomor telepon").fill("081311122233");
  await page.getByLabel("Kota/domisili").fill("Jakarta Uji");
  await save(page);
  await expect(page.getByText("Profil berhasil diperbarui.", { exact: true })).toBeVisible();
  await expect(page.getByLabel("Nomor telepon")).toHaveValue("+6281311122233");
  await expect(page.locator(".account-menu")).toContainText(name);
  await page.getByRole("navigation", { name: "Navigasi utama" }).getByRole("link", { name: "Kartu", exact: true }).click();
  await expect(page.getByRole("heading", { name, exact: true })).toBeVisible();
  await expect(page.getByRole("img", { name: "QR keanggotaan untuk dipindai petugas" })).toBeVisible();
  expect(state.patches).toBe(1);
  expect(state.profile.gender).toBe(null);
});

test("profile validation preserves input, rejects a future date and cancellation restores server values", async ({ page, context }) => {
  const state = await fixture(context);
  await openProfile(page);
  await page.getByLabel("Nama lengkap").fill("A");
  await page.getByRole("textbox", { name: "Tanggal lahir", exact: true }).fill("01/01/2999");
  await save(page);
  await expect(page.getByText("Nama minimal 2 karakter.", { exact: true })).toBeVisible();
  await expect(page.getByText("Periksa data yang ditandai sebelum menyimpan.", { exact: true })).toBeVisible();
  await expect(page.getByLabel("Nama lengkap")).toBeFocused();
  await expect(page.getByRole("textbox", { name: "Tanggal lahir", exact: true })).toHaveValue("01/01/2999");
  expect(state.patches).toBe(0);
  await page.getByRole("button", { name: "Batalkan Perubahan" }).click();
  await expect(page.getByLabel("Nama lengkap")).toHaveValue(initialName);
  await expect(page.getByRole("textbox", { name: "Tanggal lahir", exact: true })).toHaveValue("29/02/2012");
});

test("profile server validation keeps unsaved edits and focuses the rejected field", async ({ page, context }) => {
  const state = await fixture(context); state.validationFails = true;
  await openProfile(page);
  await page.getByLabel("Nama lengkap").fill("Nama Belum Tersimpan");
  await page.getByLabel("Kota/domisili").fill("Kota Koreksi");
  await save(page);
  await expect(page.getByText("Domisili perlu diperbaiki oleh anggota.", { exact: true })).toBeVisible();
  await expect(page.getByLabel("Nama lengkap")).toHaveValue("Nama Belum Tersimpan");
  await expect(page.getByLabel("Kota/domisili")).toBeFocused();
  expect(state.profile.full_name).toBe(initialName);
});

test("unknown profile save outcome is checked with GET without replaying the mutation", async ({ page, context }) => {
  const state = await fixture(context); state.commitThenDisconnect = true;
  await openProfile(page);
  await page.getByLabel("Kota/domisili").fill("Sudah Tersimpan Uji");
  await save(page);
  await expect(page.getByText(/Hasil penyimpanan belum terkonfirmasi/)).toBeVisible();
  await expect(page.getByText("Profil berhasil diperbarui.", { exact: true })).toHaveCount(0);
  await page.getByRole("button", { name: "Periksa Data Tersimpan" }).click();
  await expect(page.getByText("Data profil terbaru berhasil dimuat.", { exact: true })).toBeVisible();
  await expect(page.getByLabel("Kota/domisili")).toHaveValue("Sudah Tersimpan Uji");
  expect(state.patches).toBe(1);
  expect(state.reads).toBeGreaterThanOrEqual(2);
});

test("profile save rejection after session expiry hides private biodata", async ({ page, context }) => {
  const state = await fixture(context); state.expireOnSave = true;
  await openProfile(page);
  await page.getByLabel("Kota/domisili").fill("Tidak Tersimpan");
  await save(page);
  await expect(page).toHaveURL(/\/login(?:\?|$)/);
  await expect(page.getByRole("form", { name: "Form profil anggota" })).toHaveCount(0);
  await expect(page.getByRole("textbox", { name: "Email", exact: true })).toBeVisible();
});

test("profile read failure offers retry without inventing empty biodata", async ({ page, context }) => {
  const state = await fixture(context); state.readFails = true;
  await page.goto("/profil");
  await expect(page.getByRole("heading", { name: "Profil belum dapat ditampilkan" })).toBeVisible();
  await expect(page.getByRole("form", { name: "Form profil anggota" })).toHaveCount(0);
  state.readFails = false;
  await page.getByRole("button", { name: "Coba Lagi", exact: true }).click();
  await expect(page.getByLabel("Nama lengkap")).toHaveValue(initialName);
});

test("late profile update cannot populate the next account after logout", async ({ page, context }) => {
  const state = await fixture(context);
  let release: (() => void) | undefined;
  state.holdPatch = new Promise<void>((resolve) => { release = resolve; });
  try {
    await openProfile(page);
    await page.getByLabel("Nama lengkap").fill("Nama Akun Lama");
    const patch = page.waitForRequest((request) => request.url().includes("/me/profile") && request.method() === "PATCH");
    await save(page); await patch;
    await page.getByRole("button", { name: "Keluar dari akun" }).click();
    await expect(page).toHaveURL(/\/login(?:\?|$)/);
    state.ownerId = 43; state.email = "profile-second@example.test";
    state.profile = { ...state.profile, full_name: "Anggota Kedua", city: "Kota Kedua" };
    await page.getByRole("textbox", { name: "Email", exact: true }).fill(state.email);
    await page.getByLabel(/^Password/).fill("Test-only-password-123");
    await page.getByRole("button", { name: "Masuk", exact: true }).click();
    await expect(page).toHaveURL(/\/(?:beranda|profil)$/);
    if (new URL(page.url()).pathname !== "/profil")
      await page.getByRole("navigation", { name: "Navigasi utama" }).getByRole("link", { name: "Profil", exact: true }).click();
    await expect(page.getByLabel("Nama lengkap")).toHaveValue("Anggota Kedua");
    release?.();
    await expect.poll(() => state.completed).toBe(1);
    await page.unrouteAll({ behavior: "wait" });
    await expect(page.getByLabel("Nama lengkap")).toHaveValue("Anggota Kedua");
    expect(await page.evaluate(() => ({ local: localStorage.length, session: sessionStorage.length }))).toEqual({ local: 0, session: 0 });
  } finally { release?.(); await page.unrouteAll({ behavior: "wait" }); }
});

test("profile form fits a 320px viewport and keyboard controls remain usable", async ({ page, context }) => {
  const state = await fixture(context); state.profile.full_name = "Anggota dengan nama panjang ".repeat(4).trim();
  await page.setViewportSize({ width: 320, height: 720 });
  await openProfile(page);
  await page.getByLabel("Kota/domisili").fill("Kota Mobile");
  await page.getByRole("button", { name: "Simpan Perubahan" }).focus();
  await page.keyboard.press("Enter");
  await expect(page.getByText("Profil berhasil diperbarui.", { exact: true })).toBeVisible();
  expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBe(true);
});

test("completed verified profile refreshes pending membership status from the session API", async ({ page, context }) => {
  const state = await fixture(context); state.active = false; state.profile.city = ""; state.profile.profile_complete = false;
  await openProfile(page);
  await page.getByLabel("Kota/domisili").fill("Kota Lengkap");
  await save(page);
  await expect(page.getByText("Profil berhasil diperbarui.", { exact: true })).toBeVisible();
  await expect(page.getByText("Aktif", { exact: true })).toBeVisible();
  expect(state.patches).toBe(1);
});
