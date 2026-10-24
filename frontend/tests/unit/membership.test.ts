import { test } from "node:test";
import assert from "node:assert/strict";
import jsQR from "jsqr";
import {
  createMembershipQr,
  QR_MARGIN,
} from "../../src/features/membership/qr";
import { safeReturnPath } from "../../src/features/auth/navigation";
import {
  intentFromFragment,
  verificationPath,
  verificationIntent,
} from "../../src/features/auth/verification-intent";
import {
  birthDateToIso,
  registrationSchema,
  loginSchema,
} from "../../src/features/auth/schemas";
import { validateApiOrigin } from "../../src/lib/config";
import { ApiError, normalizeApiError } from "../../src/lib/errors";
import {
  clearPrivateQueries,
  createQueryClient,
  sessionKey,
} from "../../src/lib/query-client";

const apiOrigin = "http://localhost:8000";
const signed = `${apiOrigin}/api/v1/auth/email-verification/42/${"a".repeat(40)}?expires=1999999999&v=1&signature=${"b".repeat(64)}`;
const valid = {
  full_name: "Anggota Uji",
  birth_date: "01/01/2015",
  phone: "081234567890",
  city: "Kota Uji",
  gender: "",
  email: "unit@example.test",
  password: "Test-only-password-123",
  password_confirmation: "Test-only-password-123",
  privacy_notice_accepted: true,
  terms_accepted: true,
};

