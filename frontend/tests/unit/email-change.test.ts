import assert from "node:assert/strict";
import test from "node:test";
import { emailChangeFromFragment, emailChangeIntent } from "../../src/features/auth/email-change-intent";

const requestId = "d6578996-20ac-4d4e-ab72-ef8d187fe122";
const token = "a".repeat(64);
const fragment = `#request_id=${requestId}&email=NEW%40example.test&token=${token}`;

test("email change proof requires a unique request, destination and token without navigation fields", () => {
  assert.deepEqual(emailChangeFromFragment(fragment), { request_id: requestId, email: "new@example.test", token });
  for (const input of ["", fragment.replace("#", "?"), `${fragment}&returnTo=/beranda`, `${fragment}&token=${token}`, fragment.replace(requestId, "42"), fragment.replace("NEW%40example.test", "invalid"), fragment.replace(token, "short")]) {
    assert.equal(emailChangeFromFragment(input), null);
  }
});

test("leaving the confirmation clears in-memory proof while Strict Mode replay preserves it", async () => {
  emailChangeIntent.set(emailChangeFromFragment(fragment));
  assert.equal(emailChangeIntent.server(), null);
  const release = emailChangeIntent.retain();
  release();
  const releaseReplay = emailChangeIntent.retain();
  await Promise.resolve();
  assert.equal(emailChangeIntent.get()?.token, token);
  releaseReplay();
  await Promise.resolve();
  assert.equal(emailChangeIntent.get(), null);
});
