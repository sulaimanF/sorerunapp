"use client";

import { useEffect, useRef, useState } from "react";
import Link from "next/link";
import { useForm } from "react-hook-form";
import { zodResolver } from "@hookform/resolvers/zod";
import { useQuery, useQueryClient } from "@tanstack/react-query";
import { useSession } from "@/providers/session-provider";
import { Alert, LoadingPanel } from "@/components/shared/feedback";
import { FormField } from "@/components/shared/form-field";
import { Button } from "@/components/ui/button";
import { Card } from "@/components/ui/card";
import { Input } from "@/components/ui/input";
import { normalizeApiError, type ApiError } from "@/lib/errors";
import { sessionKey } from "@/lib/query-client";
import type { BrowserSession } from "@/features/auth/types";
import { canProvisionAdmins } from "@/features/auth/permissions";
import { useCountdown } from "@/features/auth/hooks/use-countdown";
import { createAdminSchema, type CreateAdminValues, type AdminInvitationStatus } from "../schemas";
import { createAdmin, getAdminInvitation, resendAdminInvitation } from "../services";

export function CreateAdminScreen() {
  const { session } = useSession();
  if (!session || !canProvisionAdmins(session)) return null;
  return <CreateAdminForm key={session.user.id} actorId={session.user.id} />;
}

function CreateAdminForm({ actorId }: { actorId: number }) {
  const client = useQueryClient();
  const [problem, setProblem] = useState<ApiError | null>(null);
  const [created, setCreated] = useState<AdminInvitationStatus | null>(null);
  const [unknown, setUnknown] = useState(false);
  const [creating, setCreating] = useState(false);
  const resultHeading = useRef<HTMLHeadingElement>(null);
  const attempt = useRef<{ fingerprint: string; key: string; values: CreateAdminValues } | null>(null);
  const running = useRef(false);
  const countdown = useCountdown();
  const form = useForm<CreateAdminValues>({
    resolver: zodResolver(createAdminSchema), defaultValues: { full_name: "", email: "" },
  });
  useEffect(() => { if (created) resultHeading.current?.focus(); }, [created]);
  const sameActor = () => {
    const current = client.getQueryData<BrowserSession | null>(sessionKey);
    return current?.user.id === actorId && canProvisionAdmins(current);
  };
  async function submit(values: CreateAdminValues) {
    if (running.current || !sameActor()) return;
    running.current = true;
    setCreating(true);
    setProblem(null);
    const fingerprint = JSON.stringify(values);
    if (!attempt.current || attempt.current.fingerprint !== fingerprint) attempt.current = { fingerprint, key: crypto.randomUUID(), values };
    try {
      const data = await createAdmin(values, attempt.current.key);
      if (!sameActor()) return;
      setCreated(data);
      void client.invalidateQueries({ queryKey: ["administration", "accounts", actorId] });
      setUnknown(false);
      countdown.start(data.retry_after);
    } catch (error) {
      if (!sameActor()) return;
      const parsed = normalizeApiError(error);
      setProblem(parsed);
      setUnknown(parsed.status === 0 || parsed.status >= 500 || parsed.code === "IDEMPOTENCY_CONFLICT");
      if (parsed.status === 429) countdown.start(parsed.retryAfter || 60);
      let first: keyof CreateAdminValues | undefined;
      for (const field of ["full_name", "email"] as const) {
        if (parsed.fields[field]?.[0]) {
          form.setError(field, { message: parsed.fields[field][0] });
          first ??= field;
        }
      }
      if (first) {
        const field = first;
        requestAnimationFrame(() => { if (sameActor()) form.setFocus(field); });
      }
    } finally { running.current = false; setCreating(false); }
  }
  return <div className="stack-large">
    <header className="stack-small"><p className="eyebrow text-brand">Super Admin</p><h1 className="page-heading">Buat Akun Admin</h1>
      <p>Penerima mengonfirmasi email dan mengatur password sendiri melalui undangan 24 jam. Akun ini tidak membuat keanggotaan atau kartu QR.</p>
      <div className="flex flex-wrap gap-3"><Button variant="outline" asChild><Link href="/admin/accounts">Daftar Akun Admin</Link></Button></div></header>
    {created ? <Card className="stack-medium">
      <h2 ref={resultHeading} tabIndex={-1} className="text-heading-3">Akun Admin dibuat</h2>
      <Alert tone="success">Undangan aktivasi diterima untuk diproses. Status ini belum berarti email sudah terkirim.</Alert>
      <InvitationStatus key={created.user_id} initial={created} actorId={actorId} />
      <div className="flex flex-wrap items-center gap-3">
        <Link className="text-link" href={`/admin/accounts/${created.user_id}/invitation`}>Buka Halaman Status Undangan</Link>
        <Button variant="outline" onClick={() => { setCreated(null); setProblem(null); setUnknown(false); attempt.current = null; form.reset(); }}>Buat Admin Lain</Button>
      </div>
    </Card> : <Card>
      <form onSubmit={event => void form.handleSubmit(submit)(event)} noValidate aria-label="Form pembuatan Admin" className="stack-medium">
        {problem && <Alert>{problem.message}</Alert>}
        {unknown && <Alert tone="warning">Hasil permintaan belum pasti. Data dikunci; kirim ulang permintaan yang sama untuk memeriksa hasil tanpa membuat akun ganda. Jangan memuat ulang halaman sebelum hasil jelas.</Alert>}
        <fieldset disabled={creating || unknown} className="stack-medium">
          <legend className="sr-only">Identitas Admin baru</legend>
          <FormField name="admin-name" label="Nama lengkap" error={form.formState.errors.full_name?.message}>
            <Input id="admin-name" autoComplete="name" required maxLength={150} aria-invalid={!!form.formState.errors.full_name}
              aria-describedby={form.formState.errors.full_name ? "admin-name-error" : undefined} {...form.register("full_name")} />
          </FormField>
          <FormField name="admin-email" label="Email" error={form.formState.errors.email?.message}>
            <Input id="admin-email" type="email" autoComplete="email" autoCapitalize="none" spellCheck={false} required maxLength={254}
              aria-invalid={!!form.formState.errors.email} aria-describedby={form.formState.errors.email ? "admin-email-error" : undefined} {...form.register("email")} />
          </FormField>
          <p><strong>Role:</strong> Admin</p>
          <p className="supporting">Role ditetapkan server. Form ini tidak dapat membuat Super Admin dan tidak memakai password bawaan bersama.</p>
        </fieldset>
        <Button type={unknown ? "button" : "submit"} loading={creating} disabled={creating || countdown.remaining > 0} aria-busy={creating}
          onClick={unknown ? () => { if (attempt.current) void submit(attempt.current.values); } : undefined}>
          {creating ? "Membuat akun…" : countdown.remaining ? `Tunggu ${countdown.remaining} detik` : unknown ? "Periksa Permintaan yang Sama" : "Buat Akun Admin"}
        </Button>
      </form>
    </Card>}
  </div>;
}

