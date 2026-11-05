import { test, expect, type Page } from "@playwright/test";
import { fixtureApi, login, email, signed, FRONTEND, API } from "../support/fake-member-api";

const errors = new WeakMap<Page, string[]>();
test.beforeEach(({ page }) => {
  const messages: string[] = [];
  errors.set(page, messages);
  page.on("pageerror", error => messages.push(error.message));
});
test.afterEach(({ page }) => expect(errors.get(page)).toEqual([]));

async function submitLogin(page: Page) {
  await page.getByRole("textbox", { name: "Email", exact: true }).fill(email);
  await page.getByLabel(/^Password/).fill("Test-only-password-123");
  await page.getByRole("button", { name: "Masuk", exact: true }).click();
  await expect(page).toHaveURL(`${FRONTEND}/beranda`);
}

test("root redirects to the clean login URL before rendering a page", async ({ page, context }) => {
  await fixtureApi(context);
  const redirect = await page.request.get("/", { maxRedirects: 0 });
  expect(redirect.status()).toBe(307);
  expect(new URL(redirect.headers().location, FRONTEND).pathname).toBe("/login");
  await page.goto("/");
  await expect(page).toHaveURL(`${FRONTEND}/login`);
  await expect(page.getByRole("heading", { name: "Masuk ke sorerunapp" })).toBeVisible();
});

for (const target of ["/profil", "/kartu", "https://untrusted.example", "/login?returnTo=%2Fprofil"]) {
  test(`old returnTo is removed and cannot change landing: ${target}`, async ({ page, context }) => {
    await fixtureApi(context);
    await page.goto(`/login?returnTo=${encodeURIComponent(target)}`);
    await expect(page).toHaveURL(`${FRONTEND}/login`);
    await submitLogin(page);
  });
}

for (const path of ["/beranda", "/profil", "/kartu"]) {
  test(`guest opening ${path} gets the clean login URL`, async ({ page, context }) => {
    await fixtureApi(context);
    await page.goto(path);
    await expect(page).toHaveURL(`${FRONTEND}/login`);
    await submitLogin(page);
  });
}

test("a valid session survives menu changes; logout from profile uses plain login", async ({ page, context }) => {
  const state = await fixtureApi(context);
  await login(page);
  await page.getByRole("navigation", { name: "Navigasi utama" }).getByRole("link", { name: "Profil", exact: true }).click();
  await expect(page).toHaveURL(`${FRONTEND}/profil`);
  await expect(page.getByRole("button", { name: "Keluar dari akun" })).toBeVisible();
  await page.getByRole("navigation", { name: "Navigasi utama" }).getByRole("link", { name: "Kartu", exact: true }).click();
  await expect(page).toHaveURL(`${FRONTEND}/kartu`);
  expect(state.loginCount).toBe(1);
  await page.getByRole("navigation", { name: "Navigasi utama" }).getByRole("link", { name: "Profil", exact: true }).click();
  await expect(page).toHaveURL(`${FRONTEND}/profil`);
  // This test covers the authenticated shell; profile editor content has its own suite.
  // Wait for the destination shell without depending on a stage-specific profile title.
  await expect(page.getByRole("navigation", { name: "Navigasi utama" }).getByRole("link", { name: "Profil", exact: true })).toHaveAttribute("aria-current", "page");
  const logout = page.locator(".member-header:visible").getByRole("button", { name: "Keluar dari akun" });
  await expect(logout).toBeEnabled();
  expect(state.loggedIn).toBe(true);
  expect(state.loginCount).toBe(1);
  await logout.click();
  // stage07: confirm logout before expecting the signed-out result.
  await page.getByRole("dialog", { name: "Keluar dari akun?", exact: true }).getByRole("button", { name: "Ya, Keluar", exact: true }).click();
  await expect(page).toHaveURL(`${FRONTEND}/login`);
  expect(state.loggedIn).toBe(false);
  await submitLogin(page);
});

test("expired session while opening another menu returns to clean login, then home", async ({ page, context }) => {
  const state = await fixtureApi(context);
  await login(page);
  state.loggedIn = false;
  await page.goto("/kartu");
  await expect(page).toHaveURL(`${FRONTEND}/login`);
  await submitLogin(page);
});

