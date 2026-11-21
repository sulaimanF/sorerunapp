import { chromium, expect, type Page, type BrowserContext } from "@playwright/test";
import { spawn } from "node:child_process";
import { randomBytes } from "node:crypto";
import { readFile, mkdir, writeFile } from "node:fs/promises";
import { resolve } from "node:path";
import { setTimeout as delay } from "node:timers/promises";
import { z } from "zod";

const run = randomBytes(16).toString("hex");
const password = randomBytes(24).toString("base64url");
const root = resolve("../backend");
const frontend = "http://localhost:3000";
const inbox = "http://127.0.0.1:8025";
const statePath = resolve(root, "storage/framework/local-background/state.json");
const email = (suffix: string) => `background-${run}-${suffix}@example.test`;
const checks: string[] = [];
let stage = "preflight";
let paused = false;

async function php(arguments_: string[], input?: object): Promise<string> {
  return new Promise((done, reject) => {
    const child = spawn("php", arguments_, { cwd: root, windowsHide: true, stdio: ["pipe", "pipe", "pipe"] });
    let output = "";
    child.stdout.on("data", chunk => { output += String(chunk); });
    child.stderr.resume();
    child.on("error", () => reject(new Error("Local PHP failed to start.")));
    child.on("close", code => code === 0 ? done(output) : reject(new Error("Local PHP operation failed.")));
    child.stdin.end(input ? JSON.stringify(input) : "");
  });
}
const helper = async (operation: string, extra = {}) => JSON.parse(await php(["tests/Support/local-background-smoke.php"], { run, operation, ...extra }));
const state = async () => z.object({ worker_enabled: z.boolean(), worker_pid: z.number().nullable(), generation: z.number(), restarting: z.boolean() }).parse(JSON.parse(await readFile(statePath, "utf8")));
function pass(message: string) { checks.push(message); console.log(`PASS: ${message}`); }

async function register(page: Page, suffix: string) {
  await page.goto(`${frontend}/register`);
  await page.getByLabel(/^Nama lengkap/).fill("Anggota Uji Background");
  await page.getByLabel(/^Tanggal lahir/).fill("01/01/2000");
  await page.getByLabel(/^Nomor telepon/).fill("081234567890");
  await page.getByLabel(/^Domisili/).fill("Kota Uji Lokal");
  await page.getByLabel(/^Email/).fill(email(suffix));
  await page.getByLabel(/^Password/).fill(password);
  await page.getByLabel(/^Konfirmasi password/).fill(password);
  await page.getByRole("checkbox", { name: /^Saya telah membaca/ }).check();
  await page.getByRole("checkbox", { name: /^Saya menyetujui/ }).check();
  const response = page.waitForResponse(reply => reply.url().endsWith("/api/v1/auth/register") && reply.request().method() === "POST");
  await page.getByRole("button", { name: "Daftar Member", exact: true }).click();
  expect((await response).status()).toBe(201);
  await expect(page).toHaveURL(`${frontend}/registration-received`);
}

