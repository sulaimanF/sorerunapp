import { z } from "zod";
import { api, initializeCsrf } from "@/lib/api";
import { ApiError } from "@/lib/errors";
import { loginSchema } from "./schemas";
import type { EmailChangeProof } from "./email-change-intent";
export type { EmailChangeStatus } from "./schemas";

export const emailChangeSchema = loginSchema;
export type EmailChangeValues = z.infer<typeof emailChangeSchema>;

export async function requestEmailChange(values: EmailChangeValues, signal?: AbortSignal) {
  await initializeCsrf();
  const response = await api.post("/api/v1/me/email-change", values, { signal });
  if (response.status !== 202 || response.data?.data?.result !== "email_change_requested")
    throw new ApiError("Permintaan belum terkonfirmasi. Periksa status email pada Profil sebelum mencoba lagi.", 502, "INVALID_RESPONSE");
  const retry = Number(response.headers["retry-after"]);
  return Number.isFinite(retry) ? Math.min(3600, Math.max(1, Math.ceil(retry))) : 60;
}

export async function confirmEmailChange(proof: EmailChangeProof, signal?: AbortSignal) {
  await initializeCsrf();
  const response = await api.post("/api/v1/me/email-change/verify", proof, { signal });
  if (response.status !== 200 || response.data?.data?.result !== "email_changed")
    throw new ApiError("Hasil konfirmasi belum diketahui. Periksa sesi atau coba masuk menggunakan email baru.", 502, "INVALID_RESPONSE");
}
