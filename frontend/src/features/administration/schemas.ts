import { z } from "zod";
import { resetPasswordSchema } from "@/features/auth/schemas";

export const createAdminSchema = z.object({
  full_name: z.string().trim().min(2, "Nama minimal 2 karakter.").max(150, "Nama maksimal 150 karakter."),
  email: z.string().trim().toLowerCase().email("Masukkan alamat email yang valid.").max(254),
}).strict();
export type CreateAdminValues = z.infer<typeof createAdminSchema>;

export const adminInvitationSchema = z.object({
  user_id: z.number().int().positive(), full_name: z.string(), email: z.string().email(),
  role: z.literal("admin"), status: z.enum(["pending", "active", "expired", "revoked", "stale"]),
  expires_at: z.string().datetime({ offset: true }).nullable(), retry_after: z.number().int().nonnegative(),
  activated_at: z.string().datetime({ offset: true }).nullable(),
});
export type AdminInvitationStatus = z.infer<typeof adminInvitationSchema>;
export const adminAccountListSchema = z.object({
  items: z.array(z.object({
    user_id: z.number().int().positive(), full_name: z.string(), email: z.string().email(),
    account_status: z.enum(["enabled", "blocked"]), email_verified: z.boolean(), has_invitation: z.boolean(), can_delete: z.boolean(), can_change_password: z.boolean(),
  })),
  pagination: z.object({
    current_page: z.number().int().positive(), last_page: z.number().int().positive(),
    per_page: z.literal(20), total: z.number().int().nonnegative(),
  }),
});
export const invitationProofSchema = z.object({
  invitation_id: z.string().uuid(), email: z.string().trim().toLowerCase().email().max(254),
  token: z.string().regex(/^[a-f0-9]{64}$/),
}).strict();
export type AdminInvitationProof = z.infer<typeof invitationProofSchema>;
export const activationPasswordSchema = resetPasswordSchema;
export const administrativePasswordSchema = resetPasswordSchema.safeExtend({ current_password: z.string().min(1, "Password akun Anda saat ini wajib diisi.").max(1024) }).strict();
export type AdministrativePasswordValues = z.infer<typeof administrativePasswordSchema>;

