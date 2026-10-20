import axios from "axios";
import { z } from "zod";

const errorEnvelope = z.object({
  error: z.object({
    code: z.string().regex(/^[A-Z][A-Z0-9_]{0,79}$/),
    message: z.string().max(600),
    fields: z.record(z.string(), z.array(z.string().max(600))).default({}),
  }),
  meta: z.object({ request_id: z.string().uuid() }),
});

export class ApiError extends Error {
  constructor(
    message: string,
    readonly status: number,
    readonly code: string,
    readonly fields: Record<string, string[]> = {},
    readonly retryAfter = 0,
    readonly requestId?: string,
  ) {
    super(message);
    this.name = "ApiError";
  }
}

export function normalizeApiError(error: unknown): ApiError {
  if (error instanceof ApiError) return error;
  if (axios.isAxiosError(error)) {
    const status = error.response?.status ?? 0;
    const parsed = errorEnvelope.safeParse(error.response?.data);
    const retry = Number(error.response?.headers["retry-after"]);
    const retryAfter = Number.isFinite(retry)
      ? Math.min(3600, Math.max(0, Math.ceil(retry)))
      : 0;
    if (parsed.success) {
      return new ApiError(
        parsed.data.error.message,
        status,
        parsed.data.error.code,
        parsed.data.error.fields,
        retryAfter,
        parsed.data.meta.request_id,
      );
    }
    if (axios.isCancel(error))
      return new ApiError("Permintaan dibatalkan.", 0, "REQUEST_CANCELLED");
    if (status === 419)
      return new ApiError(
        "Sesi permintaan berubah. Silakan coba kembali.",
        419,
        "CSRF_MISMATCH",
      );
    return new ApiError(
      status === 0
        ? "Koneksi belum terkonfirmasi. Periksa koneksi sebelum mencoba kembali."
        : "Permintaan belum dapat diproses. Silakan coba kembali.",
      status,
      status === 0 ? "NETWORK_ERROR" : "SERVICE_UNAVAILABLE",
      {},
      retryAfter,
    );
  }
  // Do not propagate Axios config, passwords, signed URLs, or arbitrary exception text.
  return new ApiError(
    "Layanan belum dapat digunakan. Silakan coba kembali.",
    0,
    "SERVICE_UNAVAILABLE",
  );
}
