import { z } from "zod";
import { api } from "@/lib/api";
import { ApiError } from "@/lib/errors";
const cardSchema = z.object({
  community_name: z.string().min(1),
  full_name: z.string().min(1),
  member_number: z.string().min(1),
  qr_payload: z.string().regex(/^src-member:v1:[A-Za-z0-9_-]{43}$/),
  issued_at: z.string().datetime({ offset: true }),
});
export async function getMembershipCard(signal?: AbortSignal) {
  const response = await api.get("/api/v1/me/membership/card", { signal });
  const parsed = cardSchema.safeParse(response.data?.data);
  if (!parsed.success)
    throw new ApiError(
      "Kartu belum dapat ditampilkan. Hubungi pengurus.",
      502,
      "INVALID_RESPONSE",
    );
  return parsed.data;
}
