import { test, expect } from "@playwright/test";
import { spawn } from "node:child_process";
import { randomBytes } from "node:crypto";
import { resolve } from "node:path";
import { z } from "zod";

function php(file: string, input: string, args: string[] = []): Promise<{ code: number | null; output: string }> {
  return new Promise((done, reject) => {
    const child = spawn("php", [resolve(`../backend/tests/Support/${file}`), ...args], { windowsHide: true, stdio: ["pipe", "pipe", "pipe"] });
    let output = "";
    child.stdout.on("data", chunk => { output += String(chunk); });
    child.stderr.resume();
    child.on("error", reject);
    child.on("close", code => done({ code, output }));
    child.stdin.end(input);
  });
}
async function bridge(run: string, operation: string): Promise<unknown> {
  const result = await php("stage11-live-bridge.php", JSON.stringify({ run, operation }));
  if (result.code !== 0) throw new Error(`Initial activation bridge failed (${operation}).`);
  return JSON.parse(result.output);
}
const stateSchema = z.object({
  verified: z.boolean(), version: z.number(), roles: z.array(z.string()), fence_completed: z.boolean(),
  activation_audits: z.number(), bootstrap_audits: z.number(), deliveries: z.array(z.string()),
  jobs: z.number(), member_data: z.number(), sequence: z.number(),
});

test("real initial CLI → queue → Mailpit → browser activation without role → separate bootstrap → Super login", async ({ page, request }) => {
  const run = randomBytes(16).toString("hex");
  const email = `stage11-live-${run}@example.test`;
  const fixture = z.object({ user_id: z.number() }).parse(await bridge(run, "create-initial"));
  const target = [`--user-id=${fixture.user_id}`, `--email=${email}`, "--no-ansi"];
  const inspect = async () => stateSchema.parse(await bridge(run, "inspect-initial"));
  try {
    const before = await inspect();
    expect(before.verified).toBe(false);
    expect(before.roles).toEqual([]);
    const premature = await php("stage11-console.php", "yes\n", ["admin:bootstrap-super", ...target]);
    expect(premature.code).not.toBe(0);
    const issued = await php("stage11-console.php", "yes\n", ["admin:activate-first-account", ...target]);
    expect(issued.code, "Explicit initial activation CLI must succeed on the test database.").toBe(0);
    const queued = await inspect();
    expect(queued.jobs).toBe(1);
    expect(queued.deliveries).toEqual(["pending"]);
    const delivered = await php("stage11-console.php", "", ["admin:deliver-first-activation", ...target]);
    expect(delivered.code, "Controlled Mailpit delivery command must complete.").toBe(0);
    expect((await inspect()).deliveries).toEqual(["sent"]);
    const inboxSchema = z.object({ messages: z.array(z.object({ ID: z.string(), To: z.array(z.object({ Address: z.string() })) })) });
    let messageId: string | undefined;
    await expect.poll(async () => {
      const inbox = inboxSchema.parse(await (await request.get("http://127.0.0.1:8025/api/v1/messages", { params: { limit: 100 } })).json());
      messageId = inbox.messages.find(message => message.To.some(to => to.Address === email))?.ID;
      return !!messageId;
    }).toBe(true);
    if (!messageId) throw new Error("Synthetic initial activation message missing.");
    const message = z.object({ HTML: z.string() }).parse(await (await request.get(`http://127.0.0.1:8025/api/v1/message/${messageId}`)).json());
    const invitation = [...message.HTML.matchAll(/href="([^"]+)"/g)].map(match => match[1].replaceAll("&amp;", "&"))
      .find(link => link.startsWith("http://localhost:3130/activate-first-account#"));
    if (!invitation) throw new Error("Initial activation link missing from local inbox.");
    await page.setViewportSize({ width: 320, height: 760 });
    await page.goto(invitation);
    await expect(page.getByRole("form", { name: "Form aktivasi akun operasional" })).toBeVisible();
    expect(new URL(page.url()).hash.length).toBe(0);
    expect((await inspect()).verified).toBe(false);
    expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBe(true);
    const robots = await page.locator('meta[name="robots"]').getAttribute("content");
    expect(robots).toContain("noindex");
    await page.getByLabel(/^Password baru/).focus();
    await expect(page.getByLabel(/^Password baru/)).toBeFocused();
    await page.screenshot({ path: ".next/stage11-first-activation-320.png" });
    await page.setViewportSize({ width: 1280, height: 900 });
    await page.screenshot({ path: ".next/stage11-first-activation-desktop.png" });
    await page.getByLabel(/^Password baru/).fill("Initial-browser-test-password-123");
    await page.getByLabel(/^Konfirmasi password/).fill("Initial-browser-test-password-123");
    await page.getByRole("button", { name: "Aktifkan Akun Operasional", exact: true }).click();
    await expect(page.getByRole("heading", { name: "Akun operasional berhasil diaktifkan" })).toBeVisible();
    expect(await page.evaluate(() => localStorage.length + sessionStorage.length)).toBe(0);
    const activated = await inspect();
    expect(activated.verified).toBe(true);
    expect(activated.version).toBe(2);
    expect(activated.roles).toEqual([]);
    expect(activated.fence_completed).toBe(false);
    expect(activated.activation_audits).toBe(1);
    expect(activated.bootstrap_audits).toBe(0);
    expect(activated.member_data).toBe(0);
    expect(activated.sequence).toBe(before.sequence);
    const bootstrap = await php("stage11-console.php", "yes\n", ["admin:bootstrap-super", ...target]);
    expect(bootstrap.code).toBe(0);
    const repeated = await php("stage11-console.php", "yes\n", ["admin:bootstrap-super", ...target]);
    expect(repeated.code).not.toBe(0);
    const bootstrapped = await inspect();
    expect(bootstrapped.roles).toEqual(["super_admin"]);
    expect(bootstrapped.version).toBe(3);
    expect(bootstrapped.bootstrap_audits).toBe(1);
    await page.getByRole("link", { name: "Masuk ke Akun", exact: true }).click();
    await page.getByRole("textbox", { name: "Email", exact: true }).fill(email);
    await page.getByLabel(/^Password/).fill("Initial-browser-test-password-123");
    await page.getByRole("button", { name: "Masuk", exact: true }).click();
    await expect(page).toHaveURL(/\/beranda$/);
    await expect(page.getByRole("link", { name: "Buat Admin", exact: true })).toBeVisible();
    await page.getByRole("link", { name: "Buat Admin", exact: true }).click();
    await expect(page.getByRole("form", { name: "Form pembuatan Admin" })).toBeVisible();
    await page.goto("/admin/landing-page");
    await expect(page.getByRole("form", { name: "Editor landing page" })).toBeVisible();
    await page.goto(invitation);
    await expect(page.getByText("Ada akun yang sedang login.", { exact: false })).toBeVisible();
    expect((await inspect()).activation_audits).toBe(1);
  } finally {
    await bridge(run, "cleanup");
  }
});
