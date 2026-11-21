import { expect, test, type BrowserContext } from "@playwright/test";

const API = "http://localhost:8000";
const FRONTEND = "http://localhost:3100";
const meta = { request_id: "53c2f8b3-0286-4923-a729-b9c249fe5a2c" };
const proof = { invitation_id: "81812099-2b74-4862-8c41-1d275cdf1fd6", email: "invited@example.test", token: "a".repeat(64) };

// HTTP fixture only: no Laravel, database, role changes or real email.
async function fixture(context: BrowserContext, role: "super_admin" | "admin" | "staff" | "member", authenticated = true, member = false) {
  const state = {
    authenticated, role, profileReads: 0, accepts: 0, posts: [] as Record<string, unknown>[],
    disconnectAfterCreate: false, requestKey: "", activation: "pending",
    listMode: "populated" as "populated" | "empty" | "error", listReads: 0,
    deletions: 0, deletionFails: false, deleted: false, passwordChanges: 0, passwordFails: false,
  };
  await context.addCookies([{ name: "XSRF-TOKEN", value: "synthetic-stage11-xsrf", domain: "localhost", path: "/", sameSite: "Lax" }]);
  const target = () => ({
    user_id: 142, full_name: "Admin Uji", email: "invited@example.test", role: "admin",
    status: state.activation, expires_at: new Date(Date.now() + 86400000).toISOString(), retry_after: 60, activated_at: null,
  });
  await context.route(`${API}/**`, async route => {
    const request = route.request();
    const path = new URL(request.url()).pathname;
    const respond = (status: number, data?: unknown) => route.fulfill({
      status, headers: {
        "Access-Control-Allow-Origin": FRONTEND, "Access-Control-Allow-Credentials": "true",
        "Access-Control-Allow-Headers": "Accept, Content-Type, X-XSRF-TOKEN", "Access-Control-Allow-Methods": "GET, POST, DELETE, OPTIONS",
        "Cache-Control": "no-store", "Access-Control-Expose-Headers": "Retry-After", "Retry-After": "60",
      }, ...(status === 204 ? {} : { contentType: "application/json", body: JSON.stringify({ data, meta }) }),
    });
    const fail = (status: number, code: string) => route.fulfill({
      status, contentType: "application/json", body: JSON.stringify({ error: { code, message: "Permintaan ditolak pada fixture.", fields: {} }, meta }),
      headers: { "Access-Control-Allow-Origin": FRONTEND, "Access-Control-Allow-Credentials": "true" },
    });
    if (request.method() === "OPTIONS" || path === "/sanctum/csrf-cookie") return respond(204);
    if (path === "/api/v1/auth/me") {
      if (!state.authenticated) return fail(401, "UNAUTHENTICATED");
      return respond(200, { user: { id: 42, full_name: "Pengurus Sintetis", email: "actor@example.test", email_verified: true }, roles: member ? [role, "member"] : [role], profile_complete: member, membership: member ? { status: "active", member_number: "SRC-TEST", joined_at: "2026-11-21T00:00:00Z" } : null });
    }
    if (path === "/api/v1/auth/logout") { state.authenticated = false; return respond(204); }
    if (path === "/api/v1/me/account") return respond(200, { full_name: "Pengurus Sintetis", email: "actor@example.test", email_verified: true, email_change: { active_email: "actor@example.test", pending: null, retry_after: 0 } });
    if (path === "/api/v1/me/profile") { state.profileReads++; return fail(404, "PROFILE_NOT_FOUND"); }
    if (path === "/api/v1/admin/accounts" && request.method() === "GET") {
      state.listReads++;
      if (role !== "super_admin") return fail(403, "FORBIDDEN");
      if (state.listMode === "error") return fail(503, "UNAVAILABLE");
      const page = Number(new URL(request.url()).searchParams.get("page") || 1);
      return respond(200, {
        items: state.listMode === "empty" || state.deleted ? [] : page === 1 ? [{ user_id: 142, full_name: "Admin Uji", email: "invited@example.test", account_status: "enabled", email_verified: state.activation === "active", has_invitation: true, can_delete: true, can_change_password: state.activation === "active" }]
          : [{ user_id: 141, full_name: "Admin Lama", email: "legacy@example.test", account_status: "blocked", email_verified: true, has_invitation: false, can_delete: false, can_change_password: false }],
        pagination: { current_page: page, last_page: state.listMode === "empty" ? 1 : 2, per_page: 20, total: state.listMode === "empty" ? 0 : 21 },
      });
    }
    if (path === "/api/v1/admin/accounts/142" && request.method() === "DELETE") {
      state.deletions++;
      if (state.deletionFails) return fail(409, "ADMIN_DELETE_NOT_ALLOWED");
      state.deleted = true;
      return respond(204);
    }
    if (["/api/v1/me/password", "/api/v1/admin/accounts/142/password"].includes(path) && request.method() === "POST") {
      state.passwordChanges++;
      if (state.passwordFails) return fail(422, "CURRENT_PASSWORD_INVALID");
      if (path === "/api/v1/me/password") state.authenticated = false;
      return respond(204);
    }
    if (path === "/api/v1/admin/accounts" && request.method() === "POST") {
      if (role !== "super_admin") return fail(403, "FORBIDDEN");
      const body: Record<string, unknown> = request.postDataJSON();
      state.posts.push(body);
      state.requestKey ||= String(body.request_key);
      expect(body.request_key).toBe(state.requestKey);
      if (state.disconnectAfterCreate) { state.disconnectAfterCreate = false; return route.abort("failed"); }
      return respond(201, target());
    }
    if (path === "/api/v1/admin/accounts/142/invitation") return respond(200, target());
    if (path === "/api/v1/admin/accounts/142/invitation/resend") return respond(202, target());
    if (path === "/api/v1/auth/admin-invitations/accept") {
      state.accepts++;
      if (state.authenticated) return fail(403, "INVITATION_SESSION_CONFLICT");
      state.activation = "active";
      return respond(200, { result: "admin_activated" });
    }
    return fail(404, "NOT_FOUND");
  });
  return state;
}

