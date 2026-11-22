import { test, expect, type Page } from "@playwright/test";

const API = "http://localhost:18100";
const mediaId = "18e62276-14a2-4b26-bd09-724db36c926b";
test.beforeEach(async ({ request }) => { await request.post(`${API}/__fixture/reset`); });

async function signIn(page: Page) {
  await page.goto("/login");
  await page.getByRole("textbox", { name: "Email", exact: true }).fill("landing@example.test");
  await page.getByLabel(/^Password/).fill("Synthetic-browser-password");
  await page.getByRole("button", { name: "Masuk", exact: true }).click();
  await expect(page).toHaveURL(/\/beranda$/);
  // The URL can change before the existing login/session transition finishes.
  await expect(page.getByRole("heading", { name: "Halo, Admin Sintetis.", exact: true })).toBeVisible();
}

test("landing loading has a centered spinner, accessible status and reduced motion", async ({ page, request }) => {
  await request.post(`${API}/__fixture/state`, { data: { holdPublic: true } });
  try {
    await page.setViewportSize({ width: 320, height: 720 });
    await page.goto("/", { waitUntil: "commit" });
    const loading = page.getByRole("status").filter({ hasText: "Memuat informasi komunitas…" });
    await expect(loading).toBeVisible();
    await expect(loading).toHaveAttribute("aria-busy", "true");
    const spinner = loading.locator(".spinner");
    await expect(spinner).toBeVisible();
    await expect(spinner).toHaveAttribute("aria-hidden", "true");
    await expect(spinner).toHaveCSS("animation-name", "spin");
    expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBe(true);
    await page.screenshot({ path: ".next/loading-320.png" });
    await page.emulateMedia({ reducedMotion: "reduce" });
    await expect(spinner).toHaveCSS("animation-name", "none");
    await expect(loading).toContainText("Memuat informasi komunitas…");
    await page.setViewportSize({ width: 1280, height: 900 });
    await page.screenshot({ path: ".next/loading-desktop.png" });
  } finally {
    await request.post(`${API}/__fixture/state`, { data: { holdPublic: false } });
  }
  await expect(page.getByRole("heading", { name: "Selamat datang di sorerunapp" })).toBeVisible();
  await expect(page.locator(".src-landing-loading")).toHaveCount(0);
});

test("admin landing tab shares member navigation and stays active in editor and preview", async ({ page }) => {
  await signIn(page);
  const desktop = page.getByRole("navigation", { name: "Navigasi utama", exact: true });
  await expect(desktop.getByRole("link")).toHaveText(["Beranda", "Kartu", "Profil", "Kelola Landing Page"]);
  await expect(page.locator(".account-menu").getByRole("link", { name: "Kelola Landing Page" })).toHaveCount(0);
  await desktop.getByRole("link", { name: "Kelola Landing Page", exact: true }).click();
  await expect(desktop.getByRole("link", { name: "Kelola Landing Page", exact: true })).toHaveAttribute("aria-current", "page");
  await expect(page.getByRole("form", { name: "Editor landing page" })).toBeVisible();
  await page.getByRole("link", { name: "Preview Draft Tersimpan" }).click();
  await expect(page).toHaveURL(/\/admin\/landing-page\/preview$/);
  await expect(desktop.getByRole("link", { name: "Kelola Landing Page", exact: true })).toHaveAttribute("aria-current", "page");
  for (const width of [1024, 768, 320]) {
    await page.setViewportSize({ width, height: 720 });
    const navigation = width >= 1024 ? desktop : page.getByRole("navigation", { name: "Navigasi anggota", exact: true });
    await expect(navigation.getByRole("link")).toHaveText(["Beranda", "Kartu", "Profil", "Kelola Landing Page"]);
    const tab = navigation.getByRole("link", { name: "Kelola Landing Page", exact: true });
    await expect(tab).toBeVisible();
    await expect(tab).toHaveAttribute("aria-current", "page");
    expect(await tab.evaluate(element => element.getBoundingClientRect().height)).toBeGreaterThanOrEqual(44);
    expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBe(true);
    if (width === 320) await page.screenshot({ path: ".next/stage10-admin-tabs-320.png" });
  }
  await page.getByRole("navigation", { name: "Navigasi anggota", exact: true }).getByRole("link", { name: "Beranda", exact: true }).click();
  await expect(page).toHaveURL(/\/beranda$/);
  await page.getByRole("navigation", { name: "Navigasi anggota", exact: true }).getByRole("link", { name: "Kelola Landing Page", exact: true }).click();
  await expect(page.getByRole("form", { name: "Editor landing page" })).toBeVisible();
  const mobileBar = await page.getByRole("navigation", { name: "Navigasi anggota", exact: true }).boundingBox();
  const mainBottom = await page.getByRole("main").evaluate(element => element.getBoundingClientRect().bottom + scrollY);
  const documentHeight = await page.evaluate(() => document.documentElement.scrollHeight);
  if (mobileBar === null) throw new Error("Visible mobile navigation is missing.");
  expect(documentHeight - mainBottom).toBeGreaterThanOrEqual(mobileBar.height);
  await page.setViewportSize({ width: 1280, height: 900 });
  await page.screenshot({ path: ".next/stage10-admin-tabs-desktop.png" });
});

