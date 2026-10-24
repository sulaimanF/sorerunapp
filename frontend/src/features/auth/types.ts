import { z } from "zod";

export const membershipStatusSchema = z.enum([
  "pending_verification",
  "active",
  "suspended",
  "ended",
]);
export const browserSessionSchema = z.object({
  user: z.object({
    id: z.number().int().positive(),
    full_name: z.string(),
    email: z.string(),
    email_verified: z.boolean(),
  }),
  roles: z.array(z.enum(["member", "staff", "admin"])),
  profile_complete: z.boolean(),
  membership: z
    .object({
      status: membershipStatusSchema,
      member_number: z.string().nullable(),
      joined_at: z.string().nullable(),
    })
    .nullable(),
});
export type BrowserSession = z.infer<typeof browserSessionSchema>;
export type MembershipStatus = z.infer<typeof membershipStatusSchema>;
export type LoginCredentials = { email: string; password: string };
