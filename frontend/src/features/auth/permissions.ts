import type { BrowserSession } from "./types";

type RoleSession = Pick<BrowserSession, "roles"> | null | undefined;

export function canManageAdministration(session: RoleSession): boolean {
  return !!session?.roles.some(role => role === "admin" || role === "super_admin");
}

export function canProvisionAdmins(session: RoleSession): boolean {
  return !!session?.roles.includes("super_admin");
}

export function canChangeOwnPassword(session: RoleSession): boolean {
  return !!session?.roles.some(role => role === "member" || role === "admin" || role === "super_admin");
}

export const roleLabels: Record<BrowserSession["roles"][number], string> = {
  member: "Anggota", staff: "Petugas", admin: "Admin", super_admin: "Super Admin",
};