for (const role of ["member", "staff"]) {
  test(`${role} sees only member tabs and cannot open the landing editor`, async ({ page, request }) => {
    await request.post(`${API}/__fixture/state`, { data: { role } });
    await signIn(page);
    for (const width of [1280, 320]) {
      await page.setViewportSize({ width, height: 720 });
      const navigation = page.getByRole("navigation", { name: width === 320 ? "Navigasi anggota" : "Navigasi utama", exact: true });
      await expect(navigation.getByRole("link")).toHaveText(["Beranda", "Kartu", "Profil"]);
      await expect(page.getByRole("link", { name: "Kelola Landing Page" })).toHaveCount(0);
      expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBe(true);
    }
    await page.goto("/admin/landing-page");
    await expect(page.getByRole("heading", { name: "Akses terbatas" })).toBeVisible();
    await expect(page.getByRole("form", { name: "Editor landing page" })).toHaveCount(0);
  });
}

test("image caption validation is associated with its input and blocks saving", async ({ page, request }) => {
  const state = (await (await request.get(`${API}/__fixture/state`)).json()).data;
  state.content.hero.image = { media_id: mediaId, alt: "Gambar sintetis", caption: "" };
  await request.post(`${API}/__fixture/state`, { data: { content: state.content, media: [{ id: mediaId, mime: "image/webp", width: 640, height: 360, url: `${API}/api/v1/admin/landing-page/media/${mediaId}` }] } });
  await signIn(page);
  await page.getByRole("link", { name: "Kelola Landing Page" }).click();
  const caption = page.getByRole("group", { name: "Gambar hero", exact: true }).getByLabel("Caption (opsional)");
  await caption.fill("<script>Konten tidak valid</script>");
  await page.getByRole("button", { name: "Simpan Draft", exact: true }).click();
  await expect(caption).toHaveAttribute("aria-invalid", "true");
  await expect(caption).toHaveAccessibleDescription("Gunakan teks biasa tanpa markup atau karakter kontrol.");
  await expect(caption).toBeFocused();
  expect((await (await request.get(`${API}/__fixture/state`)).json()).data.version).toBe(0);
});