export function AdminInvitationScreen({ userId }: { userId: number }) {
  const { session } = useSession();
  const query = useQuery({
    queryKey: ["administration", "invitation", session?.user.id, userId],
    queryFn: ({ signal }) => getAdminInvitation(userId, signal),
    enabled: !!session && canProvisionAdmins(session), meta: { requiresSession: true }, refetchOnWindowFocus: false,
  });
  if (!session || !canProvisionAdmins(session)) return null;
  if (query.isPending) return <LoadingPanel>Memuat status undangan…</LoadingPanel>;
  if (!query.data) return <Card className="stack-medium"><Alert>{normalizeApiError(query.error).message}</Alert><Button onClick={() => void query.refetch()}>Coba Lagi</Button></Card>;
  return <div className="stack-large"><h1 className="page-heading">Status Undangan Admin</h1>
    <Card><InvitationStatus key={`${session.user.id}:${userId}`} initial={query.data} actorId={session.user.id} showCreateAction /></Card>
    <Button variant="outline" asChild><Link href="/admin/accounts">Daftar Akun Admin</Link></Button>
  </div>;
}

function InvitationStatus({ initial, actorId, showCreateAction = false }: { initial: AdminInvitationStatus; actorId: number; showCreateAction?: boolean }) {
  const { session } = useSession();
  const client = useQueryClient();
  const countdown = useCountdown();
  const [sending, setSending] = useState(false);
  const [problem, setProblem] = useState<string | null>(null);
  const busy = useRef(false);
  const key = ["administration", "invitation", actorId, initial.user_id];
  const query = useQuery({
    queryKey: key, queryFn: ({ signal }) => getAdminInvitation(initial.user_id, signal),
    enabled: session?.user.id === actorId && canProvisionAdmins(session),
    initialData: initial, meta: { requiresSession: true }, refetchOnWindowFocus: false,
  });
  const status = query.data;
  const startCountdown = countdown.start;
  useEffect(() => {
    if (status.retry_after > 0) startCountdown(status.retry_after);
  }, [status, startCountdown]);
  const labels = { pending: "Menunggu aktivasi", active: "Sudah diaktifkan", expired: "Kedaluwarsa", revoked: "Dicabut", stale: "Tidak berlaku lagi" };
  async function resend() {
    if (busy.current) return;
    busy.current = true;
    setSending(true);
    setProblem(null);
    try {
      const data = await resendAdminInvitation(initial.user_id);
      if (client.getQueryData<BrowserSession | null>(sessionKey)?.user.id !== actorId) return;
      client.setQueryData(key, data);
      countdown.start(data.retry_after);
    } catch (error) {
      if (client.getQueryData<BrowserSession | null>(sessionKey)?.user.id !== actorId) return;
      const parsed = normalizeApiError(error);
      setProblem(parsed.message);
      if (parsed.status === 429) countdown.start(parsed.retryAfter || 60);
    } finally { busy.current = false; setSending(false); }
  }
  return <section aria-label="Status undangan" className="stack-medium">
    <dl className="profile-details">
      <div><dt>Nama</dt><dd>{status.full_name}</dd></div><div><dt>Email</dt><dd>{status.email}</dd></div>
      <div><dt>Role</dt><dd>Admin</dd></div><div><dt>Status aktivasi</dt><dd>{labels[status.status]}</dd></div>
      {status.expires_at && status.status !== "active" && <div><dt>Berlaku sampai</dt><dd>{new Date(status.expires_at).toLocaleString("id-ID")}</dd></div>}
    </dl>
    {(problem || query.error) && <Alert>{problem ?? normalizeApiError(query.error).message}</Alert>}
    <div className="flex flex-wrap gap-3">
      <Button type="button" variant="outline" loading={query.isFetching} disabled={query.isFetching || sending} onClick={() => void query.refetch()}>{query.isFetching ? "Memeriksa status…" : "Periksa Status Undangan"}</Button>
      {status.status !== "active" && <Button type="button" loading={sending} disabled={sending || countdown.remaining > 0} onClick={() => void resend()}>
        {sending ? "Memproses undangan…" : countdown.remaining ? `Tunggu ${countdown.remaining} detik` : "Kirim Ulang Undangan"}
      </Button>}
      {showCreateAction && <Button variant="outline" asChild><Link href="/admin/accounts/new">Buat Akun Admin</Link></Button>}
    </div>
  </section>;
}

