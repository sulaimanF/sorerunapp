import { test, expect, type Page } from "@playwright/test";
import { email, fixtureApi } from "../support/fake-member-api";

const resetUrl = `/reset-password#email=${encodeURIComponent(email)}&token=${"a".repeat(64)}`;
const pageErrors = new WeakMap<Page, string[]>();
test.beforeEach(({ page }) => {
  const errors: string[] = [];
  pageErrors.set(page, errors);
  page.on("pageerror", error => errors.push(error.message));
});
test.afterEach(({ page }) => expect(pageErrors.get(page)).toEqual([]));
async function fillReset(page: Page) {
  await page.getByLabel(/^Password baru/).fill("New-test-password-123");
  await page.getByLabel(/^Konfirmasi password baru/).fill("New-test-password-123");
}

test("login exposes recovery while keeping active-session banner and fixed navigation", async ({ page, context }) => {
  const state = await fixtureApi(context, { loggedIn: true });
  await page.goto("/login?returnTo=/profil");
  await expect(page.getByRole("link", { name: "Lupa password?" })).toBeVisible();
  await expect(page.getByRole("link", { name: /Lanjutkan ke akun Anda/i })).toBeVisible();
  await page.getByRole("link", { name: "Lupa password?" }).click();
  await expect(page).toHaveURL(/\/forgot-password$/);
  expect(state.loggedIn).toBe(true);
  expect(state.loginCount).toBe(0);
});

test("forgot validates email, shows loading and generic receipt with server cooldown", async ({ page, context }) => {
  const state = await fixtureApi(context, { passwordDelay: 800 });
  await page.goto("/forgot-password");
  await page.getByRole("button", { name: "Kirim Tautan Reset" }).click();
  await expect(page.getByRole("textbox", { name: "Email", exact: true })).toHaveAttribute("aria-invalid", "true");
  expect(state.forgotCount).toBe(0);
  await page.getByRole("textbox", { name: "Email", exact: true }).fill("absent@example.test");
  await page.getByRole("button", { name: "Kirim Tautan Reset" }).click();
  await expect(page.getByRole("button", { name: /Memproses permintaan/ })).toBeDisabled();
  await expect(page.getByText(/Jika alamat email memenuhi syarat/)).toBeVisible();
  await expect(page.getByRole("button", { name: /Kirim ulang dalam \d+ detik/ })).toBeDisabled();
  expect(state.forgotCount).toBe(1);
});

for (const code of [-1, 419, 429]) {
  test(`forgot handles failure ${code} without automatic mutation retry`, async ({ page, context }) => {
    const state = await fixtureApi(context, { passwordError: code });
    await page.goto("/forgot-password");
    await page.getByRole("textbox", { name: "Email", exact: true }).fill(email);
    await page.getByRole("button", { name: "Kirim Tautan Reset" }).click();
    await expect(page.locator("main").getByRole("alert")).toBeVisible();
    await expect(page.getByRole("textbox", { name: "Email", exact: true })).toHaveValue(email);
    if (code === 429) await expect(page.getByRole("button", { name: /Kirim ulang dalam 7\d detik/ })).toBeDisabled();
    expect(state.forgotCount).toBe(1);
  });
}

test("reset GET is read-only, removes proof from URL and stores no secrets", async ({ page, context }) => {
  const state = await fixtureApi(context);
  await page.goto(resetUrl);
  await expect(page.getByRole("form", { name: "Form reset password" })).toBeVisible();
  await expect(page).toHaveURL(/\/reset-password$/);
  expect(state.resetCount).toBe(0);
  expect(await page.evaluate(() => [localStorage.length, sessionStorage.length])).toEqual([0, 0]);
  await page.reload();
  await expect(page.getByText(/Tautan reset tidak tersedia/)).toBeVisible();
  expect(state.resetCount).toBe(0);
});

test("reset validates confirmation and supports visibility, then requires manual login", async ({ page, context }) => {
  const state = await fixtureApi(context, { passwordDelay: 800 });
  await page.goto(resetUrl);
  const password = page.getByLabel(/^Password baru/);
  await expect(password).toHaveAttribute("autocomplete", "new-password");
  await fillReset(page);
  await page.getByLabel(/^Konfirmasi password baru/).fill("different");
  await page.getByRole("button", { name: "Simpan Password Baru" }).click();
  await expect(page.getByLabel(/^Konfirmasi password baru/)).toHaveAttribute("aria-invalid", "true");
  expect(state.resetCount).toBe(0);
  await page.getByRole("button", { name: /Tampilkan password/i }).first().click();
  await expect(password).toHaveAttribute("type", "text");
  await fillReset(page);
  await page.getByRole("button", { name: "Simpan Password Baru" }).click();
  await expect(page.getByRole("button", { name: /Menyimpan password/ })).toBeDisabled();
  await expect(page.getByRole("heading", { name: "Password berhasil diperbarui" })).toBeFocused();
  expect(state.resetCount).toBe(1);
  expect(state.loginCount).toBe(0);
  await expect(page).toHaveURL(/\/reset-password$/);
  await page.getByRole("link", { name: "Masuk ke Akun" }).click();
  await expect(page).toHaveURL(/\/login$/);
});