test("editor manages editorial fields, section ordering and visibility through publication", async ({ page, request }) => {
  await request.post(`${API}/__fixture/state`, { data: { media: [{ id: mediaId, mime: "image/webp", width: 640, height: 360, url: `${API}/api/v1/admin/landing-page/media/${mediaId}` }] } });
  await signIn(page);
  await page.getByRole("link", { name: "Kelola Landing Page" }).click();
  const fields = {
    "header-login_label": "Buka aplikasi uji", "hero-eyebrow": "Pengantar editorial", "hero-title": "Headline editorial",
    "hero-description": "Deskripsi editorial", "hero-login_label": "Login anggota uji",
    "about-title": "Tentang tersembunyi", "about-description": "Cerita tersembunyi",
    "activities-title": "Kegiatan editorial", "activities-description": "Informasi kegiatan publik sintetis.",
    "gallery-title": "Galeri sintetis", "faq-title": "FAQ editorial", "faq-items-0-question": "Pertanyaan editorial?", "faq-items-0-answer": "Jawaban editorial.",
    "contact-title": "Kontak editorial", "contact-description": "Kontak sintetis untuk pengujian.", "contact-email": "kontak@example.test", "contact-phone": "+6281234567890",
    "footer-text": "Footer editorial sintetis", "seo-title": "SEO editorial", "seo-description": "Deskripsi SEO editorial.",
  };
  for (const [name, value] of Object.entries(fields)) await page.locator(`#content-${name}`).fill(value);
  await page.getByRole("heading", { name: "Kegiatan dan manfaat", exact: true }).locator("..").getByRole("button", { name: "Tambah item", exact: true }).click();
  await page.locator("#content-activities-items-0-title").fill("Manfaat sintetis");
  await page.locator("#content-activities-items-0-description").fill("Penjelasan manfaat sintetis.");
  await page.getByRole("button", { name: "Tambah media sosial", exact: true }).click();
  await page.locator("#content-contact-socials-0-label").fill("Komunitas uji");
  await page.locator("#content-contact-socials-0-url").fill("https://example.test/komunitas");
  await page.getByRole("button", { name: "Tambah foto galeri", exact: true }).click();
  const gallery = page.getByRole("group", { name: "Gambar galeri 1", exact: true });
  await gallery.getByLabel("Alt text", { exact: true }).fill("Alt galeri sintetis");
  await gallery.getByLabel("Caption (opsional)").fill("Caption galeri editorial");
  const sharing = page.getByRole("group", { name: "Gambar berbagi", exact: true });
  await sharing.getByRole("combobox").selectOption(mediaId);
  await sharing.getByLabel("Alt text", { exact: true }).fill("Alt berbagi sintetis");
  await page.getByRole("button", { name: "Naikkan activities", exact: true }).click();
  await page.getByRole("checkbox", { name: "Tampilkan about", exact: true }).uncheck();
  await page.getByRole("button", { name: "Simpan Draft", exact: true }).click();
  await expect(page.getByText("Draft versi 1 berhasil disimpan.")).toBeVisible();
  await page.getByRole("button", { name: "Publikasikan", exact: true }).click();
  await page.getByRole("button", { name: "Ya, Publikasikan", exact: true }).click();
  await expect(page.getByRole("dialog", { name: "Publikasi berhasil", exact: true })).toBeVisible();
  await page.getByRole("dialog", { name: "Publikasi berhasil", exact: true }).getByRole("button", { name: "Tutup", exact: true }).click();
  await page.goto("/");
  await expect(page).toHaveTitle("SEO editorial");
  await expect(page.locator('meta[name="description"]')).toHaveAttribute("content", "Deskripsi SEO editorial.");
  await expect(page.locator('meta[property="og:image:alt"]')).toHaveAttribute("content", "Alt berbagi sintetis");
  await expect(page.getByRole("link", { name: "Buka aplikasi uji", exact: true })).toHaveAttribute("href", "/login");
  await expect(page.getByRole("link", { name: "Login anggota uji", exact: true })).toHaveAttribute("href", "/login");
  await expect(page.getByRole("heading", { name: "Headline editorial" })).toBeVisible();
  await expect(page.getByText("Pengantar editorial", { exact: true })).toBeVisible();
  await expect(page.getByText("Manfaat sintetis", { exact: true })).toBeVisible();
  await expect(page.getByText("Caption galeri editorial", { exact: true })).toBeVisible();
  await expect(page.getByAltText("Alt galeri sintetis")).toHaveCount(1);
  await page.getByText("Pertanyaan editorial?", { exact: true }).click();
  await expect(page.getByText("Jawaban editorial.", { exact: true })).toBeVisible();
  await expect(page.getByRole("link", { name: "kontak@example.test" })).toHaveAttribute("href", "mailto:kontak@example.test");
  await expect(page.getByRole("link", { name: "Komunitas uji" })).toHaveAttribute("href", "https://example.test/komunitas");
  await expect(page.getByText("Footer editorial sintetis", { exact: true })).toBeVisible();
  await expect(page.getByRole("heading", { name: "Tentang tersembunyi" })).toHaveCount(0);
  expect(await page.locator("main > section[id]").evaluateAll(elements => elements.map(element => element.id))).toEqual(["activities", "gallery", "faq", "contact"]);
});

