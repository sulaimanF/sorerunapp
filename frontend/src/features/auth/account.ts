import { z } from "zod";
import { api } from "@/lib/api";
import { ApiError } from "@/lib/errors";
import { emailChangeStatusSchema } from "./schemas";

const ownAccountSchema = z.object({
  full_name: z.string(), email: z.string().email(), email_verified: z.boolean(),
  email_change: emailChangeStatusSchema.nullable(),
});
export async function getOwnAccount(signal?: AbortSignal) {
  const response = await api.get("/api/v1/me/account", { signal });
  const parsed = ownAccountSchema.safeParse(response.data?.data);
  if (!parsed.success) throw new ApiError("Informasi akun belum dapat dibaca.", 502, "INVALID_RESPONSE");
  return parsed.data;
}

