import { api, initializeCsrf } from "@/lib/api";
import { ApiError } from "@/lib/errors";
import { adminAccountListSchema, adminInvitationSchema, type AdministrativePasswordValues, type AdminInvitationProof, type CreateAdminValues } from "./schemas";
import type { ResetPasswordValues } from "@/features/auth/schemas";

function invitationResponse(data: unknown) {
  const parsed = adminInvitationSchema.safeParse(data);
  if (!parsed.success) throw new ApiError("Status undangan belum terkonfirmasi. Periksa status sebelum mencoba lagi.", 502, "INVALID_RESPONSE");
  return parsed.data;
}

export async function createAdmin(values: CreateAdminValues, requestKey: string) {
  await initializeCsrf();
  const response = await api.post("/api/v1/admin/accounts", { ...values, request_key: requestKey });
  return invitationResponse(response.data?.data);
}
export async function getAdminInvitation(userId: number, signal?: AbortSignal) {
  const response = await api.get(`/api/v1/admin/accounts/${userId}/invitation`, { signal });
  return invitationResponse(response.data?.data);
}
export async function getAdminAccounts(page: number, signal?: AbortSignal) {
  const response = await api.get("/api/v1/admin/accounts", { params: { page }, signal });
  const parsed = adminAccountListSchema.safeParse(response.data?.data);
  if (!parsed.success) throw new ApiError("Daftar akun Admin belum dapat dimuat. Silakan coba lagi.", 502, "INVALID_RESPONSE");
  return parsed.data;
}
export async function deleteAdminAccount(userId: number) {
  await initializeCsrf();
  const response = await api.delete(`/api/v1/admin/accounts/${userId}`, { data: {} });
  if (response.status !== 204) throw new ApiError("Penghapusan belum terkonfirmasi. Periksa daftar sebelum mencoba kembali.", 502, "INVALID_RESPONSE");
}
export async function changeAdministrativePassword(values: AdministrativePasswordValues, targetId?: number) {
  await initializeCsrf();
  const response = await api.post(targetId === undefined ? "/api/v1/me/password" : `/api/v1/admin/accounts/${targetId}/password`, values);
  if (response.status !== 204) throw new ApiError("Perubahan password belum terkonfirmasi. Jangan mengirim ulang otomatis.", 502, "INVALID_RESPONSE");
}
export async function resendAdminInvitation(userId: number) {
  await initializeCsrf();
  const response = await api.post(`/api/v1/admin/accounts/${userId}/invitation/resend`, {});
  return invitationResponse(response.data?.data);
}
export async function acceptAdminInvitation(proof: AdminInvitationProof, values: ResetPasswordValues) {
  return acceptAccountActivation(proof, values, false);
}
export async function acceptFirstAccountActivation(proof: AdminInvitationProof, values: ResetPasswordValues) {
  return acceptAccountActivation(proof, values, true);
}
async function acceptAccountActivation(proof: AdminInvitationProof, values: ResetPasswordValues, first: boolean) {
  await initializeCsrf();
  const response = await api.post(first ? "/api/v1/auth/first-account-activation/accept" : "/api/v1/auth/admin-invitations/accept", { ...proof, ...values });
  if (response.status !== 200 || response.data?.data?.result !== (first ? "first_account_activated" : "admin_activated"))
    throw new ApiError("Aktivasi belum terkonfirmasi. Coba login atau hubungi Super Admin sebelum meminta undangan baru.", 502, "INVALID_RESPONSE");
}

