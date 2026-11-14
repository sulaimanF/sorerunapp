"use client";

import { useEffect, useRef, useState } from "react";
import * as Dialog from "@radix-ui/react-dialog";
import { useQueryClient } from "@tanstack/react-query";
import { LoaderCircle } from "lucide-react";
import { Alert } from "@/components/shared/feedback";
import { Button } from "@/components/ui/button";
import { getMemberProfile } from "@/features/members/services";
import { ApiError, normalizeApiError } from "@/lib/errors";
import { sessionKey } from "@/lib/query-client";
import type { BrowserSession } from "../types";
import type { EmailChangeStatus } from "../email-change";
import { useCountdown } from "../hooks/use-countdown";

export function EmailChangeStatusDialog({ userId, disabled }: { userId: number; disabled: boolean }) {
  const client = useQueryClient();
  const [checking, setChecking] = useState(false);
  const [snapshot, setSnapshot] = useState<EmailChangeStatus | null>(null);
  const [problem, setProblem] = useState<string | null>(null);
  const running = useRef(false);
  const mounted = useRef(true);
  const countdown = useCountdown();
  useEffect(() => {
    mounted.current = true;
    return () => { mounted.current = false; };
  }, []);
  const sameAccount = () => client.getQueryData<BrowserSession | null>(sessionKey)?.user.id === userId;

  async function checkStatus() {
    if (running.current || disabled || !sameAccount()) return;
    running.current = true;
    setChecking(true);
    setProblem(null);
    try {
      const profile = await client.fetchQuery({
        queryKey: ["members", "profile", userId],
        queryFn: ({ signal }) => getMemberProfile(signal),
        staleTime: 0,
        meta: { requiresSession: true },
      });
      if (!mounted.current || !sameAccount()) return;
      if (!profile.email_change) {
        throw new ApiError("Status email belum dapat dibaca. Silakan coba lagi.", 502, "INVALID_RESPONSE");
      }
      if (profile.email_change.retry_after > 0) countdown.start(profile.email_change.retry_after);
      setSnapshot(profile.email_change);
    } catch (error) {
      if (mounted.current && sameAccount()) setProblem(normalizeApiError(error).message);
    } finally {
      running.current = false;
      if (mounted.current) setChecking(false);
    }
  }

  const remaining = snapshot?.retry_after ? countdown.remaining : 0;
  return (
    <>
      {problem && <Alert>{problem}</Alert>}
      <Dialog.Root open={snapshot !== null} onOpenChange={open => { if (!open) setSnapshot(null); }}>
        <Dialog.Trigger asChild>
          <Button variant="outline" type="button" disabled={disabled || checking} aria-busy={checking}
            onClick={event => { event.preventDefault(); void checkStatus(); }}>
            {checking && <LoaderCircle className="spinner" size={18} aria-hidden />}
            {checking ? "Memeriksa status email…" : "Periksa Status Email"}
          </Button>
        </Dialog.Trigger>
        <Dialog.Portal>
          <Dialog.Overlay className="dialog-overlay" />
          <Dialog.Content className="dialog-panel">
            <Dialog.Title className="text-heading-3">Status Email</Dialog.Title>
            <Dialog.Description className="supporting">Status terbaru dari server. Konfirmasi perubahan tetap dilakukan melalui tautan email.</Dialog.Description>
            {snapshot && <dl className="profile-details">
              <div><dt>Email aktif</dt><dd>{snapshot.active_email}</dd></div>
              <div>
                <dt>{snapshot.pending?.status === "expired" ? "Email kedaluwarsa" : "Email pending"}</dt>
                <dd>{snapshot.pending ? <>
                  {snapshot.pending.email}
                  <p className="supporting">{snapshot.pending.status === "expired"
                    ? "Tautan sudah kedaluwarsa. Ajukan perubahan email baru."
                    : "Menunggu konfirmasi melalui tautan email."}</p>
                </> : "Tidak ada permintaan perubahan email."}</dd>
              </div>
              <div><dt>Cooldown</dt><dd>{remaining > 0
                ? `Tunggu ${remaining} detik sebelum mengajukan perubahan lagi.`
                : "Dapat mengajukan perubahan email sekarang."}</dd></div>
            </dl>}
            <Dialog.Close asChild><Button type="button">Tutup</Button></Dialog.Close>
          </Dialog.Content>
        </Dialog.Portal>
      </Dialog.Root>
    </>
  );
}
