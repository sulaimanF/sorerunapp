import { z } from "zod";
import { api, initializeCsrf } from "@/lib/api";
import { ApiError } from "@/lib/errors";
import { apiOrigin, registrationPolicies } from "@/lib/config";
import { browserSessionSchema, type LoginCredentials } from "./types";
import { birthDateToIso, type RegistrationValues } from "./schemas";
import { verificationPath } from "./verification-intent";

export async function currentMember(signal?: AbortSignal) {
  try {
    const response = await api.get("/api/v1/auth/me", { signal });
    const parsed = browserSessionSchema.safeParse(response.data?.data);
    if (!parsed.success)
      throw new ApiError(
        "Data akun belum dapat dibaca.",
        502,
        "INVALID_RESPONSE",
      );
    return parsed.data;
  } catch (error) {
    if (error instanceof ApiError && error.status === 401) return null;
    throw error;
  }
}
export async function loginMember(credentials: LoginCredentials) {
  await initializeCsrf();
  const response = await api.post("/api/v1/auth/login", credentials);
  const parsed = browserSessionSchema.safeParse(response.data?.data);
  if (!parsed.success)
    throw new ApiError(
      "Status login belum dapat dibaca. Periksa sesi Anda.",
      502,
      "INVALID_RESPONSE",
    );
  return parsed.data;
}
export async function logoutMember() {
  await initializeCsrf();
  await api.post("/api/v1/auth/logout", {});
}
export async function registerMember(values: RegistrationValues) {
  const birthDate = birthDateToIso(values.birth_date);
  if (!birthDate)
    throw new ApiError("Periksa tanggal lahir Anda.", 422, "VALIDATION_FAILED");
  await initializeCsrf();
  const response = await api.post("/api/v1/auth/register", {
    ...values,
    birth_date: birthDate,
    gender: values.gender || undefined,
    ...registrationPolicies(),
  });
  if (
    response.status !== 201 ||
    response.data?.data?.result !== "registration_received"
  )
    throw new ApiError(
      "Hasil registrasi belum terkonfirmasi. Periksa akun sebelum mencoba kembali.",
      502,
      "INVALID_RESPONSE",
    );
}
export async function resendVerification() {
  await initializeCsrf();
  const response = await api.post("/api/v1/auth/email-verification/resend", {});
  if (response.status !== 202)
    throw new ApiError(
      "Permintaan pengiriman ulang belum terkonfirmasi.",
      502,
      "INVALID_RESPONSE",
    );
}
const verificationResultSchema = z.object({
  result: z.enum([
    "membership_activated",
    "already_active",
    "email_verified_profile_incomplete",
  ]),
  membership: z.object({
    status: z.enum(["active", "pending_verification"]),
    member_number: z.string().nullable(),
  }),
});
export async function verifyMemberEmail(path: string) {
  if (verificationPath(apiOrigin() + path, apiOrigin()) !== path)
    throw new ApiError(
      "Tautan verifikasi tidak valid.",
      403,
      "INVALID_VERIFICATION_LINK",
    );
  let response;
  try {
    response = await api.get(path);
  } catch (error) {
    if (
      error instanceof ApiError &&
      error.code === "INVALID_VERIFICATION_LINK" &&
      Number(new URLSearchParams(path.split("?")[1]).get("expires")) * 1000 <
        Date.now()
    ) {
      throw new ApiError(
        "Tautan ini sudah kedaluwarsa. Minta email verifikasi baru.",
        403,
        "VERIFICATION_LINK_EXPIRED",
      );
    }
    throw error;
  }
  const parsed = verificationResultSchema.safeParse(response.data?.data);
  if (!parsed.success)
    throw new ApiError(
      "Hasil verifikasi belum terkonfirmasi. Periksa status akun.",
      502,
      "INVALID_RESPONSE",
    );
  return parsed.data;
}