test("publication result popup waits for the server, prevents duplicate submission and restores focus", async ({ page, request }) => {
  await signIn(page);
  await page.goto("/admin/landing-page");
  await page.setViewportSize({ width: 320, height: 900 });
  let release = () => {};
  const gate = new Promise<void>(resolve => { release = resolve; });
  let requests = 0;
  await page.route(`${API}/api/v1/admin/landing-page/publish`, async route => {
    requests++;
    await gate;
    await route.fallback();
  });
  try {
    await page.getByRole("button", { name: "Publikasikan", exact: true }).click();
    const confirm = page.getByRole("dialog", { name: "Publikasikan landing page?", exact: true });
    await expect(confirm.getByRole("button", { name: "Batal", exact: true })).toBeFocused();
    await confirm.getByRole("button", { name: "Ya, Publikasikan", exact: true }).evaluate(button => {
      if (!(button instanceof HTMLButtonElement)) throw new Error("Publish confirmation must be a button.");
      button.click(); button.click();
    });
    await expect(confirm.getByRole("button", { name: "Menerbitkan…", exact: true })).toBeDisabled();
    await expect(confirm.locator(".spinner")).toBeVisible();
    await page.keyboard.press("Escape");
    await expect(confirm).toBeVisible();
    await expect(page.getByRole("dialog", { name: "Publikasi berhasil", exact: true })).toHaveCount(0);
    expect((await (await request.get(`${API}/__fixture/state`)).json()).data.publication).toBeNull();
    release();
    const result = page.getByRole("dialog", { name: "Publikasi berhasil", exact: true });
    await expect(result).toBeVisible();
    await expect(result).toContainText("Draft versi 0 berhasil dipublikasikan.");
    await expect(result.getByRole("button", { name: "Tutup", exact: true })).toBeFocused();
    const bounds = await result.boundingBox();
    if (!bounds) throw new Error("Publication result popup is missing.");
    expect(bounds.x).toBeGreaterThanOrEqual(0);
    expect(bounds.x + bounds.width).toBeLessThanOrEqual(320);
    expect(requests).toBe(1);
    await page.keyboard.press("Escape");
    await expect(result).toHaveCount(0);
    await expect(page.getByRole("button", { name: "Publikasikan", exact: true })).toBeFocused();
  } finally { release(); }
});

