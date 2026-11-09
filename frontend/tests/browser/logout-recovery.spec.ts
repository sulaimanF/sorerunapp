import { test, expect, type Page } from "@playwright/test";
import { fixtureApi, API, FRONTEND } from "../support/fake-member-api";

const qrName = "QR keanggotaan untuk dipindai petugas";
const recoveryTitle = "Status keluar belum terkonfirmasi";

async function confirmLogout(page: Page) {
  await page.goto("/kartu");
  await expect(page.getByRole("img", { name: qrName })).toBeVisible();
  await page.getByRole("button", { name: "Keluar dari akun", exact: true }).click();
  await page.getByRole("dialog").getByRole("button", { name: "Ya, Keluar" }).click();
}

test("an unexpected successful HTTP response does not confirm logout", async ({ page, context }) => {
  const state = await fixtureApi(context, { loggedIn: true, logoutStatus: 200 });
  await confirmLogout(page);
  await expect(page.getByRole("heading", { name: recoveryTitle })).toBeVisible();
  await expect(page.getByRole("img", { name: qrName })).toHaveCount(0);
  expect(state.loggedIn).toBe(true);
  state.logoutStatus = 204;
  await page.getByRole("button", { name: "Coba Keluar Lagi" }).click();
  await expect(page).toHaveURL(`${FRONTEND}/login`);
  expect(state.loggedIn).toBe(false);
});

test("failed session recovery stays explicit and never replays logout", async ({ page, context }) => {
  const state = await fixtureApi(context, { loggedIn: true, logoutFails: true });
  let logoutRequests = 0;
  page.on("request", (request) => {
    if (request.url() === `${API}/api/v1/auth/logout` && request.method() === "POST") logoutRequests++;
  });
  await confirmLogout(page);
  await expect(page.getByRole("heading", { name: recoveryTitle })).toBeVisible();
  state.sessionFails = true;
  await page.getByRole("button", { name: "Periksa Sesi" }).click();
  await expect(page.getByRole("main").getByRole("alert")).toContainText("Pemeriksaan sesi belum berhasil");
  await expect(page.getByRole("heading", { name: recoveryTitle })).toBeVisible();
  await expect(page).toHaveURL(`${FRONTEND}/kartu`);
  await expect(page.getByRole("img", { name: qrName })).toHaveCount(0);
  expect(logoutRequests).toBe(1);
  expect(state.loggedIn).toBe(true);

  state.sessionFails = false;
  await page.getByRole("button", { name: "Periksa Sesi" }).click();
  await expect(page.getByRole("img", { name: qrName })).toBeVisible();
  expect(logoutRequests).toBe(1);
});

test("session lookup resolves a lost logout response without another mutation", async ({ page, context }) => {
  const state = await fixtureApi(context, { loggedIn: true, logoutFails: true });
  const other = await context.newPage();
  await other.goto("/kartu");
  await expect(other.getByRole("img", { name: qrName })).toBeVisible();
  await confirmLogout(page);
  await expect(page.getByRole("heading", { name: recoveryTitle })).toBeVisible();
  // Simulate a server commit whose response never reached this browser.
  state.loggedIn = false;
  await page.getByRole("button", { name: "Periksa Sesi" }).click();
  await expect(page).toHaveURL(`${FRONTEND}/login`);
  await expect(page.getByRole("img", { name: qrName })).toHaveCount(0);
  await expect(other).toHaveURL(`${FRONTEND}/login`);
  await expect(other.getByRole("img", { name: qrName })).toHaveCount(0);
});

test("a revoked email session during logout recovery returns to login", async ({ page, context }) => {
  const state = await fixtureApi(context, { loggedIn: true, logoutFails: true });
  await confirmLogout(page);
  await expect(page.getByRole("heading", { name: recoveryTitle })).toBeVisible();
  state.active = false;
  await page.getByRole("button", { name: "Periksa Sesi" }).click();
  await expect(page).toHaveURL(`${FRONTEND}/login`);
  await expect(page.getByRole("img", { name: qrName })).toHaveCount(0);
});

test("session recovery hides private content and actions until the lookup finishes", async ({ page, context }) => {
  await fixtureApi(context, { loggedIn: true, logoutFails: true });
  await confirmLogout(page);
  await expect(page.getByRole("heading", { name: recoveryTitle })).toBeVisible();
  let release: () => void = () => {};
  const pending = new Promise<void>((resolve) => { release = resolve; });
  await page.route(`${API}/api/v1/auth/me`, async (route) => {
    await pending;
    await route.fallback();
  });
  try {
    await page.getByRole("button", { name: "Periksa Sesi" }).click();
    await expect(page.getByRole("status")).toContainText("Memeriksa sesi…");
    await expect(page.getByRole("button", { name: "Periksa Sesi" })).toHaveCount(0);
    await expect(page.getByRole("button", { name: "Coba Keluar Lagi" })).toHaveCount(0);
    await expect(page.getByRole("img", { name: qrName })).toHaveCount(0);
    release();
    await expect(page.getByRole("img", { name: qrName })).toBeVisible();
  } finally {
    release();
    await page.unrouteAll({ behavior: "wait" });
  }
});

test("logout in one tab clears the card in another tab", async ({ page, context }) => {
  await fixtureApi(context, { loggedIn: true });
  const other = await context.newPage();
  await other.goto("/kartu");
  await expect(other.getByRole("img", { name: qrName })).toBeVisible();
  await confirmLogout(page);
  await expect(page).toHaveURL(`${FRONTEND}/login`);
  await expect(other).toHaveURL(`${FRONTEND}/login`);
  await expect(other.getByRole("img", { name: qrName })).toHaveCount(0);
});
