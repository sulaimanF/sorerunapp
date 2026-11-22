import { test, expect } from "@playwright/test";
import { spawn } from "node:child_process";
import { randomBytes } from "node:crypto";
import { resolve } from "node:path";
import { z } from "zod";

async function bridge(run: string, operation: string): Promise<string> {
  return new Promise((done, reject) => {
    const child = spawn("php", [resolve("../backend/tests/Support/stage10-live-bridge.php")], { windowsHide: true, stdio: ["pipe", "pipe", "pipe"] });
    let output = "";
    child.stdout.on("data", chunk => { output += String(chunk); });
    child.stderr.resume();
    child.on("error", reject);
    child.on("close", code => code === 0 ? done(output) : reject(new Error(`Isolated Stage10 helper failed (${operation}).`)));
    child.stdin.end(JSON.stringify({ run, operation }));
  });
}

test("real browser → Laravel → MySQL test → private storage → draft → preview → publish → public SSR", async ({ page, request }) => {
  const run = randomBytes(16).toString("hex");
  const fixture = z.object({ image: z.string() }).parse(JSON.parse(await bridge(run, "create")));
  try {
    await page.goto("/login");
    await expect(page.locator('meta[name="robots"]')).toHaveAttribute("content", /noindex/);
    await page.getByRole("textbox", { name: "Email", exact: true }).fill(`stage10-${run}@example.test`);
    await page.getByLabel(/^Password/).fill("Stage10-test-password-123");
    await page.getByRole("button", { name: "Masuk", exact: true }).click();
    await expect(page).toHaveURL(/\/beranda$/);
    await page.getByRole("link", { name: "Kelola Landing Page" }).click();
    await expect(page.locator('meta[name="robots"]')).toHaveAttribute("content", /noindex/);
    await page.locator("#content-hero-title").fill("Publikasi integrasi sintetis");
    await page.locator("#content-hero-description").fill("Konten test terisolasi, bukan publikasi resmi.");
    await page.locator("#content-seo-title").fill("SEO integrasi sintetis");
    await page.locator("#content-seo-description").fill("Metadata pengujian integrasi.");
    const uploaded = page.waitForResponse(response => response.url().endsWith("/admin/landing-page/media") && response.request().method() === "POST");
    await page.getByLabel("Unggah gambar", { exact: true }).setInputFiles({ name: "synthetic.png", mimeType: "image/png", buffer: Buffer.from(fixture.image, "base64") });
    await expect(page.getByText(/Gambar tersimpan privat/)).toBeVisible();
    const uploadedId = z.object({ data: z.object({ id: z.string().uuid() }) }).parse(await (await uploaded).json()).data.id;
    expect((await request.get(`http://localhost:8110/api/v1/public/landing-page/media/${uploadedId}`)).status()).toBe(404);
    expect((await request.get(`http://localhost:8110/api/v1/admin/landing-page/media/${uploadedId}`)).status()).toBe(401);
    expect((await request.get('/_next/image', { params: { url: `http://localhost:8110/api/v1/admin/landing-page/media/${uploadedId}`, w: 640, q: 75 } })).status()).toBe(400);
    const hero = page.getByRole("group", { name: "Gambar hero", exact: true });
    await hero.getByRole("combobox").selectOption({ index: 1 });
    await hero.getByLabel("Alt text", { exact: true }).fill("Bidang emas sintetis");
    await hero.getByLabel("Caption (opsional)").fill("Gambar sintetis untuk pengujian.");
    await page.getByRole("button", { name: "Simpan Draft", exact: true }).click();
    await expect(page.getByText("Draft versi 1 berhasil disimpan.")).toBeVisible();
    expect((await (await request.get("http://localhost:8110/api/v1/public/landing-page")).json()).data.publication).toBeNull();
    await page.setViewportSize({ width: 320, height: 720 });
    expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBe(true);
    await page.getByRole("heading", { name: "Kelola Landing Page", exact: true }).scrollIntoViewIfNeeded();
    await page.screenshot({ path: ".next/stage10-editor-320-viewport.png" });
    await page.screenshot({ path: ".next/stage10-editor-320.png", fullPage: true });
    await page.getByRole("link", { name: "Preview Draft Tersimpan" }).click();
    await expect(page.getByRole("heading", { name: "Publikasi integrasi sintetis" })).toBeVisible();
    await expect.poll(() => page.getByAltText("Bidang emas sintetis").evaluate((image: HTMLImageElement) => image.naturalWidth)).toBe(640);
    await expect(page.getByAltText("Bidang emas sintetis")).toHaveAttribute("src", /^blob:/);
    await expect(page.locator('meta[name="robots"]')).toHaveAttribute("content", /noindex/);
    await page.getByRole("link", { name: "Kembali ke Editor" }).click();
    await page.getByRole("button", { name: "Publikasikan", exact: true }).click();
    await expect(page.getByRole("button", { name: "Batal", exact: true })).toBeFocused();
    await page.keyboard.press("Escape");
    await expect(page.getByRole("button", { name: "Publikasikan", exact: true })).toBeFocused();
    await page.getByRole("button", { name: "Publikasikan", exact: true }).click();
    await page.getByRole("button", { name: "Ya, Publikasikan", exact: true }).click();
    await expect(page.getByRole("dialog", { name: "Publikasi berhasil", exact: true })).toBeVisible();
    await page.getByRole("dialog", { name: "Publikasi berhasil", exact: true }).getByRole("button", { name: "Tutup", exact: true }).click();
    await page.locator("#content-hero-title").fill("Draft integrasi belum terbit");
    await page.getByRole("button", { name: "Simpan Draft", exact: true }).click();
    await expect(page.getByText("Draft versi 2 berhasil disimpan.")).toBeVisible();
    const serverResponse = await request.get("/");
    const html = await serverResponse.text();
    // Report only safe render states on failure, rather than dumping HTML/RSC.
    expect({
      status: serverResponse.status(),
      headline: html.includes("Publikasi integrasi sintetis"),
      metadata: html.includes("SEO integrasi sintetis"),
      privateDraft: html.includes("Draft integrasi belum terbit"),
      unavailable: html.includes("Informasi komunitas belum dapat dimuat"),
    }).toEqual({ status: 200, headline: true, metadata: true, privateDraft: false, unavailable: false });
    const api = await (await request.get("http://localhost:8110/api/v1/public/landing-page")).json();
    const asset = z.object({ data: z.object({ publication: z.object({ media: z.array(z.object({ url: z.string().url() })).nonempty() }) }) }).parse(api).data.publication.media[0];
    expect((await request.get(asset.url)).status()).toBe(200);
    let sessionReads = 0;
    page.on("request", req => { if (req.url().endsWith("/api/v1/auth/me")) sessionReads++; });
    await page.setViewportSize({ width: 1440, height: 1000 });
    await page.goto("/");
    await expect(page).toHaveTitle("SEO integrasi sintetis");
    await expect(page.locator('meta[name="robots"]')).toHaveAttribute("content", process.env.LANDING_PRODUCTION_TEST === "true" ? "index, follow" : "noindex, nofollow");
    await expect(page.locator('link[rel="canonical"]')).toHaveAttribute("href", process.env.LANDING_PRODUCTION_TEST === "true" ? "https://src.example.invalid/" : "http://localhost:3111/");
    await expect.poll(() => page.getByAltText("Bidang emas sintetis").evaluate((image: HTMLImageElement) => image.naturalWidth)).toBe(640);
    expect(sessionReads).toBe(0);
    await page.screenshot({ path: ".next/stage10-public-desktop.png", fullPage: true });
    await page.setViewportSize({ width: 320, height: 720 });
    await page.emulateMedia({ reducedMotion: "reduce" });
    expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBe(true);
    await page.screenshot({ path: ".next/stage10-public-320.png", fullPage: true });
    await page.goto("/admin/landing-page");
    await page.getByRole("button", { name: "Keluar dari akun" }).click();
    await page.getByRole("button", { name: "Tidak", exact: true }).click();
    await expect(page.getByRole("form", { name: "Editor landing page" })).toBeVisible();
    await page.getByRole("button", { name: "Keluar dari akun" }).click();
    await page.getByRole("button", { name: "Ya, Keluar", exact: true }).click();
    await expect(page).toHaveURL(/\/login$/);
    await page.goto("/admin/landing-page");
    await expect(page).toHaveURL(/\/login$/);
    await page.goto("/");
    await expect(page.getByRole("heading", { name: "Publikasi integrasi sintetis" })).toBeVisible();
    expect(JSON.parse(await bridge(run, "inspect"))).toMatchObject({ revisions: 1, media: 1, version: 2, batch: expect.any(Number) });
    expect(await page.evaluate(() => [localStorage.length, sessionStorage.length])).toEqual([0, 0]);
  } finally {
    await bridge(run, "cleanup");
  }
});
