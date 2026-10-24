"use client";
import { useState } from "react";
import Link from "next/link";
import { useRouter } from "next/navigation";
import { useForm } from "react-hook-form";
import { zodResolver } from "@hookform/resolvers/zod";
import { ArrowRight, CalendarDays } from "lucide-react";
import { FormField, PasswordInput } from "@/components/shared/form-field";
import { Alert } from "@/components/shared/feedback";
import { Input } from "@/components/ui/input";
import { Button } from "@/components/ui/button";
import { normalizeApiError, type ApiError } from "@/lib/errors";
import { registrationSchema, type RegistrationValues } from "../schemas";
import { registerMember } from "../services";
import { useCountdown } from "../hooks/use-countdown";

const fields = [
  "full_name",
  "birth_date",
  "phone",
  "city",
  "gender",
  "email",
  "password",
  "password_confirmation",
  "privacy_notice_accepted",
  "terms_accepted",
] as const;
export function RegisterForm() {
  const router = useRouter();
  const [problem, setProblem] = useState<ApiError | null>(null);
  const countdown = useCountdown();
  const form = useForm<RegistrationValues>({
    resolver: zodResolver(registrationSchema),
    defaultValues: {
      full_name: "",
      birth_date: "",
      phone: "",
      city: "",
      gender: "",
      email: "",
      password: "",
      password_confirmation: "",
      privacy_notice_accepted: false,
      terms_accepted: false,
    },
  });
  const errors = form.formState.errors;
  const described = (name: (typeof fields)[number]) =>
    errors[name] ? `${name}-error` : undefined;
  async function submit(values: RegistrationValues) {
    setProblem(null);
    try {
      await registerMember(values);
      form.reset();
      router.replace("/registration-received");
    } catch (error) {
      const parsed = normalizeApiError(error);
      setProblem(parsed);
      if (parsed.status === 429) countdown.start(parsed.retryAfter || 60);
      let first: (typeof fields)[number] | undefined;
      for (const field of fields)
        if (parsed.fields[field]?.[0]) {
          form.setError(field, { message: parsed.fields[field][0] });
          first ??= field;
        }
      if (first) form.setFocus(first);
    }
  }
  return (
    <div className="stack-large">
      <header>
        <p className="eyebrow text-brand">Satu langkah untuk bergabung</p>
        <h2 className="page-heading">Daftar Member</h2>
        <p className="muted">
          Keanggotaan gratis. Daftar sekali untuk menjadi bagian dari SRC.
        </p>
      </header>
      <form
        onSubmit={form.handleSubmit(submit)}
        noValidate
        className="stack-large"
        aria-label="Form registrasi anggota"
      >
        <p className="supporting">
          Tanda <span className="required-marker">*</span> menunjukkan data
          wajib.
        </p>
        {problem && (
          <Alert>
            {problem.message}
            {problem.status === 0 && (
              <p>
                Permintaan mungkin sudah diterima. Periksa email atau coba masuk
                sebelum mendaftar kembali.
              </p>
            )}
          </Alert>
        )}
        <fieldset className="stack-medium">
          <legend>Identitas Anda</legend>
          <FormField
            name="full_name"
            label="Nama lengkap"
            error={errors.full_name?.message}
          >
            <Input
              id="full_name"
              autoComplete="name"
              required
              maxLength={150}
              aria-invalid={!!errors.full_name}
              aria-describedby={described("full_name")}
              {...form.register("full_name")}
            />
          </FormField>
          <FormField
            name="birth_date"
            label="Tanggal lahir"
            help="Format: dd/mm/yyyy. Contoh: 18/10/2000."
            error={errors.birth_date?.message}
          >
            <div className="date-field">
              <Input
                id="birth_date"
                placeholder="dd/mm/yyyy"
                autoComplete="bday"
                inputMode="numeric"
                required
                aria-invalid={!!errors.birth_date}
                aria-describedby={["birth_date-help", described("birth_date")]
                  .filter(Boolean)
                  .join(" ")}
                {...form.register("birth_date")}
              />
              <span className="calendar-picker">
                <CalendarDays size={20} aria-hidden />
                <input
                  type="date"
                  aria-label="Pilih tanggal lahir melalui kalender"
                  onChange={(event) => {
                    const [year, month, day] = event.target.value.split("-");
                    if (year && month && day)
                      form.setValue("birth_date", `${day}/${month}/${year}`, {
                        shouldValidate: true,
                        shouldDirty: true,
                      });
                  }}
                />
              </span>
            </div>
          </FormField>
          <FormField
            name="phone"
            label="Nomor telepon"
            error={errors.phone?.message}
          >
            <Input
              id="phone"
              type="tel"
              inputMode="tel"
              autoComplete="tel"
              required
              placeholder="081234567890"
              aria-invalid={!!errors.phone}
              aria-describedby={described("phone")}
              {...form.register("phone")}
            />
          </FormField>
          <FormField
            name="city"
            label="Domisili / kota"
            error={errors.city?.message}
          >
            <Input
              id="city"
              autoComplete="address-level2"
              required
              maxLength={100}
              aria-invalid={!!errors.city}
              aria-describedby={described("city")}
              {...form.register("city")}
            />
          </FormField>
          <FormField
            name="gender"
            label="Jenis kelamin"
            optional
            error={errors.gender?.message}
          >
            <Input
              id="gender"
              maxLength={32}
              aria-invalid={!!errors.gender}
              aria-describedby={described("gender")}
              {...form.register("gender")}
            />
          </FormField>
        </fieldset>
        <fieldset className="stack-medium">
          <legend>Akun Anda</legend>
          <FormField name="email" label="Email" error={errors.email?.message}>
            <Input
              id="email"
              type="email"
              autoComplete="email"
              autoCapitalize="none"
              spellCheck={false}
              required
              aria-invalid={!!errors.email}
              aria-describedby={described("email")}
              {...form.register("email")}
            />
          </FormField>
          <FormField
            name="password"
            label="Password"
            help="Gunakan minimal 12 karakter."
            error={errors.password?.message}
          >
            <PasswordInput
              id="password"
              autoComplete="new-password"
              required
              aria-invalid={!!errors.password}
              aria-describedby={["password-help", described("password")]
                .filter(Boolean)
                .join(" ")}
              {...form.register("password")}
            />
          </FormField>
          <FormField
            name="password_confirmation"
            label="Konfirmasi password"
            error={errors.password_confirmation?.message}
          >
            <PasswordInput
              id="password_confirmation"
              autoComplete="new-password"
              required
              aria-invalid={!!errors.password_confirmation}
              aria-describedby={described("password_confirmation")}
              {...form.register("password_confirmation")}
            />
          </FormField>
        </fieldset>
        <fieldset className="stack-small">
          <legend>Persetujuan</legend>
          <div>
            <div className="checkbox-row">
              <input
                id="privacy_notice_accepted"
                type="checkbox"
                required
                aria-invalid={!!errors.privacy_notice_accepted}
                aria-describedby={described("privacy_notice_accepted")}
                {...form.register("privacy_notice_accepted")}
              />
              <label htmlFor="privacy_notice_accepted">
                Saya telah membaca dan menyetujui{" "}
                <Link
                  href="/privacy"
                  target="_blank"
                  rel="noopener noreferrer"
                  className="text-link"
                >
                  pemberitahuan privasi
                </Link>
                .
              </label>
            </div>
            {errors.privacy_notice_accepted && (
              <p
                id="privacy_notice_accepted-error"
                role="alert"
                className="field-error"
              >
                {errors.privacy_notice_accepted.message}
              </p>
            )}
          </div>
          <div>
            <div className="checkbox-row">
              <input
                id="terms_accepted"
                type="checkbox"
                required
                aria-invalid={!!errors.terms_accepted}
                aria-describedby={described("terms_accepted")}
                {...form.register("terms_accepted")}
              />
              <label htmlFor="terms_accepted">
                Saya menyetujui{" "}
                <Link
                  href="/terms"
                  target="_blank"
                  rel="noopener noreferrer"
                  className="text-link"
                >
                  ketentuan komunitas
                </Link>
                .
              </label>
            </div>
            {errors.terms_accepted && (
              <p id="terms_accepted-error" role="alert" className="field-error">
                {errors.terms_accepted.message}
              </p>
            )}
          </div>
        </fieldset>
        <Button
          type="submit"
          disabled={form.formState.isSubmitting || countdown.remaining > 0}
        >
          {form.formState.isSubmitting
            ? "Memproses pendaftaran…"
            : countdown.remaining
              ? `Tunggu ${countdown.remaining} detik`
              : "Daftar Member"}
          <ArrowRight size={18} aria-hidden />
        </Button>
      </form>
      <p className="supporting center">
        Sudah punya akun?{" "}
        <Link href="/login" className="text-link">
          Masuk
        </Link>
      </p>
    </div>
  );
}
