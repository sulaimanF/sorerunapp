import { test, expect, type Page } from "@playwright/test";
import { emailChangeFixture, changeLink, oldEmail, newEmail } from "../support/fake-email-change-api";

const errors = new WeakMap<Page, string[]>();
test.beforeEach(({ page }) => {
  const seen: string[] = [];
  errors.set(page, seen);
  page.on("pageerror", error => seen.push(error.message));
});
test.afterEach(({ page }) => expect(errors.get(page)).toEqual([]));
async function openChange(page: Page) {
  await page.getByRole("button", { name: "Ubah Email", exact: true }).click();
  await expect(page.getByLabel(/^Email baru/)).toBeFocused();
}
async function fillChange(page: Page) {
  await page.getByLabel(/^Email baru/).fill(newEmail);
  await page.getByLabel(/^Password akun saat ini/).fill("Test-only-password-123");
}

test("email editor opens on demand and cancelling clears inputs without hiding pending status or resetting cooldown", async ({ page, context }) => {
  const state = await emailChangeFixture(context);
  state.pending = { email: newEmail, expires_at: new Date(Date.now() + 3600_000).toISOString(), status: "pending" };
  state.cooldownUntil = Date.now() + 73_000;
  await page.goto("/profil");
  const trigger = page.getByRole("button", { name: "Ubah Email", exact: true });
  await expect(trigger).toHaveAttribute("aria-expanded", "false");
  await expect(page.getByRole("form", { name: "Form perubahan email" })).toHaveCount(0);
  await expect(page.getByText("Email pending:", { exact: true }).locator("..")).toContainText(newEmail);
  await trigger.focus();
  await page.keyboard.press("Enter");
  await expect(page.getByLabel(/^Email baru/)).toBeFocused();
  const cancel = page.getByRole("button", { name: "Batal Ubah Email", exact: true });
  await expect(cancel).toHaveAttribute("aria-expanded", "true");
  await fillChange(page);
  await cancel.click();
  await expect(trigger).toBeFocused();
  await expect(page.getByLabel(/^Email baru/)).toHaveCount(0);
  await expect(page.getByText("Email pending:", { exact: true })).toBeVisible();
  await openChange(page);
  await expect(page.getByLabel(/^Email baru/)).toHaveValue("");
  await expect(page.getByLabel(/^Password akun saat ini/)).toHaveValue("");
  await expect(page.getByRole("button", { name: /Tunggu \d+ detik/ })).toBeDisabled();
  expect(state.requests).toBe(0);
});

test("profile validates and submits without replacing active email, follows server cooldown", async ({ page, context }) => {
  const state = await emailChangeFixture(context);
  state.delay = 600;
  await page.goto("/profil");
  await openChange(page);
  await page.getByRole("button", { name: "Kirim Verifikasi Email Baru" }).click();
  await expect(page.getByLabel(/^Email baru/)).toHaveAttribute("aria-invalid", "true");
  await fillChange(page);
  await page.getByRole("button", { name: "Kirim Verifikasi Email Baru" }).click();
  await expect(page.getByRole("button", { name: "Memproses permintaan…" })).toBeDisabled();
  await expect(page.getByRole("button", { name: "Batal Ubah Email" })).toBeDisabled();
  await expect(page.getByText(/Permintaan perubahan email diterima/)).toBeVisible();
  await expect(page.getByRole("button", { name: /Tunggu [1-3] detik/ })).toBeDisabled();
  await expect(page.getByText(oldEmail, { exact: true })).toBeVisible();
  await expect(page.getByText("Email pending:", { exact: true }).locator("..")).toContainText(newEmail);
  expect(state.requests).toBe(1);
  await page.reload();
  await expect(page.getByText("Email pending:", { exact: true }).locator("..")).toContainText(newEmail);
  await expect(page.getByRole("button", { name: "Ubah Email", exact: true })).toHaveAttribute("aria-expanded", "false");
  await expect(page.getByRole("form", { name: "Form perubahan email" })).toHaveCount(0);
});

test("server password validation preserves input and focuses the invalid control", async ({ page, context }) => {
  const state = await emailChangeFixture(context); state.fieldError = true;
  await page.goto("/profil"); await openChange(page); await fillChange(page);
  await page.getByRole("button", { name: "Kirim Verifikasi Email Baru" }).click();
  await expect(page.getByLabel(/^Password akun saat ini/)).toHaveAttribute("aria-invalid", "true");
  await expect(page.getByLabel(/^Password akun saat ini/)).toBeFocused();
  await expect(page.getByLabel(/^Email baru/)).toHaveValue(newEmail);
});