for (const status of [422, 409, 429, 503, "network"] as const) {
  test(`publication result popup handles ${status} without replaying or claiming success`, async ({ page, request }) => {
    await signIn(page);
    await page.goto("/admin/landing-page");
    let requests = 0;
    await page.route(`${API}/api/v1/admin/landing-page/publish`, route => {
      requests++;
      if (status === "network") return route.abort();
      return route.fulfill({ status, headers: { "Access-Control-Allow-Origin": "http://localhost:3110", "Access-Control-Allow-Credentials": "true", "Access-Control-Expose-Headers": "Retry-After", ...(status === 429 ? { "Retry-After": "73" } : {}) }, json: {
        error: { code: status === 422 ? "VALIDATION_FAILED" : status === 409 ? "CONTENT_VERSION_CONFLICT" : status === 429 ? "RATE_LIMITED" : "SERVICE_UNAVAILABLE", message: "Publikasi ditolak dalam pengujian sintetis.", fields: status === 422 ? { "content.hero.title": ["Lengkapi headline sebelum publikasi."] } : {} },
        meta: { request_id: "b3618469-5e0b-4f70-a61b-78680e559b1c" },
      } });
    });
    await page.getByRole("button", { name: "Publikasikan", exact: true }).click();
    await page.getByRole("button", { name: "Ya, Publikasikan", exact: true }).click();
    const unknown = status === 503 || status === "network";
    const result = page.getByRole("dialog", { name: unknown ? "Status publikasi belum pasti" : "Publikasi gagal", exact: true });
    await expect(result).toBeVisible();
    await expect(result).toContainText(unknown ? "Hasil publikasi belum dapat dipastikan." : "Publikasi ditolak dalam pengujian sintetis.");
    if (status === 422) await expect(result).toContainText("Lengkapi headline sebelum publikasi.");
    await expect(result.getByRole("button", { name: "Tutup", exact: true })).toBeEnabled();
    await result.getByRole("button", { name: "Tutup", exact: true }).click();
    await expect(result).toHaveCount(0);
    if (status === 422) {
      await expect(page.locator("#content-hero-title")).toBeFocused();
      await expect(page.locator("#content-hero-title")).toHaveAttribute("aria-invalid", "true");
      await expect(page.locator("#content-hero-title")).toHaveValue("Komunitas untuk pengujian");
    }
    if (status === 409 || unknown) await expect(page.getByRole("button", { name: "Muat Ulang Draft Terbaru", exact: true })).toBeVisible();
    if (status === 429) await expect(page.getByText(/Tunggu \d+ detik sebelum mencoba kembali/)).toBeVisible();
    expect(requests).toBe(1);
    expect((await (await request.get(`${API}/__fixture/state`)).json()).data.publication).toBeNull();
    await expect(page.getByRole("dialog", { name: "Publikasi berhasil", exact: true })).toHaveCount(0);
  });
}

for (const status of [429, 503]) {
  test(`draft error ${status} preserves input without replaying the mutation`, async ({ page, request }) => {
    await signIn(page);
    await page.getByRole("link", { name: "Kelola Landing Page" }).click();
    let requests = 0;
    await page.route(`${API}/api/v1/admin/landing-page/draft`, route => {
      requests++;
      return route.fulfill({ status, headers: { "Access-Control-Allow-Origin": "http://localhost:3110", "Access-Control-Allow-Credentials": "true", "Access-Control-Expose-Headers": "Retry-After, X-Request-ID", ...(status === 429 ? { "Retry-After": "73" } : {}) }, json: { error: { code: status === 429 ? "RATE_LIMITED" : "SERVICE_UNAVAILABLE", message: "Gangguan pengujian sintetis.", fields: {} }, meta: { request_id: "b3618469-5e0b-4f70-a61b-78680e559b1c" } } });
    });
    const headline = page.locator("#content-hero-title");
    await headline.fill("Input lokal dipertahankan");
    await page.getByRole("button", { name: "Simpan Draft", exact: true }).click();
    await expect(page.getByRole("alert").filter({ hasText: "Gangguan pengujian sintetis." })).toBeVisible();
    await expect(headline).toHaveValue("Input lokal dipertahankan");
    if (status === 429) await expect(page.getByText(/Tunggu 7\d detik/)).toBeVisible();
    else await expect(page.getByRole("button", { name: "Muat Ulang Draft Terbaru" })).toBeVisible();
    await expect(page.getByRole("button", { name: status === 429 ? "Memproses…" : "Simpan Draft", exact: true })).toBeDisabled();
    expect(requests).toBe(1);
    expect((await (await request.get(`${API}/__fixture/state`)).json()).data.version).toBe(0);
  });
}