test("QR encodes the actual credential and decodes with the four-module quiet zone", () => {
  const payload = `src-member:v1:${"c".repeat(43)}`;
  const qr = createMembershipQr(payload);
  const scale = 8;
  const side = qr.total * scale;
  const pixels = new Uint8ClampedArray(side * side * 4).fill(255);
  for (let row = 0; row < qr.modules.size; row++)
    for (let col = 0; col < qr.modules.size; col++) {
      if (!qr.modules.get(row, col)) continue;
      for (let y = 0; y < scale; y++)
        for (let x = 0; x < scale; x++) {
          const offset =
            (((row + QR_MARGIN) * scale + y) * side +
              (col + QR_MARGIN) * scale +
              x) *
            4;
          pixels[offset] = pixels[offset + 1] = pixels[offset + 2] = 0;
        }
    }
  assert.equal(qr.total - qr.modules.size, 8);
  assert.equal(jsQR(pixels, side, side)?.data, payload);
  assert.throws(() => createMembershipQr("https://untrusted.example"));
});
test("return paths reject external, encoded and nested redirects", () => {
  for (const bad of [
    "https://untrusted.example",
    "//untrusted.example",
    "/\\untrusted.example",
    "/%2F%2Funtrusted.example",
    "/login?returnTo=//untrusted.example",
    "javascript:alert(1)",
    "\n/kartu",
  ])
    assert.equal(safeReturnPath(bad), "/beranda");
  assert.equal(safeReturnPath("/verify-email"), "/verify-email");
  assert.equal(safeReturnPath("/kartu"), "/kartu");
});
test("verification parser preserves the signed query and accepts only configured API origin", () => {
  const path = signed.slice(apiOrigin.length);
  assert.equal(verificationPath(signed, apiOrigin), path);
  assert.equal(
    intentFromFragment(
      "#verification=" + encodeURIComponent(signed),
      apiOrigin,
    ),
    path,
  );
  for (const bad of [
    signed.replace("localhost:8000", "localhost:8000.evil.example"),
    signed.replace("http:", "https:"),
    signed + "&unexpected=1",
    signed + "&v=2",
    signed + "#bad",
    signed.replace("signature=", "signature%5B%5D="),
    signed.replace("/42/", "/0/"),
    "//untrusted.example",
    signed.replace("http://", "http://name:secret@"),
  ])
    assert.equal(verificationPath(bad, apiOrigin), null);
  assert.equal(intentFromFragment("#verification=%invalid", apiOrigin), null);
});
test("verification intent is a memory-only store and clears explicitly", () => {
  let events = 0;
  const unsubscribe = verificationIntent.subscribe(() => events++);
  verificationIntent.set("/synthetic-path");
  assert.equal(verificationIntent.get(), "/synthetic-path");
  assert.equal(verificationIntent.server(), null);
  verificationIntent.set(null);
  unsubscribe();
  assert.equal(events, 2);
  assert.equal(verificationIntent.get(), null);
});
test("birth date accepts minors and leap dates without imposing an age minimum", () => {
  assert.equal(birthDateToIso("29/02/2000"), "2000-02-29");
  assert.equal(birthDateToIso("29/02/1900"), null);
  assert.equal(birthDateToIso("31/04/2020"), null);
  assert.equal(birthDateToIso("2000-01-01"), null);
  assert.equal(registrationSchema.safeParse(valid).success, true);
  assert.equal(
    registrationSchema.safeParse({ ...valid, birth_date: "01/01/2999" })
      .success,
    false,
  );
});
test("form validation preserves password spaces and email aliases, and requires both policies", () => {
  const password = "  Test-only-password-123  ";
  const parsed = registrationSchema.parse({
    ...valid,
    email: "  Person.Name+SRC@Example.test ",
    password,
    password_confirmation: password,
  });
  assert.equal(parsed.email, "person.name+src@example.test");
  assert.equal(parsed.password, password);
  assert.equal(
    registrationSchema.safeParse({ ...valid, privacy_notice_accepted: false })
      .success,
    false,
  );
  assert.equal(
    registrationSchema.safeParse({ ...valid, terms_accepted: false }).success,
    false,
  );
  assert.equal(
    registrationSchema.safeParse({
      ...valid,
      password_confirmation: "different",
    }).success,
    false,
  );
  const long = "é".repeat(37);
  assert.equal(
    registrationSchema.safeParse({
      ...valid,
      password: long,
      password_confirmation: long,
    }).success,
    false,
  );
  assert.equal(
    loginSchema.parse({ email: "unit@example.test", password: " x " }).password,
    " x ",
  );
});
test("API configuration rejects credentials, paths and non-HTTP origins", () => {
  assert.equal(validateApiOrigin("http://localhost:8000/"), apiOrigin);
  for (const bad of [
    undefined,
    "https://example.test/api",
    "https://name:secret@example.test",
    "javascript:alert(1)",
    "https://example.test?bad=1",
  ])
    assert.throws(() => validateApiOrigin(bad), ApiError);
});
test("unknown exceptions never expose transport configuration or secret text", () => {
  const safe = normalizeApiError(new Error("synthetic-private-password-url"));
  assert.equal(safe.code, "SERVICE_UNAVAILABLE");
  assert.equal(safe.message.includes("synthetic-private"), false);
});
test("logout/account switch removes private cached data", async () => {
  const client = createQueryClient();
  client.setQueryData(sessionKey, { user: { id: 1 } });
  client.setQueryData(["membership", "card", 1], { qr_payload: "synthetic" });
  await clearPrivateQueries(client);
  assert.equal(client.getQueryData(sessionKey), null);
  assert.equal(client.getQueryData(["membership", "card", 1]), undefined);
  client.clear();
});
test("late private query responses cannot refill cache after account switch", async () => {
  const client = createQueryClient();
  let resolve: (value: unknown) => void = () => undefined;
  const deferred = new Promise((done) => {
    resolve = done;
  });
  const request = client
    .fetchQuery({
      queryKey: ["membership", "card", 1],
      queryFn: ({ signal }) => {
        signal.throwIfAborted();
        return deferred;
      },
    })
    .catch(() => undefined);
  await clearPrivateQueries(client);
  resolve({ qr_payload: "synthetic-late" });
  await request;
  assert.equal(client.getQueryData(["membership", "card", 1]), undefined);
  client.clear();
});