test("server validation preserves passwords and focuses the first invalid field", async ({ page, context }) => {
  const state = await fixtureApi(context, { passwordFieldsError: true });
  await page.goto(resetUrl);
  await fillReset(page);
  await page.getByRole("button", { name: "Simpan Password Baru" }).click();
  await expect(page.getByText("Password ditolak oleh validasi server.")).toBeVisible();
  await expect(page.getByLabel(/^Password baru/)).toBeFocused();
  await expect(page.getByLabel(/^Password baru/)).toHaveAttribute("aria-describedby", "password-help password-error");
  await expect(page.getByLabel(/^Konfirmasi password baru/)).toHaveValue("New-test-password-123");
  expect(state.resetCount).toBe(1);
});

test("successful reset keeps another active identity and still requires manual navigation", async ({ page, context }) => {
  const state = await fixtureApi(context, { loggedIn: true });
  await page.goto(resetUrl);
  await fillReset(page);
  await page.getByRole("button", { name: "Simpan Password Baru" }).click();
  await expect(page.getByRole("heading", { name: "Password berhasil diperbarui" })).toBeVisible();
  await expect(page).toHaveURL(/\/reset-password$/);
  expect(state.loggedIn).toBe(true);
  expect(state.loginCount).toBe(0);
  await page.getByRole("link", { name: "Masuk ke Akun" }).click();
  await expect(page.getByRole("link", { name: "Lanjutkan ke akun Anda" })).toBeVisible();
  await expect(page).toHaveURL(/\/login$/);
});

for (const code of [422, -1, 419, 429]) {
  test(`reset handles ${code} safely without automatic retry`, async ({ page, context }) => {
    const state = await fixtureApi(context, { passwordError: code, loggedIn: true });
    await page.goto(resetUrl);
    await fillReset(page);
    await page.getByRole("button", { name: "Simpan Password Baru" }).click();
    if (code === 422) {
      await expect(page.getByText(/Tautan reset tidak tersedia/)).toBeVisible();
      await expect(page.getByRole("form")).toHaveCount(0);
    } else {
      await expect(page.locator("main").getByRole("alert")).toBeVisible();
      await expect(page.getByLabel(/^Password baru/)).toHaveValue("New-test-password-123");
    }
    if (code === -1) await expect(page.getByText(/Hasil reset belum terkonfirmasi/)).toBeVisible();
    if (code === 429) await expect(page.getByRole("button", { name: /Tunggu 7\d detik/ })).toBeDisabled();
    expect(state.resetCount).toBe(1);
    expect(state.loggedIn).toBe(true);
  });
}

test("missing proof recovers, leaving the page clears proof, mobile and keyboard remain usable", async ({ page, context }) => {
  await fixtureApi(context);
  await page.setViewportSize({ width: 320, height: 640 });
  await page.goto("/reset-password");
  await expect(page.getByText(/Tautan reset tidak tersedia/)).toBeVisible();
  await page.goto(resetUrl);
  const password = page.getByLabel(/^Password baru/);
  await password.focus();
  await page.keyboard.press("Tab");
  await expect(page.getByRole("button", { name: /Tampilkan password/i }).first()).toBeFocused();
  for (const control of await page.locator("main input, main button").all()) {
    const box = await control.boundingBox();
    if (box) expect(box.height).toBeGreaterThanOrEqual(44);
  }
  expect(await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth)).toBe(true);
  await page.screenshot({ path: "test-results/password-reset-mobile.png", fullPage: true });
  await page.setViewportSize({ width: 640, height: 1280 });
  await page.evaluate(() => { document.body.style.zoom = "2"; });
  expect(await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth)).toBe(true);
  await expect(page.getByRole("button", { name: "Simpan Password Baru" })).toBeVisible();
  await page.evaluate(() => { document.body.style.zoom = "1"; });
  await page.getByRole("link", { name: "Kembali ke Login" }).click();
  await expect(page).toHaveURL(/\/login$/);
  await expect(page.getByRole("heading", { name: "Masuk ke sorerunapp" })).toBeVisible();
  await page.goBack();
  await expect(page.getByText(/Tautan reset tidak tersedia/)).toBeVisible();
  await page.getByRole("link", { name: "Minta Tautan Reset Baru" }).click();
  expect(await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth)).toBe(true);
});