for (const code of [-1, 419, 429]) {
  test(`request error ${code} preserves values without retry`, async ({ page, context }) => {
    const state = await emailChangeFixture(context); state.error = code;
    await page.goto("/profil"); await openChange(page); await fillChange(page);
    await page.getByRole("button", { name: "Kirim Verifikasi Email Baru" }).click();
    await expect(page.getByRole("alert")).toBeVisible();
    await expect(page.getByLabel(/^Email baru/)).toHaveValue(newEmail);
    if (code === 429) await expect(page.getByRole("button", { name: /Tunggu 7\d detik/ })).toBeDisabled();
    expect(state.requests).toBe(1);
  });
}

test("failed status refresh keeps unsaved profile and email inputs mounted", async ({ page, context }) => {
  const state = await emailChangeFixture(context);
  await page.goto("/profil"); await openChange(page); await fillChange(page);
  state.readFails = true;
  await page.getByRole("button", { name: "Periksa Status Email" }).click();
  await expect(page.getByText(/Data terakhir tetap ditampilkan/)).toBeVisible();
  await expect(page.getByLabel(/^Email baru/)).toHaveValue(newEmail);
  await expect(page.getByRole("dialog", { name: "Status Email", exact: true })).toHaveCount(0);
  await expect(page.getByRole("button", { name: "Periksa Status Email", exact: true })).toBeEnabled();
});

for (const pendingStatus of ["none", "pending", "expired"] as const) {
  test(`status check shows loading then fresh ${pendingStatus} email details in an accessible dialog`, async ({ page, context }) => {
    await page.setViewportSize({ width: 320, height: 760 });
    const state = await emailChangeFixture(context);
    await page.goto("/profil");
    const trigger = page.getByRole("button", { name: "Periksa Status Email", exact: true });
    await expect(trigger).toBeVisible();
    if (pendingStatus === "pending") {
      await openChange(page); await fillChange(page);
    }
    // Change server state after the initial profile read to reject a stale-cache popup.
    state.pending = pendingStatus === "none" ? null : {
      email: newEmail, status: pendingStatus,
      expires_at: pendingStatus === "expired" ? "2026-01-01T00:00:00Z" : new Date(Date.now() + 3600_000).toISOString(),
    };
    state.cooldownUntil = pendingStatus === "pending" ? Date.now() + 73_000 : 0;
    state.readDelay = 600;
    const previousReads = state.reads;
    await trigger.click();
    const loading = page.getByRole("button", { name: "Memeriksa status email…", exact: true });
    await expect(loading).toBeDisabled();
    await expect(loading).toHaveAttribute("aria-busy", "true");
    const dialog = page.getByRole("dialog", { name: "Status Email", exact: true });
    await expect(dialog).toHaveCount(0);
    await expect(dialog).toBeVisible();
    expect(state.reads).toBe(previousReads + 1);
    await expect(dialog.getByText(oldEmail, { exact: true })).toBeVisible();
    if (pendingStatus === "none") {
      await expect(dialog.getByText("Tidak ada permintaan perubahan email.", { exact: true })).toBeVisible();
    } else {
      await expect(dialog.getByText(pendingStatus === "expired" ? "Email kedaluwarsa" : "Email pending", { exact: true })).toBeVisible();
      await expect(dialog.locator("dd").filter({ hasText: newEmail })).toBeVisible();
    }
    if (pendingStatus === "pending") {
      await expect(dialog.getByText(/Tunggu 7\d detik sebelum mengajukan perubahan lagi/)).toBeVisible();
    } else {
      await expect(dialog.getByText("Dapat mengajukan perubahan email sekarang.", { exact: true })).toBeVisible();
    }
    await expect(dialog.getByRole("button", { name: "Tutup", exact: true })).toBeFocused();
    expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBe(true);
    await page.keyboard.press("Escape");
    await expect(dialog).toHaveCount(0);
    await expect(trigger).toBeFocused();
    if (pendingStatus === "pending") await expect(page.getByLabel(/^Email baru/)).toHaveValue(newEmail);
    expect(state.requests).toBe(0);
  });
}

