"use client";

import { useEffect, useRef, useState, useSyncExternalStore } from "react";
import Link from "next/link";
import { useForm } from "react-hook-form";
import { zodResolver } from "@hookform/resolvers/zod";
import { useSession } from "@/providers/session-provider";
import { Alert, LoadingPanel } from "@/components/shared/feedback";
import { FormField, PasswordInput } from "@/components/shared/form-field";
import { Button } from "@/components/ui/button";
import { normalizeApiError, type ApiError } from "@/lib/errors";
import type { ResetPasswordValues } from "@/features/auth/schemas";
import { useCountdown } from "@/features/auth/hooks/use-countdown";
import { activationPasswordSchema } from "../schemas";
import { adminInvitationFromFragment, adminInvitationIntent, firstActivationIntent } from "../invitation-intent";
import { acceptAdminInvitation, acceptFirstAccountActivation } from "../services";

export function AcceptAdminInvitationForm({ firstAccount = false }: { firstAccount?: boolean } = {}) {
  const intent = firstAccount ? firstActivationIntent : adminInvitationIntent;
  const route = firstAccount ? "/activate-first-account" : "/accept-admin-invitation";
  const proof = useSyncExternalStore(intent.subscribe, intent.get, intent.server);
  const ready = useSyncExternalStore(intent.subscribe, intent.ready, intent.serverReady);
  const auth = useSession();
  const [problem, setProblem] = useState<ApiError | null>(null);
  const [saved, setSaved] = useState(false);
  const heading = useRef<HTMLHeadingElement>(null);
  const countdown = useCountdown();
  const running = useRef(false);
  const form = useForm<ResetPasswordValues>({
    resolver: zodResolver(activationPasswordSchema), defaultValues: { password: "", password_confirmation: "" },
  });
  const reset = form.reset;
  useEffect(() => {
    const release = intent.retain();
    intent.set(window.location.hash ? adminInvitationFromFragment(window.location.hash) : intent.get());
    if (window.location.hash || window.location.search) window.history.replaceState(window.history.state, "", route);
    function receive(event: HashChangeEvent) {
      intent.set(adminInvitationFromFragment(new URL(event.newURL).hash));
      window.history.replaceState(window.history.state, "", route);
      reset();
      setSaved(false);
      setProblem(null);
    }
    window.addEventListener("hashchange", receive);
    return () => { window.removeEventListener("hashchange", receive); release(); };
  }, [reset, intent, route]);
  useEffect(() => { if (saved) heading.current?.focus(); }, [saved]);
  async function submit(values: ResetPasswordValues) {
    if (!proof || running.current || auth.session) return;
    running.current = true;
    setProblem(null);
    try {
      await (firstAccount ? acceptFirstAccountActivation : acceptAdminInvitation)(proof, values);
      reset();
      intent.set(null);
      setSaved(true);
    } catch (error) {
      const parsed = normalizeApiError(error);
      setProblem(parsed);
      if (parsed.status === 429) countdown.start(parsed.retryAfter || 60);
      if (["ADMIN_INVITATION_INVALID", "FIRST_ACTIVATION_INVALID"].includes(parsed.code)) intent.set(null);
      for (const field of ["password", "password_confirmation"] as const) {
        if (parsed.fields[field]?.[0]) form.setError(field, { message: parsed.fields[field][0] });
      }
      if (parsed.fields.password || parsed.fields.password_confirmation) {
        const field = parsed.fields.password ? "password" : "password_confirmation";
        requestAnimationFrame(() => form.setFocus(field));
      }
    } finally { running.current = false; }
  }
  if (!ready || auth.loading) return <LoadingPanel>Membaca undangan…</LoadingPanel>;
  if (saved) return <div className="stack-large src-auth-root">
    <h2 ref={heading} tabIndex={-1} className="page-heading">{firstAccount ? "Akun operasional berhasil diaktifkan" : "Akun Admin berhasil diaktifkan"}</h2>
    <Alert tone="success">Email telah dikonfirmasi dan password tersimpan. {firstAccount ? "Aktivasi belum memberikan role administratif. Beri tahu operator agar bootstrap dapat dijalankan secara terpisah." : "Silakan masuk ke akun Anda."}</Alert>
    <Button asChild><Link href="/login">Masuk ke Akun</Link></Button>
  </div>;
  return <div className="stack-large src-auth-root">
    <header><h2 className="page-heading">{firstAccount ? "Aktifkan Akun Operasional" : "Aktifkan Akun Admin"}</h2><p className="muted">Konfirmasikan kepemilikan email dan buat password Anda sendiri. Undangan berlaku 24 jam sejak diterbitkan.</p>{firstAccount && <p className="muted">Aktivasi ini tidak memberikan role Admin atau Super Admin dan tidak menerbitkan membership atau QR.</p>}</header>
    {auth.session ? <Alert tone="warning">Ada akun yang sedang login. Keluar melalui menu akun, lalu buka ulang tautan undangan. Identitas sesi tidak akan diganti.<p><Link href="/beranda" className="text-link">Buka Akun Aktif</Link></p></Alert>
      : auth.error ? <Alert>{auth.error.message}<Button variant="outline" onClick={() => void auth.refresh().catch(() => undefined)}>Periksa Sesi Lagi</Button></Alert>
      : !proof ? <Alert tone="warning">Undangan tidak tersedia, tidak valid, kedaluwarsa, atau sudah digunakan. Hubungi {firstAccount ? "operator" : "Super Admin"} untuk meminta undangan baru.</Alert>
      : <form onSubmit={event => void form.handleSubmit(submit)(event)} noValidate className="stack-medium" aria-label={firstAccount ? "Form aktivasi akun operasional" : "Form aktivasi Admin"}>
        {problem && <Alert>{problem.message}{(problem.status === 0 || problem.status >= 500) && <p>Hasil aktivasi belum diketahui. Coba login dengan password baru sebelum meminta undangan lain.</p>}</Alert>}
        <fieldset disabled={form.formState.isSubmitting} className="stack-medium">
          <legend className="sr-only">{firstAccount ? "Password akun operasional" : "Password akun Admin"}</legend>
          <FormField name="admin-password" label="Password baru" help="Gunakan minimal 12 karakter." error={form.formState.errors.password?.message}>
            <PasswordInput id="admin-password" autoComplete="new-password" required aria-invalid={!!form.formState.errors.password}
              aria-describedby={`admin-password-help${form.formState.errors.password ? " admin-password-error" : ""}`} {...form.register("password")} />
          </FormField>
          <FormField name="admin-password-confirmation" label="Konfirmasi password" error={form.formState.errors.password_confirmation?.message}>
            <PasswordInput id="admin-password-confirmation" autoComplete="new-password" required aria-invalid={!!form.formState.errors.password_confirmation}
              aria-describedby={form.formState.errors.password_confirmation ? "admin-password-confirmation-error" : undefined} {...form.register("password_confirmation")} />
          </FormField>
        </fieldset>
        <Button type="submit" loading={form.formState.isSubmitting} disabled={form.formState.isSubmitting || countdown.remaining > 0}>
          {form.formState.isSubmitting ? "Mengaktifkan akun…" : countdown.remaining ? `Tunggu ${countdown.remaining} detik` : firstAccount ? "Aktifkan Akun Operasional" : "Aktifkan Akun Admin"}
        </Button>
      </form>}
    <Link href="/login" className="text-link">Kembali ke Login</Link>
  </div>;
}

