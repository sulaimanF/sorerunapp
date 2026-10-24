import { z } from "zod";

export function birthDateToIso(value: string): string | null {
  const match = /^(\d{2})\/(\d{2})\/(\d{4})$/.exec(value);
  if (!match) return null;
  const [, day, month, year] = match;
  const date = new Date(0);
  date.setUTCFullYear(Number(year), Number(month) - 1, Number(day));
  date.setUTCHours(0, 0, 0, 0);
  if (
    Number(year) < 1 ||
    date.getUTCFullYear() !== Number(year) ||
    date.getUTCMonth() !== Number(month) - 1 ||
    date.getUTCDate() !== Number(day)
  )
    return null;
  return `${year}-${month}-${day}`;
}

const email = z
  .string()
  .trim()
  .min(1, "Email wajib diisi.")
  .max(254)
  .email("Masukkan email yang valid.")
  .toLowerCase();
export const loginSchema = z.object({
  email,
  password: z.string().min(1, "Password wajib diisi."),
});
export type LoginValues = z.infer<typeof loginSchema>;
export const registrationSchema = z
  .object({
    full_name: z
      .string()
      .trim()
      .min(2, "Nama minimal 2 karakter.")
      .max(150, "Nama maksimal 150 karakter."),
    birth_date: z.string().refine((value) => {
      const iso = birthDateToIso(value);
      return iso !== null && iso <= new Date().toISOString().slice(0, 10);
    }, "Masukkan tanggal lahir yang valid dan tidak di masa depan."),
    phone: z
      .string()
      .trim()
      .min(1, "Nomor telepon wajib diisi.")
      .max(32)
      .refine((value) => {
        const clean = value.replace(/[\s().-]/g, "");
        return /^(?:\+|00)?[0-9]{7,15}$/.test(clean);
      }, "Masukkan nomor telepon yang valid."),
    city: z
      .string()
      .trim()
      .min(1, "Domisili wajib diisi.")
      .max(100, "Domisili maksimal 100 karakter."),
    gender: z.string().trim().max(32, "Maksimal 32 karakter."),
    email,
    password: z
      .string()
      .refine(
        (value) => [...value].length >= 12,
        "Gunakan minimal 12 karakter.",
      )
      .refine(
        (value) => new TextEncoder().encode(value).length <= 72,
        "Password maksimal 72 byte.",
      )
      .refine(
        (value) => !value.includes("\0"),
        "Password mengandung karakter yang tidak didukung.",
      ),
    password_confirmation: z.string().min(1, "Ulangi password Anda."),
    privacy_notice_accepted: z
      .boolean()
      .refine(
        Boolean,
        "Anda perlu membaca dan menyetujui pemberitahuan privasi.",
      ),
    terms_accepted: z
      .boolean()
      .refine(
        Boolean,
        "Anda perlu membaca dan menyetujui ketentuan komunitas.",
      ),
  })
  .refine((value) => value.password === value.password_confirmation, {
    path: ["password_confirmation"],
    message: "Konfirmasi password belum sesuai.",
  });
export type RegistrationValues = z.infer<typeof registrationSchema>;
