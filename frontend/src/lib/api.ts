import axios from "axios";
import { apiOrigin } from "./config";
import { normalizeApiError } from "./errors";

export const api = axios.create({
  timeout: 20_000,
  withCredentials: true,
  withXSRFToken: true,
  headers: { Accept: "application/json" },
});
api.interceptors.request.use((request) => {
  request.baseURL = apiOrigin();
  return request;
});
api.interceptors.response.use(
  (response) => response,
  (error: unknown) => Promise.reject(normalizeApiError(error)),
);

export async function initializeCsrf(): Promise<void> {
  await api.get("/sanctum/csrf-cookie");
}
