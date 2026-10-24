import { z } from "zod";
import { birthDateToIso, registrationSchema } from "../auth/schemas";

export const profileSchema = z.object({
  full_name: registrationSchema.shape.full_name,
  birth_date: registrationSchema.shape.birth_date,
  phone: registrationSchema.shape.phone,
  city: registrationSchema.shape.city,
  gender: registrationSchema.shape.gender,
});
export type ProfileValues = z.infer<typeof profileSchema>;

export const memberProfileSchema = z.object({
  full_name: z.string(),
  birth_date: z.string().regex(/^\d{4}-\d{2}-\d{2}$/).nullable(),
  phone: z.string(),
  city: z.string(),
  gender: z.string().nullable(),
  profile_complete: z.boolean(),
  updated_at: z.string().datetime({ offset: true }).nullable(),
});
export type MemberProfile = z.infer<typeof memberProfileSchema>;

export function profileValues(profile: MemberProfile): ProfileValues {
  return {
    full_name: profile.full_name,
    birth_date: profile.birth_date ? profile.birth_date.split("-").reverse().join("/") : "",
    phone: profile.phone,
    city: profile.city,
    gender: profile.gender ?? "",
  };
}

export function profilePayload(values: ProfileValues) {
  const birthDate = birthDateToIso(values.birth_date);
  if (!birthDate) throw new Error("Invalid birth date.");
  return {
    full_name: values.full_name,
    birth_date: birthDate,
    phone: values.phone,
    city: values.city,
    gender: values.gender || null,
  };
}
