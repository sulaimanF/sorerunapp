import axios from "axios";

const baseURL = process.env.NEXT_PUBLIC_API_BASE_URL;

if (!baseURL) {
  throw new Error("NEXT_PUBLIC_API_BASE_URL belum dikonfigurasi.");
}

export const api = axios.create({
  baseURL,
  timeout: 15_000,
  withCredentials: true,
  withXSRFToken: true,
  headers: {
    Accept: "application/json",
  },
});

export async function initializeCsrf(): Promise<void> {
  await api.get("/sanctum/csrf-cookie");
}