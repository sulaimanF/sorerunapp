"use client";
import { useState } from "react";
import Link from "next/link";
import { useForm } from "react-hook-form";
import { zodResolver } from "@hookform/resolvers/zod";
import { z } from "zod";
import { Input } from "@/components/ui/input";
import { Button } from "@/components/ui/button";
import { Alert } from "@/components/shared/feedback";
import { FormField } from "@/components/shared/form-field";
import { normalizeApiError, type ApiError } from "@/lib/errors";
import { loginSchema } from "../schemas";
import { resendVerification } from "../services";
import { useCountdown } from "../hooks/use-countdown";
import { AUTH_NAVIGATION } from "../navigation";

const schema = loginSchema.pick({ email: true });
type Values = z.infer<typeof schema>;
export function ResendVerificationForm() {
  const [problem, setProblem] = useState<ApiError | null>(null);
  const [accepted, setAccepted] = useState(false);
  const countdown = useCountdown();
  const form = useForm<Values>({ resolver: zodResolver(schema), defaultValues: { email: "" } });
  async function submit(values: Values) {
    setProblem(null);
    setAccepted(false);
    try {
      const retryAfter = await resendVerification(values.email);
      setAccepted(true);
      countdown.start(retryAfter);
      form.reset();
    } catch (error) {
      const parsed = normalizeApiError(error);
      setProblem(parsed);
      if (parsed.status === 429) countdown.start(parsed.retryAfter || 60);
      if (parsed.fields.email?.[0]) form.setError("email", { message: parsed.fields.email[0] });
    }
  }
  return (
    <div className="stack-large src-auth-root">
      <header>
        <h2 className="page-heading">Kirim ulang email verifikasi</h2>
        <p className="muted">Masukkan email yang Anda gunakan saat registrasi.</p>
      </header>
      {accepted && <Alert tone="info">Jika alamat email memenuhi syarat, email verifikasi akan diproses. Periksa kotak masuk dan spam.</Alert>}
      {problem && <Alert>{problem.message}</Alert>}
      <form onSubmit={form.handleSubmit(submit)} noValidate className="stack-medium" aria-label="Form kirim ulang verifikasi">
        <FormField name="email" label="Email" error={form.formState.errors.email?.message}>
          <Input id="email" type="email" autoComplete="email" autoCapitalize="none" spellCheck={false} required
            aria-invalid={!!form.formState.errors.email}
            aria-describedby={form.formState.errors.email ? "email-error" : undefined}
            {...form.register("email")} />
        </FormField>
        <Button type="submit" disabled={form.formState.isSubmitting || countdown.remaining > 0}>
          {form.formState.isSubmitting ? "Memproses…" : countdown.remaining ? `Kirim ulang dalam ${countdown.remaining} detik` : "Kirim Ulang Email"}
        </Button>
      </form>
      <nav className="src-auth-secondary-links" aria-label="Navigasi kembali">
        <Link className="text-link" href={AUTH_NAVIGATION.login}>Kembali ke Login</Link>
      </nav>
    </div>
  );
}
