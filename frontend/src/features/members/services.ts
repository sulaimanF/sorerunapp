import { api, initializeCsrf } from "@/lib/api";
import { ApiError } from "@/lib/errors";
import { memberProfileSchema, profilePayload, type ProfileValues } from "./schemas";

function readProfile(data: unknown) {
  const result = memberProfileSchema.safeParse(data);
  if (!result.success)
    throw new ApiError("Data profil belum dapat dibaca. Silakan coba lagi.", 502, "INVALID_RESPONSE");
  return result.data;
}

export async function getMemberProfile(signal?: AbortSignal) {
  const response = await api.get("/api/v1/me/profile", { signal });
  return readProfile(response.data?.data);
}

export async function updateMemberProfile(values: ProfileValues, signal?: AbortSignal) {
  await initializeCsrf();
  const response = await api.patch("/api/v1/me/profile", profilePayload(values), { signal });
  return readProfile(response.data?.data);
}