test("root is public with no publication and never requires a session", async ({ page, request }) => {
  const response = await page.goto("/");
  expect(response?.status()).toBe(200);
  await expect(page.getByText("Informasi komunitas belum dipublikasikan.", { exact: false })).toBeVisible();
  await expect(page.getByRole("link", { name: "Masuk ke Aplikasi" }).first()).toHaveAttribute("href", "/login");
  await expect(page.locator('meta[name="robots"]')).toHaveAttribute("content", /noindex/);
  await expect(page.locator('link[rel="canonical"]')).toHaveAttribute("href", "http://localhost:3110/");
  const state = (await (await request.get(`${API}/__fixture/state`)).json()).data;
  expect(state.publicCookie).toBe(false);
});

test("API failure is explicit and never presented as unpublished content", async ({ page, request }) => {
  await request.post(`${API}/__fixture/state`, { data: { failPublic: true } });
  await page.goto("/");
  await expect(page.getByRole("heading", { name: "Informasi komunitas belum dapat dimuat" })).toBeVisible();
  await expect(page.getByText(/Informasi komunitas belum dipublikasikan/)).toHaveCount(0);
});

test("metadata and body share one render while later requests see fresh content", async ({ page, request }) => {
  const state = (await (await request.get(`${API}/__fixture/state`)).json()).data;
  await request.post(`${API}/__fixture/state`, { data: { rotatePublic: true, publication: { version: 1, content: state.content, published_at: "2026-11-14T12:00:00Z", media: [] } } });
  await page.goto("/");
  const heading = page.getByRole("heading", { level: 1 });
  await expect(heading).toHaveText(/^render-/);
  const first = await heading.innerText();
  await expect(page).toHaveTitle(first);
  await page.reload();
  await expect(heading).toHaveText(/^render-/);
  await expect(heading).not.toHaveText(first);
  await expect(page).toHaveTitle(await heading.innerText());
});

test("admin draft, private preview, publish, metadata and later draft remain separate", async ({ page, request }) => {
  await signIn(page);
  await page.getByRole("link", { name: "Kelola Landing Page" }).click();
  await page.getByLabel("Headline (opsional)", { exact: true }).fill("Headline publik sintetis");
  await page.getByRole("button", { name: "Simpan Draft", exact: true }).click();
  await expect(page.getByText(/Draft versi/).first()).toContainText("1");
  await page.getByRole("link", { name: "Preview Draft Tersimpan" }).click();
  await expect(page.getByRole("heading", { name: "Headline publik sintetis" })).toBeVisible();
  await expect(page.locator('meta[name="robots"]')).toHaveAttribute("content", /noindex/);
  await page.getByRole("link", { name: "Kembali ke Editor" }).click();
  await page.getByRole("button", { name: "Publikasikan", exact: true }).click();
  await page.getByRole("button", { name: "Ya, Publikasikan", exact: true }).click();
  await expect(page.getByRole("dialog", { name: "Publikasi berhasil", exact: true })).toBeVisible();
  await page.getByRole("dialog", { name: "Publikasi berhasil", exact: true }).getByRole("button", { name: "Tutup", exact: true }).click();
  await page.getByLabel("Headline (opsional)", { exact: true }).fill("Draft yang belum diterbitkan");
  await page.getByRole("button", { name: "Simpan Draft", exact: true }).click();
  await expect(page.getByText(/Draft versi/).first()).toContainText("2");
  const html = await (await request.get("/")).text();
  expect(html).toContain("Headline publik sintetis"); expect(html).not.toContain("Draft yang belum diterbitkan");
  await page.goto("/");
  await expect(page).toHaveTitle("SEO landing sintetis");
  await expect(page.getByRole("heading", { name: "Headline publik sintetis" })).toBeVisible();
  expect((await (await request.get(`${API}/__fixture/state`)).json()).data.publicCookie).toBe(false);
});

