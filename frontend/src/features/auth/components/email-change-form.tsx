"use client";

import { useEffect, useRef, useState } from "react";
import { useQueryClient } from "@tanstack/react-query";
import { useForm } from "react-hook-form";
import { zodResolver } from "@hookform/resolvers/zod";
import { Alert } from "@/components/shared/feedback";
import { FormField, PasswordInput } from "@/components/shared/form-field";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { clearPrivateQueries, sessionKey } from "@/lib/query-client";
import { isSessionRejection, normalizeApiError, type ApiError } from "@/lib/errors";
import type { BrowserSession } from "../types";
import { emailChangeSchema, requestEmailChange, type EmailChangeValues, type EmailChangeStatus } from "../email-change";
import { useCountdown } from "../hooks/use-countdown";
import { EmailChangeStatusDialog } from "./email-change-status-dialog";

export function EmailChangeForm({ status, userId }: { status: EmailChangeStatus | null; userId: number }) {
  const client = useQueryClient();
  const countdown = useCountdown();
  const [editing, setEditing] = useState(false);
  const [problem, setProblem] = useState<ApiError | null>(null);
  const [accepted, setAccepted] = useState(false);
  const [now, setNow] = useState(0);
  const pending = useRef<AbortController | null>(null);
  const form = useForm<EmailChangeValues>({ resolver: zodResolver(emailChangeSchema), defaultValues: { email: "", password: "" } });
  const profileKey = ["members", "profile", userId];
  const sameAccount = () => client.getQueryData<BrowserSession | null>(sessionKey)?.user.id === userId;
  useEffect(() => () => pending.current?.abort(), []);
  const startCountdown = countdown.start;
  useEffect(() => {
    if (status?.retry_after) startCountdown(status.retry_after);
  }, [status, startCountdown]);
  useEffect(() => {
    const timer = window.setInterval(() => setNow(Date.now()), 1000);
    return () => window.clearInterval(timer);
  }, []);
  const expired = !!status?.pending && (status.pending.status === "expired" || (now > 0 && Date.parse(status.pending.expires_at) <= now));
  function toggleEditor() {
    if (editing) {
      form.reset({ email: "", password: "" });
      setProblem(null);
      setEditing(false);
    } else {
      setEditing(true);
      requestAnimationFrame(() => form.setFocus("email"));
    }
  }
  async function submit(values: EmailChangeValues) {
    const controller = new AbortController();
    pending.current = controller;
    setProblem(null);
    setAccepted(false);
    try {
      const retryAfter = await requestEmailChange(values, controller.signal);
      if (controller.signal.aborted || !sameAccount()) return;
      form.reset({ email: values.email, password: "" });
      setAccepted(true);
      countdown.start(retryAfter);
      await client.invalidateQueries({ queryKey: profileKey });
    } catch (error) {
      if (controller.signal.aborted || !sameAccount()) return;
      const parsed = normalizeApiError(error);
      setProblem(parsed);
      if (isSessionRejection(parsed)) await clearPrivateQueries(client, userId);
      if (parsed.status === 429) countdown.start(parsed.retryAfter || 60);
      let first: keyof EmailChangeValues | undefined;
      for (const field of ["email", "password"] as const) {
        if (parsed.fields[field]?.[0]) {
          form.setError(field, { message: parsed.fields[field][0] });
          first ??= field;
        }
      }
      if (first) {
        const field = first;
        requestAnimationFrame(() => {
          if (sameAccount()) form.setFocus(field);
        });
      }
    }
  }
  return (
    <section className="stack-medium src-auth-email-change" aria-labelledby="email-change-heading">
      <h3 id="email-change-heading" className="text-heading-3">Ubah email</h3>
      {status?.pending && <Alert tone={expired ? "warning" : "info"}>
        <p><strong>Email {expired ? "kedaluwarsa" : "pending"}:</strong> {status.pending.email}</p>
        <p>{expired ? "Tautan sudah kedaluwarsa. Ajukan perubahan lagi untuk mendapatkan tautan baru." : "Konfirmasi alamat baru melalui email. Tautan berlaku 60 menit sejak permintaan."}</p>
      </Alert>}
      <Button type="button" variant={editing ? "outline" : "default"} aria-expanded={editing} aria-controls="email-change-fields"
        disabled={form.formState.isSubmitting} onClick={toggleEditor}>
        {editing ? "Batal Ubah Email" : "Ubah Email"}
      </Button>
      {accepted && <Alert tone="success">Permintaan perubahan email diterima untuk diproses. Periksa kotak masuk alamat baru dan folder spam. Ini belum berarti email telah terkirim.</Alert>}
      <div id="email-change-fields" hidden={!editing}>
        {editing && <div className="stack-medium">
          <p className="supporting">Email aktif lama tetap digunakan untuk login sampai alamat baru dikonfirmasi. Konfirmasi perubahan akan mengakhiri seluruh sesi akun; Anda perlu masuk kembali menggunakan email baru.</p>
          <p className="supporting">Permintaan baru yang diterima akan menggantikan tautan perubahan email sebelumnya.</p>
          <form onSubmit={event => void form.handleSubmit(submit)(event)} noValidate className="stack-medium" aria-label="Form perubahan email">
            {problem && <Alert>{problem.message}
              {(problem.status === 0 || problem.status >= 500) && <p>Hasil permintaan belum diketahui. Periksa status pada Profil sebelum mengirim ulang.</p>}
              {problem.status === 419 && <p>Perlindungan sesi akan diperbarui pada percobaan berikutnya.</p>}
            </Alert>}
            <fieldset disabled={form.formState.isSubmitting} className="stack-medium">
              <legend className="sr-only">Email baru dan konfirmasi password</legend>
              <FormField name="change-email" label="Email baru" error={form.formState.errors.email?.message}>
                <Input id="change-email" type="email" autoComplete="email" autoCapitalize="none" spellCheck={false} required
                  aria-invalid={!!form.formState.errors.email} aria-describedby={form.formState.errors.email ? "change-email-error" : undefined} {...form.register("email")} />
              </FormField>
              <FormField name="change-password" label="Password akun saat ini" error={form.formState.errors.password?.message}>
                <PasswordInput id="change-password" autoComplete="current-password" required
                  aria-invalid={!!form.formState.errors.password} aria-describedby={form.formState.errors.password ? "change-password-error" : undefined} {...form.register("password")} />
              </FormField>
            </fieldset>
            <Button type="submit" disabled={form.formState.isSubmitting || countdown.remaining > 0}>
              {form.formState.isSubmitting ? "Memproses permintaan…" : countdown.remaining ? `Tunggu ${countdown.remaining} detik` : "Kirim Verifikasi Email Baru"}
            </Button>
          </form>
        </div>}
      </div>
      <EmailChangeStatusDialog userId={userId} disabled={form.formState.isSubmitting} />
    </section>
  );
}
