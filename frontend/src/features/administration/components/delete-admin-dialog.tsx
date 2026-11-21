"use client";

import { useRef, useState } from "react";
import * as Dialog from "@radix-ui/react-dialog";
import { useQueryClient } from "@tanstack/react-query";
import { Alert } from "@/components/shared/feedback";
import { Button } from "@/components/ui/button";
import { canProvisionAdmins } from "@/features/auth/permissions";
import type { BrowserSession } from "@/features/auth/types";
import { normalizeApiError } from "@/lib/errors";
import { sessionKey } from "@/lib/query-client";
import { deleteAdminAccount } from "../services";

export function DeleteAdminDialog({ userId, fullName, email, actorId, onDeleted }: {
  userId: number; fullName: string; email: string; actorId: number; onDeleted: () => Promise<void>;
}) {
  const client = useQueryClient();
  const [open, setOpen] = useState(false);
  const [pending, setPending] = useState(false);
  const [problem, setProblem] = useState<string | null>(null);
  const busy = useRef(false);
  const cancel = useRef<HTMLButtonElement>(null);
  const sameActor = () => {
    const current = client.getQueryData<BrowserSession | null>(sessionKey);
    return current?.user.id === actorId && canProvisionAdmins(current);
  };
  async function remove() {
    if (busy.current || !sameActor()) return;
    busy.current = true;
    setPending(true);
    setProblem(null);
    try {
      await deleteAdminAccount(userId);
      if (!sameActor()) return;
      setOpen(false);
      client.removeQueries({ queryKey: ["administration", "invitation", actorId, userId], exact: true });
      await onDeleted();
    } catch (error) {
      if (!sameActor()) return;
      const parsed = normalizeApiError(error);
      setProblem(parsed.status === 0 || parsed.status >= 500
        ? "Hasil penghapusan belum terkonfirmasi. Tutup dialog dan perbarui daftar sebelum mencoba kembali."
        : parsed.message);
    } finally { busy.current = false; setPending(false); }
  }
  return <Dialog.Root open={open} onOpenChange={next => { if (!busy.current) { setOpen(next); setProblem(null); } }}>
    <Dialog.Trigger asChild><Button variant="destructive" aria-label={`Hapus Akun ${fullName}`}>Hapus Akun</Button></Dialog.Trigger>
    <Dialog.Portal>
      <Dialog.Overlay className="dialog-overlay" />
      <Dialog.Content className="dialog-panel stack-medium" onOpenAutoFocus={event => { event.preventDefault(); cancel.current?.focus(); }}
        onEscapeKeyDown={event => { if (busy.current) event.preventDefault(); }} onPointerDownOutside={event => { if (busy.current) event.preventDefault(); }}>
        <Dialog.Title className="text-heading-3">Hapus akun Admin?</Dialog.Title>
        <Dialog.Description>
          Akun <strong className="break-words">{fullName}</strong> (<span className="break-all">{email}</span>) akan dihapus dari daftar dan tidak dapat login. Seluruh sesi serta undangannya dicabut. Audit dan email tetap disimpan; email tidak dapat dipakai untuk akun baru. Tidak tersedia pemulihan melalui halaman ini.
        </Dialog.Description>
        {problem && <Alert>{problem}</Alert>}
        <div className="flex flex-wrap gap-3">
          <Dialog.Close asChild><Button ref={cancel} variant="outline" disabled={pending}>Batal</Button></Dialog.Close>
          <Button variant="destructive" disabled={pending} aria-busy={pending} onClick={() => void remove()}>{pending ? "Menghapus akun…" : "Ya, Hapus Akun"}</Button>
        </div>
      </Dialog.Content>
    </Dialog.Portal>
  </Dialog.Root>;
}
