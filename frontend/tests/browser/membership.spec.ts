import { test, expect, type Page } from "@playwright/test";
import { fixtureApi, login, fixtureName, email, signed, FRONTEND } from "../support/fake-member-api";

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

test("registration, denied unverified login, guest verification, login, QR, and logout", async ({
  page,
  context,
}) => {
  const state = await fixtureApi(context, { active: false });
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
  await page.goto("/login");
  await page.getByRole("textbox", { name: "Email", exact: true }).fill(email);
  await page.getByLabel(/^Password/).fill("Test-only-password-123");
  await page.getByRole("button", { name: "Masuk", exact: true }).click();
  await expect(page.getByRole("form", { name: "Form login" }).getByRole("alert")).toContainText("Verifikasi email Anda sebelum masuk.");
  expect(state.loggedIn).toBe(false);
  await page.goto(`/verify-email#verification=${encodeURIComponent(signed)}`);
  await expect(page).toHaveURL(`${FRONTEND}/verify-email`);
  expect(state.verifyCount).toBe(0);
  await page.getByRole("button", { name: "Verifikasi Email", exact: true }).click();
  await expect(page.getByRole("heading", { name: "Email terverifikasi" })).toBeVisible();
  expect(state.loggedIn).toBe(false);
  await login(page, "/kartu");
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
  // stage07: confirm logout before expecting the signed-out result.
  await page.getByRole("dialog", { name: "Keluar dari akun?", exact: true }).getByRole("button", { name: "Ya, Keluar", exact: true }).click();
  await expect(page).toHaveURL(/\/login(?:\?|$)/);
  await expect(
    page.getByRole("img", { name: "QR keanggotaan untuk dipindai petugas" }),
  ).toHaveCount(0);
  expect(state.loggedIn).toBe(false);
});

test("public verification needs explicit confirmation, clears the fragment, and never logs in", async ({ page, context }) => {
  const state = await fixtureApi(context, { active: false });
  await page.goto(`/verify-email#verification=${encodeURIComponent(signed)}`);
  await expect(page).toHaveURL(`${FRONTEND}/verify-email`);
  const button = page.getByRole("button", { name: "Verifikasi Email", exact: true });
  await expect(button).toBeVisible();
  expect(state.verifyCount).toBe(0);
  expect(state.loginCount).toBe(0);
  await button.click();
  await expect(page.getByRole("heading", { name: "Email terverifikasi" })).toBeVisible();
  expect(state.verifyCount).toBe(1);
  expect(state.loggedIn).toBe(false);
  const storage = await page.evaluate(() => ({ local: localStorage.length, session: sessionStorage.length }));
  expect(storage).toEqual({ local: 0, session: 0 });
  await page.goto(`/verify-email#verification=${encodeURIComponent(signed)}`);
  await button.click();
  await expect(page.getByRole("heading", { name: "Email terverifikasi" })).toBeVisible();
  expect(state.loggedIn).toBe(false);
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
  // stage07: confirm logout before expecting the signed-out result.
  await page.getByRole("dialog", { name: "Keluar dari akun?", exact: true }).getByRole("button", { name: "Ya, Keluar", exact: true }).click();
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
  await fixtureApi(context, { resendRateLimited: true });
  await page.goto("/resend-verification");
  await page.getByRole("textbox", { name: "Email", exact: true }).fill(email);
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
  for (const path of ["/login", "/register", "/resend-verification", "/verify-email", "/kartu", "/profil"]) {
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


test("public resend accepts unknown email generically without creating a login", async ({ page, context }) => {
  const state = await fixtureApi(context, { active: false });
  await page.goto("/resend-verification");
  await page.getByRole("textbox", { name: "Email", exact: true }).fill("absent@example.test");
  await page.getByRole("button", { name: "Kirim Ulang Email", exact: true }).click();
  await expect(page.getByRole("status")).toContainText("Jika alamat email memenuhi syarat");
  await expect(page.getByRole("button", { name: /Kirim ulang dalam/ })).toBeDisabled();
  expect(state.loggedIn).toBe(false);
  expect(state.loginCount).toBe(0);
});

test("revoking email verification on an existing session removes the private card", async ({ page, context }) => {
  const state = await fixtureApi(context, { active: true, loggedIn: true });
  await page.goto("/kartu");
  const qr = page.getByRole("img", { name: "QR keanggotaan untuk dipindai petugas" });
  await expect(qr).toBeVisible();
  state.active = false;
  await page.getByRole("button", { name: "Perbarui Kartu" }).click();
  await expect(qr).toHaveCount(0);
  expect(state.loggedIn).toBe(false);
});
