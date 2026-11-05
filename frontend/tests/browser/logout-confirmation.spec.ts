import { test, expect, type Page } from "@playwright/test";
import { fixtureApi, API, FRONTEND } from "../support/fake-member-api";

function countLogoutRequests(page: Page) {
  let count = 0;
  page.on("request", (request) => {
    if (request.url() === `${API}/api/v1/auth/logout` && request.method() === "POST") count++;
  });
  return () => count;
}

test("Tidak cancels logout without a request or clearing the member card", async ({ page, context }) => {
  const state = await fixtureApi(context, { loggedIn: true });
  const requests = countLogoutRequests(page);
  await page.goto("/kartu");
  const qr = page.getByRole("img", { name: "QR keanggotaan untuk dipindai petugas" });
  await expect(qr).toBeVisible();
  const trigger = page.getByRole("button", { name: "Keluar dari akun", exact: true });
  await trigger.click();
  const dialog = page.getByRole("dialog", { name: "Keluar dari akun?", exact: true });
  await expect(dialog).toContainText("Apakah Anda ingin logout dari sorerunapp?");
  await expect(dialog.getByRole("button", { name: "Tidak", exact: true })).toBeFocused();
  expect(requests()).toBe(0);
  expect(state.loggedIn).toBe(true);
  await dialog.getByRole("button", { name: "Tidak", exact: true }).click();
  await expect(dialog).toHaveCount(0);
  await expect(trigger).toBeFocused();
  await expect(page).toHaveURL(`${FRONTEND}/kartu`);
  await expect(qr).toBeVisible();
  expect(requests()).toBe(0);
  expect(state.loggedIn).toBe(true);
});

test("logout dialog traps keyboard focus and Escape returns focus without logging out", async ({ page, context }) => {
  const state = await fixtureApi(context, { loggedIn: true });
  const requests = countLogoutRequests(page);
  await page.goto("/beranda");
  const trigger = page.getByRole("button", { name: "Keluar dari akun", exact: true });
  await trigger.click();
  const dialog = page.getByRole("dialog", { name: "Keluar dari akun?", exact: true });
  const cancel = dialog.getByRole("button", { name: "Tidak", exact: true });
  const confirm = dialog.getByRole("button", { name: "Ya, Keluar", exact: true });
  await expect(cancel).toBeFocused();
  await page.keyboard.press("Tab");
  await expect(confirm).toBeFocused();
  await page.keyboard.press("Tab");
  await expect(cancel).toBeFocused();
  await page.keyboard.press("Escape");
  await expect(dialog).toHaveCount(0);
  await expect(trigger).toBeFocused();
  await expect(page).toHaveURL(`${FRONTEND}/beranda`);
  expect(requests()).toBe(0);
  expect(state.loggedIn).toBe(true);
});

test("Ya, Keluar sends one logout request and returns to the clean login URL", async ({ page, context }) => {
  const state = await fixtureApi(context, { loggedIn: true });
  const requests = countLogoutRequests(page);
  await page.goto("/kartu");
  await expect(page.getByRole("img", { name: "QR keanggotaan untuk dipindai petugas" })).toBeVisible();
  await page.getByRole("button", { name: "Keluar dari akun", exact: true }).click();
  expect(requests()).toBe(0);
  await page.getByRole("dialog", { name: "Keluar dari akun?", exact: true }).getByRole("button", { name: "Ya, Keluar", exact: true }).click();
  await expect(page).toHaveURL(`${FRONTEND}/login`);
  await expect(page.getByRole("img", { name: "QR keanggotaan untuk dipindai petugas" })).toHaveCount(0);
  await expect(page.getByRole("dialog", { name: "Keluar dari akun?", exact: true })).toHaveCount(0);
  expect(requests()).toBe(1);
  expect(state.loggedIn).toBe(false);
});

test("logout confirmation fits a 320px viewport with usable buttons", async ({ page, context }) => {
  const state = await fixtureApi(context, { loggedIn: true });
  await page.setViewportSize({ width: 320, height: 720 });
  await page.goto("/beranda");
  await page.getByRole("button", { name: "Keluar dari akun", exact: true }).click();
  const dialog = page.getByRole("dialog", { name: "Keluar dari akun?", exact: true });
  await expect(dialog).toBeVisible();
  const panel = await dialog.boundingBox();
  expect(panel!.x).toBeGreaterThanOrEqual(0);
  expect(panel!.x + panel!.width).toBeLessThanOrEqual(320);
  for (const name of ["Tidak", "Ya, Keluar"]) {
    const button = dialog.getByRole("button", { name, exact: true });
    await expect(button).toBeVisible();
    const box = await button.boundingBox();
    expect(box!.height).toBeGreaterThanOrEqual(44);
    expect(box!.width).toBeGreaterThanOrEqual(44);
  }
  expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBe(true);
  await dialog.getByRole("button", { name: "Tidak", exact: true }).click();
  expect(state.loggedIn).toBe(true);
});
