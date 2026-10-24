import { test } from "node:test";
import assert from "node:assert/strict";
import { profileSchema, profilePayload, profileValues, memberProfileSchema } from "../../src/features/members/schemas";

const input = { full_name: "Anggota Profil Uji", birth_date: "29/02/2012", phone: "081234567890", city: "Kota Uji", gender: "" };

test("profile edit accepts a minor and leap day, and never submits account or QR fields", () => {
  const parsed = profileSchema.parse({ ...input, email: "ignored@example.test", role: "admin" });
  const payload = profilePayload(parsed);
  assert.equal(payload.birth_date, "2012-02-29");
  assert.equal(payload.gender, null);
  assert.deepEqual(Object.keys(payload), ["full_name", "birth_date", "phone", "city", "gender"]);
  assert.equal(profileSchema.safeParse({ ...input, birth_date: "29/02/2011" }).success, false);
  assert.equal(profileSchema.safeParse({ ...input, birth_date: "01/01/2999" }).success, false);
});
test("profile required fields cannot be blank while optional gender can be cleared", () => {
  for (const field of ["full_name", "birth_date", "phone", "city"])
    assert.equal(profileSchema.safeParse({ ...input, [field]: "   " }).success, false);
  assert.equal(profileSchema.safeParse({ ...input, gender: "" }).success, true);
  assert.equal(profileValues({ full_name: input.full_name, birth_date: "2012-02-29", phone: input.phone, city: input.city, gender: null, profile_complete: true, updated_at: null }).birth_date, input.birth_date);
});
test("profile response parser keeps only the private profile contract", () => {
  const parsed = memberProfileSchema.parse({ full_name: input.full_name, birth_date: "2012-02-29", phone: input.phone, city: input.city, gender: null, profile_complete: true, updated_at: "2026-10-24T00:00:00Z", photo_path: "private/path", auth_version: 1 });
  assert.equal("photo_path" in parsed, false);
  assert.equal("auth_version" in parsed, false);
  assert.equal(memberProfileSchema.safeParse({ full_name: input.full_name }).success, false);
});
