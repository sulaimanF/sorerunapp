"use client";
import { useEffect, useState } from "react";
import Link from "next/link";
import { useRouter } from "next/navigation";
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
import { AUTH_NAVIGATION } from "../navigation";
import { useCountdown } from "../hooks/use-countdown";

export function LoginForm() {
  const router = useRouter();
  const auth = useSession();
  const [problem, setProblem] = useState<ApiError | null>(null);
  const countdown = useCountdown();
  const form = useForm<LoginValues>({
    resolver: zodResolver(loginSchema),
    defaultValues: { email: "", password: "" },
  });
  useEffect(() => {
    // Old bookmarks must not preserve a destination or alter the post-login flow.
    if (window.location.search || window.location.hash) {
      window.history.replaceState(window.history.state, "", AUTH_NAVIGATION.login);
    }
  }, []);
  async function submit(values: LoginValues) {
    setProblem(null);
    try {
      await auth.login(values);
      form.reset();
      router.replace(AUTH_NAVIGATION.afterLogin);
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
    <div className="stack-large src-auth-root">
      <header>
        <p className="eyebrow">Selamat datang kembali</p>
        <h2 className="page-heading">Masuk ke sorerunapp</h2>
        <p className="muted">Kartu dan keanggotaan Anda, dalam satu tempat.</p>
      </header>
      {auth.session && (
        <Alert tone="info">
          Anda sedang masuk sebagai {auth.session.user.full_name}.{" "}
          <Link href={AUTH_NAVIGATION.afterLogin} className="text-link">
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
            {problem.code === "EMAIL_NOT_VERIFIED" && (
              <p><Link href="/resend-verification" className="text-link">Kirim Ulang Email</Link></p>
            )}
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
        <Link href="/forgot-password" className="text-link src-auth-forgot-link">Lupa password?</Link>
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
      <footer className="src-auth-login-footer">
        <p className="supporting src-auth-register-line">
          <span>Belum menjadi anggota?</span>
          <Link href="/register" className="text-link">Daftar Member</Link>
        </p>
        <Link href="/resend-verification" className="text-link supporting">
          Belum menerima email verifikasi?
        </Link>
      </footer>
    </div>
  );
}
