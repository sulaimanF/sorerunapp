"use client";

import { useEffect, useRef, useState, useSyncExternalStore } from "react";
import Link from "next/link";
import { useForm } from "react-hook-form";
import { zodResolver } from "@hookform/resolvers/zod";
import { Alert, LoadingPanel } from "@/components/shared/feedback";
import { FormField, PasswordInput } from "@/components/shared/form-field";
import { Button } from "@/components/ui/button";
import { normalizeApiError, type ApiError } from "@/lib/errors";
import { useSession } from "@/providers/session-provider";
import { resetPasswordSchema, type ResetPasswordValues } from "../schemas";
import { passwordResetFromFragment, passwordResetIntent } from "../password-reset-intent";
import { resetMemberPassword } from "../services";
import { useCountdown } from "../hooks/use-countdown";

export function ResetPasswordForm() {
  const intent = useSyncExternalStore(passwordResetIntent.subscribe, passwordResetIntent.get, passwordResetIntent.server);
  const ready = useSyncExternalStore(passwordResetIntent.subscribe, passwordResetIntent.ready, passwordResetIntent.serverReady);
  const [problem, setProblem] = useState<ApiError | null>(null);
  const [saved, setSaved] = useState(false);
  const successHeading = useRef<HTMLHeadingElement>(null);
  const auth = useSession();
  const countdown = useCountdown();
  const form = useForm<ResetPasswordValues>({ resolver: zodResolver(resetPasswordSchema), defaultValues: { password: "", password_confirmation: "" } });
  const resetForm = form.reset;
  useEffect(() => {
    const release = passwordResetIntent.retain();
    passwordResetIntent.set(window.location.hash ? passwordResetFromFragment(window.location.hash) : passwordResetIntent.get());
    if (window.location.hash || window.location.search) window.history.replaceState(window.history.state, "", "/reset-password");
    function receiveLink(event: HashChangeEvent) {
      // The initial effect may already have stripped the address bar before this event runs.
      passwordResetIntent.set(passwordResetFromFragment(new URL(event.newURL).hash));
      window.history.replaceState(window.history.state, "", "/reset-password");
      resetForm();
      setSaved(false);
      setProblem(null);
    }
    window.addEventListener("hashchange", receiveLink);
    return () => {
      window.removeEventListener("hashchange", receiveLink);
      release();
    };
  }, [resetForm]);
  useEffect(() => { if (saved) successHeading.current?.focus(); }, [saved]);

  async function submit(values: ResetPasswordValues) {
    if (!intent) return;
    setProblem(null);
    try {
      await resetMemberPassword(intent, values);
      form.reset();
      passwordResetIntent.set(null);
      setSaved(true);
      // Recheck the browser's own identity; the reset proof may belong to a different account.
      void auth.refresh().catch(() => undefined);
    } catch (error) {
      const parsed = normalizeApiError(error);
      setProblem(parsed);
      if (parsed.status === 429) countdown.start(parsed.retryAfter || 60);
      if (parsed.code === "PASSWORD_RESET_INVALID" || parsed.fields.token || parsed.fields.email) {
        passwordResetIntent.set(null);
      }
      let first: keyof ResetPasswordValues | undefined;
      for (const field of ["password", "password_confirmation"] as const) {
        if (parsed.fields[field]?.[0]) {
          form.setError(field, { message: parsed.fields[field][0] });
          first ??= field;
        }
      }
      if (first) {
        const field = first;
        // Wait until the submitting fieldset is enabled, as in the profile editor.
        requestAnimationFrame(() => {
          if (passwordResetIntent.get() === intent) form.setFocus(field);
        });
      }
    }
  }
  if (!ready) return <LoadingPanel>Membaca tautan reset…</LoadingPanel>;
  if (saved) return (
    <div className="stack-large src-auth-root">
      <h2 ref={successHeading} tabIndex={-1} className="page-heading">Password berhasil diperbarui</h2>
      <Alert tone="success">Semua sesi akun yang direset telah diakhiri. Silakan masuk menggunakan password baru.</Alert>
      <Button asChild><Link href="/login">Masuk ke Akun</Link></Button>
    </div>
  );
  return (
    <div className="stack-large src-auth-root">
      <header><h2 className="page-heading">Reset password</h2><p className="muted">Buat password baru untuk akun Anda.</p></header>
      {!intent ? <Alert tone="warning">Tautan reset tidak tersedia, tidak valid, kedaluwarsa, atau sudah digunakan. Buka tautan dari email atau minta tautan reset baru.</Alert> : (
        <form onSubmit={form.handleSubmit(submit)} noValidate aria-label="Form reset password" className="stack-medium">
          {problem && <Alert>{problem.message}
            {(problem.status === 0 || problem.status >= 500) && <p>Hasil reset belum terkonfirmasi. Coba masuk dengan password baru atau minta tautan reset baru.</p>}
            {problem.status === 419 && <p>Perlindungan sesi akan diperbarui saat Anda mencoba lagi.</p>}
          </Alert>}
          <fieldset disabled={form.formState.isSubmitting} className="stack-medium">
            <legend className="sr-only">Password baru</legend>
            <FormField name="password" label="Password baru" help="Gunakan minimal 12 karakter." error={form.formState.errors.password?.message}>
              <PasswordInput id="password" required autoComplete="new-password" aria-invalid={!!form.formState.errors.password}
                aria-describedby={`password-help${form.formState.errors.password ? " password-error" : ""}`} {...form.register("password")} />
            </FormField>
            <FormField name="password_confirmation" label="Konfirmasi password baru" error={form.formState.errors.password_confirmation?.message}>
              <PasswordInput id="password_confirmation" required autoComplete="new-password" aria-invalid={!!form.formState.errors.password_confirmation}
                aria-describedby={form.formState.errors.password_confirmation ? "password_confirmation-error" : undefined} {...form.register("password_confirmation")} />
            </FormField>
          </fieldset>
          <Button type="submit" disabled={form.formState.isSubmitting || countdown.remaining > 0}>
            {form.formState.isSubmitting ? "Menyimpan password…" : countdown.remaining ? `Tunggu ${countdown.remaining} detik` : "Simpan Password Baru"}
          </Button>
        </form>
      )}
      <nav className="src-auth-secondary-links" aria-label="Bantuan reset password">
        <Link href="/forgot-password" className="text-link">Minta Tautan Reset Baru</Link>
        <Link href="/login" className="text-link">Kembali ke Login</Link>
      </nav>
    </div>
  );
}