test("super admin creates an operational Admin and retains idempotency after an unknown response", async ({ page, context }) => {
  const state = await fixture(context, "super_admin");
  state.disconnectAfterCreate = true;
  await page.goto("/admin/accounts/new");
  await page.getByRole("textbox", { name: "Nama lengkap", exact: true }).fill("Admin Uji");
  await page.getByRole("textbox", { name: "Email", exact: true }).fill("invited@example.test");
  await page.getByRole("button", { name: "Buat Akun Admin", exact: true }).click();
  await expect(page.getByText("Hasil permintaan belum pasti.", { exact: false })).toBeVisible();
  await page.getByRole("button", { name: "Periksa Permintaan yang Sama" }).click();
  await expect(page.getByRole("heading", { name: "Akun Admin dibuat" })).toBeVisible();
  expect(state.posts).toHaveLength(2);
  expect(Object.keys(state.posts[0]).sort()).toEqual(["email", "full_name", "request_key"]);
  await expect(page.getByRole("button", { name: /^Tunggu .* detik$/ })).toBeDisabled();
  await page.getByRole("link", { name: "Buka Halaman Status Undangan" }).click();
  await expect(page.getByRole("heading", { name: "Status Undangan Admin" })).toBeVisible();
  await page.setViewportSize({ width: 320, height: 720 });
  expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBe(true);
  const status = page.getByRole("region", { name: "Status undangan", exact: true });
  const create = status.getByRole("link", { name: "Buat Akun Admin", exact: true });
  await expect(create).toHaveClass(/button-outline/);
  expect(await create.evaluate(element => {
    const parent = element.parentElement;
    return !!parent && parent.querySelectorAll("button").length === 2 && getComputedStyle(parent).gap === "12px";
  })).toBe(true);
  await page.getByRole("navigation", { name: "Navigasi anggota", exact: true }).getByRole("link", { name: "Profil", exact: true }).click();
  await expect(page.getByRole("heading", { name: "Profil Akun" })).toBeVisible();
  expect(state.profileReads).toBe(0);
});

