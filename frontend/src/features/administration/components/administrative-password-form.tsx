"use client";

import { useRef, useState } from "react";
import { useRouter } from "next/navigation";
import { useForm } from "react-hook-form";
import { zodResolver } from "@hookform/resolvers/zod";
import { useQueryClient } from "@tanstack/react-query";
import * as Dialog from "@radix-ui/react-dialog";
import { Alert } from "@/components/shared/feedback";
import { FormField, PasswordInput } from "@/components/shared/form-field";
import { Button } from "@/components/ui/button";
import { Card } from "@/components/ui/card";
import { useSession } from "@/providers/session-provider";
import { canChangeOwnPassword, canProvisionAdmins } from "@/features/auth/permissions";
import type { BrowserSession } from "@/features/auth/types";
import { useCountdown } from "@/features/auth/hooks/use-countdown";
import { sessionKey } from "@/lib/query-client";
import { normalizeApiError, type ApiError } from "@/lib/errors";
import { administrativePasswordSchema, type AdministrativePasswordValues } from "../schemas";
import { changeAdministrativePassword } from "../services";

export function OwnPasswordControl() {
  const { session } = useSession();
  const [open, setOpen] = useState(false);
  if (!session || !canChangeOwnPassword(session)) return null;
  return <Card className="stack-medium">
    <h2 className="text-heading-3">Keamanan Akun</h2>
    {open ? <AdministrativePasswordForm actorId={session.user.id} onCancel={() => setOpen(false)} />
      : <Button variant="outline" onClick={() => setOpen(true)}>Ubah Password</Button>}
  </Card>;
}

export function AdminPasswordDialog({ userId, fullName, email, onChanged }: {
  userId: number; fullName: string; email: string; onChanged: () => Promise<void>;
}) {
  const { session } = useSession();
  const [open, setOpen] = useState(false);
  const busy = useRef(false);
  if (!session || !canProvisionAdmins(session)) return null;
  return <Dialog.Root open={open} onOpenChange={next => { if (!busy.current) setOpen(next); }}>
    <Dialog.Trigger asChild><Button variant="outline" aria-label={`Ubah Password ${fullName}`}>Ubah Password</Button></Dialog.Trigger>
    <Dialog.Portal><Dialog.Overlay className="dialog-overlay" />
      <Dialog.Content className="dialog-panel stack-medium" onEscapeKeyDown={event => { if (busy.current) event.preventDefault(); }} onPointerDownOutside={event => { if (busy.current) event.preventDefault(); }}>
        <Dialog.Title className="text-heading-3">Ubah Password Admin</Dialog.Title>
        <Dialog.Description>Ganti password <strong className="break-words">{fullName}</strong> (<span className="break-all">{email}</span>) tanpa konfirmasi email. Isi password Super Admin Anda untuk mengonfirmasi tindakan ini. Seluruh sesi Admin sasaran akan diakhiri; sesi Anda tetap aktif.</Dialog.Description>
        <AdministrativePasswordForm actorId={session.user.id} targetId={userId} onBusy={value => { busy.current = value; }} onCancel={() => setOpen(false)} onChanged={async () => { setOpen(false); await onChanged(); }} />
      </Dialog.Content>
    </Dialog.Portal>
  </Dialog.Root>;
}

function AdministrativePasswordForm({ actorId, targetId, onCancel, onChanged, onBusy }: {
  actorId: number; targetId?: number; onCancel: () => void; onChanged?: () => Promise<void>; onBusy?: (busy: boolean) => void;
}) {
  const auth = useSession();
  const router = useRouter();
  const client = useQueryClient();
  const [problem, setProblem] = useState<ApiError | null>(null);
  const [unknown, setUnknown] = useState(false);
  const running = useRef(false);
  const countdown = useCountdown();
  const form = useForm<AdministrativePasswordValues>({ resolver: zodResolver(administrativePasswordSchema), defaultValues: { current_password: "", password: "", password_confirmation: "" } });
  const fields = ["current_password", "password", "password_confirmation"] as const;
  const labels = { current_password: "Password Anda saat ini", password: targetId === undefined ? "Password baru" : "Password baru Admin", password_confirmation: "Konfirmasi password baru" };
  const sameActor = () => {
    const current = client.getQueryData<BrowserSession | null>(sessionKey);
    return current?.user.id === actorId && (targetId === undefined ? canChangeOwnPassword(current) : canProvisionAdmins(current));
  };
  async function submit(values: AdministrativePasswordValues) {
    if (running.current || !sameActor()) return;
    running.current = true;
    onBusy?.(true);
    setProblem(null);
    try {
      await changeAdministrativePassword(values, targetId);
      if (!sameActor()) return;
      form.reset();
      if (targetId === undefined) {
        if (await auth.completePasswordChange(actorId)) router.replace("/login");
      } else await onChanged?.();
    } catch (error) {
      if (!sameActor()) return;
      const parsed = normalizeApiError(error);
      setProblem(parsed);
      setUnknown(parsed.status === 0 || parsed.status >= 500);
      if (parsed.status === 429) countdown.start(parsed.retryAfter || 60);
      const first = fields.find(field => parsed.fields[field]?.[0]);
      for (const field of fields) if (parsed.fields[field]?.[0]) form.setError(field, { message: parsed.fields[field][0] });
      if (first) requestAnimationFrame(() => { if (sameActor()) form.setFocus(first); });
    } finally { running.current = false; onBusy?.(false); }
  }
  return <form aria-label={targetId === undefined ? "Form ubah password sendiri" : "Form ubah password Admin"} onSubmit={event => void form.handleSubmit(submit)(event)} noValidate className="stack-medium">
    {targetId === undefined && <p>Setelah password disimpan, seluruh sesi Anda akan diakhiri. Masuk kembali menggunakan password baru.</p>}
    {problem && <Alert>{problem.message}{unknown && <p>Hasil perubahan belum terkonfirmasi. {targetId === undefined ? "Coba login dengan password baru sebelum mengulang perubahan." : "Periksa hasil bersama pemilik akun sebelum mengulang perubahan."}</p>}</Alert>}
    <fieldset disabled={form.formState.isSubmitting || unknown} className="stack-medium">
      <legend className="sr-only">Konfirmasi dan password baru</legend>
      {fields.map(field => {
        const id = `change-password-${targetId ?? "self"}-${field}`;
        const error = form.formState.errors[field]?.message;
        return <FormField key={field} name={id} label={labels[field]} error={error} help={field === "password" ? "Gunakan minimal 12 karakter, maksimal 72 byte." : undefined}>
          <PasswordInput id={id} autoFocus={field === "current_password"} autoComplete={field === "current_password" ? "current-password" : "new-password"} required aria-invalid={!!error}
            aria-describedby={[field === "password" ? `${id}-help` : "", error ? `${id}-error` : ""].filter(Boolean).join(" ") || undefined} {...form.register(field)} />
        </FormField>;
      })}
    </fieldset>
    <div className="flex flex-wrap gap-3">
      <Button type="submit" loading={form.formState.isSubmitting} disabled={form.formState.isSubmitting || unknown || countdown.remaining > 0} aria-busy={form.formState.isSubmitting}>
        {form.formState.isSubmitting ? "Menyimpan password…" : countdown.remaining ? `Tunggu ${countdown.remaining} detik` : "Simpan Password Baru"}
      </Button>
      <Button type="button" variant="outline" disabled={form.formState.isSubmitting} onClick={onCancel}>Batal Ubah Password</Button>
    </div>
  </form>;
}