test("version conflict preserves input and member cannot open the editor", async ({ page, request }) => {
  await signIn(page); await page.goto("/admin/landing-page");
  await page.getByLabel("Headline (opsional)", { exact: true }).fill("Perubahan lokal dipertahankan");
  await request.post(`${API}/__fixture/state`, { data: { conflict: true } });
  await page.getByRole("button", { name: "Simpan Draft", exact: true }).click();
  await expect(page.getByRole("alert").filter({ hasText: "CONTENT_VERSION_CONFLICT" })).toBeVisible();
  await expect(page.getByLabel("Headline (opsional)", { exact: true })).toHaveValue("Perubahan lokal dipertahankan");
  await request.post(`${API}/__fixture/state`, { data: { role: "member" } });
  await page.reload();
  await expect(page.getByRole("heading", { name: "Akses terbatas" })).toBeVisible();
  await expect(page.getByRole("form", { name: "Editor landing page" })).toHaveCount(0);
});

test("published landing supports 320px, keyboard, FAQ and reduced motion", async ({ page, request }) => {
  const state = (await (await request.get(`${API}/__fixture/state`)).json()).data;
  await request.post(`${API}/__fixture/state`, { data: { publication: { version: 1, content: state.content, published_at: "2026-11-14T12:00:00Z", media: [] } } });
  await page.setViewportSize({ width: 320, height: 720 }); await page.emulateMedia({ reducedMotion: "reduce" });
  await page.goto("/");
  await expect(page.getByRole("heading", { level: 1 })).toHaveText("Komunitas untuk pengujian");
  expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBe(true);
  const faq = page.getByText("Bagaimana membuka aplikasi?", { exact: true });
  await faq.focus(); await page.keyboard.press("Enter");
  await expect(page.getByText("Gunakan tombol masuk.", { exact: true })).toBeVisible();
  await expect(page.getByRole("link", { name: "Galeri", exact: true })).toHaveCount(0);
  const menu = page.getByText("Menu halaman", { exact: true });
  await menu.focus(); await page.keyboard.press("Enter");
  await expect(page.getByRole("navigation", { name: "Navigasi section" })).toBeVisible();
  await page.keyboard.press("Tab");
  await expect(page.getByRole("navigation", { name: "Navigasi section" }).getByRole("link").first()).toBeFocused();
  const focus = await page.evaluate(() => getComputedStyle(document.activeElement!).outlineStyle);
  expect(focus).not.toBe("none");
  // A 640px viewport at 200% desktop zoom has a 320 CSS-pixel layout viewport.
  const cdp = await page.context().newCDPSession(page);
  await cdp.send("Emulation.setDeviceMetricsOverride", { width: 320, height: 720, deviceScaleFactor: 2, mobile: false });
  expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBe(true);
  expect(await page.getByRole("link", { name: "Masuk ke Aplikasi", exact: true }).first().evaluate(element => element.getBoundingClientRect().height)).toBeGreaterThanOrEqual(44);
});