for (const role of ["admin", "staff", "member"] as const) {
  test(`${role} cannot open provisioning or see its navigation`, async ({ page, context }) => {
    const state = await fixture(context, role);
    await page.goto("/admin/accounts/new");
    await expect(page.getByRole("heading", { name: "Akses terbatas" })).toBeVisible();
    await expect(page.getByRole("form", { name: "Form pembuatan Admin" })).toHaveCount(0);
    await expect(page.getByRole("link", { name: "Buat Admin", exact: true })).toHaveCount(0);
    await page.goto("/admin/accounts");
    await expect(page.getByRole("heading", { name: "Akses terbatas" })).toBeVisible();
    expect(state.listReads).toBe(0);
    expect(state.posts).toHaveLength(0);
  });
}

test("Super Admin browses the Admin list, pagination and invitation status at 320px", async ({ page, context }) => {
  const state = await fixture(context, "super_admin");
  await page.goto("/admin/accounts/new");
  await page.getByRole("link", { name: "Daftar Akun Admin", exact: true }).click();
  await expect(page.getByRole("heading", { name: "Daftar Akun Admin", exact: true })).toBeVisible();
  await expect(page.getByText("invited@example.test", { exact: true })).toBeVisible();
  await expect(page.getByRole("button", { name: "Sebelumnya" })).toBeDisabled();
  await page.getByRole("button", { name: "Berikutnya" }).click();
  await expect(page.getByText("legacy@example.test", { exact: true })).toBeVisible();
  await expect(page.getByText("Diblokir", { exact: true })).toBeVisible();
  await expect(page.getByRole("link", { name: /^Status Undangan/ })).toHaveCount(0);
  await page.getByRole("button", { name: "Sebelumnya" }).click();
  await page.setViewportSize({ width: 320, height: 720 });
  const link = page.getByRole("link", { name: "Status Undangan Admin Uji", exact: true });
  await expect(link).toBeVisible();
  expect((await link.boundingBox())?.height).toBeGreaterThanOrEqual(44);
  expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBe(true);
  await page.screenshot({ path: ".next/stage11-admin-list-320.png" });
  await page.setViewportSize({ width: 1280, height: 900 });
  await page.screenshot({ path: ".next/stage11-admin-list-desktop.png" });
  await link.click();
  await expect(page.getByRole("heading", { name: "Status Undangan Admin" })).toBeVisible();
  expect(state.posts).toHaveLength(0);
});

test("Admin list distinguishes failures and empty results and clears after logout", async ({ page, context }) => {
  const state = await fixture(context, "super_admin");
  state.listMode = "error";
  await page.goto("/admin/accounts");
  await expect(page.getByRole("alert")).toBeVisible();
  await expect(page.getByText("Belum ada akun Admin.", { exact: true })).toHaveCount(0);
  state.listMode = "empty";
  await page.getByRole("button", { name: "Perbarui Daftar" }).click();
  await expect(page.getByText("Belum ada akun Admin.", { exact: true })).toBeVisible();
  state.listMode = "populated";
  await page.getByRole("button", { name: "Perbarui Daftar" }).click();
  await expect(page.getByText("invited@example.test", { exact: true })).toBeVisible();
  await page.getByRole("button", { name: "Keluar dari akun", exact: true }).click();
  await page.getByRole("dialog").getByRole("button", { name: "Ya, Keluar", exact: true }).click();
  await expect(page).toHaveURL(/\/login$/);
  await page.goto("/admin/accounts");
  await expect(page).toHaveURL(/\/login$/);
  await expect(page.getByText("invited@example.test", { exact: true })).toHaveCount(0);
});

