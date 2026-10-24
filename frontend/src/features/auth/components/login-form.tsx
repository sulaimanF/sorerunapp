"use client";
import { useState } from "react";
import Link from "next/link";
import { useRouter, useSearchParams } from "next/navigation";
import { useForm } from "react-hook-form";
import { zodResolver } from "@hookform/resolvers/zod";
import { ArrowRight } from "lucide-react";
import { Input } from "@/components/ui/input";
import { Button } from "@/components/ui/button";
import { FormField, PasswordInput } from "@/components/shared/form-field";
import { Alert } from "@/components/shared/feedback";
import { useSession } from "@/providers/session-provider";
import { normalizeApiError, type ApiError } from "@/lib/errors";
import { loginSchema, type LoginValues } from "../schemas";
import { safeReturnPath } from "../navigation";
import { useCountdown } from "../hooks/use-countdown";

export function LoginForm() {
  const router = useRouter();
  const params = useSearchParams();
  const auth = useSession();
  const [problem, setProblem] = useState<ApiError | null>(null);
  const countdown = useCountdown();
  const form = useForm<LoginValues>({
    resolver: zodResolver(loginSchema),
    defaultValues: { email: "", password: "" },
  });
  const returnPath = safeReturnPath(params.get("returnTo"));
  async function submit(values: LoginValues) {
    setProblem(null);
    try {
      await auth.login(values);
      form.reset();
      router.replace(returnPath);
    } catch (error) {
      const parsed = normalizeApiError(error);
      setProblem(parsed);
      if (parsed.status === 429) countdown.start(parsed.retryAfter || 60);
      for (const field of ["email", "password"] as const)
        if (parsed.fields[field]?.[0])
          form.setError(field, { message: parsed.fields[field][0] });
    }
  }
  return (
    <div className="stack-large">
      <header>
        <p className="eyebrow text-primary">Selamat datang kembali</p>
        <h2 className="page-heading">Masuk ke sorerunapp</h2>
        <p className="muted">Kartu dan keanggotaan Anda, dalam satu tempat.</p>
      </header>
      {auth.session && (
        <Alert tone="info">
          Anda sedang masuk sebagai {auth.session.user.full_name}.{" "}
          <Link href={returnPath} className="text-link">
            Lanjutkan ke akun Anda
          </Link>{" "}
          atau masuk dengan akun lain.
        </Alert>
      )}
      <form
        onSubmit={form.handleSubmit(submit)}
        noValidate
        className="stack-medium"
        aria-label="Form login"
      >
        {problem && (
          <Alert>
            {problem.message}
            {problem.status === 419 && (
              <p>Permintaan berikutnya akan memulai ulang perlindungan sesi.</p>
            )}
          </Alert>
        )}
        <FormField
          name="email"
          label="Email"
          error={form.formState.errors.email?.message}
        >
          <Input
            id="email"
            type="email"
            autoComplete="email"
            required
            autoCapitalize="none"
            spellCheck={false}
            aria-invalid={!!form.formState.errors.email}
            aria-describedby={
              form.formState.errors.email ? "email-error" : undefined
            }
            {...form.register("email")}
          />
        </FormField>
        <FormField
          name="password"
          label="Password"
          error={form.formState.errors.password?.message}
        >
          <PasswordInput
            id="password"
            autoComplete="current-password"
            required
            aria-invalid={!!form.formState.errors.password}
            aria-describedby={
              form.formState.errors.password ? "password-error" : undefined
            }
            {...form.register("password")}
          />
        </FormField>
        <Button
          type="submit"
          disabled={form.formState.isSubmitting || countdown.remaining > 0}
        >
          {form.formState.isSubmitting
            ? "Memeriksa akun…"
            : countdown.remaining
              ? `Tunggu ${countdown.remaining} detik`
              : "Masuk"}
          <ArrowRight size={18} aria-hidden />
        </Button>
      </form>
      <p className="supporting center">
        Belum menjadi anggota?{" "}
        <Link href="/register" className="text-link">
          Daftar Member
        </Link>
      </p>
    </div>
  );
}
