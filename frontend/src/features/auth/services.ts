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
  if (!parsed.data.user.email_verified)
    throw new ApiError("Verifikasi email Anda sebelum masuk.", 403, "EMAIL_NOT_VERIFIED");
  return parsed.data;
}
export async function logoutMember() {
  await initializeCsrf();
  const response = await api.post("/api/v1/auth/logout", {});
  if (response.status !== 204)
    throw new ApiError(
      "Status keluar belum terkonfirmasi. Periksa sesi Anda.",
      502,
      "INVALID_RESPONSE",
    );
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
export async function resendVerification(email: string) {
  await initializeCsrf();
  const response = await api.post("/api/v1/auth/email-verification/resend", { email });
  if (response.status !== 202 || response.data?.data?.result !== "verification_requested")
    throw new ApiError(
      "Permintaan pengiriman ulang belum terkonfirmasi.",
      502,
      "INVALID_RESPONSE",
    );
  const retryAfter = Number(response.headers["retry-after"]);
  return Number.isFinite(retryAfter) ? Math.min(3600, Math.max(1, Math.ceil(retryAfter))) : 60;
}
const verificationResultSchema = z.object({
  result: z.enum([
    "email_verified",
    "already_verified",
    "email_verified_profile_incomplete",
  ]),
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
    await initializeCsrf();
    response = await api.post(path, {});
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