test("Admin deletion requires confirmation, preserves the account on error and refreshes after success", async ({ page, context }) => {
  const state = await fixture(context, "super_admin");
  await page.setViewportSize({ width: 320, height: 720 });
  await page.goto("/admin/accounts");
  const remove = page.getByRole("button", { name: "Hapus Akun Admin Uji", exact: true });
  await expect(remove).toBeVisible();
  expect(await remove.evaluate(element => element.parentElement ? getComputedStyle(element.parentElement).gap : null)).toBe("12px");
  await remove.click();
  const dialog = page.getByRole("dialog", { name: "Hapus akun Admin?" });
  await expect(dialog.getByRole("button", { name: "Batal", exact: true })).toBeFocused();
  expect(state.deletions).toBe(0);
  await page.keyboard.press("Escape");
  await expect(dialog).toHaveCount(0);
  state.deletionFails = true;
  await remove.click();
  await dialog.getByRole("button", { name: "Ya, Hapus Akun", exact: true }).click();
  await expect(dialog.getByRole("alert")).toBeVisible();
  expect(state.deleted).toBe(false);
  expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBe(true);
  await page.screenshot({ path: ".next/stage11-delete-dialog-320.png" });
  state.deletionFails = false;
  await dialog.getByRole("button", { name: "Ya, Hapus Akun", exact: true }).click();
  await expect(dialog).toHaveCount(0);
  await expect(page.getByText("Akun Admin telah dihapus dari daftar.", { exact: false })).toBeVisible();
  await expect(remove).toHaveCount(0);
  expect(state.deletions).toBe(2);
});

for (const role of ["admin", "super_admin"] as const) {
  test(`${role} changes own password from Profile and returns to login with a cleared session`, async ({ page, context }) => {
    const state = await fixture(context, role);
    await page.goto("/profil");
    await expect(page.getByRole("form", { name: "Form ubah password sendiri" })).toHaveCount(0);
    await page.getByRole("button", { name: "Ubah Password", exact: true }).click();
    const form = page.getByRole("form", { name: "Form ubah password sendiri" });
    await form.getByLabel("Password Anda saat ini", { exact: false }).fill("Current-test-password-123");
    await form.getByLabel("Password baru", { exact: false }).first().fill("short");
    await form.getByLabel("Konfirmasi password baru", { exact: false }).fill("short");
    await form.getByRole("button", { name: "Simpan Password Baru" }).click();
    expect(state.passwordChanges).toBe(0);
    await expect(form.getByRole("alert")).toBeVisible();
    await form.getByLabel(/^Password baru/).fill("Changed-test-password-123");
    await form.getByLabel(/^Konfirmasi password baru/).fill("Changed-test-password-123");
    await form.getByRole("button", { name: "Simpan Password Baru" }).click();
    await expect(page).toHaveURL(/\/login$/);
    await expect(page.getByText("Password berhasil diubah dan seluruh sesi telah diakhiri.", { exact: false })).toBeVisible();
    expect(state.passwordChanges).toBe(1);
    expect(await page.evaluate(() => localStorage.length + sessionStorage.length)).toBe(0);
  });
}

