import { test, expect, type Page, type BrowserContext } from "@playwright/test";

const browserErrors = new WeakMap<Page, string[]>();
test.beforeEach(({ page }) => {
  const errors: string[] = [];
  browserErrors.set(page, errors);
  page.on("pageerror", (error) => errors.push(error.message));
  page.on("console", (message) => {
    if (
      message.type() === "error" &&
      /hydration|hydrated/i.test(message.text())
    )
      errors.push(message.text());
  });
});
test.afterEach(({ page }) => {
  expect(browserErrors.get(page) ?? []).toEqual([]);
});

const API = "http://localhost:8000";
const FRONTEND = "http://localhost:3100";
const fixtureName = "Anggota Browser Uji";
const email = "browser-stage06@example.test";
const payload = `src-member:v1:${"c".repeat(43)}`;
const signed = `${API}/api/v1/auth/email-verification/42/${"a".repeat(40)}?expires=1999999999&v=1&signature=${"b".repeat(64)}`;
const meta = { request_id: "d6578996-20ac-4d4e-ab72-ef8d187fe122" };

type FakeState = {
  loggedIn: boolean;
  active: boolean;
  cardDenied: boolean;
  logoutFails: boolean;
  resendRateLimited: boolean;
  loginRateLimited: boolean;
  loginCount: number;
  verifyCount: number;
  cardCount: number;
  csrf: string;
  cardDelay: number;
};
async function fixtureApi(
  context: BrowserContext,
  initial: Partial<FakeState> = {},
) {
  const state: FakeState = {
    loggedIn: false,
    active: false,
    cardDenied: false,
    logoutFails: false,
    resendRateLimited: false,
    loginRateLimited: false,
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
      if (state.loginRateLimited)
        return error(429, "RATE_LIMITED", "Terlalu banyak percobaan login.", {
          "Retry-After": "65",
        });
      if (request.postDataJSON().password !== "Test-only-password-123")
        return error(
          401,
          "INVALID_CREDENTIALS",
          "Email atau password tidak sesuai.",
        );
      state.loggedIn = true;
      state.csrf = "csrf-after-login+";
      await cookies();
      return respond(200, { data: session(), meta });
    }
    if (!state.loggedIn)
      return error(401, "UNAUTHENTICATED", "Silakan masuk untuk melanjutkan.");
    if (path === "/api/v1/auth/me")
      return respond(200, { data: session(), meta });
    if (path.startsWith("/api/v1/auth/email-verification/")) {
      if (path.endsWith("/resend"))
        return state.resendRateLimited
          ? error(429, "RATE_LIMITED", "Tunggu sebelum mengirim kembali.", {
              "Retry-After": "65",
            })
          : respond(202, { data: { result: "verification_requested" }, meta });
      state.verifyCount++;
      const result = state.active ? "already_active" : "membership_activated";
      state.active = true;
      return respond(200, {
        data: {
          result,
          membership: { status: "active", member_number: "SRC-000042" },
        },
        meta,
      });
    }
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
      state.loggedIn = false;
      state.csrf = "csrf-after-logout+";
      await cookies();
      return respond(204);
    }
    return error(404, "NOT_FOUND", "Endpoint fixture tidak dikenal.");
  });
  return state;
}
async function login(page: Page, returnPath = "/beranda") {
  await page.goto(`/login?returnTo=${encodeURIComponent(returnPath)}`);
  await page.getByRole("textbox", { name: "Email", exact: true }).fill(email);
  await page.getByLabel(/^Password/).fill("Test-only-password-123");
  await page.getByRole("button", { name: "Masuk", exact: true }).click();
  await expect(page).toHaveURL(new RegExp(`${returnPath}$`));
}

for (const active of [false, true]) {
  test(`existing ${active ? "active" : "unverified"} session opens home from login without another click`, async ({
    page,
    context,
  }) => {
    const state = await fixtureApi(context, { loggedIn: true, active });
    await page.goto("/login");
    // The development server compiles Beranda on its first navigation.
    await expect(page).toHaveURL(`${FRONTEND}/beranda`, { timeout: 30_000 });
    await expect(page.getByRole("form", { name: "Form login" })).toHaveCount(0);
    await expect(page.getByText(/Anda sedang masuk sebagai/)).toHaveCount(0);
    expect(state.loginCount).toBe(0);
    expect(state.verifyCount).toBe(0);
    if (!active)
      await expect(
        page.getByText("Menunggu verifikasi", { exact: true }),
      ).toBeVisible();
  });
}