test("GET confirmation is read-only and explicit confirmation clears session then logs in with new email", async ({ page, context }) => {
  const state = await emailChangeFixture(context);
  await page.goto(changeLink);
  await expect(page.getByRole("button", { name: "Konfirmasi Email Baru" })).toBeVisible();
  await expect(page).toHaveURL(/\/verify-email-change$/);
  expect(state.confirms).toBe(0);
  expect(await page.evaluate(() => [localStorage.length, sessionStorage.length])).toEqual([0, 0]);
  await page.getByRole("button", { name: "Konfirmasi Email Baru" }).click();
  await expect(page).toHaveURL(/\/login$/);
  await expect(page.getByText(/Email berhasil diubah dan seluruh sesi/)).toBeVisible();
  expect(state.loggedIn).toBe(false);
  expect(state.confirms).toBe(1);
  await page.getByRole("textbox", { name: "Email", exact: true }).fill(newEmail);
  await page.getByLabel(/^Password/).fill("Test-only-password-123");
  await page.getByRole("button", { name: "Masuk", exact: true }).click();
  await expect(page).toHaveURL(/\/beranda$/);
  expect(state.logins).toBe(1);
  await expect(page.getByText(/Anda sedang masuk sebagai/)).toHaveCount(0);
});

test("guest logs in with old email then reopens the link, without returnTo or retained proof", async ({ page, context }) => {
  const state = await emailChangeFixture(context); state.loggedIn = false;
  await page.goto(changeLink);
  await expect(page.getByText(/Masuk menggunakan alamat email aktif lama/)).toBeVisible();
  await page.getByRole("link", { name: "Kembali ke Login" }).click();
  await expect(page).toHaveURL(/\/login$/);
  await page.getByRole("textbox", { name: "Email", exact: true }).fill(oldEmail);
  await page.getByLabel(/^Password/).fill("Test-only-password-123");
  await page.getByRole("button", { name: "Masuk", exact: true }).click();
  await expect(page).toHaveURL(/\/beranda$/);
  await page.goto("/verify-email-change");
  await expect(page.getByText(/Tautan tidak tersedia/)).toBeVisible();
  await page.goto(changeLink);
  await expect(page.getByRole("button", { name: "Konfirmasi Email Baru" })).toBeVisible();
  expect(state.confirms).toBe(0);
});

test("another account is rejected safely and retains its identity", async ({ page, context }) => {
  const state = await emailChangeFixture(context); state.ownerId = 99; state.wrongOwner = true;
  await page.goto(changeLink);
  await page.getByRole("button", { name: "Konfirmasi Email Baru" }).click();
  await expect(page.getByText(/Tautan tidak sesuai dengan akun ini/)).toBeVisible();
  expect(state.loggedIn).toBe(true);
  expect(state.ownerId).toBe(99);
  expect(state.email).toBe(oldEmail);
  await page.getByRole("link", { name: "Kembali ke Profil" }).click();
  await expect(page).toHaveURL(/\/profil$/);
});

test("expired pending state, keyboard controls and layout reflow at 320px", async ({ page, context }) => {
  await page.setViewportSize({ width: 320, height: 760 });
  const state = await emailChangeFixture(context);
  state.pending = { email: newEmail, expires_at: "2026-01-01T00:00:00Z", status: "expired" };
  await page.goto("/profil");
  await expect(page.getByText(/Tautan sudah kedaluwarsa/)).toBeVisible();
  const trigger = page.getByRole("button", { name: "Ubah Email", exact: true });
  expect((await trigger.boundingBox())?.height).toBeGreaterThanOrEqual(44);
  expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBe(true);
  await trigger.focus();
  await page.keyboard.press("Enter");
  await expect(page.getByLabel(/^Email baru/)).toBeFocused();
  const password = page.getByLabel(/^Password akun saat ini/);
  await expect(password).toHaveAttribute("autocomplete", "current-password");
  await password.focus(); await password.fill("Test-only-password-123");
  await page.keyboard.press("Tab");
  const toggle = page.getByRole("button", { name: "Tampilkan password", exact: true });
  await expect(toggle).toBeFocused();
  expect((await toggle.boundingBox())?.height).toBeGreaterThanOrEqual(44);
  await page.keyboard.press("Enter");
  await expect(password).toHaveAttribute("type", "text");
  expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBe(true);
  await page.screenshot({ path: "test-results/email-change-320.png", fullPage: true });
});