test("Super Admin changes an activated Admin password without email and keeps own session", async ({ page, context }) => {
  const state = await fixture(context, "super_admin");
  state.activation = "active";
  state.passwordFails = true;
  await page.setViewportSize({ width: 320, height: 720 });
  await page.goto("/admin/accounts");
  await page.getByRole("button", { name: "Ubah Password Admin Uji", exact: true }).click();
  const dialog = page.getByRole("dialog", { name: "Ubah Password Admin", exact: true });
  await expect(dialog.getByLabel(/^Password Anda saat ini/)).toBeFocused();
  await dialog.getByLabel(/^Password Anda saat ini/).fill("Super-test-password-123");
  await dialog.getByLabel(/^Password baru Admin/).fill("Target-new-password-123");
  await dialog.getByLabel(/^Konfirmasi password baru/).fill("Target-new-password-123");
  await dialog.getByRole("button", { name: "Simpan Password Baru" }).click();
  await expect(dialog.getByRole("alert")).toBeVisible();
  expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBe(true);
  await page.screenshot({ path: ".next/stage11-password-dialog-320.png" });
  state.passwordFails = false;
  await dialog.getByRole("button", { name: "Simpan Password Baru" }).click();
  await expect(dialog).toHaveCount(0);
  await expect(page.getByText("Password Admin berhasil diubah tanpa konfirmasi email.", { exact: false })).toBeVisible();
  expect(state.authenticated).toBe(true);
  expect(state.passwordChanges).toBe(2);
});

test("invitation GET is read-only and activation requires an explicit POST without auto-login", async ({ page, context }) => {
  const state = await fixture(context, "admin", false);
  await page.goto(`/accept-admin-invitation#${new URLSearchParams(proof)}`);
  await expect(page.getByRole("form", { name: "Form aktivasi Admin" })).toBeVisible();
  expect(state.accepts).toBe(0);
  await expect(page).toHaveURL(/\/accept-admin-invitation$/);
  await page.getByLabel(/^Password baru/).fill("New-test-password-123");
  await page.getByLabel(/^Konfirmasi password/).fill("New-test-password-123");
  await page.getByRole("button", { name: "Aktifkan Akun Admin", exact: true }).click();
  await expect(page.getByRole("heading", { name: "Akun Admin berhasil diaktifkan" })).toBeVisible();
  expect(state.accepts).toBe(1);
  expect(state.authenticated).toBe(false);
  await expect(page.getByRole("link", { name: "Masuk ke Akun" })).toHaveAttribute("href", "/login");
  expect(await page.evaluate(() => localStorage.length + sessionStorage.length)).toBe(0);
});

test("an invitation does not replace another active browser identity", async ({ page, context }) => {
  const state = await fixture(context, "member", true);
  await page.goto(`/accept-admin-invitation#${new URLSearchParams(proof)}`);
  await expect(page.getByText("Ada akun yang sedang login.", { exact: false })).toBeVisible();
  await expect(page.getByRole("form", { name: "Form aktivasi Admin" })).toHaveCount(0);
  expect(state.accepts).toBe(0);
});

test("multi-role Super Admin retains five accessible navigation items at 320px", async ({ page, context }) => {
  await fixture(context, "super_admin", true, true);
  await page.setViewportSize({ width: 320, height: 720 });
  await page.emulateMedia({ reducedMotion: "reduce" });
  await page.goto("/admin/accounts/new");
  const nav = page.getByRole("navigation", { name: "Navigasi anggota", exact: true });
  await expect(nav.getByRole("link")).toHaveCount(5);
  for (const label of ["Beranda", "Kartu", "Profil", "Kelola Landing Page", "Buat Admin"]) {
    const link = nav.getByRole("link", { name: label, exact: true });
    await expect(link).toBeVisible();
    const bounds = await link.boundingBox();
    expect(bounds?.width).toBeGreaterThanOrEqual(44);
    expect(bounds?.height).toBeGreaterThanOrEqual(44);
  }
  const name = page.getByRole("textbox", { name: "Nama lengkap", exact: true });
  await name.focus();
  await page.keyboard.press("Tab");
  await expect(page.getByRole("textbox", { name: "Email", exact: true })).toBeFocused();
  expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBe(true);
  await page.screenshot({ path: ".next/stage11-multi-role-320.png" });
  await page.setViewportSize({ width: 1440, height: 900 });
  await expect(page.getByRole("navigation", { name: "Navigasi utama", exact: true })).toBeVisible();
  await page.screenshot({ path: ".next/stage11-multi-role-desktop.png" });
});