test("existing session returns to verification automatically without activating membership", async ({
  page,
  context,
}) => {
  const state = await fixtureApi(context, { loggedIn: true });
  await page.goto("/login?returnTo=%2Fverify-email");
  await expect(page).toHaveURL(`${FRONTEND}/verify-email`);
  expect(state.loginCount).toBe(0);
  expect(state.verifyCount).toBe(0);
  expect(state.active).toBe(false);
});

test("login shows checking button before inline preparation until home commits", async ({
  page,
  context,
}) => {
  const state = await fixtureApi(context, { active: true });
  let releaseLogin: (() => void) | undefined;
  const loginReady = new Promise<void>((resolve) => {
    releaseLogin = resolve;
  });
  await page.route(`${API}/api/v1/auth/login`, async (route) => {
    if (route.request().method() === "POST") await loginReady;
    await route.fallback();
  });
  let releaseHome: (() => void) | undefined;
  const homeReady = new Promise<void>((resolve) => {
    releaseHome = resolve;
  });
  await page.route("**/beranda?*", async (route) => {
    await homeReady;
    await route.continue();
  });
  try {
    await page.goto("/login");
    await page.getByRole("textbox", { name: "Email", exact: true }).fill(email);
    await page.getByLabel(/^Password/).fill("Test-only-password-123");
    const loginResponse = page.waitForResponse(
      (response) => response.url() === `${API}/api/v1/auth/login` &&
        response.request().method() === "POST",
    );
    const loginRequest = page.waitForRequest(
      (request) => request.url() === `${API}/api/v1/auth/login` &&
        request.method() === "POST",
    );
    await page.getByRole("button", { name: "Masuk", exact: true }).click();
    await loginRequest;
    const checking = page.getByRole("button", { name: "Memeriksa akun…", exact: true });
    await expect(checking).toBeVisible();
    await expect(checking).toBeDisabled();
    await expect(page.getByRole("form", { name: "Form login" })).toBeVisible();
    await expect(
      page.getByRole("region", { name: "Persiapan akun" }),
    ).toHaveCount(0);
    releaseLogin?.();
    await (await loginResponse).finished();

    const preparation = page.getByRole("region", { name: "Persiapan akun" });
    await expect(preparation).toBeVisible();
    await expect(preparation).toContainText("Menyiapkan akun…");
    await expect(page.getByRole("form", { name: "Form login" })).toHaveCount(0);
    expect(new URL(page.url()).pathname).toBe("/login");
    expect(state.loginCount).toBe(1);
    // The brand panel stays interactive; preparation does not cover the screen.
    await page.getByRole("complementary").getByRole("link").click({ trial: true });

    releaseHome?.();
    await expect(page).toHaveURL(`${FRONTEND}/beranda`);
    await expect(
      page.getByRole("heading", { name: `Halo, ${fixtureName}.`, exact: true }),
    ).toBeVisible();
    await expect(preparation).toHaveCount(0);
    await expect(page.getByRole("form", { name: "Form login" })).toHaveCount(0);
  } finally {
    releaseLogin?.();
    releaseHome?.();
    await page.unrouteAll({ behavior: "wait" });
  }
});

test("login rate limit keeps its server cooldown without a countdown button label", async ({
  page,
  context,
}) => {
  const state = await fixtureApi(context, { active: true, loginRateLimited: true });
  await page.clock.install();
  await page.goto("/login");
  await page.getByRole("textbox", { name: "Email", exact: true }).fill(email);
  await page.getByLabel(/^Password/).fill("Test-only-password-123");
  await page.getByRole("button", { name: "Masuk", exact: true }).click();
  await expect(
    page.getByRole("form", { name: "Form login" }).getByRole("alert"),
  ).toContainText("Terlalu banyak percobaan login.");
  const button = page.getByRole("button", { name: "Masuk", exact: true });
  await expect(button).toBeDisabled();
  await expect(page.getByRole("button", { name: /Tunggu \d+ detik/ })).toHaveCount(0);
  expect(state.loggedIn).toBe(false);

  state.loginRateLimited = false;
  await page.clock.fastForward(66_000);
  await expect(button).toBeEnabled();
  await button.click();
  await expect(page).toHaveURL(`${FRONTEND}/beranda`);
  expect(state.loginCount).toBe(2);
});

