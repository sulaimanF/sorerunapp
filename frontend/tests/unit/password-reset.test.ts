import assert from "node:assert/strict";
import test from "node:test";
import { passwordResetFromFragment, passwordResetIntent } from "../../src/features/auth/password-reset-intent";
import { forgotPasswordSchema, resetPasswordSchema } from "../../src/features/auth/schemas";

const token = "a".repeat(64);
test("reset proof requires exactly email and token in a fragment", () => {
  assert.deepEqual(passwordResetFromFragment(`#email=TEST%40example.test&token=${token}`), { email: "test@example.test", token });
  for (const input of ["", `?email=test@example.test&token=${token}`, `#email=test@example.test&token=${token}&returnTo=/bad`, `#email=test@example.test&token=${token}&token=${token}`, `#email=bad&token=${token}`, "#email=test@example.test&token=short"]) {
    assert.equal(passwordResetFromFragment(input), null);
  }
});
test("reset intent is cleared without persistence and not exposed to server rendering", () => {
  passwordResetIntent.set({ email: "test@example.test", token });
  assert.equal(passwordResetIntent.get()?.token, token);
  assert.equal(passwordResetIntent.server(), null);
  passwordResetIntent.set(null);
  assert.equal(passwordResetIntent.get(), null);
});
test("leaving a preserved route clears proof while Strict Mode effect replay keeps it", async () => {
  passwordResetIntent.set({ email: "test@example.test", token });
  const releaseFirst = passwordResetIntent.retain();
  releaseFirst();
  const releaseReplay = passwordResetIntent.retain();
  await Promise.resolve();
  assert.equal(passwordResetIntent.get()?.token, token);
  releaseReplay();
  await Promise.resolve();
  assert.equal(passwordResetIntent.get(), null);
});
test("reset validation keeps registration password policy and matching confirmation", () => {
  assert.equal(forgotPasswordSchema.parse({ email: "  TEST@example.test " }).email, "test@example.test");
  for (const password of ["short", "é".repeat(37), "Long-password-\0"]) {
    assert.equal(resetPasswordSchema.safeParse({ password, password_confirmation: password }).success, false);
  }
  assert.equal(resetPasswordSchema.safeParse({ password: "New-test-password-123", password_confirmation: "different" }).success, false);
  assert.equal(resetPasswordSchema.safeParse({ password: "New-test-password-123", password_confirmation: "New-test-password-123" }).success, true);
});
