import { z } from "zod";
import { api, initializeCsrf } from "@/lib/api";
import { apiOrigin } from "@/lib/config";
import { ApiError } from "@/lib/errors";
import { landingDraftSchema, landingMediaSchema, type LandingDocument } from "./schemas";

function parseResponse<T>(schema: z.ZodType<T>, value: unknown): T {
  const result = schema.safeParse(value);
  if (!result.success) throw new ApiError("Data konten belum dapat dibaca. Muat ulang untuk memeriksa hasilnya.", 502, "INVALID_RESPONSE");
  return result.data;
}
export async function getLandingDraft(signal?: AbortSignal, preview = false) {
  const response = await api.get(`/api/v1/admin/landing-page${preview ? "/preview" : ""}`, { signal });
  return parseResponse(landingDraftSchema, response.data?.data);
}
export async function saveLandingDraft(version: number, content: LandingDocument) {
  await initializeCsrf();
  const response = await api.put("/api/v1/admin/landing-page/draft", { version, content });
  return parseResponse(z.object({ version: z.number().int().nonnegative() }), response.data?.data);
}
export async function publishLandingDraft(version: number) {
  await initializeCsrf();
  const response = await api.post("/api/v1/admin/landing-page/publish", { version });
  return parseResponse(z.object({ version: z.number().int().nonnegative(), published_at: z.string().datetime({ offset: true }) }), response.data?.data);
}
export async function uploadLandingMedia(file: File) {
  await initializeCsrf();
  const body = new FormData();
  body.append("image", file);
  const response = await api.post("/api/v1/admin/landing-page/media", body);
  return parseResponse(landingMediaSchema, response.data?.data);
}
export async function deleteLandingMedia(id: string) {
  await initializeCsrf();
  await api.delete(`/api/v1/admin/landing-page/media/${encodeURIComponent(id)}`);
}
export async function getLandingMedia(page = 1, signal?: AbortSignal) {
  const response = await api.get("/api/v1/admin/landing-page/media", { params: { page }, signal });
  return parseResponse(z.object({ items: z.array(landingMediaSchema.extend({ state: z.enum(["ready", "deleting"]) })), page: z.number().int(), last_page: z.number().int() }), response.data?.data);
}
export function landingMediaUrl(id: string, privateMedia: boolean) {
  return `${apiOrigin()}/api/v1/${privateMedia ? "admin" : "public"}/landing-page/media/${encodeURIComponent(id)}`;
}