test("registration, login, explicit verification, QR dialog, and logout", async ({
  page,
  context,
}) => {
  const state = await fixtureApi(context);
  await page.goto("/register");
  await page.getByLabel("Nama lengkap").fill(fixtureName);
  await page
    .getByRole("textbox", { name: "Tanggal lahir", exact: true })
    .fill("01/01/2015");
  await page.getByLabel("Nomor telepon").fill("081234567890");
  await page.getByLabel("Domisili / kota").fill("Kota Uji");
  await page.getByRole("textbox", { name: "Email", exact: true }).fill(email);
  await page.getByLabel(/^Password/).fill("Test-only-password-123");
  await page.getByLabel(/^Konfirmasi password/).fill("Test-only-password-123");
  await page.getByLabel(/Saya telah membaca/).check();
  await page.getByLabel(/Saya menyetujui/).check();
  await page
    .getByRole("button", { name: "Daftar Member", exact: true })
    .click();
  await expect(page).toHaveURL(/\/registration-received$/);
  await expect(
    page.getByRole("heading", { name: "Periksa email Anda" }),
  ).toBeVisible();
  await login(page);
  await expect(
    page.getByText("Menunggu verifikasi", { exact: true }),
  ).toBeVisible();
  await page.goto(`/verify-email#verification=${encodeURIComponent(signed)}`);
  await expect(page).toHaveURL(`${FRONTEND}/verify-email`);
  expect(state.verifyCount).toBe(0);
  await page
    .getByRole("button", { name: "Verifikasi dan Aktifkan Keanggotaan" })
    .click();
  await expect(
    page.getByRole("heading", { name: "Selamat bergabung di SRC!" }),
  ).toBeVisible();
  await page
    .getByRole("link", { name: "Lihat Kartu Anggota", exact: true })
    .click();
  await expect(
    page.getByRole("img", { name: "QR keanggotaan untuk dipindai petugas" }),
  ).toBeVisible();
  await expect(page.getByText("SRC-000042", { exact: true })).toBeVisible();
  const trigger = page.getByRole("button", { name: "Lihat QR Lebih Besar" });
  await trigger.click();
  await expect(page.getByRole("dialog")).toBeVisible();
  await page.keyboard.press("Escape");
  await expect(page.getByRole("dialog")).toHaveCount(0);
  await expect(trigger).toBeFocused();
  const storage = await page.evaluate(() => ({
    local: window.localStorage.length,
    session: window.sessionStorage.length,
  }));
  expect(storage).toEqual({ local: 0, session: 0 });
  await page.getByRole("button", { name: "Keluar dari akun" }).click();
  await expect(page).toHaveURL(/\/login(?:\?|$)/);
  await expect(
    page.getByRole("img", { name: "QR keanggotaan untuk dipindai petugas" }),
  ).toHaveCount(0);
  expect(state.loggedIn).toBe(false);
});

test("verification intent survives a client navigation to login without entering the router query", async ({
  page,
  context,
}) => {
  const state = await fixtureApi(context);
  await page.goto(`/verify-email#verification=${encodeURIComponent(signed)}`);
  await page.getByRole("link", { name: "Masuk untuk Verifikasi" }).click();
  expect(page.url()).not.toContain("signature");
  await page.getByRole("textbox", { name: "Email", exact: true }).fill(email);
  await page.getByLabel(/^Password/).fill("Test-only-password-123");
  await page.getByRole("button", { name: "Masuk", exact: true }).click();
  await expect(
    page.getByRole("button", { name: "Verifikasi dan Aktifkan Keanggotaan" }),
  ).toBeVisible();
  expect(state.verifyCount).toBe(0);
  await page
    .getByRole("button", { name: "Verifikasi dan Aktifkan Keanggotaan" })
    .click();
  await expect(
    page.getByRole("heading", { name: "Selamat bergabung di SRC!" }),
  ).toBeVisible();
});

