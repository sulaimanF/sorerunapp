import { test, expect } from "@playwright/test";
import { spawn } from "node:child_process";
import { randomBytes } from "node:crypto";
import { resolve } from "node:path";
import { z } from "zod";

async function bridge(run: string, operation: string): Promise<string> {
  return new Promise((done, reject) => {
    const child = spawn("php", [resolve("../backend/tests/Support/stage09-live-bridge.php")], { windowsHide: true, stdio: ["pipe", "pipe", "pipe"] });
    let output = "";
    child.stdout.on("data", chunk => { output += String(chunk); });
    child.stderr.resume();
    child.on("error", reject);
    child.on("close", code => code === 0 ? done(output) : reject(new Error(`Isolated test helper failed (${operation}).`)));
    child.stdin.end(JSON.stringify({ run, operation }));
  });
}

test("real browser, Laravel sessions, MySQL, queue and local SMTP inbox complete email change", async ({ page, request }) => {
  const run = randomBytes(16).toString("hex");
  const oldEmail = `stage09-live-${run}@example.test`;
  const newEmail = `stage09-new-${run}@example.test`;
  await bridge(run, "create");
  try {
    await page.goto("/login");
    await page.getByRole("textbox", { name: "Email", exact: true }).fill(oldEmail);
    await page.getByLabel(/^Password/).fill("Stage09-live-password-123");
    await page.getByRole("button", { name: "Masuk", exact: true }).click();
    await expect(page).toHaveURL(/\/beranda$/);
    await page.getByRole("link", { name: "Profil", exact: true }).first().click();
    await page.getByRole("button", { name: "Ubah Email", exact: true }).click();
    await page.getByLabel(/^Email baru/).fill(newEmail);
    await page.getByLabel(/^Password akun saat ini/).fill("Stage09-live-password-123");
    await page.getByRole("button", { name: "Kirim Verifikasi Email Baru" }).click();
    await expect(page.getByText(/Permintaan perubahan email diterima/)).toBeVisible();
    await expect(page.getByText(oldEmail, { exact: true })).toBeVisible();
    expect(JSON.parse(await bridge(run, "deliver"))).toMatchObject({ delivery: { status: "sent", last_error_code: null } });
    const inboxSchema = z.object({ messages: z.array(z.object({ ID: z.string(), To: z.array(z.object({ Address: z.string() })) })) });
    const readInbox = async () => inboxSchema.parse(await (await request.get("http://127.0.0.1:8029/api/v1/messages")).json());
    await expect.poll(async () => (await readInbox()).messages.some(item => item.To.some(to => to.Address === newEmail))).toBe(true);
    const inbox = await readInbox();
    const message = inbox.messages.find(item => item.To.some(to => to.Address === newEmail));
    if (!message) throw new Error("Pending address did not receive a message in the local inbox.");
    const content = z.object({ Text: z.string() }).parse(await (await request.get(`http://127.0.0.1:8029/api/v1/message/${message.ID}`)).json());
    const link = String(content.Text).match(/http:\/\/localhost:3119\/verify-email-change#[^\s<>]+/)?.[0];
    if (!link) throw new Error("Local email did not contain the trusted confirmation link.");
    // The URL comes from the actual locally delivered email, never a fixture or DB token lookup.
    await page.goto(link);
    await expect(page).toHaveURL(/\/verify-email-change$/);
    await page.getByRole("button", { name: "Konfirmasi Email Baru" }).click();
    await expect(page).toHaveURL(/\/login$/);
    await expect(page.getByText(/Email berhasil diubah dan seluruh sesi/)).toBeVisible();
    const account = JSON.parse(await bridge(run, "inspect"));
    expect(account).toMatchObject({ email: newEmail, version: 2, verified: true, sessions: 0, consumed: 1 });
    await page.getByRole("textbox", { name: "Email", exact: true }).fill(newEmail);
    await page.getByLabel(/^Password/).fill("Stage09-live-password-123");
    await page.getByRole("button", { name: "Masuk", exact: true }).click();
    await expect(page).toHaveURL(/\/beranda$/);
    expect(await page.evaluate(() => [localStorage.length, sessionStorage.length])).toEqual([0, 0]);
  } finally {
    await bridge(run, "cleanup");
  }
});