test("hero photo spans the banner with readable overlay, private preview and safe image failure", async ({ page, request, context }) => {
  // Synthetic bright image makes overlay contrast observable without any external photo.
  const canvas = await context.newPage();
  const image = Buffer.from(await canvas.evaluate(() => {
    const image = document.createElement("canvas");
    image.width = 1600; image.height = 900;
    const drawing = image.getContext("2d");
    if (!drawing) throw new Error("Synthetic image could not be created.");
    const gradient = drawing.createLinearGradient(0, 0, 1600, 900);
    gradient.addColorStop(0, "white"); gradient.addColorStop(0.5, "gold"); gradient.addColorStop(1, "orange");
    drawing.fillStyle = gradient; drawing.fillRect(0, 0, 1600, 900);
    return image.toDataURL("image/webp").split(",")[1];
  }), "base64");
  await canvas.close();
  const url = `${API}/hero-synthetic.webp`;
  await page.route(url, route => route.fulfill({ contentType: "image/webp", body: image }));
  await page.route(`${API}/api/v1/admin/landing-page/media/${mediaId}`, route => route.fulfill({ contentType: "image/webp", body: image }));
  const state = (await (await request.get(`${API}/__fixture/state`)).json()).data;
  state.content.hero.image = { media_id: mediaId, alt: "Gambar hero sintetis", caption: "Gambar sintetis untuk memeriksa banner SRC." };
  const media = [{ id: mediaId, mime: "image/webp", width: 1600, height: 900, url }];
  await request.post(`${API}/__fixture/state`, { data: { content: state.content, media, publication: { version: 1, content: state.content, published_at: "2026-11-14T12:00:00Z", media } } });
  await page.goto("/");
  for (const width of [1440, 320]) {
    await page.setViewportSize({ width, height: 900 });
    const hero = page.getByRole("region", { name: "Komunitas untuk pengujian" });
    const photo = hero.getByAltText("Gambar hero sintetis");
    await expect(photo).toBeVisible();
    await expect(photo).toHaveCSS("object-fit", "cover");
    await expect.poll(() => photo.evaluate(node => node instanceof HTMLImageElement && node.naturalWidth)).toBe(1600);
    const banner = await hero.boundingBox();
    const picture = await photo.boundingBox();
    if (!banner || !picture) throw new Error("Hero or image is missing.");
    expect(banner.x).toBe(0);
    expect(banner.width).toBe(width);
    expect(picture.width).toBeCloseTo(banner.width, 0);
    expect(picture.height).toBeCloseTo(banner.height, 0);
    const login = hero.getByRole("link", { name: "Masuk ke Aplikasi", exact: true });
    await expect(login).toHaveAttribute("href", "/login");
    await login.focus();
    await expect(login).toBeFocused();
    await expect(login).not.toHaveCSS("outline-style", "none");
    await expect(hero.getByText("Gambar sintetis untuk memeriksa banner SRC.")).toBeVisible();
    expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBe(true);
    await page.screenshot({ path: `.next/hero-banner-${width}.png` });
  }
  await signIn(page);
  await page.goto("/admin/landing-page/preview");
  const preview = page.locator(".src-landing-hero-cover");
  await expect(preview.getByAltText("Gambar hero sintetis")).toHaveAttribute("src", /^blob:/);
  await expect(preview.getByAltText("Gambar hero sintetis")).toHaveCSS("object-fit", "cover");
  await page.goto("/");
  await page.route(url, route => route.abort());
  await page.reload();
  await expect(page.getByRole("heading", { name: "Komunitas untuk pengujian" })).toBeVisible();
  await expect(page.locator(".src-landing-hero-cover").getByRole("link", { name: "Masuk ke Aplikasi", exact: true })).toBeVisible();
});

test("failed public image leaves headline and login usable and root ignores a logged-in session", async ({ page, request }) => {
  await signIn(page);
  const state = (await (await request.get(`${API}/__fixture/state`)).json()).data;
  const id = "18e62276-14a2-4b26-bd09-724db36c926b";
  state.content.hero.image = { media_id: id, alt: "Gambar sintetis gagal", caption: "Caption tetap tersedia" };
  await request.post(`${API}/__fixture/state`, { data: { publication: { version: 1, content: state.content, published_at: "2026-11-14T12:00:00Z", media: [{ id, mime: "image/webp", width: 640, height: 360, url: `${API}/missing-image.webp` }] } } });
  const reads: string[] = [];
  page.on("request", req => { if (req.url().endsWith("/auth/me")) reads.push(new URL(page.url()).pathname); });
  await page.getByRole("link", { name: "sorerunapp — Sore Running Club" }).first().click();
  await expect(page.getByRole("heading", { name: "Komunitas untuk pengujian" })).toBeVisible();
  await expect(page.getByAltText("Gambar sintetis gagal")).toBeVisible();
  await expect(page.getByRole("link", { name: "Masuk ke Aplikasi" }).first()).toHaveAttribute("href", "/login");
  expect(reads).toEqual([]);
  await page.getByRole("link", { name: "Masuk ke Aplikasi" }).first().click();
  await page.getByRole("textbox", { name: "Email", exact: true }).fill("landing@example.test");
  await page.getByLabel(/^Password/).fill("Synthetic-browser-password");
  await page.getByRole("button", { name: "Masuk", exact: true }).click();
  await expect(page).toHaveURL(/\/beranda$/);
});