test("failed logout hides QR and asks for recovery instead of claiming success", async ({
  page,
  context,
}) => {
  const state = await fixtureApi(context, {
    active: true,
    loggedIn: true,
    logoutFails: true,
  });
  await page.goto("/kartu");
  await expect(
    page.getByRole("img", { name: "QR keanggotaan untuk dipindai petugas" }),
  ).toBeVisible();
  await page.getByRole("button", { name: "Keluar dari akun" }).click();
  await expect(
    page.getByRole("heading", { name: "Status keluar belum terkonfirmasi" }),
  ).toBeVisible();
  await expect(
    page.getByRole("img", { name: "QR keanggotaan untuk dipindai petugas" }),
  ).toHaveCount(0);
  expect(state.loggedIn).toBe(true);
  state.logoutFails = false;
  await page.getByRole("button", { name: "Coba Keluar Lagi" }).click();
  await expect(page).toHaveURL(/\/login(?:\?|$)/);
  expect(state.loggedIn).toBe(false);
});

test("server denial after a successful card fetch removes the QR", async ({
  page,
  context,
}) => {
  const state = await fixtureApi(context, { active: true, loggedIn: true });
  await page.goto("/kartu");
  await expect(
    page.getByRole("img", { name: "QR keanggotaan untuk dipindai petugas" }),
  ).toBeVisible();
  state.cardDenied = true;
  await page.getByRole("button", { name: "Perbarui Kartu" }).click();
  await expect(
    page.getByRole("heading", { name: "Kartu belum dapat ditampilkan" }),
  ).toBeVisible();
  await expect(
    page.getByRole("img", { name: "QR keanggotaan untuk dipindai petugas" }),
  ).toHaveCount(0);
});

test("resend uses the server cooldown and does not claim delivery", async ({
  page,
  context,
}) => {
  await fixtureApi(context, { loggedIn: true, resendRateLimited: true });
  await page.goto("/beranda");
  await page.getByRole("button", { name: "Kirim Ulang Email" }).click();
  const button = page.getByRole("button", {
    name: /Kirim ulang dalam \d+ detik/,
  });
  await expect(button).toBeDisabled();
  await expect(
    page
      .getByRole("alert")
      .filter({ hasText: "Tunggu sebelum mengirim kembali." }),
  ).toBeVisible();
});

test("login error preserves input and external return URLs are rejected", async ({
  page,
  context,
}) => {
  await fixtureApi(context);
  await page.goto("/login?returnTo=https%3A%2F%2Funtrusted.example");
  await page.getByRole("textbox", { name: "Email", exact: true }).fill(email);
  await page.getByLabel(/^Password/).fill("Wrong-password");
  await page.getByRole("button", { name: "Masuk", exact: true }).click();
  await expect(
    page.getByRole("form", { name: "Form login" }).getByRole("alert"),
  ).toContainText("Email atau password tidak sesuai.");
  await expect(
    page.getByRole("textbox", { name: "Email", exact: true }),
  ).toHaveValue(email);
  await expect(page.getByLabel(/^Password/)).toHaveValue("Wrong-password");
  await expect(
    page.getByRole("region", { name: "Persiapan akun" }),
  ).toHaveCount(0);
  await page.getByLabel(/^Password/).fill("Test-only-password-123");
  await page.getByRole("button", { name: "Masuk", exact: true }).click();
  await expect(page).toHaveURL(`${FRONTEND}/beranda`);
});

test("public forms and private card fit a 320px viewport", async ({
  page,
  context,
}) => {
  await fixtureApi(context, { active: true, loggedIn: true });
  await page.setViewportSize({ width: 320, height: 720 });
  for (const path of ["/login", "/register", "/kartu", "/profil"]) {
    await page.goto(path);
    if (path === "/kartu")
      await expect(
        page.getByRole("img", {
          name: "QR keanggotaan untuk dipindai petugas",
        }),
      ).toBeVisible();
    expect(
      await page.evaluate(
        () => document.documentElement.scrollWidth <= window.innerWidth,
      ),
    ).toBe(true);
  }
  await page.goto("/kartu");
  await page.getByRole("button", { name: "Lihat QR Lebih Besar" }).click();
  await expect(page.getByRole("dialog")).toBeVisible();
  expect(
    await page.evaluate(
      () => document.documentElement.scrollWidth <= window.innerWidth,
    ),
  ).toBe(true);
});