test("existing-session banner is preserved and continues to home", async ({ page, context }) => {
  await fixtureApi(context, { loggedIn: true });
  await page.goto("/login?returnTo=%2Fprofil");
  await expect(page).toHaveURL(`${FRONTEND}/login`);
  const link = page.getByRole("link", { name: "Lanjutkan ke akun Anda" });
  await expect(link).toBeVisible();
  await expect(link).toHaveAttribute("href", "/beranda");
  await link.click();
  await expect(page).toHaveURL(`${FRONTEND}/beranda`);
});

test("incomplete-profile verification still offers clean login, without automatic sign-in", async ({ page, context }) => {
  const state = await fixtureApi(context, { active: false });
  await context.route(`${API}/api/v1/auth/email-verification/42/**`, route => route.fulfill({
    status: 200,
    headers: { "Access-Control-Allow-Origin": FRONTEND, "Access-Control-Allow-Credentials": "true", "Access-Control-Allow-Headers": "Accept, Content-Type, X-XSRF-TOKEN", "Access-Control-Allow-Methods": "GET, POST, OPTIONS" },
    contentType: "application/json",
    body: JSON.stringify({ data: { result: "email_verified_profile_incomplete" } }),
  }));
  await page.goto(`/verify-email#verification=${encodeURIComponent(signed)}`);
  await page.getByRole("button", { name: "Verifikasi Email", exact: true }).click();
  await expect(page.getByRole("heading", { name: "Email terverifikasi" })).toBeVisible();
  await expect(page.getByRole("link", { name: "Masuk ke Akun" })).toHaveAttribute("href", "/login");
  expect(state.loggedIn).toBe(false);
});

test("approved auth footers stack with spacing at desktop and 320px", async ({ page, context }) => {
  await fixtureApi(context, { active: false });
  for (const width of [1280, 320]) {
    await page.setViewportSize({ width, height: 900 });
    await page.goto("/login");
    const submit = page.getByRole("button", { name: "Masuk", exact: true });
    const help = page.getByRole("link", { name: "Belum menerima email verifikasi?" });
    const signup = page.getByRole("link", { name: "Daftar Member", exact: true });
    await expect(help).toBeVisible();
    const loginFooter = page.locator(".src-auth-login-footer:visible");
    await expect(loginFooter).toHaveCSS("display", "flex");
    await expect(loginFooter).toHaveCSS("flex-direction", "column");
    await expect(loginFooter).toHaveCSS("row-gap", "12px");
    await expect(loginFooter).toHaveCSS("margin-top", "28px");
    const buttonBox = await submit.boundingBox(), signupBox = await signup.boundingBox(), helpBox = await help.boundingBox();
    expect(signupBox!.y - buttonBox!.y - buttonBox!.height).toBeGreaterThanOrEqual(27);
    expect(helpBox!.y).toBeGreaterThan(signupBox!.y + signupBox!.height);
    await page.goto(`/verify-email#verification=${encodeURIComponent(signed)}`);
    const confirm = page.getByRole("button", { name: "Verifikasi Email", exact: true });
    await expect(confirm).toBeVisible();
    const verifyLinks = page.getByRole("navigation", { name: "Bantuan verifikasi email" });
    await expect(verifyLinks).toHaveCSS("display", "flex");
    await expect(verifyLinks).toHaveCSS("flex-direction", "column");
    await expect(verifyLinks).toHaveCSS("row-gap", "12px");
    await expect(verifyLinks).toHaveCSS("margin-top", "28px");
    const confirmBox = await confirm.boundingBox();
    const resendBox = await page.getByRole("link", { name: "Kirim Ulang Email", exact: true }).boundingBox();
    const backBox = await page.getByRole("link", { name: "Kembali ke Login", exact: true }).boundingBox();
    expect(resendBox!.y - confirmBox!.y - confirmBox!.height).toBeGreaterThanOrEqual(27);
    expect(backBox!.y).toBeGreaterThan(resendBox!.y + resendBox!.height);
    await page.getByRole("link", { name: "Kirim Ulang Email", exact: true }).click();
    const resendLinks = page.getByRole("navigation", { name: "Navigasi kembali" });
    await expect(resendLinks).toHaveCSS("display", "flex");
    await expect(resendLinks).toHaveCSS("flex-direction", "column");
    await expect(resendLinks).toHaveCSS("margin-top", "28px");
    const resendButton = await page.getByRole("button", { name: "Kirim Ulang Email", exact: true }).boundingBox();
    const back = await page.getByRole("link", { name: "Kembali ke Login", exact: true }).boundingBox();
    expect(back!.y - resendButton!.y - resendButton!.height).toBeGreaterThanOrEqual(27);
    expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBe(true);
  }
});