const inboxSchema = z.object({ messages: z.array(z.object({ ID: z.string(), Subject: z.string(), To: z.array(z.object({ Address: z.string() })) })) });
async function messages(context: BrowserContext, recipient: string) {
  const response = await context.request.get(`${inbox}/api/v1/messages`);
  expect(response.status()).toBe(200);
  return inboxSchema.parse(await response.json()).messages.filter(message => message.To.some(to => to.Address === recipient));
}
async function verificationLink(context: BrowserContext, suffix: string) {
  let match: z.infer<typeof inboxSchema>["messages"][number] | undefined;
  await expect.poll(async () => {
    match = (await messages(context, email(suffix))).find(message => /verifikasi/i.test(message.Subject));
    return !!match;
  }, { timeout: 95000, intervals: [1000] }).toBe(true);
  if (!match) throw new Error("Synthetic verification message missing.");
  const response = await context.request.get(`${inbox}/api/v1/message/${match.ID}`);
  const content = z.object({ Text: z.string() }).parse(await response.json());
  const link = content.Text.match(/http:\/\/localhost:3000\/verify-email#[^\s<>]+/)?.[0];
  if (!link) throw new Error("Trusted local verification link missing.");
  return link;
}

const browser = await chromium.launch({ headless: true });
try {
  await helper("baseline");
  const context = await browser.newContext();
  const page = await context.newPage();
  page.setDefaultTimeout(30000);
  page.setDefaultNavigationTimeout(90000);
  stage = "normal queue";
  await register(page, "normal");
  const link = await verificationLink(context, "normal");
  const normal = await helper("inspect");
  expect(normal.deliveries.some((row: { status: string }) => row.status === "sent")).toBe(true);
  pass("Normal browser request enqueues after commit and arrives through the running worker without a delivery command");
  stage = "verification and login";
  await page.goto(link);
  await page.getByRole("button", { name: "Verifikasi Email", exact: true }).click();
  await expect(page.getByRole("heading", { name: "Email terverifikasi", exact: true })).toBeVisible();
  await page.getByRole("link", { name: "Masuk ke Akun", exact: true }).click();
  await page.getByRole("textbox", { name: "Email", exact: true }).fill(email("normal"));
  await page.getByLabel(/^Password/).fill(password);
  await page.getByRole("button", { name: "Masuk", exact: true }).click();
  await expect(page).toHaveURL(`${frontend}/beranda`);
  pass("Link from Mailpit verifies the synthetic account and normal login opens beranda");
  stage = "pause worker";
  await php(["artisan", "dev:background", "pause-worker"]);
  paused = true;
  await expect.poll(async () => { const current = await state(); return !current.worker_enabled && current.worker_pid === null; }, { timeout: 30000 }).toBe(true);
  const otherContext = await browser.newContext();
  const other = await otherContext.newPage();
  await register(other, "paused");
  await delay(2000);
  expect(await messages(otherContext, email("paused"))).toHaveLength(0);
  const queued = await helper("inspect");
  expect(queued.queue_jobs).toBeGreaterThan(0);
  expect(queued.deliveries.some((row: { type: string; status: string }) => row.type === "email_verification" && row.status === "pending")).toBe(true);
  await php(["artisan", "dev:background", "resume-worker"]);
  paused = false;
  await verificationLink(otherContext, "paused");
  pass("Worker stopped, synthetic request remained queued, worker restarted and sent it automatically");
  stage = "scheduler recovery";
  await expect.poll(async () => (await helper("inspect")).queue_jobs, { timeout: 20000 }).toBe(0);
  // Avoid the minute boundary so absence of a producer job can be observed before the real scheduler tick.
  if (new Date().getUTCSeconds() >= 50) await delay((61 - new Date().getUTCSeconds()) * 1000);
  const gap = await helper("gap", { password });
  expect(gap.created_without_enqueue).toBe(true);
  expect(gap.queue_jobs).toBe(0);
  const createdAt = Date.now();
  await verificationLink(otherContext, "gap");
  const schedulerLog = await readFile(resolve(root, "storage/logs/local-scheduler.log"), "utf8");
  expect(schedulerLog).toContain("Outbox jobs enqueued:");
  pass("Real scheduled outbox task recovers a newly committed synthetic delivery with no producer job and sends it");
  stage = "restart and integrity";
  const previousGeneration = (await state()).generation;
  await php(["artisan", "dev:background", "restart"]);
  await expect.poll(async () => { const current = await state(); return !current.restarting && current.worker_enabled && current.generation > previousGeneration; }, { timeout: 30000 }).toBe(true);
  const final = await helper("inspect");
  expect(final.existing_unchanged).toBe(true);
  pass("Launcher restart and original account/delivery/credential snapshots remain consistent");
  const output = resolve(root, "storage/app/private/local-background-smoke", `${run}-result.json`);
  await mkdir(resolve(root, "storage/app/private/local-background-smoke"), { recursive: true });
  await writeFile(output, JSON.stringify({ run, success: true, checks, final, scheduler_recovery_wait_ms: Date.now() - createdAt, finished_at: new Date().toISOString() }, null, 2));
  console.log(JSON.stringify({ success: true, run, output, accounts: final.accounts }));
} catch (error) {
  const diagnostic = error instanceof Error ? error.message.split("\n")[0].replaceAll(password, "[redacted]").replace(/#[^\s]+/g, "#[redacted]") : "unknown";
  console.error(JSON.stringify({ success: false, run, stage, diagnostic, error_type: error instanceof Error ? error.name : "unknown" }));
  process.exitCode = 1;
} finally {
  if (paused) await php(["artisan", "dev:background", "resume-worker"]);
  await browser.close();
}
