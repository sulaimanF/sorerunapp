"use client";

import { useState } from "react";
import Link from "next/link";
import { useForm } from "react-hook-form";
import { zodResolver } from "@hookform/resolvers/zod";
import { Alert } from "@/components/shared/feedback";
import { FormField } from "@/components/shared/form-field";
import { Input } from "@/components/ui/input";
import { Button } from "@/components/ui/button";
import { normalizeApiError, type ApiError } from "@/lib/errors";
import { forgotPasswordSchema, type ForgotPasswordValues } from "../schemas";
import { requestPasswordReset } from "../services";
import { useCountdown } from "../hooks/use-countdown";

export function ForgotPasswordForm() {
  const [problem, setProblem] = useState<ApiError | null>(null);
  const [accepted, setAccepted] = useState(false);
  const countdown = useCountdown();
  const form = useForm<ForgotPasswordValues>({ resolver: zodResolver(forgotPasswordSchema), defaultValues: { email: "" } });
  async function submit(values: ForgotPasswordValues) {
    setProblem(null);
    setAccepted(false);
    try {
      countdown.start(await requestPasswordReset(values.email));
      setAccepted(true);
      form.reset();
    } catch (error) {
      const parsed = normalizeApiError(error);
      setProblem(parsed);
      if (parsed.status === 429) countdown.start(parsed.retryAfter || 60);
      if (parsed.fields.email?.[0]) form.setError("email", { message: parsed.fields.email[0] }, { shouldFocus: true });
    }
  }
  return (
    <div className="stack-large src-auth-root">
      <header><h2 className="page-heading">Lupa password?</h2><p className="muted">Masukkan email akun Anda untuk meminta tautan reset password.</p></header>
      {accepted && <Alert tone="info">Jika alamat email memenuhi syarat, tautan reset password akan diproses. Periksa kotak masuk dan folder spam.</Alert>}
      <form onSubmit={form.handleSubmit(submit)} noValidate aria-label="Form lupa password" className="stack-medium">
        {problem && <Alert>{problem.message}{problem.status === 419 && <p>Perlindungan sesi akan diperbarui saat Anda mencoba lagi.</p>}</Alert>}
        <FormField name="email" label="Email" error={form.formState.errors.email?.message}>
          <Input id="email" type="email" autoComplete="email" required autoCapitalize="none" spellCheck={false}
            aria-invalid={!!form.formState.errors.email} aria-describedby={form.formState.errors.email ? "email-error" : undefined}
            {...form.register("email")} />
        </FormField>
        <Button type="submit" disabled={form.formState.isSubmitting || countdown.remaining > 0}>
          {form.formState.isSubmitting ? "Memproses permintaan…" : countdown.remaining ? `Kirim ulang dalam ${countdown.remaining} detik` : "Kirim Tautan Reset"}
        </Button>
      </form>
      <nav className="src-auth-secondary-links" aria-label="Bantuan reset password"><Link href="/login" className="text-link">Kembali ke Login</Link></nav>
    </div>
  );
}
