import assert from "node:assert/strict";
import test from "node:test";
import { browserSessionSchema, type BrowserSession } from "../../src/features/auth/types";
import { canChangeOwnPassword, canManageAdministration, canProvisionAdmins } from "../../src/features/auth/permissions";
import { createAdminSchema, activationPasswordSchema, administrativePasswordSchema } from "../../src/features/administration/schemas";
import { adminInvitationFromFragment, adminInvitationIntent, firstActivationIntent } from "../../src/features/administration/invitation-intent";

const id = "7f94729d-ad4f-4ef5-8388-16881f705b82";
const token = "a".repeat(64);
test("session parser and centralized permissions support super_admin and multiple roles", () => {
  const session: BrowserSession = browserSessionSchema.parse({
    user: { id: 41, full_name: "Synthetic Super", email: "super@example.test", email_verified: true },
    roles: ["member", "super_admin"], profile_complete: false, membership: null,
  });
  assert.equal(canManageAdministration(session), true);
  assert.equal(canProvisionAdmins(session), true);
  assert.equal(canManageAdministration({ roles: ["admin"] }), true);
  for (const roles of [["admin"], ["staff"], ["member"], []] as const) {
    assert.equal(canProvisionAdmins({ roles: [...roles] }), false);
  }
  assert.equal(canManageAdministration({ roles: ["staff"] }), false);
  assert.equal(canManageAdministration(null), false);
  for (const role of ["member", "admin", "super_admin"] as const) assert.equal(canChangeOwnPassword({ roles: [role] }), true);
  assert.equal(canChangeOwnPassword({ roles: ["staff"] }), false);
  assert.equal(canChangeOwnPassword(null), false);
  assert.equal(browserSessionSchema.safeParse({ ...session, roles: ["owner"] }).success, false);
});
test("provisioning normalizes email and does not accept an administrative role selector", () => {
  const data = createAdminSchema.parse({ full_name: " Admin Uji ", email: " ADMIN@EXAMPLE.TEST " });
  assert.equal(data.email, "admin@example.test");
  assert.equal(data.full_name, "Admin Uji");
  for (const extra of ["role", "roles", "status", "actor_id", "email_verified_at"]) {
    assert.equal(createAdminSchema.safeParse({ ...data, [extra]: "super_admin" }).success, false);
  }
});
test("invitation proof requires exactly three fields and is never serialized by SSR", async () => {
  const proof = adminInvitationFromFragment(`#invitation_id=${id}&email=ADMIN%40example.test&token=${token}`);
  assert.equal(proof?.email, "admin@example.test");
  for (const value of [
    "", `?invitation_id=${id}&email=admin@example.test&token=${token}`,
    `#invitation_id=${id}&email=admin@example.test&token=${token}&role=super_admin`,
    `#invitation_id=${id}&email=admin@example.test&token=${token}&token=${token}`,
    `#invitation_id=${id}&email=admin@example.test&token=short`,
  ]) assert.equal(adminInvitationFromFragment(value), null);
  adminInvitationIntent.set(proof);
  assert.equal(adminInvitationIntent.server(), null);
  const release = adminInvitationIntent.retain();
  release();
  const replay = adminInvitationIntent.retain();
  await Promise.resolve();
  assert.equal(adminInvitationIntent.get()?.invitation_id, id);
  replay();
  await Promise.resolve();
  assert.equal(adminInvitationIntent.get(), null);
});
test("invitation password uses existing minimum, byte limit, null and confirmation policy", () => {
  for (const password of ["short", "é".repeat(37), "Password-test-\0"]) {
    assert.equal(activationPasswordSchema.safeParse({ password, password_confirmation: password }).success, false);
  }
  assert.equal(activationPasswordSchema.safeParse({ password: "New-test-password-123", password_confirmation: "other" }).success, false);
  assert.equal(activationPasswordSchema.safeParse({ password: "New-test-password-123", password_confirmation: "New-test-password-123" }).success, true);
});

test("initial activation and Admin invitation intents never share proof or SSR state", async () => {
  const proof = adminInvitationFromFragment(`#invitation_id=${id}&email=initial%40example.test&token=${token}`);
  firstActivationIntent.set(proof);
  assert.equal(adminInvitationIntent.get(), null);
  assert.equal(firstActivationIntent.server(), null);
  const release = firstActivationIntent.retain();
  release();
  await Promise.resolve();
  assert.equal(firstActivationIntent.get(), null);
});

test("administrative password validation retains confirmation and byte limits and requires actor reauthentication", () => {
  const values = { current_password: "Actor-test-password-123", password: "New-admin-password-123", password_confirmation: "New-admin-password-123" };
  assert.equal(administrativePasswordSchema.safeParse(values).success, true);
  for (const invalid of [{ current_password: "" }, { password_confirmation: "other" }, { password: "short" }, { password: "a".repeat(73) }, { user_id: 1 }, { role: "super_admin" }]) {
    assert.equal(administrativePasswordSchema.safeParse({ ...values, ...invalid }).success, false);
  }
});

